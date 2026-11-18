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
 * xml_allegato.php - Un allegato XML reso leggibile (lib/XmlAllegato.php).
 *
 * Parametri GET come get_attachment_content.php:
 * - account_id (o "user"), file (o "uid"), index, folder
 *
 * Risposta JSON: {success, nome, tipo, titolo, esito, frase, righe, sezioni,
 * avvisi, testo, firmato}. Solo testi: la pagina li mostra con textContent.
 * Ricevute PEC (daticert.xml), notifiche SdI, fatture elettroniche (anche
 * .xml.p7m), qualsiasi altro XML come testo ordinato.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';
require_once __DIR__ . '/lib/XmlAllegato.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function xmlErrore(string $messaggio, int $codice): void {
    http_response_code($codice);
    echo json_encode(['success' => false, 'error' => $messaggio], JSON_UNESCAPED_UNICODE);
    exit;
}

$accountParam = requestParam(['account_id', 'user']);
$uid = (int)requestParam(['uid', 'file']);
$index = (int)requestParam(['index'], '0');
$folder = requestParam(['folder'], 'inbox');
if (!MailboxService::isFolderParam($folder)) {
    $folder = 'inbox';
}

if ($accountParam === '' || $uid <= 0 || $index < 0) {
    xmlErrore('Parametri non validi', 400);
}

try {
    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        xmlErrore('Account non valido o non autorizzato', 403);
    }
    $raw = $mailbox->getRaw($folder, $uid, false);
    $mailbox->close();
} catch (Throwable $e) {
    xmlErrore('Errore di lettura dal server di posta', 502);
}

if ($raw === null) {
    xmlErrore('Email non trovata', 404);
}

$email = parseRawEmail($raw);
if (isset($email['error'])) {
    xmlErrore('Email non leggibile', 500);
}
$allegato = $email['attachments'][$index] ?? null;
if ($allegato === null) {
    xmlErrore('Allegato non trovato', 404);
}

$nome = (string)($allegato['filename'] ?? 'allegato.xml');
if (!XmlAllegato::eXml($nome, (string)($allegato['content_type'] ?? ''))) {
    xmlErrore('L\'allegato non è un file XML', 400);
}

$contenuto = base64_decode($allegato['content'] ?? '', true);
if ($contenuto === false) {
    xmlErrore('Contenuto dell\'allegato non valido', 500);
}

$risultato = XmlAllegato::analizza($contenuto, $nome);
$json = json_encode(['success' => true, 'nome' => $nome] + $risultato,
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
echo $json === false ? json_encode(['success' => false, 'error' => 'Risposta non codificabile']) : $json;
