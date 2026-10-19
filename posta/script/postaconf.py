#!/usr/bin/env python3
"""
Configurazione della copia locale: caselle in /srv/posta/config/account.conf,
utenti Dovecot, configurazione di mbsync, ciclo di sincronizzazione.
Usato da riga di comando (genera-config) e dalla pagina di gestione.

    genera-config dovecot          utenti Dovecot + certificato di prova
    genera-config mbsync           mbsyncrc e password dei gestori
    genera-config sincronizza      ciclo: mbsync per casella ogni POSTA_INTERVALLO s
    genera-config imposta-password [utente]   login della pagina di gestione
    genera-config pulizia-prova [indirizzo] [giorni]
                                   cosa verrebbe cancellato dal gestore (non cancella niente)

account.conf, un blocco per casella (nome del blocco = indirizzo email,
usato anche come utente IMAP locale):

    [mario.rossi@libero.it]
    server = imap.libero.it
    porta = 993
    sicurezza = ssl              ; ssl | starttls
    utente = mario.rossi@libero.it
    password_gestore = ...
    password_locale_hash = {SHA512-CRYPT}$6$...   ; oppure password_locale = in chiaro
    escludi = Templates, __liberosms*            ; facoltativo
    attiva = si                                  ; no = sincronizzazione sospesa
    tieni_giorni = 0     ; giorni di email da tenere sul gestore, 0 = non cancellare mai

Con tieni_giorni > 0, dopo ogni sincronizzazione riuscita vengono cancellate
dal gestore le email arrivate da più di tieni_giorni giorni, SOLO se la loro
copia locale è confermata (vedi pulisci_gestore). In locale resta tutto.

Le cartelle speciali del gestore (Posta inviata, Spam, Cestino, Bozze...)
vengono riconosciute (flag SPECIAL-USE o nome) e copiate in Sent, Junk,
Trash, Drafts della casella locale, quale che sia il nome usato dal gestore.
"""

import base64
import configparser
import fcntl
import getpass
import hashlib
import hmac
import imaplib
import json
import os
import re
import secrets
import socket
import ssl
import subprocess
import sys
import time

BASE = '/srv/posta'
CONFIG_DIR = os.path.join(BASE, 'config')
CONFIG = os.path.join(CONFIG_DIR, 'account.conf')
GENERATI = os.path.join(CONFIG_DIR, 'generati')
STATO = os.path.join(CONFIG_DIR, 'stato')
SSL_DIR = os.path.join(CONFIG_DIR, 'ssl')
PASSWD_DOVECOT = os.path.join(GENERATI, 'caselle.passwd')
MBSYNCRC = os.path.join(GENERATI, 'mbsyncrc')
CASELLE_JSON = os.path.join(GENERATI, 'caselle.json')
GESTIONE_PASSWD = os.path.join(CONFIG_DIR, 'gestione.passwd')
RICHIESTA_SINCRONIZZAZIONE = os.path.join(STATO, 'sincronizza-ora')
VMAIL = 5000
TIENI_GIORNI_MINIMO = 7      # sotto questo valore tieni_giorni è rifiutato (svista)

INDIRIZZO = re.compile(r'^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$')

# Cartella locale → flag SPECIAL-USE e nomi usati dai gestori (minuscolo)
SPECIALI = {
    'Sent':   ('\\sent',   ['sent', 'sent messages', 'sent items', 'sent mail', 'inviata',
                            'posta inviata', 'inviati', 'elementi inviati', 'outbox']),
    'Drafts': ('\\drafts', ['drafts', 'draft', 'bozze', 'bozza']),
    'Junk':   ('\\junk',   ['junk', 'spam', 'junk e-mail', 'junk email', 'posta indesiderata',
                            'indesiderata', 'bulk mail']),
    'Trash':  ('\\trash',  ['trash', 'cestino', 'deleted messages', 'deleted items',
                            'elementi eliminati', 'posta eliminata']),
}
NOMI_LOCALI = {'Sent': 'Inviata', 'Drafts': 'Bozze', 'Junk': 'Indesiderata', 'Trash': 'Cestino'}

# Server IMAP proposti in base al dominio dell'indirizzo (modificabili nel form)
GESTORI_NOTI = {
    'libero.it': ('imap.libero.it', 993, 'ssl'),
    'inwind.it': ('imap.libero.it', 993, 'ssl'),
    'iol.it': ('imap.libero.it', 993, 'ssl'),
    'blu.it': ('imap.libero.it', 993, 'ssl'),
    'giallo.it': ('imap.libero.it', 993, 'ssl'),
    'tiscali.it': ('imap.tiscali.it', 993, 'ssl'),
    'aruba.it': ('imaps.aruba.it', 993, 'ssl'),
    'pec.it': ('imaps.pec.aruba.it', 993, 'ssl'),
    'legalmail.it': ('mbox.cert.legalmail.it', 993, 'ssl'),
    'virgilio.it': ('in.virgilio.it', 993, 'ssl'),
    'gmail.com': ('imap.gmail.com', 993, 'ssl'),
    'googlemail.com': ('imap.gmail.com', 993, 'ssl'),
    'yahoo.it': ('imap.mail.yahoo.com', 993, 'ssl'),
    'yahoo.com': ('imap.mail.yahoo.com', 993, 'ssl'),
}
AVVISI_GESTORI = {
    'imap.gmail.com': 'Gmail accetta solo una "password per le app" (account Google con verifica in due passaggi).',
    'imap.mail.yahoo.com': 'Yahoo accetta solo una "password per le app".',
}

