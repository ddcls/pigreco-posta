<?php
/**
 * delete_new_email.php - "Elimina" un'email dal pannello delle non lette.
 *
 * Con IMAP l'email viene spostata nel Cestino dell'account (come il pulsante
 * Elimina del dettaglio), così resta recuperabile; prima il file Maildir
 * veniva cancellato definitivamente.
 *
 * Parametri POST: account_id (o "user"), file (o "uid"); cartella: inbox.
 */

require_once __DIR__ . '/lib/mail_action.php';

handleMailAction(function (MailboxService $mailbox, $folder, $uid) {
    $mailbox->move('inbox', $uid, 'trash');
    return 'Email spostata nel Cestino';
});
