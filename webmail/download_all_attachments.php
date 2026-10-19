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
 * Download di tutti gli allegati di un'email come ZIP.
 *
 * Parametri GET: account_id (o "user"), file (o "uid"), folder.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';

error_reporting(0);
ini_set('display_errors', 0);

function zipError($message, $code) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    die($message);
}

$accountParam = requestParam(['account_id', 'user']);
$uid = (int)requestParam(['uid', 'file']);
$folder = requestParam(['folder'], 'inbox');
if (!MailboxService::isFolderParam($folder)) {
    $folder = 'inbox';
}
if ($accountParam === '' || $uid <= 0) {
    zipError('Parametri non validi', 400);
}

try {
    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        zipError('Account non valido o non autorizzato', 403);
    }
    $raw = $mailbox->getRaw($folder, $uid, false);
    $mailbox->close();
} catch (Throwable $e) {
    zipError('Errore di lettura dal server di posta: ' . $e->getMessage(), 502);
}

if ($raw === null) {
    zipError('Email non trovata', 404);
}

$result = parseRawEmail($raw);
if (empty($result['attachments'])) {
    zipError('Nessun allegato trovato', 404);
}

$zipFilename = 'allegati_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.zip';
$zipPath = sys_get_temp_dir() . '/' . $zipFilename;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    zipError('Impossibile creare il file ZIP', 500);
}

foreach ($result['attachments'] as $attachment) {
    $content = base64_decode($attachment['content'] ?? '', true);
    if ($content === false) {
        continue;
    }

    // Nome senza percorsi; se già presente nello ZIP si aggiunge un suffisso numerico
    $originalFilename = basename(str_replace('\\', '/', $attachment['filename'] ?? '')) ?: 'allegato';
    $filename = $originalFilename;
    $counter = 1;
    while ($zip->locateName($filename) !== false) {
        $pathInfo = pathinfo($originalFilename);
        $filename = $pathInfo['filename'] . '_' . $counter;
        if (isset($pathInfo['extension'])) {
            $filename .= '.' . $pathInfo['extension'];
        }
        $counter++;
    }

    $zip->addFromString($filename, $content);
}

$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($zipPath);
unlink($zipPath);
exit;