# Gestori che via IMAP accettano solo OAuth2 (niente utente + password): non
# gestibili, il modulo li rifiuta e la pagina spiega perché. Microsoft: caselle
# personali (hotmail, outlook, live, msn) e Microsoft 365 (outlook.office365.com,
# anche con dominio aziendale).
OAUTH_DOMINIO = re.compile(r'^(hotmail|outlook|live|msn|windowslive)\.[a-z]{2,3}(\.[a-z]{2})?$')
OAUTH_SERVER = re.compile(r'(^|\.)(outlook\.com|office365\.com|office\.com|outlook\.live\.com)$')


def richiede_oauth(indirizzo, server):
    """True se la casella è di un gestore che accetta solo OAuth2."""
    dominio = (indirizzo or '').rsplit('@', 1)[-1].strip().lower() if '@' in (indirizzo or '') else ''
    return bool(OAUTH_DOMINIO.match(dominio) or OAUTH_SERVER.search((server or '').strip().lower().rstrip('.')))


def errore(msg):
    print(f'genera-config: {msg}', file=sys.stderr)


def e_root():
    return os.geteuid() == 0


def scrivi(percorso, testo, modo=0o600):
    tmp = f'{percorso}.{os.getpid()}.tmp'
    with open(tmp, 'w', encoding='utf-8') as f:
        f.write(testo)
    os.chmod(tmp, modo)
    if e_root():
        os.chown(tmp, VMAIL, VMAIL)
    os.replace(tmp, percorso)


def crea_cartella(percorso, modo=0o750):
    os.makedirs(percorso, mode=modo, exist_ok=True)
    if e_root():
        os.chown(percorso, VMAIL, VMAIL)
    try:
        os.chmod(percorso, modo)   # anche se esisteva già (e contro l'umask)
    except OSError:
        pass


# ==================== ACCOUNT.CONF ====================

def _parser():
    return configparser.ConfigParser(interpolation=None, inline_comment_prefixes=(';',))


def leggi_account(tutte=True):
    """Caselle valide di account.conf (con tutte=False solo quelle attive)."""
    if not os.path.isfile(CONFIG):
        return []
    cp = _parser()
    cp.read(CONFIG, encoding='utf-8')
    account = []
    for indirizzo in cp.sections():
        s = cp[indirizzo]
        if not INDIRIZZO.match(indirizzo):
            errore(f'[{indirizzo}] non è un indirizzo email valido: ignorato')
            continue
        mancanti = [k for k in ('server', 'utente', 'password_gestore') if not s.get(k)]
        if not (s.get('password_locale') or s.get('password_locale_hash')):
            mancanti.append('password_locale')
        if mancanti:
            errore(f'[{indirizzo}] mancano: {", ".join(mancanti)}: ignorato')
            continue
        sicurezza = s.get('sicurezza', 'ssl').strip().lower()
        if sicurezza not in ('ssl', 'starttls'):
            errore(f'[{indirizzo}] sicurezza "{sicurezza}" non valida (ssl | starttls): ignorato')
            continue
        a = {
            'indirizzo': indirizzo.lower(),
            'server': s['server'].strip(),
            'porta': int(s.get('porta', '993' if sicurezza == 'ssl' else '143')),
            'sicurezza': sicurezza,
            'utente': s['utente'].strip(),
            'password_gestore': s['password_gestore'],
            'password_locale': s.get('password_locale', ''),
            'password_locale_hash': s.get('password_locale_hash', '').strip(),
            'escludi': [e.strip() for e in s.get('escludi', '').split(',') if e.strip()],
            'attiva': s.get('attiva', 'si').strip().lower() not in ('no', '0', 'false'),
        }
        try:
            a['tieni_giorni'] = int(s.get('tieni_giorni', '0').strip() or '0')
        except ValueError:
            a['tieni_giorni'] = -1
        if a['tieni_giorni'] != 0 and a['tieni_giorni'] < TIENI_GIORNI_MINIMO:
            errore(f'[{indirizzo}] tieni_giorni = {s.get("tieni_giorni")} non valido (0 oppure almeno '
                   f'{TIENI_GIORNI_MINIMO}): nessuna email verrà cancellata dal gestore')
            a['tieni_giorni'] = 0
        if tutte or a['attiva']:
            account.append(a)
    return account


