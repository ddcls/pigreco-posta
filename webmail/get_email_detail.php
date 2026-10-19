<?php
/**
 * get_email_detail.php - Dettaglio di un'email letta via IMAP.
 *
 * Parametri POST:
 * - account_id (o "user"): id dell'account email
 * - file (o "uid"): UID IMAP del messaggio
 * - folder: inbox | sent | draft | junk | trash | imap:<nome cartella>
 *
 * Il messaggio viene scaricato da Dovecot e analizzato da email_parser.py
 * (stesso formato di risposta di prima: subject, from, to, date,
 * text_body, html_body, attachments...). L'apertura lo segna come letto.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Shutdown handler per errori fatali: la risposta resta sempre JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Errore critico durante elaborazione email'], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';
header('Content-Type: application/json; charset=utf-8');

const MAX_EMAIL_SIZE = 100 * 1024 * 1024;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo non consentito. Usare POST.');
    }

    $accountParam = requestParam(['account_id', 'user']);
    $uid = (int)requestParam(['uid', 'file']);
    $folder = requestParam(['folder'], 'inbox');
    if (!MailboxService::isFolderParam($folder)) {
        $folder = 'inbox';
    }

    if ($accountParam === '' || $uid <= 0) {
        throw new Exception('Parametri obbligatori mancanti (account, email)');
    }

    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        http_response_code(403);
        throw new Exception('Account non valido o non autorizzato');
    }

    $raw = $mailbox->getRaw($folder, $uid, true);
    $mailbox->close();

    if ($raw === null) {
        http_response_code(404);
        throw new Exception('Email non trovata (potrebbe essere stata spostata o eliminata)');
    }
    if (strlen($raw) > MAX_EMAIL_SIZE) {
        throw new Exception('Email troppo grande (max 100MB)');
    }

    $result = parseRawEmail($raw);
    if (isset($result['error'])) {
        throw new Exception($result['error']);
    }

    $result['file'] = (string)$uid;
    $result['file_size'] = strlen($raw);
    $result['folder'] = $folder;
    $result['user'] = $accountParam;

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;

} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
