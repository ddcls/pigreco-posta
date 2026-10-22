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
Servizio "posta-backup": copie di backup della posta su dischi USB con restic.
Gira come root (deve montare i dischi); la pagina di gestione mette solo
richieste e impostazioni in /srv/posta/config/backup/ (vedi copie.py).

Ogni 30 secondi: segnale di vita, dischi USB collegati, richieste in attesa,
poi i lavori automatici (pianifica()): copia nei giorni e all'ora scelti (anche
saltata: appena c'è un disco, una volta), copia sul disco appena collegato se
la sua copia ha più di un giorno, verifica di un disco ogni verifica_giorni.

Copia = due snapshot restic con lo stesso tag esecuzione-<id> nella cartella
pigreco-posta/ del disco: il dump dei database della webmail (non quelli di
sistema di MySQL: il mysqldump di MariaDB non li scrive in modo che MySQL 8
li ricarichi, e gli utenti di MySQL sono quelli del server su cui si
ripristina), poi le caselle (/srv/posta senza i file di MySQL) e il .env con
la chiave che cifra le password delle caselle. Il disco si monta solo
durante il lavoro.

Dopo ogni giro manda all'amministratore gli avvisi nuovi (avvisi.py) con
l'SMTP di Gestione caselle › Impostazioni: ogni tipo al massimo una volta al
giorno, riprovando al massimo 3 volte se l'invio non riesce.

  python3 backup.py               ciclo del servizio
  python3 backup.py --dischi      stampa i dischi USB visti, senza scrivere niente
  python3 backup.py --avvisi      stampa l'email di avviso che manderebbe, senza mandarla
  python3 backup.py --ripristina  rimette tutta la posta da un disco di backup
                                  (lo lancia scripts/ripristina-backup.sh)
"""

import datetime
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import traceback

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import avvisi  # noqa: E402
import copie as cp  # noqa: E402
import postaconf as pc  # noqa: E402

DATI = pc.BASE
ENV = '/opt/pigreco-posta/env/.env'
MONTAGGI = '/mnt/backup'
HOST_RESTIC = 'pigreco-posta'
INTERVALLO = 30
LAVORI_TENUTI = 50
MAX_TENTATIVI_EMAIL = 3
DB_SISTEMA = ('mysql', 'information_schema', 'performance_schema', 'sys')
# Client MariaDB: il certificato di MySQL è autofirmato, si resta nella rete di Docker
MYSQL = ['--skip-ssl-verify-server-cert', '-h', 'db', '-P', '3306', '-u', 'root']


class Errore(Exception):
    """Errore da mostrare così com'è nella pagina."""


def log(livello, messaggio):
    print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] [{livello}] {messaggio}", flush=True)


# ==================== COMANDI ====================

def comando(cmd, ambiente=None, per_riga=None, ingresso=None):
    """Esegue un comando senza shell: (codice, uscita, errori)."""
    env = dict(os.environ, **(ambiente or {}))
    with tempfile.TemporaryFile() as err:
        proc = subprocess.Popen(cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=err, env=env)
        if ingresso is not None:
            proc.stdin.write(ingresso.encode())
        proc.stdin.close()
        uscita = []
        for riga in proc.stdout:
            riga = riga.decode('utf-8', 'replace')
            uscita.append(riga)
            if per_riga:
                per_riga(riga.rstrip('\n'))
        codice = proc.wait()
        err.seek(0)
        return codice, ''.join(uscita), err.read().decode('utf-8', 'replace')


def ultime_righe(testo):
    righe = [r.strip() for r in testo.splitlines() if r.strip()]
    return ' · '.join(righe[-3:])[:500]


def richiedi(cmd, errore, ingresso=None):
    codice, uscita, errori = comando(cmd, ingresso=ingresso)
    if codice != 0:
        dettaglio = ultime_righe(errori or uscita)
        if any(x in dettaglio.lower() for x in ('busy', 'in use')):
            raise Errore(errore)
        raise Errore(f'{errore} ({dettaglio})')


def restic(repo, argomenti, ambiente=None, su_stato=None, grezzo=False):
    """restic sul repository; con --json il riepilogo finale, con grezzo l'uscita intera."""
    cmd = ['restic', '-r', repo, '--no-cache'] + argomenti
    if not ('--json' in argomenti and argomenti[0] in ('backup', 'restore')):
        cmd.insert(4, '--quiet')   # con --quiet restic non manda le righe di avanzamento
    riepilogo = {}

    def riga(r):
        nonlocal riepilogo
        try:
            j = json.loads(r)
        except ValueError:
            return
        if not isinstance(j, dict):
            return
        if j.get('message_type') == 'summary':
            riepilogo = j
        elif j.get('message_type') == 'status' and su_stato:
            su_stato(j)

    env = {'RESTIC_PASSWORD': os.environ.get('BACKUP_PASSWORD', '')}
    env.update(ambiente or {})
    codice, uscita, errori = comando(cmd, env, riga)
    if codice != 0:
        dettaglio = ultime_righe(errori or uscita)
        if 'wrong password' in dettaglio:
            raise Errore('Password delle copie sbagliata')
        raise Errore(f'restic {argomenti[0]} non riuscito (codice {codice}): {dettaglio}')
    return uscita if grezzo else riepilogo


# ==================== DISCHI USB ====================

def dispositivo_dati():
    """"maggiore:minore" del disco che contiene /srv/posta: non si tocca mai."""
    try:
        st = os.stat(DATI)
    except OSError:
        return ''
    return f'{os.major(st.st_dev)}:{os.minor(st.st_dev)}'


