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

-- Schema del database per PiGreco Posta
-- Importa con: mysql -u root -p db_webmail < schema.sql
-- (dopo aver creato il database, vedi README.md)

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `cognome` varchar(50) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Hash bcrypt (password_hash)',
  `email` varchar(100) NOT NULL,
  `ultimo_accesso` datetime DEFAULT NULL,
  `attivo` tinyint(1) DEFAULT 1,
  `tipo_user` enum('admin','user') DEFAULT 'user',
  `tema` varchar(20) DEFAULT NULL COMMENT 'Colore dell''interfaccia (lib/tema.php)',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `access_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `attempt_time` datetime DEFAULT current_timestamp(),
  `success` tinyint(1) NOT NULL,
  `action` varchar(50) DEFAULT 'login',
  `user_pseudonym` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_time` (`attempt_time`),
  KEY `idx_attempt_time` (`attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Account email IMAP (Dovecot) + SMTP, vedi migrations/001_mail_accounts.sql
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

-- Nessun utente viene creato da questo schema: il primo amministratore va
-- inserito a mano, vedi la sezione "Primo accesso" nel README.

-- Impostazioni generali (es. tema_predefinito), vedi migrations/003_tema.sql
CREATE TABLE IF NOT EXISTS `impostazioni` (
  `chiave` varchar(50) NOT NULL,
  `valore` varchar(255) NOT NULL,
  PRIMARY KEY (`chiave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cartelle create dalla webmail sulle caselle copiate in locale, vedi migrations/004_cartelle_create.sql
CREATE TABLE IF NOT EXISTS `cartelle_create` (
  `account_id` int(11) NOT NULL,
  `nome` varchar(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Nome IMAP grezzo (UTF-7 modificato)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `nome`),
  CONSTRAINT `fk_cc_account` FOREIGN KEY (`account_id`) REFERENCES `mail_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Filtri sulle email in arrivo, vedi migrations/005_filtri.sql
CREATE TABLE IF NOT EXISTS `filtri` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `account_id` int(11) DEFAULT NULL COMMENT 'NULL = tutte le caselle',
  `condizioni` text NOT NULL COMMENT 'JSON: [{campo, operatore, valore}]',
  `tutte` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = tutte le condizioni, 0 = almeno una',
  `sposta` varchar(500) DEFAULT NULL COMMENT 'NULL = resta, trash, junk o cartella:<percorso con " / ">',
  `segna_letta` tinyint(1) NOT NULL DEFAULT 0,
  `segna_importante` tinyint(1) NOT NULL DEFAULT 0,
  `ferma` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'non applicare i filtri successivi',
  `ordine` int(11) NOT NULL DEFAULT 0,
  `attivo` tinyint(1) NOT NULL DEFAULT 1,
  `applica_esistenti` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'richiesto "applica alle email già arrivate"',
  `applicate` int(11) NOT NULL DEFAULT 0 COMMENT 'email filtrate finora',
  `ultima_applicazione` datetime DEFAULT NULL,
  `ultimo_errore` varchar(255) DEFAULT NULL,
  `creato_da` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ordine` (`ordine`),
  CONSTRAINT `fk_filtri_account` FOREIGN KEY (`account_id`) REFERENCES `mail_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_filtri_utente` FOREIGN KEY (`creato_da`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Fin dove sono arrivati i filtri in ogni Posta in arrivo (UID IMAP)
CREATE TABLE IF NOT EXISTS `filtri_stato` (
  `account_id` int(11) NOT NULL,
  `uidvalidity` bigint(20) NOT NULL,
  `ultimo_uid` bigint(20) NOT NULL,
  `controllato` datetime DEFAULT NULL,
  `errore` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`account_id`),
  CONSTRAINT `fk_filtri_stato_account` FOREIGN KEY (`account_id`) REFERENCES `mail_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
