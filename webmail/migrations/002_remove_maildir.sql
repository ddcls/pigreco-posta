-- Migrazione 002: rimozione del vecchio modello Maildir.
-- Importa con: mysql -u root -p webmail_digitale < migrations/002_remove_maildir.sql
--
-- PRIMA di eseguirla, importa le vecchie identità SMTP come account email:
--   php initializa/import_identities.php            (anteprima)
--   php initializa/import_identities.php --esegui   (importazione)
-- Questa migrazione elimina la tabella "identities" e i dati che contiene.

DROP TABLE IF EXISTS `identities`;

-- L'utente Linux associato all'utente della webmail non serve più: le
-- caselle si leggono dagli account email (tabella mail_accounts).
ALTER TABLE `users` DROP COLUMN `system_user`;
