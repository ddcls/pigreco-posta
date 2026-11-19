#!/usr/bin/env python3
# -*- coding: utf-8 -*-
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
email_parser.py - Parser robusto per email Maildir, con supporto PEC
=======================================================================
Strutture gestite:
  - multipart/signed  (PEC Aruba, legalmail, ecc.)
  - multipart/mixed / multipart/alternative / multipart/related
  - message/rfc822    (messaggio originale annidato nella busta PEC)
  - Content-Transfer-Encoding: quoted-printable, base64, 7bit, 8bit
  - Charset: iso-8859-1, utf-8, windows-1252, latin-1, ecc.
  - Header MIME encoded-words: =?charset?B/Q?...?=

CORREZIONI rispetto alla versione precedente:
  - ✅ Usa email.message_from_bytes con policy=email.policy.compat32 (più
       robusto con PEC e email non conformi rispetto a policy.default)
  - ✅ Scende dentro message/rfc822 per estrarre il corpo del messaggio originale
  - ✅ Distingue corpo reale (dentro rfc822) da testo busta PEC (livello esterno)
  - ✅ Mantiene fix_boundary_mismatch per client non conformi (Thunderbird ecc.)
  - ✅ Decodifica charset iso-8859-1 / windows-1252 / utf-8 con fallback robusto
  - ✅ Allegati inline con filename= rilevati correttamente
  - ✅ Allegati senza Content-Disposition ma con name= catturati