def in_uso(dev):
    """
    Il disco è aperto (montato dal desktop, da PiGreco Archivio, da un altro
    programma)? Il container non vede i mount degli altri, ma il kernel
    rifiuta l'apertura esclusiva di un disco in uso.
    """
    try:
        fd = os.open(dev, os.O_RDONLY | os.O_EXCL)
    except OSError as e:
        return e.errno == 16   # EBUSY
    os.close(fd)
    return False


def partizione(p):
    return {'nome': p['name'], 'fs': p.get('fstype') or '', 'etichetta': p.get('label') or '',
            'uuid': p.get('uuid') or '', 'dimensione': int(p.get('size') or 0)}


def dischi_usb():
    """Dischi USB collegati, esclusi quelli senza supporto e quello dei dati."""
    codice, uscita, _ = comando(['lsblk', '-J', '-b', '-o',
                                 'NAME,MAJ:MIN,TYPE,TRAN,SIZE,VENDOR,MODEL,SERIAL,FSTYPE,LABEL,UUID'])
    try:
        dati = json.loads(uscita)
    except ValueError:
        dati = None
    if codice != 0 or not isinstance(dati, dict):
        raise Errore('lsblk non risponde')
    nostro = dispositivo_dati()
    dischi = []
    for d in dati.get('blockdevices', []):
        if d.get('type') != 'disk' or d.get('tran') != 'usb' or int(d.get('size') or 0) <= 0:
            continue
        if not d.get('name', '').startswith('sd'):
            continue
        partizioni, complesso = [], False
        contiene_dati = d.get('maj:min') == nostro
        if d.get('fstype') and not d.get('children'):
            partizioni.append(partizione(d))   # filesystem senza tabella delle partizioni
        for p in d.get('children') or []:
            contiene_dati = contiene_dati or p.get('maj:min') == nostro
            complesso = complesso or bool(p.get('children'))   # LVM, cifratura, RAID
            partizioni.append(partizione(p))
        if contiene_dati:
            continue
        dischi.append({
            'dispositivo': d['name'], 'seriale': (d.get('serial') or '').strip(),
            'modello': ' '.join(x for x in ((d.get('vendor') or '').strip(), (d.get('model') or '').strip()) if x),
            'dimensione': int(d['size']), 'complesso': complesso,
            'in_uso': in_uso('/dev/' + d['name']), 'partizioni': partizioni,
        })
    return dischi


# ==================== SERVIZIO ====================

