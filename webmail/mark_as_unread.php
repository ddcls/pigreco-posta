<?php
/**
 * mark_as_unread.php - Segna un'email come non letta (toglie il flag \Seen via IMAP).
 *
 * Parametri POST: account_id (o "user"), file (o "uid"), folder.
 */

require_once __DIR__ . '/lib/mail_action.php';

handleMailAction(function (MailboxService $mailbox, $folder, $uid) {
    $mailbox->setSeen($folder, $uid, false);
    return 'Email contrassegnata come non letta';
});
