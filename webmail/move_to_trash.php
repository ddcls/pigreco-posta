<?php
/**
 * move_to_trash.php - Sposta un'email nel Cestino (UID MOVE via IMAP).
 * Se la cartella Trash non esiste sul server viene creata.
 *
 * Parametri POST: account_id (o "user"), file (o "uid"), folder (cartella di origine).
 */

require_once __DIR__ . '/lib/mail_action.php';

handleMailAction(function (MailboxService $mailbox, $folder, $uid) {
    if ($folder === 'trash') {
        throw new Exception("L'email è già nel Cestino");
    }
    $mailbox->move($folder, $uid, 'trash');
    return 'Email spostata nel Cestino';
});
