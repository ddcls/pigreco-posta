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

-- Cartelle create dalla webmail sulle caselle copiate in locale (copia locale
-- sincronizzata con mbsync): solo queste si possono rinominare o eliminare
-- dalla webmail, perché quelle che vengono dal fornitore verrebbero ricreate
-- da mbsync e riscaricate (vedi MailboxService::folderEditable()).
-- Importa con: mysql -u root -p webmail_digitale < migrations/004_cartelle_create.sql
-- Nel container docker "webmail" viene applicata da sola all'avvio.

CREATE TABLE IF NOT EXISTS `cartelle_create` (
  `account_id` int(11) NOT NULL,
  `nome` varchar(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Nome IMAP grezzo (UTF-7 modificato)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`, `nome`),
  CONSTRAINT `fk_cc_account` FOREIGN KEY (`account_id`) REFERENCES `mail_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
