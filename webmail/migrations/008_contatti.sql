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

-- Rubrica dei contatti della webmail (lib/ContattiService.php, contatti.php,
-- contatti.js): una sola, in comune per tutti gli utenti, che la vedono e la
-- modificano tutti. Si usa quando la webmail non è collegata a PiGreco
-- Archivio; collegata, la rubrica è l'agenda dell'archivio.
-- Importa con: mysql -u root -p webmail_digitale < migrations/008_contatti.sql
-- Nel container docker "webmail" viene applicata da sola all'avvio.

CREATE TABLE IF NOT EXISTS `contatti` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL DEFAULT '',
  `cognome` varchar(100) NOT NULL DEFAULT '',
  `azienda` varchar(150) NOT NULL DEFAULT '',
  `categoria` varchar(20) NOT NULL DEFAULT 'Altro' COMMENT 'Cliente, Fornitore, Collega, Partner, Altro',
  `email` varchar(150) NOT NULL DEFAULT '',
  `telefono` varchar(50) NOT NULL DEFAULT '',
  `sito_web` varchar(500) NOT NULL DEFAULT '',
  `note` text DEFAULT NULL,
  `creato_da` int(11) DEFAULT NULL,
  `modificato_da` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contatti_email` (`email`),
  CONSTRAINT `fk_contatti_creato` FOREIGN KEY (`creato_da`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_contatti_modificato` FOREIGN KEY (`modificato_da`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
