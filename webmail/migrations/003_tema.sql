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

-- Colore dell'interfaccia (finestra "Aspetto", vedi lib/tema.php e temi.css)
-- Importa con: mysql -u root -p webmail_digitale < migrations/003_tema.sql
-- Nel container docker "webmail" viene applicata da sola all'avvio.

-- Colore scelto da ogni utente (NULL = usa quello predefinito)
ALTER TABLE `users`
  ADD COLUMN `tema` varchar(20) DEFAULT NULL COMMENT 'Colore dell''interfaccia (lib/tema.php)';

-- Impostazioni generali della webmail (es. tema_predefinito)
CREATE TABLE IF NOT EXISTS `impostazioni` (
  `chiave` varchar(50) NOT NULL,
  `valore` varchar(255) NOT NULL,
  PRIMARY KEY (`chiave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
