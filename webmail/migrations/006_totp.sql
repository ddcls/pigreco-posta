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

-- Secondo fattore (TOTP, app Authenticator) per gli accessi da fuori ufficio,
-- vedi lib/totp.php. Applicata dall'entrypoint del container se manca la
-- colonna users.totp_segreto.

ALTER TABLE `users`
  ADD COLUMN `totp_segreto` varchar(255) DEFAULT NULL COMMENT 'Chiave TOTP cifrata (lib/Crypto.php); NULL = da attivare',
  ADD COLUMN `totp_attivo_dal` datetime DEFAULT NULL,
  ADD COLUMN `totp_ultimo_passo` bigint DEFAULT NULL COMMENT 'Ultimo intervallo di 30 s usato: lo stesso codice non vale due volte';

-- "Ricorda questo dispositivo": nel browser resta un token casuale, qui solo
-- il suo SHA-256
CREATE TABLE IF NOT EXISTS `totp_dispositivi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `scadenza` datetime NOT NULL,
  `creato` datetime DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_totp_dispositivi_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