def salva_account(lista):
    """Riscrive account.conf con le caselle date (stesso formato letto sopra)."""
    crea_cartella(CONFIG_DIR)
    righe = ['# Caselle copiate in locale. Gestito anche dalla pagina "Gestione caselle":',
             '# le modifiche a mano restano, i commenti no.', '']
    for a in lista:
        righe.append(f"[{a['indirizzo']}]")
        for chiave in ('server', 'porta', 'sicurezza', 'utente', 'password_gestore'):
            righe.append(f'{chiave} = {a[chiave]}')
        if a.get('password_locale_hash'):
            righe.append(f"password_locale_hash = {a['password_locale_hash']}")
        else:
            righe.append(f"password_locale = {a['password_locale']}")
        if a.get('escludi'):
            righe.append('escludi = ' + ', '.join(a['escludi']))
        righe.append('attiva = ' + ('si' if a.get('attiva', True) else 'no'))
        righe.append(f"tieni_giorni = {int(a.get('tieni_giorni', 0))}"
                     '   ; giorni di email da tenere sul gestore, 0 = non cancellare mai dal gestore')
        righe.append('')
    with open(CONFIG + '.lock', 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        scrivi(CONFIG, '\n'.join(righe))


# ==================== DOVECOT ====================

def hash_password(password):
    r = subprocess.run(['openssl', 'passwd', '-6', '-stdin'], input=password + '\n',
                       capture_output=True, text=True, check=True)
    return '{SHA512-CRYPT}' + r.stdout.strip()


def genera_utenti_dovecot():
    """
    File utenti di Dovecot: Dovecot lo rilegge da solo quando cambia. Lo
    legge l'autenticazione di Dovecot (gruppo vmail): cartella e file sono
    leggibili dal gruppo, le password dei gestori (generati/password) no.
    """
    crea_cartella(GENERATI, 0o750)
    righe = []
    for a in leggi_account():
        casa = os.path.join(BASE, a['indirizzo'])
        crea_cartella(casa)
        for sotto in ('Maildir', 'Maildir/cur', 'Maildir/new', 'Maildir/tmp'):
            crea_cartella(os.path.join(casa, sotto), 0o700)
        impronta = a['password_locale_hash'] or hash_password(a['password_locale'])
        righe.append(f"{a['indirizzo']}:{impronta}:{VMAIL}:{VMAIL}::{casa}::")
    scrivi(PASSWD_DOVECOT, '\n'.join(righe) + ('\n' if righe else ''), 0o640)
    return len(righe)


def prepara_dovecot():
    crea_cartella(CONFIG_DIR)
    print(f'genera-config: {genera_utenti_dovecot()} caselle per Dovecot')
    # Certificato autofirmato di prova, se non ne è stato messo uno vero
    cert, key = os.path.join(SSL_DIR, 'cert.pem'), os.path.join(SSL_DIR, 'key.pem')
    if not (os.path.isfile(cert) and os.path.isfile(key)):
        os.makedirs(SSL_DIR, mode=0o700, exist_ok=True)
        nome = os.environ.get('POSTA_NOME_SERVER', socket.gethostname())
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:3072', '-nodes', '-days', '3650',
                        '-subj', f'/CN={nome}', '-keyout', key, '-out', cert],
                       check=True, capture_output=True)
        os.chmod(key, 0o600)
        print(f'genera-config: creato un certificato autofirmato per "{nome}"')


# ==================== GESTORE (IMAP) ====================

def connetti(a, timeout=30):
    contesto = ssl.create_default_context()
    if a['sicurezza'] == 'ssl':
        imap = imaplib.IMAP4_SSL(a['server'], a['porta'], ssl_context=contesto, timeout=timeout)
    else:
        imap = imaplib.IMAP4(a['server'], a['porta'], timeout=timeout)
        imap.starttls(ssl_context=contesto)
    imap.login(a['utente'], a['password_gestore'])
    return imap


def nome_imap(grezzo):
    grezzo = grezzo.strip()
    if grezzo.startswith('"') and grezzo.endswith('"'):
        return re.sub(r'\\(.)', r'\1', grezzo[1:-1])
    return grezzo


def q(testo):
    """Valore tra virgolette (mbsyncrc e comandi IMAP)."""
    return '"' + testo.replace('\\', '\\\\').replace('"', '\\"') + '"'


def cartelle_gestore(a, imap=None, conta=False):
    """Cartelle del gestore: [{nome, delim, flag (minuscoli), messaggi}]."""
    propria = imap is None
    if propria:
        imap = connetti(a)
    try:
        tipo, dati = imap.list('""', '*')
        if tipo != 'OK':
            raise RuntimeError('LIST rifiutato dal gestore')
        cartelle = []
        for voce in dati:
            if voce is None:
                continue
            if isinstance(voce, tuple):
                # Nome come literal: (b'(\\Flag) "/" {12}', b'Nome cartella')
                testa, nome = voce[0].decode('utf-8', 'replace'), voce[1].decode('utf-8', 'replace')
                m = re.match(r'\((?P<flag>[^)]*)\)\s+(?P<delim>"(?:[^"\\]|\\.)*"|NIL)', testa)
            else:
                riga = voce.decode('utf-8', 'replace') if isinstance(voce, bytes) else str(voce)
                m = re.match(r'\((?P<flag>[^)]*)\)\s+(?P<delim>"(?:[^"\\]|\\.)*"|NIL)\s+(?P<nome>.+)$', riga)
                nome = nome_imap(m.group('nome')) if m else None
            if not m or nome is None:
                continue
            delim = None if m.group('delim') == 'NIL' else nome_imap(m.group('delim'))
            cartelle.append({'nome': nome, 'delim': delim,
                             'flag': m.group('flag').lower().split(), 'messaggi': None})
        if conta:
            for c in cartelle:
                if apribile(c):
                    tipo, dati = imap.status(q(c['nome']), '(MESSAGES)')
                    if tipo == 'OK':
                        m = re.search(rb'MESSAGES\s+(\d+)', dati[0])
                        c['messaggi'] = int(m.group(1)) if m else None
        return cartelle
    finally:
        if propria:
            try:
                imap.logout()
            except Exception:
                pass


