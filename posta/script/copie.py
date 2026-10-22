# PiGreco Posta
# Copyright (C) 2026 Criscione Enzo
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
# secondo i termini della GNU Affero General Public License come pubblicata
# dalla Free Software Foundation, versione 3 della licenza o (a tua scelta)
# qualsiasi versione successiva.
#
# Questo programma è distribuito nella speranza che sia utile, ma SENZA
# ALCUNA GARANZIA; senza neppure la garanzia implicita di COMMERCIABILITÀ
# o di IDONEITÀ PER UN PARTICOLARE SCOPO. Vedi la GNU Affero General
# Public License per i dettagli.
#
# Dovresti aver ricevuto una copia della GNU Affero General Public License
# insieme a questo programma (file LICENSE). In caso contrario, vedi
# <https://www.gnu.org/licenses/>.

"""
Copie di backup su dischi USB: regole comuni alla pagina di gestione
(gestione.py, utente vmail) e al servizio che fa le copie (backup.py, root).

Tutto sta in /srv/posta/config/backup/ (quindi anche nelle copie):
  impostazioni.json  quando copiare, quante copie tenere (scritto dalla pagina)
  richieste/         una richiesta per file, messa dalla pagina e tolta dal servizio
  dischi.json        dischi di backup registrati (scritto solo dal servizio)
  stato.json         segnale di vita, dischi collegati, lavoro in corso (servizio)
  lavori.json        ultimi lavori con l'esito (servizio)

I dischi sono gli stessi di PiGreco Archivio: partizione ext4 con etichetta
PIGRECO-<NOME>, preparata da uno dei due programmi. Ognuno scrive solo nella
sua cartella (pigreco-archivio/, pigreco-posta/) con la sua password: un disco
già preparato si USA, non si formatta mai.
"""

import datetime
import json
import os
import re
import secrets
import time

import postaconf as pc

CARTELLA = os.path.join(pc.CONFIG_DIR, 'backup')
IMPOSTAZIONI = os.path.join(CARTELLA, 'impostazioni.json')
RICHIESTE = os.path.join(CARTELLA, 'richieste')
DISCHI = os.path.join(CARTELLA, 'dischi.json')
STATO = os.path.join(CARTELLA, 'stato.json')
LAVORI = os.path.join(CARTELLA, 'lavori.json')

PREFISSO_ETICHETTA = 'PIGRECO-'
NOME_DISCO = re.compile(r'^[A-Z0-9]{1,8}$')   # l'etichetta ext4 ha al massimo 16 caratteri
CARTELLA_COPIE = 'pigreco-posta'
CARTELLE_PIGRECO = ('pigreco-archivio', 'pigreco-posta')
SERVIZIO_FERMO = 120        # secondi senza un giro: il servizio è fermo

# chiave: (predefinito, minimo, massimo, nome per i messaggi)
NUMERI = {
    'tieni_giorni': (7, 1, 365, 'copie giornaliere'),
    'tieni_settimane': (4, 0, 520, 'copie settimanali'),
    'tieni_mesi': (12, 0, 240, 'copie mensili'),
    'verifica_giorni': (30, 0, 365, 'giorni tra due verifiche'),
}
NOMI_LAVORO = {'copia': 'Copia', 'verifica': 'Verifica disco', 'prepara_disco': 'Preparazione disco',
               'usa_disco': 'Registrazione disco', 'dimentica_disco': 'Disco tolto'}
ORA = re.compile(r'^([01]\d|2[0-3]):[0-5]\d$')


# ==================== FILE ====================

def leggi(percorso, predefinito):
    try:
        with open(percorso, encoding='utf-8') as f:
            return json.load(f)
    except (OSError, ValueError):
        return predefinito


def scrivi(percorso, dati):
    pc.scrivi(percorso, json.dumps(dati, ensure_ascii=False, indent=1))


# ==================== IMPOSTAZIONI ====================

def impostazioni():
    grezze = leggi(IMPOSTAZIONI, {})
    giorni = sorted({int(g) for g in grezze.get('giorni', [1, 2, 3, 4, 5])
                     if str(g).isdigit() and 1 <= int(g) <= 7})
    ora = str(grezze.get('ora', '23:00'))
    imp = {
        'giorni': giorni,
        # Di serie un'ora dopo PiGreco Archivio (22:00): non si contendono il disco
        'ora': ora if ORA.match(ora) else '23:00',
        'al_collegamento': bool(grezze.get('al_collegamento', True)),
    }
    for chiave, (predefinito, minimo, massimo, _) in NUMERI.items():
        v = grezze.get(chiave, predefinito)
        imp[chiave] = max(minimo, min(massimo, v)) if isinstance(v, int) else predefinito
    return imp


