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
 * get_all_new_emails.php
 * Email non lette (Inbox) di tutti gli account email visibili all'utente
 * loggato, lette via IMAP.
 *
 * Restituisce: JSON con le 100 email non lette più recenti
 * ("user" = id account, "account_name" = nome da mostrare, "file" = UID).
 * Un account irraggiungibile non blocca gli altri: finisce in "errors".
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';

header('Content-Type: application/json; charset=utf-8');

const NEW_EMAILS_LIMIT = 100;

try {
    $manager = new MailAccountManager();
    $accounts = isAdmin()
        ? array_filter($manager->listAccounts(), fn($a) => $a['attivo'])
        : $manager->listAccountsForUser((int)($_SESSION['user_id'] ?? 0));

    $emails = [];
    $errors = [];
    $total = 0;

    foreach ($accounts as $account) {
        try {
            $mailbox = new MailboxService($manager->getAccountWithCredentials($account['id']));
            $unseen = $mailbox->unseenInbox(NEW_EMAILS_LIMIT);
            $mailbox->close();
        } catch (Throwable $e) {
            $errors[] = ['account' => $account['nome_visualizzato'], 'error' => $e->getMessage()];
            continue;
        }

        $total += $unseen['total'];
        foreach ($unseen['emails'] as $email) {
            $emails[] = [
                'user'         => (string)$account['id'],
                'account_name' => $account['nome_visualizzato'],
                'sender'       => $email['sender'] ?: 'Sconosciuto',
                'subject'      => $email['subject'] ?: '(nessun oggetto)',
                'date'         => $email['date'],
                'file'         => $email['file'],
                '_ts'          => @strtotime($email['date_raw']) ?: 0,
            ];
        }
    }

    // Più recenti prima, su tutti gli account insieme
    usort($emails, fn($a, $b) => $b['_ts'] <=> $a['_ts']);
    $emails = array_slice($emails, 0, NEW_EMAILS_LIMIT);
    foreach ($emails as &$email) {
        unset($email['_ts']);
    }
    unset($email);

    echo json_encode([
        'emails'    => $emails,
        'total'     => $total,
        'truncated' => $total > count($emails),
        'errors'    => $errors,
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['emails' => [], 'total' => 0, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
