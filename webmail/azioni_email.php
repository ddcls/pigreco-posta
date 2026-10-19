<?php
/**
 * azioni_email.php - Azioni su più email insieme (selezione multipla
 * dell'elenco, selezione.js) e "Sposta in…" della singola email.
 *
 * Parametri POST:
 *   account_id (o "user"), folder (cartella di origine),
 *   uids         UID separati da virgola,
 *   azione       sposta | cestino | elimina | letta | non_letta | importante | non_importante,
 *   destinazione cartella di arrivo (solo per "sposta": logica o "imap:<nome>").
 * "elimina" (cancellazione definitiva) è ammessa solo dal Cestino.
 *
 * Risposta JSON: {success: true, message, quante} oppure {success: false, error}.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';

const MASSIMO_EMAIL = 5000;

header('Content-Type: application/json; charset=utf-8');

/** "1 email" / "N email" con il participio giusto. */
function quanteEmail($n, $singolare, $plurale) {
    return $n === 1 ? "1 email {$singolare}" : "{$n} email {$plurale}";
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Metodo non consentito. Usare POST.');
    }

    $accountParam = requestParam(['account_id', 'user']);
    $folder = requestParam(['folder'], 'inbox');
    $azione = requestParam(['azione']);
    $destinazione = requestParam(['destinazione']);
    $uids = array_values(array_filter(array_map('intval', explode(',', requestParam(['uids']))),
        function ($u) { return $u > 0; }));

    if ($accountParam === '' || !$uids) {
        http_response_code(400);
        throw new Exception('Account o email non specificati');
    }
    if (count($uids) > MASSIMO_EMAIL) {
        http_response_code(400);
        throw new Exception('Troppe email selezionate insieme (al massimo ' . MASSIMO_EMAIL . ')');
    }
    if (!MailboxService::isFolderParam($folder)) {
        http_response_code(400);
        throw new Exception('Cartella non valida');
    }
    if (!in_array($azione, ['sposta', 'cestino', 'elimina', 'letta', 'non_letta', 'importante', 'non_importante'], true)) {
        http_response_code(400);
        throw new Exception('Azione non valida');
    }
    if ($azione === 'sposta' && !MailboxService::isFolderParam($destinazione)) {
        http_response_code(400);
        throw new Exception('Cartella di destinazione non valida');
    }
    if ($azione === 'cestino' && $folder === 'trash') {
        http_response_code(400);
        throw new Exception('Le email sono già nel Cestino');
    }
    if ($azione === 'elimina' && $folder !== 'trash') {
        http_response_code(400);
        throw new Exception('La cancellazione definitiva si fa solo dal Cestino');
    }

    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        http_response_code(403);
        throw new Exception('Account non valido o non autorizzato');
    }

    switch ($azione) {
        case 'sposta':
            $n = $mailbox->moveMany($folder, $uids, $destinazione);
            $messaggio = quanteEmail($n, 'spostata', 'spostate');
            break;
        case 'cestino':
            $n = $mailbox->moveMany($folder, $uids, 'trash');
            $messaggio = quanteEmail($n, 'spostata', 'spostate') . ' nel Cestino';
            break;
        case 'elimina':
            $n = $mailbox->deleteMany($folder, $uids);
            $messaggio = quanteEmail($n, 'cancellata', 'cancellate') . ' definitivamente';
            break;
        case 'importante':
        case 'non_importante':
            $n = $mailbox->setFlaggedMany($folder, $uids, $azione === 'importante');
            $messaggio = $azione === 'importante'
                ? quanteEmail($n, 'segnata come importante', 'segnate come importanti')
                : quanteEmail($n, 'tolta dalle importanti', 'tolte dalle importanti');
            break;
        default:
            $n = $mailbox->setSeenMany($folder, $uids, $azione === 'letta');
            $stato = $azione === 'letta' ? ['letta', 'lette'] : ['non letta', 'non lette'];
            $messaggio = quanteEmail($n, "segnata come {$stato[0]}", "segnate come {$stato[1]}");
    }
    $mailbox->close();

    if ($n < count($uids)) {
        $messaggio .= ' (' . (count($uids) - $n) . ' non più presenti nella cartella)';
    }
    echo json_encode(['success' => true, 'message' => $messaggio, 'quante' => $n], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code($e instanceof ImapException ? 502 : ($e instanceof Exception ? 400 : 500));
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
