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
Email dell'amministratore e avvisi delle copie di backup.

SMTP dell'amministratore (Gestione caselle › Impostazioni), in
/srv/posta/config/amministratore.json (600, vmail): server, porta, sicurezza,
utente, password, mittente, destinatari. Lo usa il servizio posta-backup per
gli avvisi; la pagina per la prova di invio.

avvisi(): cose che l'amministratore deve sapere sulle copie, per la striscia
in cima alle pagine di gestione e per le email (al massimo una al giorno per
tipo, le manda il servizio: backup.py).
"""

import email.utils
import os
import re
import smtplib
import ssl
import time
from email.message import EmailMessage

import copie
import postaconf as pc

AMMINISTRATORE = os.path.join(pc.CONFIG_DIR, 'amministratore.json')
SICUREZZE = ('ssl', 'starttls', 'nessuna')
INDIRIZZO = re.compile(r'^[^@\s,;]+@[^@\s,;]+\.[^@\s,;]+$')


# ==================== SMTP ====================

def smtp():
    dati = copie.leggi(AMMINISTRATORE, {})
    return {
        'server': str(dati.get('server', '')), 'porta': int(dati.get('porta') or 465),
        'sicurezza': dati.get('sicurezza') if dati.get('sicurezza') in SICUREZZE else 'ssl',
        'utente': str(dati.get('utente', '')), 'password': str(dati.get('password', '')),
        'mittente': str(dati.get('mittente', '')), 'destinatari': list(dati.get('destinatari') or []),
    }


def smtp_configurato(conf=None):
    conf = conf or smtp()
    return bool(conf['server'] and conf['destinatari'] and (conf['mittente'] or conf['utente']))


def salva_smtp(dati):
    """dati: valori del modulo. Password vuota = resta quella salvata. ValueError se non va."""
    attuale = smtp()
    server = dati.get('server', '').strip()
    porta = dati.get('porta', '').strip()
    if server and not re.fullmatch(r'[A-Za-z0-9.-]+', server):
        raise ValueError('Server SMTP non valido (es. smtp.libero.it)')
    if not porta.isdigit() or not 1 <= int(porta) <= 65535:
        raise ValueError('Porta non valida (di solito 465 con SSL, 587 con STARTTLS)')
    sicurezza = dati.get('sicurezza', 'ssl')
    if sicurezza not in SICUREZZE:
        raise ValueError('Sicurezza non valida')
    mittente = dati.get('mittente', '').strip()
    if mittente and not INDIRIZZO.match(mittente):
        raise ValueError('Mittente non valido')
    destinatari = [d.strip() for d in re.split(r'[,;\s]+', dati.get('destinatari', '')) if d.strip()]
    for d in destinatari:
        if not INDIRIZZO.match(d):
            raise ValueError(f'Destinatario non valido: {d}')
    if server and not destinatari:
        raise ValueError('Scrivi almeno un destinatario per gli avvisi')
    password = dati.get('password', '')
    conf = {'server': server, 'porta': int(porta), 'sicurezza': sicurezza, 'utente': dati.get('utente', '').strip(),
            'password': password if password else (attuale['password'] if dati.get('utente', '').strip() else ''),
            'mittente': mittente, 'destinatari': destinatari}
    copie.scrivi(AMMINISTRATORE, conf)
    return conf


def invia(oggetto, corpo, conf=None):
    """Manda un'email di testo ai destinatari; errore leggibile con RuntimeError."""
    conf = conf or smtp()
    if not smtp_configurato(conf):
        raise RuntimeError("L'SMTP dell'amministratore non è impostato (Gestione caselle › Impostazioni)")
    msg = EmailMessage()
    msg['From'] = conf['mittente'] or conf['utente']
    msg['To'] = ', '.join(conf['destinatari'])
    msg['Subject'] = oggetto
    msg['Date'] = email.utils.formatdate(localtime=True)
    msg['Message-ID'] = email.utils.make_msgid(domain=(msg['From'].split('@')[-1] or 'pigreco.locale'))
    msg.set_content(corpo)
    contesto = ssl.create_default_context()
    # smtp.tiscali.it offre chiavi DH da 1024 bit che OpenSSL 3 rifiuta: niente DHE
    contesto.set_ciphers('DEFAULT:!kDHE')
    try:
        if conf['sicurezza'] == 'ssl':
            s = smtplib.SMTP_SSL(conf['server'], conf['porta'], timeout=30, context=contesto)
        else:
            s = smtplib.SMTP(conf['server'], conf['porta'], timeout=30)
        with s:
            if conf['sicurezza'] == 'starttls':
                s.starttls(context=contesto)
            if conf['utente']:
                s.login(conf['utente'], conf['password'])
            s.send_message(msg)
    except (OSError, smtplib.SMTPException) as e:
        testo = str(e)
        if isinstance(e, smtplib.SMTPAuthenticationError):
            testo = 'utente o password sbagliati'
        elif isinstance(e, (TimeoutError, ConnectionRefusedError)) or 'timed out' in testo:
            testo = 'il server non risponde (controlla server e porta)'
        raise RuntimeError(f'Invio non riuscito: {testo}') from e


