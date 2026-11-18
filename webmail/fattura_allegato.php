<?php
/*
 * PiGreco Posta
 * Copyright (C) 2026 Criscione Enzo
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
 * secondo i termini della GNU Affero General Public License come pubblicata
 * dalla Free Software Foundation, versione 3 della licenza o (a tua scelta)
 * qualsiasi versione successiva.
 *
 * Questo programma è distribuito nella speranza che sia utile, ma SENZA
 * ALCUNA GARANZIA; senza neppure la garanzia implicita di COMMERCIABILITÀ
 * o di IDONEITÀ PER UN PARTICOLARE SCOPO. Vedi la GNU Affero General
 * Public License per i dettagli.
 *
 * Dovresti aver ricevuto una copia della GNU Affero General Public License
 * insieme a questo programma (file LICENSE). In caso contrario, vedi
 * <https://www.gnu.org/licenses/>.
 */

/**
 * fattura_allegato.php - Fattura elettronica allegata (.xml o .xml.p7m)
 * impaginata con il foglio di stile ufficiale dell'Agenzia delle Entrate
 * (lib/fatturapa/FS122.xsl, XmlAllegato::impagina()).
 *
 * Parametri GET come get_attachment_content.php. Risponde con una pagina
 * HTML che non può eseguire niente: CSP "sandbox" senza script, nessuna
 * risorsa esterna. La webmail la mostra in un iframe sandbox (srcdoc) o in
 * una nuova scheda per stamparla.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';
require_once __DIR__ . '/lib/XmlAllegato.php';
require_once __DIR__ . '/lib/AllegatoRichiesto.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");

function fatturaErrore(string $messaggio, int $codice): never {
    http_response_code($codice);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Fattura</title>'
        . '<p style="font-family:sans-serif;padding:20px">' . htmlspecialchars($messaggio, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

$allegato = allegatoRichiesto('fatturaErrore');
if (!XmlAllegato::eXml($allegato['nome'], $allegato['tipo'])) {
    fatturaErrore('L\'allegato non è un file XML.', 400);
}

$html = XmlAllegato::impagina($allegato['contenuto'], $allegato['nome']);
if ($html === null) {
    fatturaErrore('Questa fattura non si può impaginare: usa il riepilogo o scaricala.', 422);
}

header('Content-Type: text/html; charset=utf-8');
echo $html;