def apribile(c):
    return '\\noselect' not in c['flag'] and '\\nonexistent' not in c['flag']


def trova_speciali(cartelle):
    """{cartella locale: nome sul gestore}: prima i flag, poi i nomi noti."""
    trovate = {}
    utili = [c for c in cartelle if apribile(c)]
    for locale, (flag, _) in SPECIALI.items():
        for c in utili:
            if flag in c['flag']:
                trovate[locale] = c['nome']
                break
    for locale, (_, nomi) in SPECIALI.items():
        if locale in trovate:
            continue
        for candidato in nomi:
            for c in utili:
                nome, delim = c['nome'], c['delim']
                foglia = nome
                # "INBOX.Sent" / "INBOX/Sent" (Aruba e simili)
                if delim and nome.upper().startswith('INBOX' + delim):
                    foglia = nome[len('INBOX' + delim):]
                if delim and delim in foglia:
                    continue
                if foglia.lower() == candidato and nome not in trovate.values():
                    trovate[locale] = nome
                    break
            if locale in trovate:
                break
    return trovate


def piano_cartelle(a, cartelle):
    """
    Come vengono copiate le cartelle del gestore.
    @return dict con 'canali' [(nome gestore, nome locale)] fuori dal canale
            principale, 'esclusi' (pattern del canale principale) e 'righe'
            [{gestore, locale, messaggi, nota}] per la pagina di gestione.
    """
    speciali = trova_speciali(cartelle)
    nomi = {c['nome'] for c in cartelle}
    canali = [(gest, loc) for loc, gest in speciali.items()]

    # Una cartella del gestore che si chiama come una speciale locale ma non
    # è quella speciale (es. "Junk" accanto a "Spam" \Junk) finirebbe nella
    # stessa cartella locale: si copia a parte, in "<nome>_gestore"
    omonime = [loc for loc in speciali if loc not in speciali.values() and loc in nomi]
    canali += [(loc, f'{loc}_gestore') for loc in omonime]

    # Nella copia locale (Maildir++) il punto separa le sottocartelle: una
    # cartella "INBOX.Sent" su un gestore che separa con "/" va rinominata
    puntate = [c['nome'] for c in cartelle if apribile(c) and c['delim'] not in (None, '.')
               and '.' in c['nome'] and c['nome'] not in speciali.values() and c['nome'] not in omonime]
    canali += [(nome, nome.replace('.', '_')) for nome in puntate]

    # Patterns vale per tutti e due i lati: vanno escluse anche le cartelle
    # locali gestite dagli altri canali (Junk, Sent_gestore...)
    esclusi = [gest for gest, _ in canali] + [loc for _, loc in canali]
    esclusi += [f'{nome}/*' for nome in puntate] + a.get('escludi', [])
    esclusi = list(dict.fromkeys(esclusi))

    locale_di = dict(canali)
    righe = []
    for c in sorted(cartelle, key=lambda c: c['nome'].lower()):
        if not apribile(c):
            continue
        nome = c['nome']
        nota = ''
        if any(re.fullmatch(re.escape(e).replace(r'\*', '.*'), nome) for e in a.get('escludi', [])):
            loc, nota = '—', 'esclusa'
        elif nome in locale_di:
            loc = locale_di[nome]
            if loc in NOMI_LOCALI:
                nota = f'cartella speciale ({NOMI_LOCALI[loc]})'
            elif nome in omonime:
                nota = 'stesso nome di una cartella speciale locale'
            else:
                nota = 'punto nel nome sostituito'
        else:
            loc = 'Posta in arrivo' if nome.upper() == 'INBOX' else nome
        righe.append({'gestore': nome, 'locale': loc, 'messaggi': c['messaggi'], 'nota': nota})
    return {'canali': canali, 'esclusi': esclusi, 'righe': righe}


# ==================== MBSYNC ====================

COMUNI = """Create Near
Remove None
Sync PullNew PullFlags
Expunge None
SyncState *
CopyArrivalDate yes
"""