class Servizio:

    def __init__(self):
        self.ultimo_segnale = 0
        self.ultimo_avanzamento = 0

    # ---------- stato ----------

    def stato(self):
        return cp.stato()

    def aggiorna_stato(self, **dati):
        st = cp.stato()
        st.update(dati)
        cp.scrivi(cp.STATO, st)

    def segnale(self, subito=False):
        if subito or time.time() - self.ultimo_segnale >= 20:
            self.ultimo_segnale = time.time()
            self.aggiorna_stato(visto=time.time())

    def aggiorna_dischi_visti(self):
        dischi = dischi_usb()
        collegati = {p['uuid'] for d in dischi for p in d['partizioni'] if p['uuid']}
        registrati = cp.dischi_registrati()
        cambiato = False
        for r in registrati:
            if r['uuid'] in collegati:
                r['ultimo_collegamento'] = time.time()
                if not r.get('collegato_dal'):
                    r['collegato_dal'] = time.time()
                cambiato = True
            elif r.get('collegato_dal'):
                r['collegato_dal'] = None
                cambiato = True
        if cambiato:
            cp.scrivi(cp.DISCHI, registrati)
        self.aggiorna_stato(visti=dischi, visto=time.time())
        return dischi

    def avvio(self):
        """All'avvio: cartelle, e il lavoro rimasto "in corso" è stato interrotto."""
        for cartella in (cp.CARTELLA, cp.RICHIESTE):
            pc.crea_cartella(cartella, 0o700)
        st = cp.stato()
        if st.get('lavoro'):
            self.chiudi_lavoro(st['lavoro'], False, 'Interrotto (PC spento o riavviato?)')

    # ---------- giro ----------

    def giro(self):
        self.segnale(True)
        self.aggiorna_dischi_visti()
        while self.prossima_richiesta():
            self.aggiorna_dischi_visti()
        if self.pianifica():
            while self.prossima_richiesta():
                self.aggiorna_dischi_visti()
        self.avvisi_per_email()

    # ---------- avvisi per email ----------

    def avvisi_per_email(self, prova=False):
        """
        Gli avvisi nuovi di oggi in una sola email (con gli altri per il quadro).
        prova: restituisce il testo senza mandarlo né segnarlo.
        """
        if not prova and (not cp.impostazioni()['avvisi_email'] or not avvisi.smtp_configurato()):
            return None
        elenco = avvisi.avvisi(per_email=True)
        oggi = time.strftime('%Y-%m-%d')
        inviati = {k: v for k, v in cp.leggi(cp.AVVISI_INVIATI, {}).items() if k.split('|')[1] >= oggi}
        nuovi = [a for a in elenco if prova or not (
            inviati.get(f"{a['tipo']}|{oggi}", {}).get('esito') == 'inviato'
            or inviati.get(f"{a['tipo']}|{oggi}", {}).get('tentativi', 0) >= MAX_TENTATIVI_EMAIL)]
        if not nuovi:
            return None
        altri = [a for a in elenco if a not in nuovi]
        oggetto = 'PiGreco Posta: ' + avvisi.OGGETTI.get(nuovi[0]['tipo'], 'avviso delle copie di backup') \
            + (' (e altri avvisi)' if len(nuovi) > 1 else '')
        corpo = '\n\n'.join(a['testo'] for a in nuovi) + '\n\n'
        if altri:
            corpo += 'Già segnalati oggi:\n' + '\n'.join('- ' + a['testo'] for a in altri) + '\n\n'
        corpo += ('Dettagli in PiGreco Posta › Gestione caselle › Copie di backup.\n\n'
                  'Ogni avviso arriva al massimo una volta al giorno. Si spegne in Copie di backup › Controlli e avvisi.\n')
        if prova:
            conf = avvisi.smtp()
            return f"A: {', '.join(conf['destinatari']) or '(nessun destinatario impostato)'}\nOggetto: {oggetto}\n\n{corpo}"
        try:
            avvisi.invia(oggetto, corpo)
            esito, errore = 'inviato', None
        except RuntimeError as e:
            esito, errore = 'errore', str(e)
        for a in nuovi:
            chiave = f"{a['tipo']}|{oggi}"
            voce = inviati.setdefault(chiave, {'tentativi': 0})
            voce.update(esito=esito, messaggio=errore, tentativi=voce['tentativi'] + 1, quando=time.time())
        cp.scrivi(cp.AVVISI_INVIATI, inviati)
        tipi = ', '.join(a['tipo'] for a in nuovi)
        log('WARN' if errore else 'INFO', f'Avvisi per email ({tipi}): {errore or "inviati"}')
        return None

    def prossima_richiesta(self):
        try:
            nomi = sorted(n for n in os.listdir(cp.RICHIESTE) if n.endswith('.json'))
        except OSError:
            return False
        if not nomi:
            return False
        percorso = os.path.join(cp.RICHIESTE, nomi[0])
        lavoro = cp.leggi(percorso, None)
        os.unlink(percorso)
        if lavoro:
            self.esegui(lavoro)
        return True

    def esegui(self, lavoro):
        lavoro.update(stato='in_corso', inizio=time.time(), avanzamento=None)
        self.aggiorna_stato(lavoro=lavoro)
        p = lavoro.get('parametri') or {}
        log('INFO', f"Lavoro {lavoro['id']}: {lavoro['tipo']}")
        try:
            if not os.environ.get('BACKUP_PASSWORD'):
                raise Errore('Manca la password delle copie (BACKUP_PASSWORD nel .env): rilancia ./installa.sh')
            azione = {'prepara_disco': self.prepara_disco, 'usa_disco': self.usa_disco, 'copia': self.copia,
                      'verifica': self.verifica, 'dimentica_disco': self.dimentica_disco}.get(lavoro['tipo'])
            if not azione:
                raise Errore('Lavoro sconosciuto')
            esito = azione(lavoro, p)
            self.chiudi_lavoro(lavoro, True, esito)
            log('INFO', f"Lavoro {lavoro['id']} riuscito: {esito}")
        except Exception as e:  # noqa: BLE001 - ogni errore finisce nella pagina
            messaggio = str(e) if isinstance(e, Errore) else f'Errore inatteso: {e}'
            if not isinstance(e, Errore):
                traceback.print_exc()
            self.chiudi_lavoro(lavoro, False, messaggio)
            log('ERROR', f"Lavoro {lavoro['id']} fallito: {messaggio}")

    def chiudi_lavoro(self, lavoro, riuscito, messaggio):
        lavoro.update(stato='riuscito' if riuscito else 'fallito', fine=time.time(),
                      messaggio=str(messaggio)[:1000], avanzamento=None)
        elenco = [lavoro] + [x for x in cp.lavori() if x.get('id') != lavoro['id']]
        cp.scrivi(cp.LAVORI, elenco[:LAVORI_TENUTI])
        self.aggiorna_stato(lavoro=None)

    def avanzamento(self, lavoro, percento):
        if time.time() - self.ultimo_avanzamento < 3:
            return
        self.ultimo_avanzamento = time.time()
        lavoro['avanzamento'] = max(0, min(99, percento))
        self.aggiorna_stato(lavoro=lavoro, visto=time.time())

    # ---------- dischi ----------

    def disco_richiesto(self, p):
        """Il disco scelto nella pagina, ricontrollato: stesso dispositivo, seriale e dimensione."""
        for d in dischi_usb():
            if (d['dispositivo'] == p.get('dispositivo') and d['seriale'] == p.get('seriale', '')
                    and d['dimensione'] == p.get('dimensione')):
                return d
        raise Errore('Il disco scelto non è più collegato (o è cambiato): ricollegalo e riprova')

    def contenuto_pigreco(self, disco):
        """
        Guarda dentro ogni partizione (montata in sola lettura): se c'è una
        cartella di copie di PiGreco Archivio o Posta il disco NON si formatta,
        anche se qualcuno ha cambiato l'etichetta. Restituisce la cartella trovata.
        Una partizione ext4 (come quelle preparate da PiGreco) che non si
        riesce a guardare blocca la formattazione.
        """
        for p in disco['partizioni']:
            if not p['fs']:
                continue
            cartella = tempfile.mkdtemp(prefix='controllo-', dir='/tmp')
            try:
                dev = '/dev/' + p['nome']
                # "-o ro" il kernel lo rifiuta se il disco è già aperto in scrittura
                # altrove: allora montaggio normale e subito sola lettura per questo punto
                montato = comando(['mount', '-o', 'ro', dev, cartella])[0] == 0
                if not montato and comando(['mount', '-o', 'noatime', dev, cartella])[0] == 0:
                    montato = comando(['mount', '-o', 'remount,bind,ro', cartella])[0] == 0
                    if not montato:
                        comando(['umount', cartella])
                if not montato:
                    if p['fs'] == 'ext4':
                        raise Errore(f"Non riesco a controllare il contenuto della partizione {p['nome']}: "
                                     'il disco non si formatta. Se è aperto sul desktop, smontalo e riprova')
                    continue
                try:
                    for nome in cp.CARTELLE_PIGRECO:
                        if os.path.isdir(os.path.join(cartella, nome)):
                            return nome
                finally:
                    comando(['umount', cartella])
            finally:
                try:
                    os.rmdir(cartella)
                except OSError:
                    pass
        return None

    def prepara_disco(self, lavoro, p):
        """Cancella il disco: GPT, una partizione ext4 PIGRECO-<NOME>, copie in pigreco-posta/."""
        disco = self.disco_richiesto(p)
        nome = p.get('nome', '')
        classe = cp.classifica(disco, cp.dischi_registrati())
        if classe['stato'] != 'da_preparare':
            raise Errore('Questo disco non si può formattare: ' + classe.get('motivo', classe['stato']))
        if not cp.NOME_DISCO.match(nome):
            raise Errore('Nome del disco non valido')
        trovata = self.contenuto_pigreco(disco)
        if trovata:
            raise Errore(f'Sul disco ci sono copie di PiGreco ({trovata}/): non si formatta. '
                         'Se è un disco di backup, ricollegalo: deve comparire come già preparato')
        dev = '/dev/' + disco['dispositivo']
        log('WARN', f"Formatto {dev} ({disco['modello']}, seriale {disco['seriale']}) come {cp.PREFISSO_ETICHETTA}{nome}")
        occupato = 'Il disco è in uso (forse aperto dal desktop): smontalo e riprova'
        # wipefs apre il disco in modo esclusivo: se una partizione è montata il kernel rifiuta
        for part in disco['partizioni']:
            if part['nome'] != disco['dispositivo']:
                richiedi(['wipefs', '-a', '/dev/' + part['nome']], occupato)
        richiedi(['wipefs', '-a', dev], occupato)
        richiedi(['sfdisk', '--quiet', '--wipe', 'always', dev], 'Non riesco a creare la partizione', 'label: gpt\n,,L\n')
        part = dev + '1'
        for _ in range(20):
            if os.path.exists(part):
                break
            time.sleep(0.5)
        else:
            raise Errore('La partizione nuova non compare: stacca e ricollega il disco, poi riprova')
        richiedi(['mkfs.ext4', '-q', '-F', '-m', '0', '-L', cp.PREFISSO_ETICHETTA + nome, part], 'Formattazione non riuscita')
        codice, uscita, _ = comando(['blkid', '-p', '-o', 'value', '-s', 'UUID', part])
        uuid = uscita.strip()
        if not uuid:
            raise Errore('La partizione nuova non ha un UUID')

        def init(repo):
            restic(repo, ['init'])
            return shutil.disk_usage(repo).free
        libero = self.con_disco(part, uuid, init)
        self.registra(nome, uuid, disco, [], libero)
        lavoro['disco'] = nome
        return f'Disco {nome} pronto'

    def usa_disco(self, lavoro, p):
        """Disco già preparato (anche da PiGreco Archivio): solo la cartella pigreco-posta/."""
        disco = self.disco_richiesto(p)
        registrati = cp.dischi_registrati()
        classe = cp.classifica(disco, registrati)
        if classe['stato'] != 'pronto':
            raise Errore('Questo disco non è preparato per le copie')
        nome = classe['nome']
        if any(d['nome'] == nome for d in registrati):
            raise Errore(f"C'è già un disco registrato con il nome {nome}")

        def apri(repo):
            if os.path.isfile(os.path.join(repo, 'config')):
                try:
                    restic(repo, ['cat', 'config'])
                except Errore:
                    raise Errore("Su questo disco ci sono copie di un'altra PiGreco Posta (con un'altra password)")
            else:
                restic(repo, ['init'])
            return self.elenco_copie(repo), shutil.disk_usage(repo).free
        copie, libero = self.con_disco('/dev/' + classe['partizione'], classe['uuid'], apri)
        self.registra(nome, classe['uuid'], disco, copie, libero)
        lavoro['disco'] = nome
        return f'Disco {nome} registrato' + (f' ({len(copie)} copie già presenti)' if copie else '')

    def registra(self, nome, uuid, disco, copie, libero):
        registrati = [d for d in cp.dischi_registrati() if d['uuid'] != uuid]
        registrati.append({
            'nome': nome, 'uuid': uuid, 'seriale': disco['seriale'], 'modello': disco['modello'],
            'dimensione': disco['dimensione'], 'spazio_libero': libero or None, 'copie': copie,
            'ultima_copia': copie[0]['data'] if copie else None, 'registrato_il': time.time(),
            'ultimo_collegamento': time.time(), 'collegato_dal': time.time(),
            'ultima_verifica': None, 'verifica_ok': None, 'verifica_messaggio': None,
        })
        registrati.sort(key=lambda d: d['nome'])
        cp.scrivi(cp.DISCHI, registrati)

    def dimentica_disco(self, lavoro, p):
        """Il disco non si usa più per le copie della posta: sul disco non si cancella niente."""
        registrati = cp.dischi_registrati()
        disco = next((d for d in registrati if d['uuid'] == p.get('uuid')), None)
        if not disco:
            raise Errore('Disco non trovato')
        cp.scrivi(cp.DISCHI, [d for d in registrati if d['uuid'] != disco['uuid']])
        lavoro['disco'] = disco['nome']
        return f"Il disco {disco['nome']} non si usa più per le copie della posta (le copie già fatte restano sul disco)"

    def disco_collegato(self, uuid=None):
        """Disco registrato e collegato: quello chiesto o quello con la copia più vecchia. (disco, /dev/partizione)"""
        collegati = {p['uuid']: '/dev/' + p['nome'] for d in dischi_usb() for p in d['partizioni'] if p['uuid']}
        registrati = sorted(cp.dischi_registrati(), key=lambda d: (d.get('ultima_copia') is not None, d.get('ultima_copia') or 0))
        for d in registrati:
            if uuid and d['uuid'] != uuid:
                continue
            if d['uuid'] in collegati:
                return d, collegati[d['uuid']]
            if uuid:
                raise Errore(f"Il disco {d['nome']} non è collegato")
        raise Errore('Nessun disco di backup collegato')

    def aggiorna_disco(self, uuid, **dati):
        registrati = cp.dischi_registrati()
        for d in registrati:
            if d['uuid'] == uuid:
                d.update(dati)
        cp.scrivi(cp.DISCHI, registrati)

    # ---------- copia ----------

    def escludi_mysql(self):
        """I file di MySQL stanno di solito dentro la cartella della posta: c'è il dump."""
        posta = os.path.normpath(os.environ.get('POSTA_DIR_HOST', '/srv/posta'))
        mysql = os.path.normpath(os.environ.get('WEBMAIL_DB_DIR_HOST', '/srv/posta/mysql'))
        if mysql.startswith(posta + os.sep):
            return ['--exclude', os.path.join(DATI, os.path.relpath(mysql, posta))]
        return []

    def copia(self, lavoro, p):
        disco, part = self.disco_collegato(p.get('uuid'))
        lavoro['disco'] = disco['nome']
        self.avanzamento(lavoro, 0)
        # Riparazione automatica dopo uno spegnimento brusco; se il disco è aperto
        # anche da altri (desktop, PiGreco Archivio) e2fsck si rifiuta: si va avanti
        codice, uscita, errori = comando(['e2fsck', '-p', part])
        if codice & 4:
            raise Errore(f"Il disco {disco['nome']} ha errori che non si riparano da soli: {ultime_righe(errori + uscita)}")
        imp = cp.impostazioni()
        etichette = ['--host', HOST_RESTIC, '--tag', 'posta', '--tag', f"esecuzione-{lavoro['id']}"]
        mysql_env = {'MYSQL_PWD': os.environ.get('DB_ROOT_PASS', '')}
        codice, uscita, errori = comando(['mysql'] + MYSQL + ['-N', '-e', 'SHOW DATABASES'], mysql_env)
        if codice != 0:
            raise Errore('Il database della webmail non risponde: ' + ultime_righe(errori or uscita))
        basi = [b for b in uscita.split() if b not in DB_SISTEMA]
        if not basi:
            raise Errore('Non trovo il database della webmail')

        def lavora(repo):
            if not os.path.isfile(os.path.join(repo, 'config')):
                restic(repo, ['init'])
            restic(repo, ['unlock'])   # solo i lock rimasti da una copia interrotta su questa macchina
            db = restic(repo, ['backup', '--json', '--tag', 'database'] + etichette + [
                '--stdin-filename', 'webmail.sql', '--stdin-from-command', '--',
                'mysqldump'] + MYSQL + ['--single-transaction', '--routines', '--events', '--add-drop-database',
                '--databases'] + basi, mysql_env)
            self.avanzamento(lavoro, 5)
            percorsi = [DATI] + ([ENV] if os.path.isfile(ENV) else [])
            caselle = restic(repo, ['backup', '--json', '--tag', 'caselle'] + etichette + self.escludi_mysql() + percorsi,
                             su_stato=lambda s: self.avanzamento(lavoro, 5 + round(90 * float(s.get('percent_done') or 0))))
            restic(repo, ['forget', '--host', HOST_RESTIC, '--prune',
                          '--keep-daily', str(imp['tieni_giorni']), '--keep-weekly', str(imp['tieni_settimane']),
                          '--keep-monthly', str(imp['tieni_mesi'])])
            return db, caselle, self.elenco_copie(repo), shutil.disk_usage(repo).free

        db, caselle, copie, libero = self.con_disco(part, disco['uuid'], lavora)
        aggiunti = int(db.get('data_added') or 0) + int(caselle.get('data_added') or 0)
        file = int(caselle.get('files_new') or 0) + int(caselle.get('files_changed') or 0)
        self.aggiorna_disco(disco['uuid'], ultima_copia=time.time(), spazio_libero=libero, copie=copie,
                            ultimo_collegamento=time.time())
        lavoro.update(byte_aggiunti=aggiunti, file_copiati=file)
        return (f"Copia sul disco {disco['nome']}: {file} file nuovi o cambiati, {cp.byte(aggiunti)} aggiunti. "
                'Puoi staccare il disco.')

    def elenco_copie(self, repo, sola_lettura=False):
        """Copie sul disco, la più recente prima: una per esecuzione, con gli id dei due snapshot."""
        uscita = restic(repo, ['snapshots', '--json', '--host', HOST_RESTIC] + (['--no-lock'] if sola_lettura else []),
                        grezzo=True)
        try:
            snapshot = json.loads(uscita) or []
        except ValueError:
            snapshot = []
        copie = {}
        for s in snapshot:
            etichette = s.get('tags') or []
            chiave = next((t for t in etichette if t.startswith('esecuzione-')), 'altro-' + s['short_id'])
            tipo = 'database' if 'database' in etichette else 'caselle'
            # restic scrive i nanosecondi, che fromisoformat (Python 3.11) non accetta
            testo = s['time']
            if '.' in testo:
                testo = testo[:testo.index('.')] + testo[testo.index('.') + 1:].lstrip('0123456789')
            data = datetime.datetime.fromisoformat(testo.replace('Z', '+00:00')).timestamp()
            c = copie.setdefault(chiave, {'data': data, 'database': None, 'caselle': None})
            c[tipo] = s['short_id']
            c['data'] = max(c['data'], data)
        return sorted(copie.values(), key=lambda c: c['data'], reverse=True)

    # ---------- verifica ----------

    def verifica(self, lavoro, p):
        """restic check con la lettura del 5% delle copie, ogni volta un pezzo diverso."""
        disco, part = self.disco_collegato(p.get('uuid'))
        lavoro['disco'] = disco['nome']

        def controlla(repo):
            if not os.path.isfile(os.path.join(repo, 'config')):
                raise Errore('Sul disco non ci sono ancora copie da verificare')
            restic(repo, ['unlock'])
            try:
                restic(repo, ['check', '--read-data-subset', '5%'])
                return None
            except Errore as e:
                if str(e) == 'Password delle copie sbagliata':
                    raise
                return str(e)
        problema = self.con_disco(part, disco['uuid'], controlla)
        self.aggiorna_disco(disco['uuid'], ultima_verifica=time.time(), verifica_ok=problema is None,
                            verifica_messaggio=problema[:1000] if problema else None)
        if problema:
            raise Errore(f"La verifica del disco {disco['nome']} ha trovato errori: {problema}")
        return f"Verifica del disco {disco['nome']}: nessun errore. Puoi staccare il disco."

    # ---------- lavori automatici ----------

    def pianifica(self):
        """
        Accoda al massimo un lavoro automatico; True se l'ha accodato.
        1. turno programmato (anche saltato) non ancora gestito;
        2. disco appena collegato con l'ultima copia di più di 24 ore fa (una
           volta per collegamento, anche se la copia non riesce);
        3. verifica del disco collegato quando è il momento (al massimo una al giorno).
        """
        if cp.occupato():
            return False
        imp = cp.impostazioni()
        adesso = datetime.datetime.now()
        ora = time.time()
        st = cp.stato()
        collegati = [d for d in cp.dischi_registrati() if d.get('collegato_dal')]
        storia = cp.lavori()

        turno = cp.turno_precedente(imp, adesso)
        if turno and turno.strftime('%Y-%m-%d %H:%M') > st.get('ultimo_turno', ''):
            inizio = turno.timestamp()
            if any(x['tipo'] == 'copia' and x['stato'] == 'riuscito' and (x.get('fine') or 0) >= inizio for x in storia):
                self.aggiorna_stato(ultimo_turno=turno.strftime('%Y-%m-%d %H:%M'))   # già copiato, anche a mano
            elif collegati:
                self.aggiorna_stato(ultimo_turno=turno.strftime('%Y-%m-%d %H:%M'))
                saltata = ora - inizio > 600
                return self.accoda('copia', None, f"copia saltata del {turno.strftime('%d/%m/%Y %H:%M')}" if saltata
                                   else 'copia programmata')
            # nessun disco collegato: si copia appena se ne collega uno

        if imp['al_collegamento']:
            for d in collegati:
                if d.get('ultima_copia') and d['ultima_copia'] > ora - 86400:
                    continue
                if any(x['tipo'] == 'copia' and x.get('origine') == 'automatica'
                       and (x.get('parametri') or {}).get('uuid') == d['uuid']
                       and x.get('richiesta_il', 0) >= d['collegato_dal'] for x in storia):
                    continue
                return self.accoda('copia', d['uuid'], f"disco {d['nome']} collegato")

        if imp['verifica_giorni'] > 0:
            for d in collegati:
                if not d.get('ultima_copia'):
                    continue
                riferimento = d.get('ultima_verifica') or d.get('registrato_il') or 0
                if riferimento > ora - imp['verifica_giorni'] * 86400:
                    continue
                if any(x['tipo'] == 'verifica' and (x.get('parametri') or {}).get('uuid') == d['uuid']
                       and x.get('richiesta_il', 0) >= ora - 86400 for x in storia):
                    continue
                return self.accoda('verifica', d['uuid'], f"verifica periodica del disco {d['nome']}")
        return False

    def accoda(self, tipo, uuid, motivo):
        parametri = {'motivo': motivo}
        if uuid:
            parametri['uuid'] = uuid
        cp.richiedi(tipo, 'automatica', **parametri)
        log('INFO', f'Lavoro automatico: {tipo} ({motivo})')
        return True

    # ---------- montaggio ----------

    def con_disco(self, part, uuid, lavoro, sola_lettura=False):
        """
        Monta la partizione, esegue lavoro(cartella delle copie) e smonta sempre.
        In sola lettura per il ripristino (restic allora va con --no-lock).
        """
        cartella = os.path.join(MONTAGGI, ''.join(c for c in uuid if c.isalnum() or c == '-'))
        os.makedirs(cartella, mode=0o700, exist_ok=True)
        if os.path.ismount(cartella):
            comando(['umount', cartella])
        richiedi(['mount', '-t', 'ext4', '-o', 'noatime', part, cartella], 'Non riesco a montare il disco')
        try:
            # Sola lettura solo per questo punto di montaggio: "-o ro" il kernel
            # lo rifiuta se il disco è già aperto altrove (desktop, PiGreco Archivio)
            if sola_lettura:
                richiedi(['mount', '-o', 'remount,bind,ro', cartella], 'Non riesco a montare il disco in sola lettura')
            repo = os.path.join(cartella, cp.CARTELLA_COPIE)
            if not sola_lettura:
                os.makedirs(repo, mode=0o700, exist_ok=True)
            return lavoro(repo)
        finally:
            comando(['sync'])
            if comando(['umount', cartella])[0] != 0:
                comando(['umount', '-l', cartella])


