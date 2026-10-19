-- PiGreco Posta
-- Copyright (C) 2026 Criscione Enzo
-- SPDX-License-Identifier: AGPL-3.0-or-later
--
-- Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
-- secondo i termini della GNU Affero General Public License come pubblicata
-- dalla Free Software Foundation, versione 3 della licenza o (a tua scelta)
-- qualsiasi versione successiva.
--
-- Questo programma è distribuito nella speranza che sia utile, ma SENZA
-- ALCUNA GARANZIA; senza neppure la garanzia implicita di COMMERCIABILITÀ
-- o di IDONEITÀ PER UN PARTICOLARE SCOPO. Vedi la GNU Affero General
-- Public License per i dettagli.
--
-- Dovresti aver ricevuto una copia della GNU Affero General Public License
-- insieme a questo programma (file LICENSE). In caso contrario, vedi
-- <https://www.gnu.org/licenses/>.

-- Migrazione 001: account email IMAP (Dovecot) + SMTP gestiti dall'amministratore.
-- Importa con: mysql -u root -p webmail_digitale < migrations/001_mail_accounts.sql
--
-- Ogni account punta a un utente Linux servito da Dovecot (IMAP, porta 143
-- con STARTTLS) e ha il proprio server SMTP per l'invio. La visibilità è
-- "tutti" (ogni utente della webmail lo vede) oppure "selezionati" (solo gli
-- utenti elencati in mail_account_users).

CREATE TABLE IF NOT EXISTS `mail_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome_visualizzato` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `societa` varchar(150) NOT NULL DEFAULT '',
  `imap_host` varchar(150) NOT NULL,
  `imap_port` int(11) NOT NULL DEFAULT 143,
  `imap_security` enum('starttls','ssl','none') NOT NULL DEFAULT 'starttls',
  `imap_username` varchar(150) NOT NULL COMMENT 'Utente Linux servito da Dovecot',
  `imap_password` text NOT NULL COMMENT 'Cifrata AES-256-GCM con MAIL_ENCRYPTION_KEY (lib/Crypto.php)',
  `smtp_host` varchar(150) NOT NULL,
  `smtp_port` int(11) NOT NULL DEFAULT 587,
  `smtp_encryption` enum('tls','ssl','none') NOT NULL DEFAULT 'tls',
  `smtp_username` varchar(150) NOT NULL,
  `smtp_password` text NOT NULL COMMENT 'Cifrata AES-256-GCM con MAIL_ENCRYPTION_KEY (lib/Crypto.php)',
  `visibilita` enum('tutti','selezionati') NOT NULL DEFAULT 'tutti',
  `attivo` tinyint(1) NOT NULL DEFAULT 1,
  `last_check` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `mail_account_users` (
  `account_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`account_id`, `user_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_mau_account` FOREIGN KEY (`account_id`) REFERENCES `mail_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mau_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