def genera_mbsync():
    crea_cartella(GENERATI, 0o750)
    crea_cartella(os.path.join(GENERATI, 'password'), 0o700)
    blocchi = ['# Generato da genera-config mbsync: NON modificare, cambiare account.conf\n']
    caselle = []
    for n, a in enumerate(leggi_account(tutte=False), 1):
        nome = f'casella{n}'
        file_password = os.path.join(GENERATI, 'password', nome)
        scrivi(file_password, a['password_gestore'])

        try:
            cartelle = cartelle_gestore(a)
            piano = piano_cartelle(a, cartelle)
            # Cartelle create solo nella copia locale (es. dalla webmail): fuori
            # dalla sincronizzazione, altrimenti mbsync va in errore cercandole
            # sul gestore ("far side box ... cannot be opened")
            piano['esclusi'] += [n for n in cartelle_solo_locali(a, cartelle, piano['canali'])
                                 if n not in piano['esclusi']]
        except Exception as e:
            errore(f"[{a['indirizzo']}] cartelle del gestore non lette ({e}): "
                   'le cartelle speciali tengono il nome del gestore')
            piano = {'canali': [], 'esclusi': a['escludi'], 'righe': []}

        maildir = os.path.join(BASE, a['indirizzo'], 'Maildir') + '/'
        blocchi.append(f"""
# ---------- {a['indirizzo']} ----------
IMAPAccount {nome}
Host {a['server']}
Port {a['porta']}
User {q(a['utente'])}
PassCmd {q('cat ' + file_password)}
SSLType {'IMAPS' if a['sicurezza'] == 'ssl' else 'STARTTLS'}
CertificateFile /etc/ssl/certs/ca-certificates.crt
Timeout 120

IMAPStore {nome}-gestore
Account {nome}

MaildirStore {nome}-locale
Inbox {maildir}
SubFolders Maildir++
""")
        canali = [nome]
        for i, (gestore, locale) in enumerate(piano['canali'], 1):
            canali.append(f'{nome}-{i}')
            blocchi.append(f"""
Channel {nome}-{i}
Far {q(':' + nome + '-gestore:' + gestore)}
Near {q(':' + nome + '-locale:' + locale)}
{COMUNI}""")
        blocchi.append(f"""
Channel {nome}
Far :{nome}-gestore:
Near :{nome}-locale:
Patterns * {' '.join('!' + q(e) for e in piano['esclusi'])}
{COMUNI}""")
        blocchi.append(f"\nGroup {nome}\n" + ''.join(f'Channel {c}\n' for c in canali))
        caselle.append({'gruppo': nome, 'indirizzo': a['indirizzo'], 'tieni_giorni': a['tieni_giorni']})

    scrivi(MBSYNCRC, ''.join(blocchi))
    scrivi(CASELLE_JSON, json.dumps(caselle, indent=1))
    return caselle


# ==================== SINCRONIZZAZIONE ====================

def file_stato(indirizzo):
    return os.path.join(STATO, indirizzo + '.json')


def leggi_stato(indirizzo):
    try:
        with open(file_stato(indirizzo), encoding='utf-8') as f:
            return json.load(f)
    except (OSError, ValueError):
        return None


def scrivi_stato(indirizzo, **dati):
    crea_cartella(STATO, 0o750)
    stato = leggi_stato(indirizzo) or {}
    stato.update(dati)
    scrivi(file_stato(indirizzo), json.dumps(stato, ensure_ascii=False), 0o640)


def richiedi_sincronizzazione():
    crea_cartella(STATO, 0o750)
    with open(RICHIESTA_SINCRONIZZAZIONE, 'w'):
        pass


def ciclo_sincronizza():
    intervallo = int(os.environ.get('POSTA_INTERVALLO', '300'))
    while True:
        try:
            if os.path.exists(RICHIESTA_SINCRONIZZAZIONE):
                os.remove(RICHIESTA_SINCRONIZZAZIONE)
            caselle = genera_mbsync()
        except Exception as e:
            errore(f'configurazione di mbsync non generata: {e}')
            caselle = []
        for c in caselle:
            inizio = time.time()
            scrivi_stato(c['indirizzo'], in_corso=True, iniziata=int(inizio))
            print(f"{time.strftime('%F %T')} sincronizzazione {c['indirizzo']}", flush=True)
            r = subprocess.run(['mbsync', '-q', '-c', MBSYNCRC, c['gruppo']],
                               capture_output=True, text=True)
            messaggi = [l for l in (r.stdout + r.stderr).splitlines() if l.strip()]
            for l in messaggi:
                print(f"  {c['indirizzo']}: {l}", flush=True)
            scrivi_stato(c['indirizzo'], in_corso=False, ultima=int(time.time()),
                         durata=int(time.time() - inizio), esito=r.returncode,
                         messaggi=messaggi[-15:],
                         **({'ultima_riuscita': int(time.time())} if r.returncode == 0 else {}))
            # Pulizia del gestore: solo se attivata e solo subito dopo una copia
            # riuscita, mai mentre mbsync lavora sulla stessa casella
            if r.returncode == 0 and c.get('tieni_giorni', 0) > 0:
                pulizia_nel_ciclo(c['indirizzo'], c['tieni_giorni'])
        # Attesa, interrotta da "Sincronizza ora" della pagina di gestione
        fine = time.time() + intervallo
        while time.time() < fine and not os.path.exists(RICHIESTA_SINCRONIZZAZIONE):
            time.sleep(3)


def conta_locale(indirizzo):
    """(email, byte) nella copia locale della casella."""
    maildir = os.path.join(BASE, indirizzo, 'Maildir')
    email = byte = 0
    cartelle = [maildir] + [os.path.join(maildir, d) for d in _ls(maildir) if d.startswith('.')
                            and os.path.isdir(os.path.join(maildir, d))]
    for c in cartelle:
        for sotto in ('cur', 'new'):
            try:
                with os.scandir(os.path.join(c, sotto)) as it:
                    for f in it:
                        email += 1
                        try:
                            byte += f.stat().st_size
                        except OSError:
                            pass
            except OSError:
                pass
    return email, byte