# ==================== AVVISI ====================

def avvisi(per_email=False):
    """Elenco di {'tipo', 'testo'}; per_email esclude quelli che hanno senso solo nella pagina."""
    imp = copie.impostazioni()
    st = copie.stato()
    elenco = []
    adesso = time.time()
    if not per_email and not copie.servizio_attivo(st):
        elenco.append({'tipo': 'servizio', 'testo': 'Il servizio delle copie di backup non risponde: le copie non si fanno '
                                                    '(dalla cartella del programma: docker compose up -d posta-backup).'})
    dischi = copie.dischi_registrati()
    if not dischi:
        if not per_email:
            elenco.append({'tipo': 'nessun_disco', 'testo': 'Le copie di backup della posta non sono attive: '
                                                            'prepara un disco USB in Copie di backup.'})
        return elenco

    storia = copie.lavori()
    riuscite = [x for x in storia if x['tipo'] == 'copia' and x['stato'] == 'riuscito']
    limite = adesso - imp['avviso_giorni'] * 86400
    if riuscite and riuscite[0]['fine'] < limite:
        giorni = int((adesso - riuscite[0]['fine']) // 86400)
        elenco.append({'tipo': 'vecchia', 'testo': f"L'ultima copia di backup della posta riuscita è di {giorni} giorni fa "
                       f"({time.strftime('%d/%m/%Y', time.localtime(riuscite[0]['fine']))}): collega un disco di backup."})
    elif not riuscite and min(d.get('registrato_il') or adesso for d in dischi) < limite:
        elenco.append({'tipo': 'vecchia', 'testo': 'Non è ancora stata fatta nessuna copia di backup della posta: '
                                                   'collega un disco di backup.'})

    ultima = next((x for x in storia if x['tipo'] == 'copia' and x['stato'] in ('riuscito', 'fallito')), None)
    if ultima and ultima['stato'] == 'fallito':
        disco = f" sul disco {ultima['disco']}" if ultima.get('disco') else ''
        elenco.append({'tipo': 'fallita', 'testo': 'La copia di backup della posta del '
                       f"{time.strftime('%d/%m/%Y alle %H:%M', time.localtime(ultima['fine']))}{disco} "
                       f"non è riuscita: {ultima.get('messaggio', '')}"})

    for d in dischi:
        if d.get('spazio_libero') is not None and d.get('dimensione') and d['spazio_libero'] < d['dimensione'] * 0.1:
            elenco.append({'tipo': 'pieno', 'testo': f"Il disco di backup {d['nome']} è quasi pieno (libero "
                           f"{copie.byte(d['spazio_libero'])}): tieni meno copie o usa un disco più grande."})
        if d.get('verifica_ok') is False:
            elenco.append({'tipo': 'verifica', 'testo': f"La verifica del disco di backup {d['nome']} ha trovato errori: "
                           f"sostituiscilo. ({d.get('verifica_messaggio', '')})"})
        if (len(dischi) > 1 and imp['rotazione_giorni'] > 0 and d.get('collegato_dal')
                and d['collegato_dal'] < adesso - imp['rotazione_giorni'] * 86400):
            giorni = int((adesso - d['collegato_dal']) // 86400)
            elenco.append({'tipo': 'rotazione', 'testo': f"Il disco di backup {d['nome']} è collegato da {giorni} giorni: "
                                                         'scambialo con quello a casa.'})
    return elenco


OGGETTI = {
    'vecchia': 'le copie di backup della posta sono vecchie',
    'fallita': 'una copia di backup della posta non è riuscita',
    'pieno': 'un disco di backup è quasi pieno',
    'verifica': 'un disco di backup ha errori',
    'rotazione': 'è ora di scambiare il disco di backup',
}
