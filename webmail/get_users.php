<?php
require_once __DIR__ . '/check_auth.php';
header('Content-Type: application/json; charset=utf-8');

/**
 * get_users.php - Elenco degli account email per la select "Account".
 *
 * Restituisce gli account email (IMAP) che l'utente loggato può consultare:
 * tutti per l'amministratore, altrimenti quelli visibili a tutti o assegnati
 * a lui. Formato: "utente" = id account (valore della select, passato agli
 * endpoint nel parametro "user"), "nickname" = etichetta mostrata.
 */

require_once __DIR__ . '/config/mail_account_manager.php';

try {
    $manager = new MailAccountManager();
    if (isAdmin()) {
        $accounts = array_values(array_filter($manager->listAccounts(), fn($a) => $a['attivo']));
    } else {
        $accounts = $manager->listAccountsForUser((int)($_SESSION['user_id'] ?? 0));
    }

    $users = array_map(function ($a) {
        return [
            'utente'   => (string)$a['id'],
            'nickname' => $a['nome_visualizzato'] . ' <' . $a['email'] . '>',
            'email'    => $a['email'],
            'nome'     => $a['nome_visualizzato'],
        ];
    }, $accounts);

    echo json_encode(['users' => $users], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