def cartelle_solo_locali(a, cartelle, canali):
    """
    Cartelle della copia locale (Maildir++) che non vengono dal gestore: né
    sue cartelle (o loro antenati) né cartelle locali dei canali speciali.
    Nomi con "/" come separatore, come nei Patterns di mbsync.
    """
    note = set()
    for c in cartelle:
        nome = c['nome'].replace(c['delim'], '/') if c['delim'] and c['delim'] != '/' else c['nome']
        parti = nome.split('/')
        note.update('/'.join(parti[:i]) for i in range(1, len(parti) + 1))
    for gestore, locale in canali:
        note.update((gestore, locale))
    maildir = os.path.join(BASE, a['indirizzo'], 'Maildir')
    locali = [d[1:].replace('.', '/') for d in _ls(maildir)
              if d.startswith('.') and len(d) > 1 and os.path.isdir(os.path.join(maildir, d, 'cur'))]
    return sorted(n for n in locali if n not in note and n.upper() != 'INBOX')


def _ls(percorso):
    try:
        return os.listdir(percorso)
    except OSError:
        return []


# ==================== PULIZIA DEL GESTORE ====================
#
# Cancella dal gestore le email più vecchie di N giorni (tieni_giorni) che
# hanno una copia locale CONFERMATA. Una email del gestore è confermata se:
#   1. la cartella locale corrispondente ha lo stato di mbsync (.mbsyncstate)
#      e il suo FarUidValidity è uguale all'UIDVALIDITY della cartella del
#      gestore (stessa cartella, numerazione non cambiata);
#   2. lo stato associa l'UID del gestore a un UID locale;
#   3. nella cartella locale esiste davvero il file con quell'UID (",U=<uid>").
# Ciò che non è confermato resta sul gestore. La copia locale non viene mai
# toccata: mbsync (Sync PullNew PullFlags, Expunge None) non propaga le
# cancellazioni del gestore.
# Le email vengono segnate \Deleted ed eliminate SUBITO: se il segno restasse,
# mbsync (PullFlags) lo copierebbe in locale. Con UIDPLUS si eliminano solo
# quelle (UID EXPUNGE). Senza UIDPLUS (Libero, Tiscali…) c'è solo EXPUNGE, che
# elimina anche le email già segnate \Deleted da altri programmi: si procede
# solo se anche quelle hanno la copia locale confermata, altrimenti la
# cartella resta com'è.

MESI_IMAP = ('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec')
BLOCCO_UID = 200


def data_imap(giorni):
    """Data di N giorni fa nel formato di SEARCH BEFORE (es. 11-Sep-2026)."""
    t = time.localtime(time.time() - giorni * 86400)
    return f'{t.tm_mday:02d}-{MESI_IMAP[t.tm_mon - 1]}-{t.tm_year}'


def cartella_locale(maildir, nome, delim, canali):
    """Cartella Maildir++ in cui mbsync copia la cartella "nome" del gestore."""
    locale = dict(canali).get(nome)
    if locale is None:
        locale = nome.replace(delim, '/') if delim else nome
    if locale.upper() == 'INBOX':
        return maildir
    return os.path.join(maildir, '.' + locale.replace('/', '.'))


def stato_mbsync(cartella):
    """(FarUidValidity, {uid gestore: uid locale}) dal .mbsyncstate, o (None, {})."""
    validita, coppie = None, {}
    try:
        with open(os.path.join(cartella, '.mbsyncstate'), encoding='ascii', errors='replace') as f:
            testata = True
            for riga in f:
                riga = riga.strip()
                if testata:
                    if not riga:
                        testata = False
                    elif riga.startswith('FarUidValidity '):
                        validita = int(riga.split()[1])
                    continue
                parti = riga.split()
                if len(parti) >= 2 and parti[0].isdigit() and parti[1].lstrip('-').isdigit():
                    if int(parti[0]) > 0 and int(parti[1]) > 0:
                        coppie[int(parti[0])] = int(parti[1])
    except (OSError, ValueError):
        return None, {}
    return validita, coppie


def uid_locali(cartella):
    """UID dei file presenti nella cartella locale (nome "...,U=<uid>:2,...")."""
    trovati = set()
    for sotto in ('cur', 'new'):
        for nome in _ls(os.path.join(cartella, sotto)):
            m = re.search(r',U=(\d+)', nome)
            if m:
                trovati.add(int(m.group(1)))
    return trovati


def aggiungi_nota(riga, nota):
    riga['nota'] = f"{riga['nota']}; {nota}" if riga['nota'] else nota


def _uid_risposta(dati):
    return [int(x) for x in b' '.join(d for d in dati if d).split()] if dati else []


def _cancella(imap, uid, uidplus):
    """Segna \\Deleted ed elimina subito; se qualcosa va storto toglie il segno."""
    segnati = []
    try:
        for i in range(0, len(uid), BLOCCO_UID):
            insieme = ','.join(str(u) for u in uid[i:i + BLOCCO_UID])
            tipo, _ = imap.uid('STORE', insieme, '+FLAGS.SILENT', r'(\Deleted)')
            if tipo != 'OK':
                raise RuntimeError('segno di cancellazione rifiutato dal gestore')
            segnati.append(insieme)
        if uidplus:
            for insieme in segnati:
                tipo, _ = imap.uid('EXPUNGE', insieme)
                if tipo != 'OK':
                    raise RuntimeError('cancellazione rifiutata dal gestore')
        else:
            tipo, _ = imap.expunge()
            if tipo != 'OK':
                raise RuntimeError('cancellazione rifiutata dal gestore')
    except (RuntimeError, imaplib.IMAP4.error, OSError) as e:
        # Il segno non deve restare: mbsync lo copierebbe in locale
        for insieme in segnati:
            try:
                imap.uid('STORE', insieme, '-FLAGS.SILENT', r'(\Deleted)')
            except Exception:
                pass
        raise RuntimeError(str(e)) from e


