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

-- Filtri sulle email in arrivo (lib/FiltriService.php, filtri_esegui.php):
-- "se arriva un'email di Tizio, spostala in una cartella / nel Cestino,
-- segnala come letta o importante". Valgono per tutte le caselle (o per una
-- sola, account_id) chiunque li abbia creati; li applica ogni minuto
-- filtri_esegui.php alle email nuove della Posta in arrivo.
-- Importa con: mysql -u root -p webmail_digitale < migrations/005_filtri.sql
-- Nel container docker "webmail" viene applicata da sola all'avvio.

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
