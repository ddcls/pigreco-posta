<?php
/**
 * Endpoint per ottenere il contenuto raw di un'email (salvataggio come .eml).
 *
 * Parametri POST: account_id (o "user"), file (o "uid"), folder.
 * Il messaggio viene letto via IMAP senza segnarlo come letto.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';
error_reporting(0);
ini_set('display_errors', 0);

function rawError($message, $code = 400) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        rawError('Metodo non consentito', 405);
    }

    $accountParam = requestParam(['account_id', 'user']);
    $uid = (int)requestParam(['uid', 'file']);
    $folder = requestParam(['folder'], 'inbox');
    if (!MailboxService::isFolderParam($folder)) {
        $folder = 'inbox';
    }
    if ($accountParam === '' || $uid <= 0) {
        rawError('Account o email non specificati');
    }

    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        rawError('Account non valido o non autorizzato', 403);
    }
    $raw = $mailbox->getRaw($folder, $uid, false);
    $mailbox->close();

    if ($raw === null) {
        rawError('Email non trovata', 404);
    }

    header('Content-Type: message/rfc822');
    header('Content-Disposition: attachment; filename="email_' . time() . '.eml"');
    header('Content-Length: ' . strlen($raw));
    echo $raw;
    exit;

} catch (Throwable $e) {
    rawError('Errore: ' . $e->getMessage(), 500);
}