class Ripristino(Servizio):
    """
    Rimette tutta la posta da un disco di backup (PC nuovo o disco dei dati
    rotto). Lo lancia scripts/ripristina-backup.sh in un container usa e getta
    del servizio posta-backup, con webmail e copia locale ferme e il database
    acceso, e la cartella /ripristino/esito per lo scambio con lo script.

    Passi: disco PIGRECO-* collegato, password (prima quella di questo PC, poi
    quella del foglio di ripristino), copia da rimettere, conferma, caselle in
    /srv/posta (i file di MySQL restano quelli di adesso), database della
    webmail (cancellati e ricaricati dal dump; utenti e password di MySQL
    restano quelli di questo server), controllo finale. Il disco si legge
    soltanto. Lo script poi rimette nel .env la chiave che cifra le password
    delle caselle e la password delle copie del vecchio .env.
    """

    ESITO = '/ripristino/esito'

    def __init__(self):
        super().__init__()
        self.password = ''

    def scrivi(self, testo=''):
        print(testo, flush=True)

    def chiedi(self, domanda, predefinita='', nascosta=False):
        print(domanda, end='', flush=True)
        terminale = sys.stdin.isatty()
        if nascosta and terminale:
            subprocess.run(['stty', '-echo'])
        try:
            riga = sys.stdin.readline()
        finally:
            if nascosta and terminale:
                subprocess.run(['stty', 'echo'])
                print()
        if not riga:
            raise Errore('Ripristino annullato')
        return riga.strip() or predefinita

    def esegui_ripristino(self):
        self.scrivi('\n=== Ripristino di PiGreco Posta da un disco di backup ===\n')
        if not os.path.isdir(self.ESITO):
            self.scrivi(f'Manca la cartella {self.ESITO}: lancia ./scripts/ripristina-backup.sh, non questo file.')
            return 1
        part, uuid, nome = self.scegli_disco()
        copie = self.con_disco(part, uuid, lambda repo: self.apri(repo, nome), True)
        if copie is None:
            return 1
        copia = self.scegli_copia(copie)
        caselle = len(pc.leggi_account()) if os.path.isfile(pc.CONFIG) else 0
        self.scrivi(f"\nStai per rimettere su questo server la copia del {self.data(copia['data'])} (disco {nome}).")
        if caselle:
            self.scrivi(f'ATTENZIONE: questo server ha già {caselle} caselle: le caselle, gli utenti e gli account della '
                        'webmail verranno SOSTITUITI da quelli della copia.')
        if self.chiedi('Per continuare scrivi RIPRISTINA: ').upper() != 'RIPRISTINA':
            self.scrivi('Ripristino annullato: non è stato cambiato niente.')
            return 1

        def rimetti(repo):
            self.salva_esito(repo, copia['caselle'])
            self.ripristina_caselle(repo, copia['caselle'])
            self.ripristina_database(repo, copia['database'])
        self.con_disco(part, uuid, rimetti, True)
        self.sistema_stato()
        return self.controllo_finale()

    def scegli_disco(self):
        while True:
            trovati = []
            for d in dischi_usb():
                for p in d['partizioni']:
                    nome = p['etichetta'][len(cp.PREFISSO_ETICHETTA):]
                    if p['fs'] == 'ext4' and p['etichetta'].startswith(cp.PREFISSO_ETICHETTA) and cp.NOME_DISCO.match(nome):
                        trovati.append(('/dev/' + p['nome'], p['uuid'], nome, d['modello']))
            if len(trovati) == 1:
                self.scrivi(f'Disco di backup trovato: {trovati[0][2]} ({trovati[0][3]}).')
                return trovati[0][:3]
            if trovati:
                self.scrivi('Dischi di backup collegati:')
                for i, t in enumerate(trovati, 1):
                    self.scrivi(f'  {i}) {t[2]} ({t[3]})')
                scelta = self.chiedi('Quale uso? [1] ', '1')
                if scelta.isdigit() and 1 <= int(scelta) <= len(trovati):
                    return trovati[int(scelta) - 1][:3]
                continue
            self.scrivi('Non trovo nessun disco di backup (disco USB con la partizione PIGRECO-...).')
            if self.chiedi('Collegalo e premi Invio (oppure scrivi "esci"): ').lower() == 'esci':
                raise Errore('Ripristino annullato: nessun disco di backup')

    def apri(self, repo, nome):
        """Prima con la password di questo server, poi chiedendola (foglio di ripristino)."""
        if not os.path.isfile(os.path.join(repo, 'config')):
            self.scrivi(f'Sul disco {nome} non ci sono copie della posta (manca la cartella {cp.CARTELLA_COPIE}/).')
            return None
        candidata = os.environ.get('BACKUP_PASSWORD', '')
        for tentativo in range(4):
            if candidata:
                os.environ['BACKUP_PASSWORD'] = candidata
                try:
                    copie = [c for c in self.elenco_copie(repo, sola_lettura=True) if c['database'] and c['caselle']]
                    self.password = candidata
                    if not copie:
                        raise Errore("Sul disco non c'è nessuna copia completa (database e caselle)")
                    return copie
                except Errore as e:
                    if str(e) != 'Password delle copie sbagliata':
                        raise
                    if tentativo:
                        self.scrivi('Password sbagliata.')
            if tentativo == 3:
                break
            candidata = ''.join(self.chiedi('Password delle copie (è sul foglio di ripristino; gli spazi non contano): ',
                                            nascosta=True).split())
        self.scrivi('Le copie non si aprono con questa password. Controlla il foglio di ripristino e riprova.')
        return None

    def scegli_copia(self, copie):
        self.scrivi('\nCopie sul disco (la più recente per prima):')
        for i, c in enumerate(copie[:30], 1):
            self.scrivi(f'  {i:2}) {self.data(c["data"])}')
        if len(copie) > 30:
            self.scrivi(f'  … e altre {len(copie) - 30} più vecchie')
        while True:
            scelta = self.chiedi('Quale copia rimetto? [1 = la più recente] ', '1')
            if scelta.isdigit() and 1 <= int(scelta) <= min(30, len(copie)):
                return copie[int(scelta) - 1]

    def ripristina_caselle(self, repo, snapshot):
        self.scrivi('\n1/3 Caselle: le copio in /srv/posta (può volerci molto, dipende da quanta posta c\'è)...')
        ultimo = [-5]

        def avanza(s):
            p = int(100 * float(s.get('percent_done') or 0))
            if p >= ultimo[0] + 5:
                ultimo[0] = p
                self.scrivi(f'    {p}%')
        restic(repo, ['restore', f'{snapshot}:{DATI}', '--no-lock', '--json', '--target', DATI], su_stato=avanza)
        self.scrivi('    Caselle a posto.')

    def ripristina_database(self, repo, snapshot):
        """Database della webmail dal dump (che li cancella e ricrea): dopo vale la chiave del vecchio .env."""
        self.scrivi('\n2/3 Database della webmail: lo ricarico dalla copia...')
        codice, uscita, errori = comando(['bash', '-o', 'pipefail', '-c',
            'restic -r "$1" --no-cache --quiet --no-lock dump "$2" /webmail.sql'
            ' | mysql --skip-ssl-verify-server-cert -h db -P 3306 -u root',
            '_', repo, snapshot], {'RESTIC_PASSWORD': self.password, 'MYSQL_PWD': os.environ.get('DB_ROOT_PASS', '')})
        if codice != 0:
            raise Errore('Il database non si carica: ' + ultime_righe(errori + uscita)
                         + '. Rilancia il ripristino; se si ripete prova una copia più vecchia.')
        # Per lo script: da qui gli account della webmail vogliono la chiave del vecchio .env
        open(os.path.join(self.ESITO, 'database_caricato'), 'w').close()
        self.scrivi('    Database a posto.')

    def salva_esito(self, repo, snapshot):
        """La password delle copie e il .env del vecchio server, per scripts/ripristina-backup.sh"""
        def pc_scrivi(nome, testo):
            with open(os.path.join(self.ESITO, nome), 'w', encoding='utf-8') as f:
                f.write(testo)
        pc_scrivi('backup_password', self.password)
        codice, env, _ = comando(['restic', '-r', repo, '--no-cache', '--quiet', '--no-lock', 'dump', snapshot, ENV],
                                 {'RESTIC_PASSWORD': self.password})
        if codice == 0 and env:
            pc_scrivi('env-dalla-copia', env)
        uid, _, gid = os.environ.get('PROPRIETARIO', '0:0').partition(':')
        for nome in os.listdir(self.ESITO):
            percorso = os.path.join(self.ESITO, nome)
            os.chmod(percorso, 0o600)
            os.chown(percorso, int(uid), int(gid or uid))

    def sistema_stato(self):
        """
        Nella copia lo stato delle copie aveva la copia stessa "in corso":
        diventa riuscita (al riavvio sarebbe segnata come interrotta).
        """
        st = cp.stato()
        lavoro = st.get('lavoro')
        if lavoro:
            lavoro.update(stato='riuscito', fine=lavoro.get('inizio') or time.time(), avanzamento=None,
                          messaggio=f"Copia usata per il ripristino del {time.strftime('%d/%m/%Y')}")
            cp.scrivi(cp.LAVORI, [lavoro] + [x for x in cp.lavori() if x.get('id') != lavoro.get('id')])
            st['lavoro'] = None
            cp.scrivi(cp.STATO, st)

    def controllo_finale(self):
        self.scrivi('\n3/3 Controllo: ogni casella ha la sua cartella?')
        caselle = pc.leggi_account() if os.path.isfile(pc.CONFIG) else []
        mancanti = [a['indirizzo'] for a in caselle if not os.path.isdir(os.path.join(DATI, a['indirizzo'], 'Maildir'))]
        self.scrivi(f'    Caselle: {len(caselle)}.')
        if mancanti:
            self.scrivi(f"    ATTENZIONE: caselle senza email copiate: {', '.join(mancanti)}.")
            self.scrivi('    Si riempiono alla prossima sincronizzazione, se sul gestore ci sono ancora.')
            return 2
        self.scrivi('    Tutte le caselle hanno le loro email.')
        return 0

    @staticmethod
    def data(ts):
        return time.strftime('%d/%m/%Y alle %H:%M', time.localtime(ts))


def main():
    if '--dischi' in sys.argv:
        print(json.dumps(dischi_usb(), indent=2, ensure_ascii=False))
        return
    if '--avvisi' in sys.argv:
        print(Servizio().avvisi_per_email(prova=True) or 'Nessun avviso da mandare.')
        return
    if '--ripristina' in sys.argv:
        try:
            sys.exit(Ripristino().esegui_ripristino())
        except Errore as e:
            print(f'\nRipristino non riuscito: {e}', flush=True)
            sys.exit(1)
    servizio = Servizio()
    servizio.avvio()
    log('INFO', f'Avvio servizio copie di backup (intervallo: {INTERVALLO}s)')
    while True:
        try:
            servizio.giro()
        except Exception as e:  # noqa: BLE001 - il servizio non deve fermarsi
            log('ERROR', f'Copie di backup: {e}')
        time.sleep(INTERVALLO)


if __name__ == '__main__':
    main()
