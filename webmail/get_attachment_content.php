<?php
/**
 * get_attachment_content.php - Contenuto di un allegato (download o anteprima).
 *
 * Parametri GET:
 * - account_id (o "user"): id dell'account email
 * - file (o "uid"): UID IMAP del messaggio
 * - index: indice allegato (0-based, come in get_email_detail.php)
 * - folder: inbox | sent | draft | junk | trash | imap:<nome cartella>
 * - disposition: "attachment" per forzare il download, altrimenti anteprima
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';

function attachmentError($message, $code) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    die($message);
}

$accountParam = requestParam(['account_id', 'user']);
$uid = (int)requestParam(['uid', 'file']);
$index = (int)requestParam(['index'], '0');
$folder = requestParam(['folder'], 'inbox');
if (!MailboxService::isFolderParam($folder)) {
    $folder = 'inbox';
}

if ($accountParam === '' || $uid <= 0 || $index < 0) {
    attachmentError('Parametri non validi', 400);
}

try {
    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        attachmentError('Account non valido o non autorizzato', 403);
    }
    $raw = $mailbox->getRaw($folder, $uid, false);
    $mailbox->close();
} catch (Throwable $e) {
    attachmentError('Errore di lettura dal server di posta: ' . $e->getMessage(), 502);
}

if ($raw === null) {
    attachmentError('Email non trovata', 404);
}

$result = parseRawEmail($raw);
if (isset($result['error'])) {
    attachmentError('Errore: ' . $result['error'], 500);
}
if (!isset($result['attachments'][$index])) {
    attachmentError("Allegato non trovato all'indice {$index}", 404);
}

$attachment = $result['attachments'][$index];
$content = base64_decode($attachment['content'] ?? '', true);
if ($content === false) {
    attachmentError('Errore: contenuto allegato non valido', 500);
}

$filename = $attachment['filename'] ?? 'allegato';
$contentType = $attachment['content_type'] ?? 'application/octet-stream';
$disposition = requestParam(['disposition']) === 'attachment' ? 'attachment' : 'inline';

if ($disposition === 'inline') {
    // Anteprima solo per tipi che il browser mostra senza eseguire codice.
    // HTML, SVG e qualsiasi altro tipo vengono scaricati: aperti inline
    // girerebbero con i permessi della webmail (XSS).
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $safeInline = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif',
        'bmp' => 'image/bmp', 'webp' => 'image/webp',
        'txt' => 'text/plain; charset=utf-8',
    ];
    if (isset($safeInline[$ext])) {
        $contentType = $safeInline[$ext];
    } else {
        $disposition = 'attachment';
    }
}

$asciiName = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename);
header('Content-Type: ' . $contentType);
header('Content-Length: ' . strlen($content));
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

echo $content;
exit;
