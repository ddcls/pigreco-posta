<?php
/**
 * restore_from_trash.php - Ripristina un'email dal Cestino alla Inbox (UID MOVE via IMAP).
 *
 * Parametri POST: account_id (o "user"), file (o "uid").
 */

require_once __DIR__ . '/lib/mail_action.php';

handleMailAction(function (MailboxService $mailbox, $folder, $uid) {
    $mailbox->move('trash', $uid, 'inbox');
    return 'Email ripristinata nella cartella Inbox';
});