def pulisci_gestore(a, giorni, prova=True):
    """
    Email del gestore arrivate prima di "giorni" giorni fa, cartella per cartella;
    con prova=False cancella quelle con copia locale confermata.
    @return {'prima_del', 'righe': [{gestore, locale, vecchie, confermate, cancellate, nota}],
             'vecchie', 'confermate', 'cancellate'}
    """
    if giorni < TIENI_GIORNI_MINIMO:
        raise ValueError(f'giorni deve essere almeno {TIENI_GIORNI_MINIMO}')
    maildir = os.path.join(BASE, a['indirizzo'], 'Maildir')
    prima_del = data_imap(giorni)
    righe = []
    imap = connetti(a, timeout=120)
    try:
        # Le capacità annunciate dopo il login (UIDPLUS spesso solo lì)
        tipo, dati = imap.capability()
        uidplus = tipo == 'OK' and b'UIDPLUS' in b' '.join(dati).upper().split()
        cartelle = cartelle_gestore(a, imap)
        piano = piano_cartelle(a, cartelle)
        escluse = {r['gestore'] for r in piano['righe'] if r['nota'] == 'esclusa'}
        for c in sorted(cartelle, key=lambda c: c['nome'].lower()):
            if not apribile(c) or c['nome'] in escluse:
                continue
            locale = cartella_locale(maildir, c['nome'], c['delim'], piano['canali'])
            riga = {'gestore': c['nome'], 'locale': os.path.relpath(locale, maildir),
                    'vecchie': 0, 'confermate': 0, 'cancellate': 0, 'nota': ''}
            righe.append(riga)
            tipo, _ = imap.select(q(c['nome']), readonly=prova)
            if tipo != 'OK':
                riga['nota'] = 'cartella non aperta sul gestore'
                continue
            validita_gestore = imap.response('UIDVALIDITY')[1]
            validita_gestore = int(validita_gestore[0]) if validita_gestore and validita_gestore[0] else None
            tipo, dati = imap.uid('SEARCH', None, 'BEFORE', prima_del)
            vecchie = _uid_risposta(dati) if tipo == 'OK' else []
            riga['vecchie'] = len(vecchie)
            if not vecchie:
                continue
            validita, coppie = stato_mbsync(locale)
            if validita is None:
                riga['nota'] = 'copia locale non trovata: non si cancella niente'
                continue
            if validita != validita_gestore:
                riga['nota'] = 'numerazione diversa da quella copiata: non si cancella niente'
                continue
            presenti = uid_locali(locale)
            confermate = [u for u in vecchie if coppie.get(u) in presenti]
            riga['confermate'] = len(confermate)
            if len(confermate) < len(vecchie):
                riga['nota'] = f'{len(vecchie) - len(confermate)} senza copia locale: restano sul gestore'
            if not confermate:
                continue
            # Email già segnate \Deleted (da altri programmi): senza UIDPLUS
            # l'EXPUNGE elimina anche loro, quindi devono avere la copia locale
            tipo, dati = imap.uid('SEARCH', None, 'DELETED')
            gia_segnate = set(_uid_risposta(dati)) - set(confermate) if tipo == 'OK' else set()
            senza_copia = [u for u in gia_segnate if coppie.get(u) not in presenti]
            if not uidplus and senza_copia:
                aggiungi_nota(riga, f'{len(senza_copia)} email segnate da cancellare da un altro programma '
                                    'senza copia locale: cartella non pulita')
                continue
            if prova:
                continue
            try:
                _cancella(imap, confermate, uidplus)
                riga['cancellate'] = len(confermate)
                if not uidplus and gia_segnate:
                    riga['cancellate'] += len(gia_segnate)
                    aggiungi_nota(riga, f'eliminate anche {len(gia_segnate)} email già segnate da cancellare '
                                        'da un altro programma (con copia locale)')
            except RuntimeError as e:
                aggiungi_nota(riga, str(e))
            # Niente CLOSE: eliminerebbe anche le email segnate \Deleted da altri
            # programmi; si passa alla cartella successiva con un nuovo SELECT
    finally:
        try:
            imap.logout()
        except Exception:
            pass
    return {'prima_del': prima_del, 'righe': righe, 'uidplus': uidplus,
            'vecchie': sum(r['vecchie'] for r in righe),
            'confermate': sum(r['confermate'] for r in righe),
            'cancellate': sum(r['cancellate'] for r in righe)}


