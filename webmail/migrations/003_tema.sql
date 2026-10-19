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
