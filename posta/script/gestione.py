#!/usr/bin/env python3
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
Gestione caselle copiate in locale: piccola pagina web (servizio "gestione")
per aggiungere, modificare, sospendere e rimuovere le caselle copiate in
locale, provare l'accesso al gestore e vedere lo stato della sincronizzazione.

Login: un solo amministratore, impostato da terminale con
    ./scripts/password-gestione.sh [utente]   (dalla cartella di PiGreco Posta)
"""

import html
import json
import os
import secrets
import sys
import threading
import time
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import postaconf as pc  # noqa: E402

# Versione e codice sorgente (la stessa versione è in webmail/lib/info.php):
# la AGPL chiede di mostrare copyright, licenza e dove trovare il sorgente
VERSIONE = '1.0'
SORGENTE = 'https://github.com/ddcls/pigreco-posta'

PORTA = int(os.environ.get('POSTA_GESTIONE_PORTA_INTERNA', '8080'))
DURATA_SESSIONE = 30 * 60
TENTATIVI_MAX, BLOCCO = 5, 5 * 60

sessioni = {}            # sid -> {'scade', 'csrf', 'utente'}
tentativi = {}           # ip -> [numero, bloccato fino a]
blocco = threading.Lock()


def e(testo):
    return html.escape(str(testo if testo is not None else ''), quote=True)


def quando(ts):
    if not ts:
        return '—'
    return time.strftime('%d/%m/%Y %H:%M', time.localtime(ts))


def dimensione(byte):
    for unita in ('byte', 'KB', 'MB', 'GB', 'TB'):
        if byte < 1024 or unita == 'TB':
            return f'{byte:.0f} {unita}' if unita == 'byte' else f'{byte:.1f} {unita}'.replace('.', ',')
        byte /= 1024


def password_casuale():
    alfabeto = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'
    return ''.join(secrets.choice(alfabeto) for _ in range(14))


STILE = """
:root { --sfondo:#f4f1ea; --pannello:#fff; --testo:#2b2b2b; --tenue:#6b6b6b; --bordo:#ddd6c8;
        --accento:#8a5a2b; --ok:#2e7d32; --errore:#b3261e; --avviso:#9a6b00; }
@media (prefers-color-scheme: dark) {
  :root { --sfondo:#1d1b18; --pannello:#2a2724; --testo:#eee; --tenue:#aaa; --bordo:#453f38;
          --accento:#d39a5f; --ok:#81c784; --errore:#ef9a9a; --avviso:#ffd54f; } }
* { box-sizing:border-box; }
body { margin:0; font:15px/1.45 system-ui, sans-serif; background:var(--sfondo); color:var(--testo); }
header { display:flex; align-items:center; gap:12px; padding:12px 20px; background:var(--accento); color:#fff; }
header h1 { font-size:18px; margin:0; flex:1; }
header a { color:#fff; }
main { max-width:1000px; margin:0 auto; padding:20px 16px; }
.pannello { background:var(--pannello); border:1px solid var(--bordo); border-radius:8px; padding:16px; margin-bottom:16px; }
table { width:100%; border-collapse:collapse; }
th, td { text-align:left; padding:7px 8px; border-bottom:1px solid var(--bordo); vertical-align:top; }
th { font-size:13px; color:var(--tenue); font-weight:600; }
td.num { text-align:right; white-space:nowrap; }
.tenue { color:var(--tenue); font-size:13px; }
.ok { color:var(--ok); } .errore { color:var(--errore); } .avviso { color:var(--avviso); }
.messaggio { padding:10px 12px; border-radius:6px; margin-bottom:14px; border:1px solid; }
.messaggio.ok { border-color:var(--ok); } .messaggio.errore { border-color:var(--errore); }
.messaggio.avviso { border-color:var(--avviso); }
label { display:block; font-weight:600; margin:12px 0 4px; }
input[type=text], input[type=password], input[type=number], select {
  width:100%; padding:8px; border:1px solid var(--bordo); border-radius:6px; background:var(--sfondo); color:var(--testo); font:inherit; }
.riga { display:grid; grid-template-columns:2fr 1fr 1fr; gap:12px; }
@media (max-width:640px) { .riga { grid-template-columns:1fr; } }
button, .pulsante { display:inline-block; padding:8px 14px; border-radius:6px; border:1px solid var(--accento);
  background:var(--accento); color:#fff; font:inherit; cursor:pointer; text-decoration:none; }
button.secondario, .pulsante.secondario { background:transparent; color:var(--accento); }
button.pericolo { background:transparent; color:var(--errore); border-color:var(--errore); }
.azioni { display:flex; flex-wrap:wrap; gap:8px; margin-top:16px; align-items:center; }
form.in-linea { display:inline; }
code, .password { font-family:ui-monospace, monospace; background:var(--sfondo); padding:2px 6px; border-radius:4px; }
.password { font-size:18px; }
.scorre { overflow-x:auto; }
dialog { max-width:620px; width:calc(100% - 32px); border:1px solid var(--bordo); border-radius:8px; padding:20px;
  background:var(--pannello); color:var(--testo); }
dialog::backdrop { background:rgba(0,0,0,.45); }
dialog h2 { margin-top:0; font-size:18px; }
dialog ul { padding-left:20px; margin:6px 0 10px; }
footer { max-width:1000px; margin:0 auto; padding:4px 16px 20px; text-align:center; color:var(--tenue); font-size:12px; }
footer a { color:inherit; }
"""


class Gestione(BaseHTTPRequestHandler):
    server_version = 'PostaDigitale'

    def log_message(self, formato, *args):
        sys.stdout.write(f"{time.strftime('%F %T')} {self.address_string()} {formato % args}\n")

    # ---------- sessione ----------

    def sessione(self):
        cookie = SimpleCookie(self.headers.get('Cookie', ''))
        sid = cookie['POSTA_SID'].value if 'POSTA_SID' in cookie else None
        with blocco:
            s = sessioni.get(sid)
            if s and s['scade'] > time.time():
                s['scade'] = time.time() + DURATA_SESSIONE
                return sid, s
            sessioni.pop(sid, None)
        return None, None

    def ip(self):
        return self.client_address[0]

    # ---------- risposte ----------

    def invia(self, codice, corpo, intestazioni=None):
        dati = corpo.encode('utf-8')
        self.send_response(codice)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.send_header('Content-Length', str(len(dati)))
        self.send_header('Cache-Control', 'no-store')
        self.send_header('X-Frame-Options', 'DENY')
        self.send_header('Content-Security-Policy',
                         "default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; form-action 'self'")
        for k, v in (intestazioni or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(dati)

    def vai(self, percorso, intestazioni=None):
        self.send_response(303)
        self.send_header('Location', percorso)
        for k, v in (intestazioni or {}).items():
            self.send_header(k, v)
        self.end_headers()

    def pagina(self, titolo, contenuto, s=None, codice=200):
        uscita = '<a href="/esci">Esci</a>' if s else ''
        self.invia(codice, f"""<!doctype html><html lang="it"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{e(titolo)} – PiGreco Posta</title><style>{STILE}</style></head>
<body><header><h1>PiGreco Posta · Gestione caselle</h1>{uscita}</header>
<main>{contenuto}</main>
<footer>PiGreco Posta {VERSIONE} · © 2026 Criscione Enzo · software libero
<a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener noreferrer">AGPL-3.0</a>, senza garanzia ·
<a href="{SORGENTE}" target="_blank" rel="noopener noreferrer">codice sorgente</a></footer></body></html>""")

    def modulo(self):
        lunghezza = int(self.headers.get('Content-Length', '0') or 0)
        if lunghezza > 100_000:
            return {}
        dati = parse_qs(self.rfile.read(lunghezza).decode('utf-8'), keep_blank_values=True)
        return {k: v[0] for k, v in dati.items()}

    # ---------- instradamento ----------

    def do_GET(self):
        url = urlparse(self.path)
        sid, s = self.sessione()
        if url.path == '/esci':
            with blocco:
                sessioni.pop(sid, None)
            return self.vai('/', {'Set-Cookie': 'POSTA_SID=; Max-Age=0; Path=/; HttpOnly; SameSite=Strict'})
        if not s:
            return self.pagina_login()
        q = {k: v[0] for k, v in parse_qs(url.query).items()}
        if url.path == '/':
            tipo = q.get('tipo', 'ok') if q.get('tipo') in ('ok', 'errore', 'avviso') else 'ok'
            return self.pagina_elenco(s, e(q.get('msg', '')), tipo)
        if url.path == '/casella':
            a = next((x for x in pc.leggi_account() if x['indirizzo'] == q.get('indirizzo', '').lower()), None)
            return self.pagina_casella(s, a or {}, nuova=a is None)
        self.pagina('Non trovata', '<p>Pagina non trovata. <a href="/">Torna all\'elenco</a></p>', s, 404)

    def do_POST(self):
        url = urlparse(self.path)
        dati = self.modulo()
        if url.path == '/accedi':
            return self.accedi(dati)
        sid, s = self.sessione()
        if not s:
            return self.vai('/')
        if not secrets.compare_digest(dati.get('csrf', ''), s['csrf']):
            return self.pagina('Errore', '<p class="errore">Richiesta non valida: ricaricare la pagina.</p>', s, 400)
        azioni = {'/casella/salva': self.salva, '/casella/prova': self.prova,
                  '/casella/sospendi': self.sospendi, '/casella/rimuovi': self.rimuovi,
                  '/sincronizza': self.sincronizza}
        if url.path in azioni:
            return azioni[url.path](s, dati)
        self.pagina('Non trovata', '<p>Azione sconosciuta.</p>', s, 404)

    # ---------- login ----------

    def pagina_login(self, errore=''):
        if not pc.gestione_configurata():
            return self.pagina('Accesso', """<div class="pannello"><h2>Password non impostata</h2>
<p>La pagina di gestione non ha ancora un amministratore. Impostarlo sul server con:</p>
<p><code>./scripts/password-gestione.sh admin</code> (dalla cartella di PiGreco Posta)</p>
<p>poi ricaricare questa pagina.</p></div>""")
        msg = f'<div class="messaggio errore">{e(errore)}</div>' if errore else ''
        self.pagina('Accesso', f"""<div class="pannello" style="max-width:380px;margin:40px auto">
<h2>Accesso</h2>{msg}<form method="post" action="/accedi">
<label for="u">Utente</label><input type="text" id="u" name="utente" value="admin" autocomplete="username" required>
<label for="p">Password</label><input type="password" id="p" name="password" autocomplete="current-password" required autofocus>
<div class="azioni"><button type="submit">Entra</button></div></form></div>""")

    def accedi(self, dati):
        ip = self.ip()
        with blocco:
            numero, fino = tentativi.get(ip, [0, 0])
        if fino > time.time():
            return self.pagina_login(f'Troppi tentativi falliti: riprovare tra {int((fino - time.time()) // 60) + 1} minuti.')
        if pc.verifica_gestione(dati.get('utente', ''), dati.get('password', '')):
            sid = secrets.token_urlsafe(32)
            with blocco:
                tentativi.pop(ip, None)
                sessioni[sid] = {'scade': time.time() + DURATA_SESSIONE, 'csrf': secrets.token_urlsafe(24),
                                 'utente': dati.get('utente')}
            return self.vai('/', {'Set-Cookie': f'POSTA_SID={sid}; Path=/; HttpOnly; SameSite=Strict'})
        time.sleep(1)
        with blocco:
            numero += 1
            tentativi[ip] = [numero, time.time() + BLOCCO if numero >= TENTATIVI_MAX else 0]
        self.pagina_login('Utente o password errati.')

    # ---------- elenco ----------

    def pagina_elenco(self, s, msg='', tipo='ok'):
        """msg è HTML già sicuro (testi dall'indirizzo vanno passati con e())."""
        caselle = pc.leggi_account()
        avviso = f'<div class="messaggio {e(tipo)}">{msg}</div>' if msg else ''
        righe = ''
        in_corso = False
        for a in caselle:
            st = pc.leggi_stato(a['indirizzo']) or {}
            email, byte = pc.conta_locale(a['indirizzo'])
            if not a['attiva']:
                stato = '<span class="avviso">sospesa</span>'
            elif st.get('in_corso'):
                in_corso = True
                stato = f'<span class="avviso">in corso dalle {e(time.strftime("%H:%M", time.localtime(st.get("iniziata", 0))))}</span>'
            elif not st:
                stato = '<span class="tenue">in attesa della prima copia</span>'
            elif st.get('esito') == 0:
                stato = f'<span class="ok">riuscita</span> <span class="tenue">{quando(st.get("ultima"))}</span>'
            else:
                ultimo = (st.get('messaggi') or ['errore'])[-1]
                stato = (f'<span class="errore">errore</span> <span class="tenue">{quando(st.get("ultima"))}</span>'
                         f'<div class="tenue">{e(ultimo)}</div>')
            numero = f'{email:,}'.replace(',', '.')
            if a['tieni_giorni'] > 0:
                gestore = f"sul gestore: ultimi {a['tieni_giorni']} giorni"
                p = st.get('pulizia') or {}
                if p.get('errore'):
                    gestore += f" · pulizia non riuscita {quando(p.get('quando'))}"
                elif p:
                    gestore += f" · {p.get('cancellate', 0)} cancellate {quando(p.get('quando'))}"
            else:
                gestore = 'sul gestore: tutte (non si cancella)'
            righe += f"""<tr><td><a href="/casella?{urlencode({'indirizzo': a['indirizzo']})}">{e(a['indirizzo'])}</a>
<div class="tenue">{e(a['server'])}</div><div class="tenue">{e(gestore)}</div></td>
<td class="num">{numero}</td><td class="num">{dimensione(byte)}</td><td>{stato}</td></tr>"""
        if not righe:
            righe = '<tr><td colspan="4" class="tenue">Nessuna casella: aggiungerne una.</td></tr>'
        aggiorna = '<meta http-equiv="refresh" content="20">' if in_corso else ''
        intervallo = int(os.environ.get('POSTA_INTERVALLO', '300')) // 60
        self.pagina('Caselle', f"""{aggiorna}{avviso}<div class="pannello">
<h2>Caselle copiate su questo server</h2>
<div class="scorre"><table><tr><th>Casella</th><th class="num">Email in locale</th><th class="num">Spazio</th>
<th>Ultima sincronizzazione</th></tr>{righe}</table></div>
<div class="azioni"><a class="pulsante" href="/casella">＋ Aggiungi casella</a>
<form class="in-linea" method="post" action="/sincronizza"><input type="hidden" name="csrf" value="{e(s['csrf'])}">
<button class="secondario" type="submit">⟳ Sincronizza ora</button></form>
<span class="tenue">Sincronizzazione automatica ogni {intervallo} minuti.</span></div></div>
<div class="pannello tenue">Webmail e telefoni si collegano a questo server (IMAP porta
{e(os.environ.get('POSTA_IMAP_PORT', '143'))} STARTTLS, oppure {e(os.environ.get('POSTA_IMAPS_PORT', '993'))} SSL)
con l'indirizzo della casella come utente e la <b>password locale</b>. L'invio resta sull'SMTP del gestore.</div>""", s)

    # ---------- casella ----------

    def pagina_casella(self, s, a, nuova, msg='', tipo='errore', prova=None):
        avviso = f'<div class="messaggio {e(tipo)}">{msg}</div>' if msg else ''
        sic = a.get('sicurezza', 'ssl')
        campo_indirizzo = (f'<input type="text" id="indirizzo" name="indirizzo" value="{e(a.get("indirizzo"))}" '
                           'required placeholder="mario.rossi@libero.it" autocomplete="off">' if nuova else
                           f'<input type="text" value="{e(a.get("indirizzo"))}" disabled>'
                           f'<input type="hidden" name="indirizzo" value="{e(a.get("indirizzo"))}">')
        nota_gestore = '' if nuova else '<div class="tenue">Lasciare vuoto per non cambiarla.</div>'
        nota_locale = ('Vuota = generata automaticamente e mostrata una volta sola.' if nuova else
                       'Vuota = resta quella attuale. Scriverne una per cambiarla.')
        piano = self.tabella_prova(prova) if prova else ''
        # Valore salvato: la conferma serve solo se la modifica fa cancellare di più
        tieni_attuale = 0 if nuova else int((next((x for x in pc.leggi_account()
                                                  if x['indirizzo'] == a.get('indirizzo')), None) or {}).get('tieni_giorni', 0))
        rimuovi = '' if nuova else f"""<div class="pannello"><h3>Sospendere o rimuovere</h3>
<p class="tenue">Sospesa: la copia locale resta leggibile ma non si aggiorna più. Rimossa: la casella non
è più accessibile e non si aggiorna, ma le email già copiate restano sul disco in
<code>/srv/posta/{e(a.get('indirizzo'))}</code>.</p><div class="azioni">
<form class="in-linea" method="post" action="/casella/sospendi"><input type="hidden" name="csrf" value="{e(s['csrf'])}">
<input type="hidden" name="indirizzo" value="{e(a.get('indirizzo'))}">
<button class="secondario" type="submit">{'Riprendi la sincronizzazione' if not a.get('attiva', True) else 'Sospendi la sincronizzazione'}</button></form>
<form class="in-linea" method="post" action="/casella/rimuovi" onsubmit="return confirm('Rimuovere la casella {e(a.get('indirizzo'))}? Le email copiate restano sul disco.')">
<input type="hidden" name="csrf" value="{e(s['csrf'])}"><input type="hidden" name="indirizzo" value="{e(a.get('indirizzo'))}">
<button class="pericolo" type="submit">Rimuovi la casella</button></form></div></div>"""
        gestori = json.dumps({d: list(v) for d, v in pc.GESTORI_NOTI.items()})
        avvisi = json.dumps(pc.AVVISI_GESTORI)
        self.pagina('Nuova casella' if nuova else a.get('indirizzo', 'Casella'), f"""{avviso}
<div class="pannello"><h2>{'Nuova casella' if nuova else e(a.get('indirizzo'))}</h2>
<form method="post" action="/casella/salva" id="modulo"><input type="hidden" name="csrf" value="{e(s['csrf'])}">
<input type="hidden" name="nuova" value="{'1' if nuova else ''}">
<label for="indirizzo">Indirizzo email</label>{campo_indirizzo}
<div class="tenue">È anche l'utente con cui webmail e telefoni accedono alla copia locale.</div>
<h3>Gestore (da dove si copia)</h3>
<div class="tenue"><a href="#" id="apri-oauth">Quali caselle non si possono aggiungere?</a></div>
<div class="riga"><div><label for="server">Server IMAP</label>
<input type="text" id="server" name="server" value="{e(a.get('server'))}" required placeholder="imap.libero.it"></div>
<div><label for="porta">Porta</label><input type="number" id="porta" name="porta" value="{e(a.get('porta', 993))}" min="1" max="65535" required></div>
<div><label for="sicurezza">Sicurezza</label><select id="sicurezza" name="sicurezza">
<option value="ssl"{' selected' if sic == 'ssl' else ''}>SSL/TLS</option>
<option value="starttls"{' selected' if sic == 'starttls' else ''}>STARTTLS</option></select></div></div>
<div id="avviso-gestore" class="avviso"></div>
<label for="utente">Utente sul gestore</label>
<input type="text" id="utente" name="utente" value="{e(a.get('utente'))}" required autocomplete="off">
<label for="password_gestore">Password sul gestore</label>
<input type="password" id="password_gestore" name="password_gestore" value="{e(a.get('_password_gestore_modulo', ''))}"
 autocomplete="new-password" {'required' if nuova else ''}>{nota_gestore}
<label for="escludi">Cartelle da non copiare <span class="tenue">(facoltativo, separate da virgola, * = qualsiasi testo)</span></label>
<input type="text" id="escludi" name="escludi" value="{e(', '.join(a.get('escludi', [])))}" placeholder="Templates, __liberosms*">
<label for="tieni_giorni">Giorni da tenere sul gestore</label>
<input type="number" id="tieni_giorni" name="tieni_giorni" value="{e(a.get('_tieni_giorni_modulo', a.get('tieni_giorni', 0)))}"
 min="0" step="1" data-attuale="{e(tieni_attuale)}" style="max-width:120px">
<div class="tenue"><b>0 = non cancellare mai dal gestore.</b> Con un numero (almeno {pc.TIENI_GIORNI_MINIMO}), dopo ogni
sincronizzazione vengono cancellate <b>dal gestore</b> le email più vecchie, solo se la loro copia su questo server
è verificata: qui resta tutto e libera spazio sul gestore. "Prova l'accesso al gestore" mostra quante verrebbero cancellate.</div>
<label id="riga-conferma" style="font-weight:normal" hidden><input type="checkbox" name="conferma_pulizia" value="1">
Ho capito: le email più vecchie verranno <b>cancellate dal gestore</b> e la copia su questo server diventerà l'unica
(il backup di <code>/srv/posta</code> è indispensabile).</label>
<h3>Copia locale (su questo server)</h3>
<label for="password_locale">Password locale</label>
<input type="text" id="password_locale" name="password_locale" value="{e(a.get('_password_locale_modulo', ''))}" autocomplete="off">
<div class="tenue">{nota_locale} Serve a webmail e telefoni; non è la password del gestore.</div>
<div class="azioni"><button type="submit">Salva</button>
<button class="secondario" type="submit" formaction="/casella/prova">Prova l'accesso al gestore</button>
<label style="display:inline;font-weight:normal"><input type="checkbox" name="senza_prova" value="1"> salva anche se la prova non riesce</label>
<a href="/" class="tenue">Annulla</a></div></form></div>
{piano}{rimuovi}
<dialog id="finestra-oauth" aria-labelledby="titolo-oauth">
<h2 id="titolo-oauth">Caselle che non si possono aggiungere</h2>
<p>PiGreco Posta copia le caselle collegandosi al gestore via IMAP con <b>utente e password</b>.
Alcuni gestori non lo permettono più: accettano solo <b>OAuth2</b>, un accesso in cui si dà il consenso su una
pagina del gestore e il programma riceve un "permesso" al posto della password. PiGreco Posta non usa OAuth2,
quindi queste caselle <b>non si possono copiare</b>, anche con utente e password giusti
(il gestore risponde per esempio "Basic authentication is disabled").</p>
<p><b>Non si possono aggiungere:</b></p>
<ul>
<li>caselle personali Microsoft: <b>@hotmail</b>.it/.com, <b>@outlook</b>.it/.com, <b>@live</b>.it/.com, <b>@msn.com</b>;</li>
<li>caselle aziendali su <b>Microsoft 365 / Exchange Online</b> (server <code>outlook.office365.com</code>),
anche con il dominio della ditta.</li>
</ul>
<p><b>Si possono aggiungere, ma con una "password per le app"</b> (da creare nelle impostazioni di sicurezza
dell'account, al posto della password normale): Gmail, Yahoo, iCloud.</p>
<p><b>Si possono aggiungere normalmente:</b> Libero, Virgilio, Tiscali, Aruba (anche PEC), Legalmail
e in genere i gestori italiani e i domini aziendali che non usano Microsoft 365.</p>
<p><b>Alternativa per le caselle Microsoft:</b> nelle impostazioni del gestore (per Outlook.com:
Impostazioni › Posta › Inoltro) far inoltrare la posta in arrivo a una casella che si può aggiungere.
Si copierà però solo la posta che arriva da quel momento: né le email già presenti né la posta inviata.</p>
<div class="azioni"><button type="button" id="chiudi-oauth">Ho capito</button></div>
</dialog>
<script>
const gestori = {gestori}, avvisi = {avvisi};
const $ = id => document.getElementById(id);
// Stesse regole di postaconf.richiede_oauth()
const oauthDominio = new RegExp({json.dumps(pc.OAUTH_DOMINIO.pattern)}), oauthServer = new RegExp({json.dumps(pc.OAUTH_SERVER.pattern)});
function richiedeOauth() {{
  const v = ($('indirizzo')?.value || '').trim().toLowerCase(), dominio = v.includes('@') ? v.split('@').pop() : '';
  return oauthDominio.test(dominio) || oauthServer.test($('server').value.trim().toLowerCase().replace(/\\.$/, ''));
}}
let oauthMostrata = false;
function controllaOauth() {{
  if (!richiedeOauth()) {{ oauthMostrata = false; return; }}
  if (!oauthMostrata) {{ oauthMostrata = true; $('finestra-oauth').showModal(); }}
}}
$('apri-oauth').addEventListener('click', ev => {{ ev.preventDefault(); $('finestra-oauth').showModal(); }});
$('chiudi-oauth').addEventListener('click', () => $('finestra-oauth').close());
function aggiornaAvviso() {{
  $('avviso-gestore').textContent = richiedeOauth()
    ? 'Casella Microsoft: accetta solo OAuth2, non si può aggiungere.' : (avvisi[$('server').value.trim()] || '');
  controllaOauth();
}}
const ind = $('indirizzo');
if (ind && ind.type === 'text' && !ind.disabled) ind.addEventListener('change', () => {{
  const v = ind.value.trim(), dominio = v.split('@')[1]?.toLowerCase();
  if (!$('utente').value) $('utente').value = v;
  const g = gestori[dominio];
  if (g && !$('server').value) {{ $('server').value = g[0]; $('porta').value = g[1]; $('sicurezza').value = g[2]; }}
  aggiornaAvviso();
}});
$('server').addEventListener('change', aggiornaAvviso); aggiornaAvviso();
function aggiornaConferma() {{
  const g = parseInt($('tieni_giorni').value, 10) || 0, attuale = parseInt($('tieni_giorni').dataset.attuale, 10) || 0;
  $('riga-conferma').hidden = !(g > 0 && (attuale === 0 || g < attuale));
}}
$('tieni_giorni').addEventListener('input', aggiornaConferma); aggiornaConferma();
</script>""", s)

    def tabella_prova(self, prova):
        if prova.get('errore'):
            return f'<div class="pannello"><h3>Prova dell\'accesso</h3><p class="errore">{e(prova["errore"])}</p></div>'
        righe = ''.join(
            f"<tr><td>{e(r['gestore'])}</td><td>{e(r['locale'])}</td>"
            f"<td class=\"num\">{'' if r['messaggi'] is None else e(r['messaggi'])}</td><td class=\"tenue\">{e(r['nota'])}</td></tr>"
            for r in prova['righe'])
        totale = sum(r['messaggi'] or 0 for r in prova['righe'] if r['nota'] != 'esclusa')
        return f"""<div class="pannello"><h3>Prova dell'accesso: <span class="ok">riuscita</span></h3>
<p class="tenue">{len(prova['righe'])} cartelle, {f'{totale:,}'.replace(',', '.')} email da copiare. Ecco dove finirà ogni cartella:</p>
<div class="scorre"><table><tr><th>Sul gestore</th><th>Nella copia locale</th><th class="num">Email</th><th></th></tr>
{righe}</table></div></div>{self.tabella_pulizia(prova.get('pulizia'))}"""

    def tabella_pulizia(self, p):
        if not p:
            return ''
        if 'righe' not in p:
            return f'<div class="pannello"><h3>Pulizia del gestore</h3><p class="tenue">{e(p.get("nota", ""))}</p></div>'
        numero = lambda n: f'{n:,}'.replace(',', '.')
        righe = ''.join(
            f"<tr><td>{e(r['gestore'])}</td><td class=\"num\">{numero(r['vecchie'])}</td>"
            f"<td class=\"num\">{numero(r['confermate'])}</td><td class=\"tenue\">{e(r['nota'])}</td></tr>"
            for r in p['righe'] if r['vecchie'] or r['nota'])
        return f"""<div class="pannello"><h3>Pulizia del gestore: cosa verrebbe cancellato</h3>
<p>Sul gestore ci sono <b>{numero(p['vecchie'])}</b> email arrivate prima del {e(p['prima_del'])}: di queste
<b>{numero(p['confermate'])}</b> hanno la copia verificata su questo server e verrebbero <b>cancellate dal gestore</b>
dopo la prossima sincronizzazione. Le altre restano sul gestore. <span class="tenue">(Stima: non è stato cancellato niente.)</span></p>
{f'<div class="scorre"><table><tr><th>Cartella sul gestore</th><th class="num">Più vecchie</th><th class="num">Da cancellare</th><th></th></tr>{righe}</table></div>' if righe else ''}</div>"""

    def dati_modulo(self, dati, esistente):
        """Casella dal modulo; i campi password vuoti tengono i valori esistenti."""
        indirizzo = dati.get('indirizzo', '').strip().lower()
        a = {
            'indirizzo': indirizzo,
            'server': dati.get('server', '').strip(),
            'porta': dati.get('porta', '').strip(),
            'sicurezza': dati.get('sicurezza', 'ssl'),
            'utente': dati.get('utente', '').strip(),
            'password_gestore': dati.get('password_gestore', '') or (esistente or {}).get('password_gestore', ''),
            'password_locale': (esistente or {}).get('password_locale', ''),
            'password_locale_hash': (esistente or {}).get('password_locale_hash', ''),
            'escludi': [x.strip() for x in dati.get('escludi', '').split(',') if x.strip()],
            'attiva': (esistente or {}).get('attiva', True),
            'tieni_giorni': 0,
            '_password_gestore_modulo': dati.get('password_gestore', ''),
            '_password_locale_modulo': dati.get('password_locale', ''),
        }
        errori = []
        if not pc.INDIRIZZO.match(indirizzo):
            errori.append('Indirizzo email non valido.')
        if not a['server'] or any(c in a['server'] for c in ' "\\\n'):
            errori.append('Server IMAP non valido.')
        elif pc.richiede_oauth(indirizzo, a['server']):
            errori.append('Questa casella è di Microsoft (Hotmail, Outlook, Live, Microsoft 365), che accetta solo '
                          'l\'accesso OAuth2: non può essere copiata da PiGreco Posta (vedere "Quali caselle non si possono aggiungere?").')
        try:
            a['porta'] = int(a['porta'])
            if not 0 < a['porta'] < 65536:
                raise ValueError
        except ValueError:
            errori.append('Porta non valida.')
        if a['sicurezza'] not in ('ssl', 'starttls'):
            errori.append('Sicurezza non valida.')
        if not a['utente'] or '\n' in a['utente']:
            errori.append('Utente sul gestore mancante.')
        if not a['password_gestore']:
            errori.append('Password sul gestore mancante.')
        if '\n' in a['password_gestore'] or '\n' in dati.get('password_locale', ''):
            errori.append('Le password non possono contenere a capo.')
        if dati.get('password_locale') and len(dati['password_locale']) < 8:
            errori.append('La password locale deve avere almeno 8 caratteri.')
        testo_giorni = str(dati.get('tieni_giorni', (esistente or {}).get('tieni_giorni', 0))).strip() or '0'
        if not testo_giorni.isdigit():
            errori.append('Giorni da tenere sul gestore: un numero intero (0 = non cancellare mai).')
            a['_tieni_giorni_modulo'] = testo_giorni
        elif 0 < int(testo_giorni) < pc.TIENI_GIORNI_MINIMO:
            errori.append(f'Giorni da tenere sul gestore: 0 oppure almeno {pc.TIENI_GIORNI_MINIMO}.')
            a['_tieni_giorni_modulo'] = testo_giorni
        else:
            a['tieni_giorni'] = int(testo_giorni)
        return a, errori

    def esegui_prova(self, a, stima=False):
        """Accesso al gestore e cartelle; con stima=True anche cosa cancellerebbe la pulizia."""
        try:
            imap = pc.connetti(a, timeout=20)
            try:
                cartelle = pc.cartelle_gestore(a, imap, conta=True)
            finally:
                try:
                    imap.logout()
                except Exception:
                    pass
            prova = pc.piano_cartelle(a, cartelle)
        except Exception as ex:
            testo = str(ex)
            if isinstance(ex, bytes) or testo.startswith("b'"):
                testo = testo[2:-1]
            if 'BASIC AUTH' in testo.upper() or 'BASICAUTHBLOCKED' in testo.upper():
                testo = ('Il gestore accetta solo l\'accesso OAuth2, non utente e password: '
                         f'questa casella non può essere copiata da PiGreco Posta ({testo}).')
            elif 'AUTHENTICATIONFAILED' in testo.upper() or 'LOGIN' in testo.upper():
                testo = f'Accesso rifiutato dal gestore: utente o password errati ({testo}).'
            elif isinstance(ex, OSError):
                testo = f'Server non raggiungibile ({testo}): controllare server, porta e sicurezza.'
            return {'errore': testo}
        if stima and a.get('tieni_giorni', 0) > 0:
            prova['pulizia'] = self.stima_pulizia(a)
        return prova

    def stima_pulizia(self, a):
        """Anteprima in sola lettura della pulizia del gestore (pc.pulisci_gestore, prova=True)."""
        if pc.leggi_stato(a['indirizzo']) is None:
            return {'nota': 'La stima sarà disponibile dopo la prima copia della casella.'}
        try:
            return pc.pulisci_gestore(a, a['tieni_giorni'], prova=True)
        except Exception as ex:
            return {'nota': f'Stima non riuscita: {ex}'}

    def prova(self, s, dati):
        esistente = next((x for x in pc.leggi_account() if x['indirizzo'] == dati.get('indirizzo', '').lower()), None)
        a, errori = self.dati_modulo(dati, esistente)
        nuova = bool(dati.get('nuova'))
        if errori:
            return self.pagina_casella(s, a, nuova, ' '.join(errori))
        return self.pagina_casella(s, a, nuova, prova=self.esegui_prova(a, stima=True))

    def salva(self, s, dati):
        caselle = pc.leggi_account()
        nuova = bool(dati.get('nuova'))
        indirizzo = dati.get('indirizzo', '').strip().lower()
        esistente = next((x for x in caselle if x['indirizzo'] == indirizzo), None)
        if nuova and esistente:
            return self.pagina_casella(s, self.dati_modulo(dati, None)[0], True,
                                       f'La casella {e(indirizzo)} esiste già.')
        if not nuova and not esistente:
            return self.vai('/?' + urlencode({'msg': 'Casella non trovata.', 'tipo': 'errore'}))
        a, errori = self.dati_modulo(dati, esistente)
        if errori:
            return self.pagina_casella(s, a, nuova, ' '.join(errori))
        attuale = (esistente or {}).get('tieni_giorni', 0)
        if a['tieni_giorni'] > 0 and (attuale == 0 or a['tieni_giorni'] < attuale) and not dati.get('conferma_pulizia'):
            return self.pagina_casella(
                s, a, nuova, 'Non salvata: per cancellare dal gestore le email più vecchie di '
                f'{a["tieni_giorni"]} giorni spuntare la conferma sotto "Giorni da tenere sul gestore". '
                'Qui sotto la stima di cosa verrebbe cancellato.', prova=self.esegui_prova(a, stima=True))

        prova = self.esegui_prova(a)
        if prova.get('errore') and not dati.get('senza_prova'):
            return self.pagina_casella(s, a, nuova, 'Non salvata: la prova dell\'accesso al gestore non è riuscita.',
                                       prova=prova)

        mostra_password = ''
        nuova_locale = dati.get('password_locale', '')
        if nuova and not nuova_locale:
            nuova_locale = password_casuale()
        if nuova_locale:
            a['password_locale_hash'] = pc.hash_password(nuova_locale)
            a['password_locale'] = ''
            mostra_password = nuova_locale

        a = {k: v for k, v in a.items() if not k.startswith('_')}
        if esistente:
            caselle = [a if x['indirizzo'] == indirizzo else x for x in caselle]
        else:
            caselle.append(a)
        pc.salva_account(caselle)
        pc.genera_utenti_dovecot()
        pc.richiedi_sincronizzazione()

        msg = f'Casella <b>{e(indirizzo)}</b> {"aggiunta" if nuova else "aggiornata"}: la sincronizzazione parte entro pochi secondi.'
        if a['tieni_giorni'] != attuale:
            msg += ('<br>Sul gestore: ' + (f'si tengono gli ultimi {a["tieni_giorni"]} giorni, le email più vecchie '
                    'con copia verificata verranno cancellate dopo la sincronizzazione.' if a['tieni_giorni']
                    else 'non si cancella più niente.'))
        if prova.get('errore'):
            msg += f'<br><span class="avviso">Attenzione: la prova dell\'accesso non è riuscita ({e(prova["errore"])}).</span>'
        if mostra_password:
            msg += (f'<br><br>Password locale di {e(indirizzo)}: <span class="password">{e(mostra_password)}</span><br>'
                    '<span class="tenue">Annotarla ora: non verrà più mostrata (si può sempre cambiarla).</span>')
        self.pagina_elenco(s, msg, 'ok')

    def sospendi(self, s, dati):
        indirizzo = dati.get('indirizzo', '').lower()
        caselle = pc.leggi_account()
        for a in caselle:
            if a['indirizzo'] == indirizzo:
                a['attiva'] = not a['attiva']
                pc.salva_account(caselle)
                pc.richiedi_sincronizzazione()
                stato = 'ripresa' if a['attiva'] else 'sospesa'
                return self.vai('/?' + urlencode({'msg': f'Sincronizzazione di {indirizzo} {stato}.'}))
        self.vai('/?' + urlencode({'msg': 'Casella non trovata.', 'tipo': 'errore'}))

    def rimuovi(self, s, dati):
        indirizzo = dati.get('indirizzo', '').lower()
        caselle = pc.leggi_account()
        rimaste = [a for a in caselle if a['indirizzo'] != indirizzo]
        if len(rimaste) == len(caselle):
            return self.vai('/?' + urlencode({'msg': 'Casella non trovata.', 'tipo': 'errore'}))
        pc.salva_account(rimaste)
        pc.genera_utenti_dovecot()
        pc.richiedi_sincronizzazione()
        self.vai('/?' + urlencode({'msg': f'Casella {indirizzo} rimossa. Le email copiate restano in /srv/posta/{indirizzo}.'}))

    def sincronizza(self, s, dati):
        pc.richiedi_sincronizzazione()
        self.vai('/?' + urlencode({'msg': 'Sincronizzazione richiesta: parte entro pochi secondi.'}))


if __name__ == '__main__':
    print(f'Gestione caselle in ascolto sulla porta {PORTA}', flush=True)
    ThreadingHTTPServer(('0.0.0.0', PORTA), Gestione).serve_forever()