def pulizia_nel_ciclo(indirizzo, giorni):
    a = next((x for x in leggi_account(tutte=False) if x['indirizzo'] == indirizzo), None)
    if not a or a['tieni_giorni'] <= 0:
        return
    try:
        esito = pulisci_gestore(a, giorni, prova=False)
        note = [f"{r['gestore']}: {r['nota']}" for r in esito['righe'] if r['nota']]
        print(f"  {indirizzo}: pulizia del gestore (prima del {esito['prima_del']}): "
              f"{esito['cancellate']} cancellate su {esito['vecchie']}", flush=True)
        for n in note:
            print(f'  {indirizzo}: {n}', flush=True)
        scrivi_stato(indirizzo, pulizia={'quando': int(time.time()), 'giorni': giorni,
                                         'cancellate': esito['cancellate'], 'vecchie': esito['vecchie'],
                                         'note': note[-10:]})
    except Exception as e:
        print(f'  {indirizzo}: pulizia del gestore non eseguita: {e}', flush=True)
        scrivi_stato(indirizzo, pulizia={'quando': int(time.time()), 'giorni': giorni, 'errore': str(e)})


def pulizia_prova(indirizzo=None, giorni=None):
    """Da riga di comando: cosa cancellerebbe la pulizia. Non cancella niente."""
    caselle = [a for a in leggi_account() if not indirizzo or a['indirizzo'] == indirizzo.lower()]
    if not caselle:
        errore(f'nessuna casella {indirizzo or ""} in account.conf')
        sys.exit(1)
    for a in caselle:
        g = giorni or a['tieni_giorni'] or 30
        attuale = (f"tieni_giorni = {a['tieni_giorni']}" if a['tieni_giorni']
                   else 'tieni_giorni = 0: oggi NON viene cancellato niente')
        print(f"== {a['indirizzo']} ({attuale}); prova con {g} giorni")
        try:
            esito = pulisci_gestore(a, g, prova=True)
        except Exception as e:
            print(f'   errore: {e}')
            continue
        if not esito['uidplus']:
            print('   il gestore non ha UIDPLUS: si pulisce una cartella solo se anche le email già '
                  'segnate da cancellare da altri programmi hanno la copia locale')
        print(f"   email arrivate prima del {esito['prima_del']}:")
        print(f"   {'sul gestore':>11} {'confermate':>11}  cartella gestore → locale")
        for r in esito['righe']:
            if r['vecchie'] or r['nota']:
                print(f"   {r['vecchie']:>11} {r['confermate']:>11}  {r['gestore']} → {r['locale']}"
                      + (f"   ({r['nota']})" if r['nota'] else ''))
        print(f"   {esito['vecchie']:>11} {esito['confermate']:>11}  TOTALE "
              '(con la pulizia attiva verrebbero cancellate le confermate)')


# ==================== LOGIN DELLA PAGINA DI GESTIONE ====================

def impronta_gestione(password, sale=None):
    sale = sale or secrets.token_bytes(16)
    h = hashlib.scrypt(password.encode(), salt=sale, n=2 ** 14, r=8, p=1)
    return 'scrypt$' + base64.b64encode(sale).decode() + '$' + base64.b64encode(h).decode()


def verifica_gestione(utente, password):
    try:
        with open(GESTIONE_PASSWD, encoding='utf-8') as f:
            riga = f.readline().strip()
        nome, impronta = riga.split(':', 1)
        _, sale, _ = impronta.split('$')
    except (OSError, ValueError):
        return False
    attesa = impronta_gestione(password, base64.b64decode(sale))
    return hmac.compare_digest(nome, utente) & hmac.compare_digest(attesa, impronta)


def gestione_configurata():
    return os.path.isfile(GESTIONE_PASSWD)


def imposta_password_gestione(utente):
    if not re.fullmatch(r'[A-Za-z0-9._-]{1,40}', utente):
        errore('nome utente non valido (lettere, numeri, . _ -)')
        sys.exit(1)
    p1 = getpass.getpass(f'Nuova password per "{utente}" (almeno 8 caratteri): ')
    if len(p1) < 8:
        errore('password troppo corta')
        sys.exit(1)
    if getpass.getpass('Ripeti la password: ') != p1:
        errore('le due password non coincidono')
        sys.exit(1)
    crea_cartella(CONFIG_DIR)
    scrivi(GESTIONE_PASSWD, f'{utente}:{impronta_gestione(p1)}\n')
    print(f'Password della pagina di gestione impostata per "{utente}".')


if __name__ == '__main__':
    modo = sys.argv[1] if len(sys.argv) > 1 else ''
    if modo == 'dovecot':
        prepara_dovecot()
    elif modo == 'mbsync':
        print(f'genera-config: mbsyncrc con {len(genera_mbsync())} caselle')
    elif modo == 'sincronizza':
        ciclo_sincronizza()
    elif modo == 'imposta-password':
        imposta_password_gestione(sys.argv[2] if len(sys.argv) > 2 else 'admin')
    elif modo == 'pulizia-prova':
        argomenti = sys.argv[2:]
        numeri = [int(x) for x in argomenti if x.isdigit()]
        nomi = [x for x in argomenti if not x.isdigit()]
        pulizia_prova(nomi[0] if nomi else None, numeri[0] if numeri else None)
    else:
        errore('uso: genera-config dovecot|mbsync|sincronizza|imposta-password [utente]'
               '|pulizia-prova [indirizzo] [giorni]')
        sys.exit(2)
