<?php
/**
 * move_email_to_cur.php - Segna un'email come letta (flag \Seen via IMAP).
 *
 * Con IMAP l'apertura del dettaglio la segna già come letta; l'endpoint
 * resta per compatibilità con il nome usato dal Maildir (new -> cur).
 *
 * Parametri POST: account_id (o "user"), file (o "uid"), folder.
 */

require_once __DIR__ . '/lib/mail_action.php';

handleMailAction(function (MailboxService $mailbox, $folder, $uid) {
    $mailbox->setSeen($folder, $uid, true);
    return 'Email contrassegnata come letta';
});
