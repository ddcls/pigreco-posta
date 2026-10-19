<?php
/**
 * Struttura comune degli endpoint che modificano un messaggio via IMAP
 * (segna letto/non letto, sposta nel cestino, ripristina...).
 *
 * Parametri POST: account_id (o "user"), file (o "uid"), folder.
 * Risposta JSON: {success: true, message} oppure {success: false, error}.
 */

require_once __DIR__ . '/../check_auth.php';
require_once __DIR__ . '/MailboxService.php';

/**
 * @param callable $action fn(MailboxService $mailbox, string $folder, int $uid): string
 *                         restituisce il messaggio di conferma
 */
function handleMailAction(callable $action) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            throw new Exception('Metodo non consentito. Usare POST.');
        }

        $accountParam = requestParam(['account_id', 'user']);
        $uid = (int)requestParam(['uid', 'file']);
        $folder = requestParam(['folder'], 'inbox');
        if (!MailboxService::isFolderParam($folder)) {
            $folder = 'inbox';
        }
        if ($accountParam === '' || $uid <= 0) {
            http_response_code(400);
            throw new Exception('Account o email non specificati');
        }

        $mailbox = openAuthorizedMailbox($accountParam);
        if ($mailbox === null) {
            http_response_code(403);
            throw new Exception('Account non valido o non autorizzato');
        }

        $message = $action($mailbox, $folder, $uid);
        $mailbox->close();

        echo json_encode([
            'success' => true,
            'message' => $message,
            'file'    => (string)$uid,
            'folder'  => $folder,
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        if (http_response_code() < 400) {
            // ImapException: problema dal server di posta; Exception: richiesta
            // non eseguibile (es. già nel Cestino); Error: bug lato server
            http_response_code($e instanceof ImapException ? 502 : ($e instanceof Exception ? 400 : 500));
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