"""

import sys
import os
import re
import json
import base64
import email
import email.policy
import traceback
from email.header import decode_header as _decode_header
from email.utils import parsedate_to_datetime


# ──────────────────────────────────────────────────────────────────────────────
# Tipi container multipart: non sono mai allegati né corpo diretto
# ──────────────────────────────────────────────────────────────────────────────
MULTIPART_CONTAINERS = {
    'multipart/mixed',
    'multipart/alternative',
    'multipart/related',
    'multipart/signed',
    'multipart/encrypted',
    'multipart/report',
    'multipart/digest',
    'message/rfc822',
}

# Tipi che sono SEMPRE allegati (anche senza filename)
ALWAYS_ATTACHMENT_TYPES = {
    'application/pdf',
    'application/zip',
    'application/x-zip-compressed',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/octet-stream',
    'application/pkcs7-signature',
    'application/pkcs7-mime',
    'application/xml',
}


# ──────────────────────────────────────────────────────────────────────────────
# Utility: decodifica header MIME  =?charset?B/Q?...?=
# ──────────────────────────────────────────────────────────────────────────────
def decode_mime_header(raw):
    """Decodifica un header MIME in stringa Unicode pulita."""
    if not raw:
        return ''
    try:
        parts = _decode_header(raw)
        result = []
        for part, charset in parts:
            if isinstance(part, bytes):
                enc = charset or 'utf-8'
                for fallback in [enc, 'utf-8', 'latin-1']:
                    try:
                        result.append(part.decode(fallback))
                        break
                    except (UnicodeDecodeError, LookupError):
                        continue
                else:
                    result.append(part.decode('latin-1', errors='replace'))
            else:
                result.append(part)
        return ' '.join(result).strip()
    except Exception:
        return str(raw).strip()


# ──────────────────────────────────────────────────────────────────────────────
# Utility: decodifica payload bytes di una parte (gestisce CTE)
# ──────────────────────────────────────────────────────────────────────────────
def get_payload_bytes(part):
    """Restituisce il payload come bytes grezzi (CTE già rimosso)."""
    try:
        payload = part.get_payload(decode=True)  # decode=True rimuove b64/qp
        if payload is not None:
            return payload
    except Exception:
        pass
    # Fallback: payload grezzo
    raw = part.get_payload()
    if isinstance(raw, bytes):
        return raw
    if isinstance(raw, str):
        return raw.encode('utf-8', errors='replace')
    return b''


def decode_text_payload(part):
    """Decodifica il payload testuale rispettando il charset dichiarato."""
    raw = get_payload_bytes(part)
    if not raw:
        return ''
    charset = part.get_param('charset') or 'utf-8'
    # Normalizza alias comuni
    charset = charset.lower().strip()
    aliases = {
        'iso-8859-1': 'latin-1', 'iso8859-1': 'latin-1',
        'windows-1252': 'cp1252', 'win-1252': 'cp1252',
        'us-ascii': 'ascii',
    }
    charset = aliases.get(charset, charset)
    for enc in [charset, 'utf-8', 'latin-1', 'cp1252']:
        try:
            return raw.decode(enc)
        except (UnicodeDecodeError, LookupError):
            continue
    return raw.decode('latin-1', errors='replace')


# ──────────────────────────────────────────────────────────────────────────────
# Utility: nome file allegato
# ──────────────────────────────────────────────────────────────────────────────
def get_filename(part):
    """Recupera il nome file di un allegato, decodificando encoded-words MIME."""
    # 1. get_filename() gestisce Content-Disposition filename= e Content-Type name=
    fname = part.get_filename()
    if fname:
        decoded = decode_mime_header(fname)
        # compat32 preserva i \r\n dai parametri folded: normalizza in spazio singolo
        return re.sub(r'\s*[\r\n]+\s*', ' ', decoded).strip()
    # 2. Content-Type name= esplicito (client vecchi)
    name = part.get_param('name')
    if name:
        decoded = decode_mime_header(name)
        return re.sub(r'\s*[\r\n]+\s*', ' ', decoded).strip()
    return None


# ──────────────────────────────────────────────────────────────────────────────
# Fix boundary mismatch (Thunderbird e client non conformi)
# ──────────────────────────────────────────────────────────────────────────────
def fix_boundary_mismatch(raw_bytes):
    """Corregge il mismatch tra boundary dichiarato e quello usato nel corpo."""
    try:
        header_match = re.search(
            rb'Content-Type\s*:[^\r\n]*multipart[^\r\n]*boundary\s*=\s*"?([^"\r\n;]+)"?',
            raw_bytes, re.IGNORECASE
        )
        if not header_match:
            return raw_bytes

        declared_boundary = header_match.group(1).strip()
        header_end = re.search(rb'\r?\n\r?\n', raw_bytes)
        if not header_end:
            return raw_bytes

        body = raw_bytes[header_end.end():]
        escaped = re.escape(declared_boundary)
        actual_match = re.search(rb'^(-{2,})' + escaped + rb'[ \t]*\r?$', body, re.MULTILINE)

        if not actual_match:
            return raw_bytes

        actual_dashes = actual_match.group(1)
        if actual_dashes == b'--':
            return raw_bytes

        real_boundary = actual_dashes[2:] + declared_boundary

        fixed = raw_bytes.replace(
            b'boundary="' + declared_boundary + b'"',
            b'boundary="' + real_boundary + b'"'
        )
        if fixed == raw_bytes:
            fixed = raw_bytes.replace(
                b'boundary=' + declared_boundary,
                b'boundary=' + real_boundary
            )
        return fixed
    except Exception:
        return raw_bytes


# ──────────────────────────────────────────────────────────────────────────────
# Classificazione parti
# ──────────────────────────────────────────────────────────────────────────────
def classify_part(part):
    """
    Restituisce 'attachment', 'text', 'html', 'container', 'rfc822' o 'skip'.
    """
    ct = part.get_content_type().lower()

    if ct == 'message/rfc822':
        return 'rfc822'

    if ct in MULTIPART_CONTAINERS or ct.startswith('multipart/'):
        return 'container'

    disp_header = (part.get('Content-Disposition') or '').strip().lower()
    disp = disp_header.split(';')[0].strip()
    filename = get_filename(part)

    # Firma digitale e tipi sempre-allegato
    if ct in ALWAYS_ATTACHMENT_TYPES:
        return 'attachment'

    # Content-Disposition: attachment
    if disp == 'attachment':
        return 'attachment'

    # Ha un filename → allegato (anche se inline)
    if filename:
        return 'attachment'

    # Testo plain senza filename → corpo
    if ct == 'text/plain':
        return 'text'

    # HTML senza filename → corpo
    if ct == 'text/html':
        return 'html'

    # Immagini/audio/video/altro senza filename → allegato generico
    if not ct.startswith('text/'):
        return 'attachment'

    return 'skip'


# ──────────────────────────────────────────────────────────────────────────────
# Estrazione ricorsiva con gestione message/rfc822
# ──────────────────────────────────────────────────────────────────────────────
def extract_parts(msg, result, depth=0, is_real_body=False):
    """
    Percorre ricorsivamente le parti del messaggio.

    is_real_body=True quando siamo dentro message/rfc822 (messaggio originale
    PEC): in questo caso il testo/HTML trovato sovrascrive quello della busta.

    Logica priorità corpo:
      - Il testo dentro message/rfc822 ha priorità assoluta (è il corpo reale).
      - Il testo al livello esterno (busta PEC) viene usato solo come fallback.
    """
    ct = msg.get_content_type().lower()

    # ── message/rfc822: scendi nel messaggio originale ───────────────────────
    if ct == 'message/rfc822':
        payload = msg.get_payload()
        # get_payload() su rfc822 ritorna una lista con un Message o il Message diretto
        if isinstance(payload, list) and len(payload) > 0:
            inner = payload[0]
        elif hasattr(payload, 'get_content_type'):
            inner = payload
        else:
            return
        extract_parts(inner, result, depth + 1, is_real_body=True)
        return

    # ── Container multipart: visita i figli ──────────────────────────────────
    if msg.is_multipart():
        for part in msg.get_payload():
            extract_parts(part, result, depth + 1, is_real_body)
        return

    # ── Parte foglia ──────────────────────────────────────────────────────────
    kind = classify_part(msg)

    if kind == 'attachment':
        raw = get_payload_bytes(msg)
        if not raw:
            return
        filename = get_filename(msg) or _fallback_filename(msg.get_content_type())
        result['attachments'].append({
            'filename': filename,
            'content_type': msg.get_content_type(),
            'size': len(raw),
            'content': base64.b64encode(raw).decode('ascii'),
            # Content-ID senza < >: il corpo HTML vi si riferisce con cid:
            # (immagini dentro l'email, per esempio il logo di una firma)
            'content_id': (msg.get('Content-ID') or '').strip().strip('<>').strip(),
        })

    elif kind == 'text':
        text = decode_text_payload(msg).strip()
        if not text:
            return
        if is_real_body:
            # Corpo reale (dentro rfc822): sovrascrive sempre
            result['text_body'] = text
        elif not result['text_body']:
            # Busta PEC o email normale: usa solo se non ne abbiamo già uno
            result['_fallback_text'] = text

    elif kind == 'html':
        html = decode_text_payload(msg).strip()
        if not html:
            return
        if is_real_body:
            result['html_body'] = clean_html(html)
        elif not result['html_body']:
            result['_fallback_html'] = clean_html(html)

    # 'container', 'rfc822', 'skip' → gestiti sopra o ignorati


# ──────────────────────────────────────────────────────────────────────────────
# Notifiche di consegna (multipart/report): recapito fallito, in ritardo, ricevute di lettura
# ──────────────────────────────────────────────────────────────────────────────
# In una notifica il messaggio allegato (message/rfc822) è l'email ORIGINALE che non è arrivata: al contrario della
# busta PEC il corpo da mostrare è la spiegazione del gestore, con i dati di consegna e le intestazioni dell'originale.
PARTI_STATO = {'message/delivery-status', 'message/global-delivery-status', 'message/disposition-notification'}
PARTI_ORIGINALE = {'message/rfc822', 'text/rfc822-headers', 'message/rfc822-headers', 'message/global-headers'}
AZIONI = {
    'failed': 'non recapitata',
    'delayed': 'in ritardo, il gestore riprova',
    'delivered': 'recapitata',
    'relayed': 'passata a un altro server',
    'expanded': 'recapitata a una lista',
}


def _testo_parte(part):
    """Testo di una parte (anche se il tipo non è text/*), in UTF-8."""
    if part.is_multipart():
        return ''
    return decode_text_payload(part)


def _blocchi_intestazioni(part):
    """Blocchi di campi "Nome: valore" di message/delivery-status (il primo è del messaggio, poi uno per
    destinatario), come liste di coppie."""
    payload = part.get_payload()
    if isinstance(payload, list):
        blocchi = [list(b.items()) for b in payload if hasattr(b, 'items')]
    else:
        testo = _testo_parte(part).replace('\r\n', '\n')
        blocchi = [list(email.message_from_string(b.strip() + '\n').items())
                   for b in re.split(r'\n\s*\n', testo) if b.strip()]
    return [[(k, ' '.join(str(v).split())) for k, v in b] for b in blocchi if b]


def _intestazioni_originale(part):
    """Intestazioni dell'email originale (message/rfc822 o solo le intestazioni)."""
    payload = part.get_payload()
    if isinstance(payload, list) and payload and hasattr(payload[0], 'get'):
        orig = payload[0]
    elif hasattr(payload, 'get'):
        orig = payload
    else:
        orig = email.message_from_string(_testo_parte(part))
    return [(nome, decode_mime_header(orig.get(campo, ''))) for campo, nome in
            (('From', 'Da'), ('To', 'A'), ('Cc', 'Cc'), ('Date', 'Data'), ('Subject', 'Oggetto'),
             ('Message-ID', 'Message-ID')) if orig.get(campo)]


def _togli_tipo(valore):
    """'rfc822; a@b.it' -> 'a@b.it', 'smtp; 550 ...' -> '550 ...'."""
    return valore.split(';', 1)[1].strip() if ';' in valore else valore.strip()


def leggi_notifica(msg):
    """Se il messaggio è una notifica di consegna restituisce testo e HTML da mostrare, altrimenti None.

    In cima un riquadro con l'esito per ogni destinatario (Final-Recipient, Action, Diagnostic-Code), poi la
    spiegazione del gestore, i dati tecnici della consegna e le intestazioni dell'email originale."""
    report = next((p for p in msg.walk() if p.get_content_type() == 'multipart/report'), None)
    if report is None:
        return None
    figli = report.get_payload() if isinstance(report.get_payload(), list) else []
    stato = next((p for p in figli if p.get_content_type() in PARTI_STATO), None)
    originale = next((p for p in figli if p.get_content_type() in PARTI_ORIGINALE), None)
    if stato is None and originale is None:
        return None

    # Spiegazione del gestore: la prima parte del report (testo, o HTML se c'è solo quello)
    spiegazione_testo, spiegazione_html = '', ''
    if figli and figli[0] is not stato and figli[0] is not originale:
        for p in figli[0].walk():
            ct = p.get_content_type()
            if ct == 'text/plain' and not spiegazione_testo:
                spiegazione_testo = decode_text_payload(p).strip()
            elif ct == 'text/html' and not spiegazione_html:
                spiegazione_html = clean_html(decode_text_payload(p).strip())

    blocchi = _blocchi_intestazioni(stato) if stato is not None else []
    esiti = []
    for b in blocchi:
        campi = {k.lower(): v for k, v in b}
        dest = campi.get('final-recipient') or campi.get('original-recipient')
        if not dest:
            continue
        azione = campi.get('action', '').lower()
        esiti.append({
            'destinatario': _togli_tipo(dest),
            'azione': AZIONI.get(azione, azione),
            'fallita': azione == 'failed',
            'stato': campi.get('status', ''),
            'motivo': _togli_tipo(campi.get('diagnostic-code', '')),
        })
    disposizione = next((v for b in blocchi for k, v in b if k.lower() == 'disposition'), '')
    intest = _intestazioni_originale(originale) if originale is not None else []
    dati_stato = '\n\n'.join('\n'.join(f'{k}: {v}' for k, v in b) for b in blocchi)
    dati_originale = '\n'.join(f'{k}: {v}' for k, v in intest)

    if any(e['fallita'] for e in esiti):
        titolo = 'Email non recapitata'
    elif disposizione:
        titolo = 'Ricevuta di lettura'
    elif esiti:
        titolo = 'Notifica di consegna'
    else:
        titolo = 'Notifica del server di posta'

    h = lambda v: v.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;').replace('"', '&quot;')
    righe = ''.join(
        f'<tr><td style="padding:3px 12px 3px 0;vertical-align:top;"><b>{h(e["destinatario"])}</b></td>'
        f'<td style="padding:3px 12px 3px 0;vertical-align:top;color:{"#b42318" if e["fallita"] else "inherit"};">'
        f'{h(e["azione"])}{" (" + h(e["stato"]) + ")" if e["stato"] else ""}</td>'
        f'<td style="padding:3px 0;vertical-align:top;">{h(e["motivo"])}</td></tr>' for e in esiti)
    orig = dict(intest)
    riquadro = (
        '<div style="font-family:sans-serif;font-size:14px;border:1px solid #f0b3ad;background:#fff4f2;'
        'border-radius:6px;padding:12px 14px;margin:0 0 16px;color:#222;">'
        f'<div style="font-weight:bold;font-size:15px;margin-bottom:6px;">{h(titolo)}</div>'
        + (f'<table style="border-collapse:collapse;font-size:14px;margin-bottom:6px;">{righe}</table>' if righe else '')
        + (f'<div>Esito: {h(disposizione)}</div>' if disposizione else '')
        + ''.join(f'<div><span style="color:#666;">{nome}:</span> {h(orig[nome])}</div>'
                  for nome in ('A', 'Cc', 'Oggetto', 'Data') if orig.get(nome))
        + '</div>')
    pre = lambda t: ('<pre style="white-space:pre-wrap;word-wrap:break-word;font-size:12px;background:#f6f6f6;'
                     f'padding:8px 10px;border-radius:4px;">{h(t)}</pre>')
    html_out = riquadro + (spiegazione_html or (text_to_html(spiegazione_testo) if spiegazione_testo else ''))
    if dati_stato:
        html_out += '<h4 style="font-family:sans-serif;margin:18px 0 4px;">Dati della consegna</h4>' + pre(dati_stato)
    if dati_originale:
        html_out += '<h4 style="font-family:sans-serif;margin:18px 0 4px;">Email originale</h4>' + pre(dati_originale)

    testo_out = '\n\n'.join(t for t in (
        titolo + ''.join(f'\n  {e["destinatario"]}: {e["azione"]} {e["motivo"]}'.rstrip() for e in esiti),
        spiegazione_testo,
        ('Dati della consegna\n' + dati_stato) if dati_stato else '',
        ('Email originale\n' + dati_originale) if dati_originale else '') if t)
    return testo_out, html_out


def _fallback_filename(content_type):
    """Genera un nome file di fallback dal content-type."""
    ext = content_type.split('/')[-1].split(';')[0].strip()
    safe_ext = ext if (ext.isalnum() and len(ext) <= 10) else 'bin'
    return f'allegato.{safe_ext}'


# ──────────────────────────────────────────────────────────────────────────────
# HTML helpers
# ──────────────────────────────────────────────────────────────────────────────
def clean_html(html):
    """Rimuove elementi pericolosi dall'HTML (XSS prevention)."""
    html = re.sub(r'<script\b[^>]*>.*?</script>', '', html, flags=re.IGNORECASE | re.DOTALL)
    html = re.sub(r'<iframe\b[^>]*>.*?</iframe>', '', html, flags=re.IGNORECASE | re.DOTALL)
    html = re.sub(r'<object\b[^>]*>.*?</object>', '', html, flags=re.IGNORECASE | re.DOTALL)
    html = re.sub(r'<embed\b[^>]*>', '', html, flags=re.IGNORECASE)
    html = re.sub(r'<style\b[^>]*>.*?</style>', '', html, flags=re.IGNORECASE | re.DOTALL)
    html = re.sub(r'\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)', '', html, flags=re.IGNORECASE)
    html = re.sub(r'(href|src)\s*=\s*["\']?\s*javascript:[^"\'>\s]*["\']?', '', html, flags=re.IGNORECASE)
    return html


def text_to_html(text):
    """Converte testo semplice in HTML basilare."""
    html = text.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
    return '<pre style="white-space:pre-wrap;word-wrap:break-word;">' + html + '</pre>'


# ──────────────────────────────────────────────────────────────────────────────
# Funzione principale di parsing
# ──────────────────────────────────────────────────────────────────────────────
def parse_email_file(filepath):
    if not os.path.exists(filepath):
        return {'error': f'File non trovato: {filepath}'}
    if not os.access(filepath, os.R_OK):
        return {'error': f'Permesso negato: {filepath}'}
    with open(filepath, 'rb') as f:
        return parse_email_bytes(f.read())


def parse_email_bytes(raw):
    """Analizza un messaggio RFC 822 completo (bytes), letto da file o da IMAP."""
    result = {
        'subject': '',
        'from': '',
        'to': '',
        'cc': '',
        'bcc': '',      # c'è solo nelle bozze e nelle copie inviate
        'reply_to': '',
        'date': '',
        'date_formatted': '',
        'date_raw': '',
        'text_body': '',
        'html_body': '',
        'attachments': [],
        # campi interni di fallback (rimossi prima dell'output)
        '_fallback_text': '',
        '_fallback_html': '',
    }

    try:
        # Fix boundary mismatch (client non conformi)
        raw = fix_boundary_mismatch(raw)

        # ── PARSING CON compat32 ────────────────────────────────────────────
        # policy.compat32 è più tollerante con email non standard (PEC, vecchi client).
        # policy.default rompe silenziosamente su certi header malformati.
        msg = email.message_from_bytes(raw, policy=email.policy.compat32)

        # ── Header ─────────────────────────────────────────────────────────
        result['subject']   = decode_mime_header(msg.get('Subject', ''))
        result['from']      = decode_mime_header(msg.get('From', ''))
        result['to']        = decode_mime_header(msg.get('To', ''))
        result['cc']        = decode_mime_header(msg.get('Cc', ''))
        result['bcc']       = decode_mime_header(msg.get('Bcc', ''))
        result['reply_to']  = decode_mime_header(msg.get('Reply-To', ''))
        result['date_raw']  = msg.get('Date', '')

        # Formatta data
        try:
            date_obj = parsedate_to_datetime(result['date_raw'])
            result['date_formatted'] = date_obj.strftime('%d/%m/%Y %H:%M:%S')
            result['date'] = result['date_formatted']
        except Exception:
            result['date_formatted'] = result['date_raw']
            result['date'] = result['date_raw']

        # ── Corpo e allegati ────────────────────────────────────────────────
        extract_parts(msg, result, depth=0, is_real_body=False)

        # Applica fallback se il corpo reale è vuoto (email non-PEC o PEC senza rfc822)
        if not result['text_body'] and result.get('_fallback_text'):
            result['text_body'] = result['_fallback_text']
        if not result['html_body'] and result.get('_fallback_html'):
            result['html_body'] = result['_fallback_html']

        # Notifica di consegna: il corpo è la spiegazione del gestore, non l'email originale allegata
        notifica = leggi_notifica(msg)
        if notifica is not None:
            result['text_body'], result['html_body'] = notifica

        # Se c'è solo testo, genera HTML basilare
        if not result['html_body'] and result['text_body']:
            result['html_body'] = text_to_html(result['text_body'])

        # Rimuovi campi interni prima dell'output
        result.pop('_fallback_text', None)
        result.pop('_fallback_html', None)

    except Exception as e:
        result['error'] = f'Errore nel parsing: {str(e)}\n{traceback.format_exc()}'
        result.pop('_fallback_text', None)
        result.pop('_fallback_html', None)

    return result


# ──────────────────────────────────────────────────────────────────────────────
# Entry point
# ──────────────────────────────────────────────────────────────────────────────
def main():
    if len(sys.argv) != 2:
        print(json.dumps({'error': 'Uso: email_parser.py <percorso_completo_file> | -'}, ensure_ascii=False))
        sys.exit(1)

    filepath = sys.argv[1]

    # "-" = messaggio letto da stdin (scaricato via IMAP da lib/MailboxService.php)
    if filepath == '-':
        result = parse_email_bytes(sys.stdin.buffer.read())
        print(json.dumps(result, ensure_ascii=False))
        return

    if '..' in filepath or not filepath.startswith('/home/'):
        print(json.dumps({'error': 'Percorso non valido'}, ensure_ascii=False))
        sys.exit(1)

    result = parse_email_file(filepath)
    print(json.dumps(result, ensure_ascii=False))


if __name__ == '__main__':
    main()