def salva_impostazioni(dati):
    """dati: valori del modulo (stringhe; giorni come caselle giorno_1…giorno_7).
    Errore leggibile con ValueError."""
    imp = {'giorni': [g for g in range(1, 8) if dati.get(f'giorno_{g}')]}
    ora = dati.get('ora', '').strip()
    if not ORA.match(ora):
        raise ValueError("L'ora della copia va scritta come 23:00")
    imp['ora'] = ora
    imp['al_collegamento'] = bool(dati.get('al_collegamento'))
    for chiave, (_, minimo, massimo, nome) in NUMERI.items():
        v = str(dati.get(chiave, '')).strip()
        if not v.isdigit() or not minimo <= int(v) <= massimo:
            raise ValueError(f'Numero di {nome}: da {minimo} a {massimo}')
        imp[chiave] = int(v)
    scrivi(IMPOSTAZIONI, imp)
    return imp


def turno_precedente(imp, adesso):
    """Turno programmato più recente fino ad adesso; None se non ci sono giorni."""
    h, m = map(int, imp['ora'].split(':'))
    for i in range(8):
        t = (adesso - datetime.timedelta(days=i)).replace(hour=h, minute=m, second=0, microsecond=0)
        if t <= adesso and t.isoweekday() in imp['giorni']:
            return t
    return None


def turno_successivo(imp, adesso):
    h, m = map(int, imp['ora'].split(':'))
    for i in range(8):
        t = (adesso + datetime.timedelta(days=i)).replace(hour=h, minute=m, second=0, microsecond=0)
        if t > adesso and t.isoweekday() in imp['giorni']:
            return t
    return None


# ==================== DISCHI ====================

def dischi_registrati():
    return leggi(DISCHI, [])


def classifica(disco, registrati):
    """
    Cosa si può fare con un disco USB collegato:
    - registrato:      è già un disco di backup della posta;
    - pronto:          preparato (PIGRECO-*, ext4) anche da PiGreco Archivio: si USA,
                       nella cartella pigreco-posta/, senza formattare;
    - da_preparare:    si può formattare (non è aperto da nessuno);
    - non_preparabile: meglio non toccarlo (motivo).
    Il servizio, prima di formattare, guarda anche dentro le partizioni
    (backup.py: niente cartelle pigreco-archivio/ o pigreco-posta/).
    """
    per_uuid = {d['uuid']: d['nome'] for d in registrati}
    for p in disco['partizioni']:
        if p['uuid'] and p['uuid'] in per_uuid:
            return {'stato': 'registrato', 'nome': per_uuid[p['uuid']], 'uuid': p['uuid'], 'partizione': p['nome']}
    for p in disco['partizioni']:
        if p['fs'] == 'ext4' and p['etichetta'].startswith(PREFISSO_ETICHETTA):
            nome = p['etichetta'][len(PREFISSO_ETICHETTA):]
            if NOME_DISCO.match(nome):
                return {'stato': 'pronto', 'nome': nome, 'uuid': p['uuid'], 'partizione': p['nome']}
    if disco.get('complesso'):
        return {'stato': 'non_preparabile', 'motivo': 'contiene volumi LVM, cifrati o RAID'}
    if disco.get('in_uso'):
        return {'stato': 'non_preparabile',
                'motivo': 'lo sta usando il computer o un altro programma (aperto sul desktop, o '
                          'PiGreco Archivio sta facendo una copia). Se vuoi prepararlo, smontalo e riprova tra qualche minuto'}
    for p in disco['partizioni']:
        if p['etichetta'].startswith(PREFISSO_ETICHETTA):
            return {'stato': 'non_preparabile', 'motivo': "ha un'etichetta PiGreco ma non è un disco di backup valido"}
    return {'stato': 'da_preparare'}


# ==================== STATO E RICHIESTE ====================

def stato():
    return leggi(STATO, {})


def servizio_attivo(st=None):
    st = st if st is not None else stato()
    return bool(st.get('visto')) and time.time() - st['visto'] < SERVIZIO_FERMO


def lavori():
    return leggi(LAVORI, [])


def richieste_in_attesa():
    try:
        nomi = sorted(n for n in os.listdir(RICHIESTE) if n.endswith('.json'))
    except OSError:
        return []
    return [r for r in (leggi(os.path.join(RICHIESTE, n), None) for n in nomi) if r]


def occupato():
    """Un lavoro in corso o in attesa: se ne fa uno alla volta."""
    return bool(stato().get('lavoro')) or bool(richieste_in_attesa())


def richiedi(tipo, origine='manuale', **parametri):
    """Mette una richiesta per il servizio; restituisce il suo id."""
    if not os.path.isdir(RICHIESTE):
        raise ValueError('Il servizio delle copie non è ancora partito: aspetta un minuto e riprova')
    if occupato():
        raise ValueError("C'è già un lavoro in corso: aspetta che finisca")
    ident = f'{int(time.time() * 1000)}-{secrets.token_hex(3)}'
    scrivi(os.path.join(RICHIESTE, f'{ident}.json'),
           {'id': ident, 'tipo': tipo, 'origine': origine, 'richiesta_il': time.time(), 'parametri': parametri})
    return ident


# ==================== FORMATI ====================

def byte(n):
    if n is None:
        return '—'
    n = float(n)
    for unita in ('B', 'KB', 'MB', 'GB', 'TB'):
        if n < 1024 or unita == 'TB':
            return f'{n:.0f} {unita}' if unita == 'B' else f'{n:.1f} {unita}'.replace('.', ',')
        n /= 1024
