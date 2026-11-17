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

-- Firme delle email (lib/FirmeService.php, firme.php, firme.js): ogni casella
-- ha le sue firme, che tutti gli utenti che usano la casella vedono, usano e
-- modificano; la casella ne sceglie una per le email nuove e una per le
-- risposte e gli inoltri (NULL = nessuna).
-- Importa con: mysql -u root -p webmail_digitale < migrations/007_firme.sql
-- Nel container docker "webmail" viene applicata da sola all'avvio.

CREATE TABLE IF NOT EXISTS `firme` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `tipo` enum('dati','testo','html') NOT NULL DEFAULT 'dati' COMMENT 'come si modifica: dai dati, testo formattato, sorgente HTML',
  `html` mediumtext NOT NULL COMMENT 'HTML già pulito da FirmeService::pulisciHtml(), al massimo 64 KB',
  `dati` text DEFAULT NULL COMMENT 'JSON dei dati da cui è composta (tipo dati)',
  `creato_da` int(11) DEFAULT NULL,
  `modificato_da` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_firme_nome` (`account_id`, `nome`),
  CONSTRAINT `fk_firme_account` FOREIGN KEY (`account_id`) REFERENCES `mail_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_firme_creato` FOREIGN KEY (`creato_da`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_firme_modificato` FOREIGN KEY (`modificato_da`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Immagini delle firme (logo): partono dentro l'email (cid:), non come link
CREATE TABLE IF NOT EXISTS `firme_immagini` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firma_id` int(11) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `mime` varchar(30) NOT NULL COMMENT 'image/png, image/jpeg o image/gif, dai primi byte',
  `larghezza` int(11) NOT NULL,
  `altezza` int(11) NOT NULL,
  `dati` mediumblob NOT NULL COMMENT 'al massimo 256 KB',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_firma` (`firma_id`),
  CONSTRAINT `fk_firme_immagini_firma` FOREIGN KEY (`firma_id`) REFERENCES `firme` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `mail_accounts`
  ADD COLUMN `firma_nuove_id` int(11) DEFAULT NULL COMMENT 'firma delle email nuove (firme.id)',
  ADD COLUMN `firma_risposte_id` int(11) DEFAULT NULL COMMENT 'firma di risposte e inoltri (firme.id)',
  ADD CONSTRAINT `fk_mail_accounts_firma_nuove` FOREIGN KEY (`firma_nuove_id`) REFERENCES `firme` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_mail_accounts_firma_risposte` FOREIGN KEY (`firma_risposte_id`) REFERENCES `firme` (`id`) ON DELETE SET NULL;
