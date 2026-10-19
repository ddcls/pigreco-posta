<?php
/**
 * cartelle.php - Crea, rinomina ed elimina le cartelle dell'account
 * (pannello delle cartelle, cartelle.js).
 *
 * Parametri POST:
 *   account_id (o "user"), azione = crea | rinomina | elimina,
 *   crea:     nome, genitore (vuoto = primo livello, oppure "imap:<nome>"),
 *   rinomina: cartella ("imap:<nome>"), nome,
 *   elimina:  cartella ("imap:<nome>"), solo se vuota e senza sottocartelle.
 * Le cinque cartelle speciali non si modificano; sulle caselle copiate in
 * locale si rinominano/eliminano solo le cartelle create dalla webmail.
 *
 * Risposta JSON: {success: true, message, id} oppure {success: false, error}.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Metodo non consentito. Usare POST.');
    }

    $accountParam = requestParam(['account_id', 'user']);
    $azione = requestParam(['azione']);
    $cartella = requestParam(['cartella']);
    $genitore = requestParam(['genitore']);
    $nome = (string)($_POST['nome'] ?? '');

    if ($accountParam === '') {
        http_response_code(400);
        throw new Exception('Nessun account selezionato');
    }
    if (!in_array($azione, ['crea', 'rinomina', 'elimina'], true)) {
        http_response_code(400);
        throw new Exception('Azione non valida');
    }
    foreach ($azione === 'crea' ? [$genitore] : [$cartella] as $id) {
        if (($azione !== 'crea' || $id !== '') && !MailboxService::isFolderParam($id)) {
            http_response_code(400);
            throw new Exception('Cartella non valida');
        }
    }

    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        http_response_code(403);
        throw new Exception('Account non valido o non autorizzato');
    }

    $nomeMostrato = trim(preg_replace('/\s+/u', ' ', $nome));
    switch ($azione) {
        case 'crea':
            $id = $mailbox->createFolder($genitore, $nome);
            $messaggio = "Cartella \"{$nomeMostrato}\" creata";
            break;
        case 'rinomina':
            $id = $mailbox->renameFolder($cartella, $nome);
            $messaggio = "Cartella rinominata in \"{$nomeMostrato}\"";
            break;
        default:
            $mailbox->deleteFolder($cartella);
            $id = null;
            $messaggio = 'Cartella eliminata';
    }
    $mailbox->close();

    echo json_encode(['success' => true, 'message' => $messaggio, 'id' => $id], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code($e instanceof ImapException ? 502 : ($e instanceof Exception ? 400 : 500));
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
