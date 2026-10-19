<?php
/*
 * PiGreco Posta
 * Copyright (C) 2026 Criscione Enzo
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
 * secondo i termini della GNU Affero General Public License come pubblicata
 * dalla Free Software Foundation, versione 3 della licenza o (a tua scelta)
 * qualsiasi versione successiva.
 *
 * Questo programma è distribuito nella speranza che sia utile, ma SENZA
 * ALCUNA GARANZIA; senza neppure la garanzia implicita di COMMERCIABILITÀ
 * o di IDONEITÀ PER UN PARTICOLARE SCOPO. Vedi la GNU Affero General
 * Public License per i dettagli.
 *
 * Dovresti aver ricevuto una copia della GNU Affero General Public License
 * insieme a questo programma (file LICENSE). In caso contrario, vedi
 * <https://www.gnu.org/licenses/>.
 */

/**
 * Script setup iniziale database
 * ESEGUI UNA VOLTA E POI ELIMINA!
 */

// Eseguibile SOLO da riga di comando: esposto via HTTP permetteva a chiunque
// di eseguire query di setup (incluse credenziali hardcoded) senza autenticazione.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Accesso negato: eseguire questo script da riga di comando.');
}

// Configurazione database: MAI hardcoded. Passa le credenziali root come
// variabili d'ambiente solo per l'esecuzione di questo script, es.:
//   DB_ROOT_USER=root DB_ROOT_PASS='...' DB_NEW_USER_PASS='...' php initializa/setup_db.php
$host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_ROOT_USER') ?: 'root';
$db_pass = getenv('DB_ROOT_PASS');
$db_name = getenv('DB_NAME') ?: 'webmail_digitale';
$new_user_pass = getenv('DB_NEW_USER_PASS');

if (!$db_pass || !$new_user_pass) {
    die("Imposta DB_ROOT_PASS e DB_NEW_USER_PASS come variabili d'ambiente prima di eseguire questo script.\n");
}

// Crea database e utente dedicato
$conn = new mysqli($host, $db_user, $db_pass);
if ($conn->connect_error) {
    die("Connessione fallita: " . $conn->connect_error);
}

// Crea database
$conn->query("CREATE DATABASE IF NOT EXISTS $db_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db($db_name);

// Crea tabelle (SQL completo da file precedente)
// ...

// Crea utente dedicato con privilegi limitati (usa ALTER se esiste già,
// così lo script è sicuro da rilanciare per ruotare la password)
$stmt = $conn->prepare("SELECT 1 FROM mysql.user WHERE User = 'webmail_user' AND Host = 'localhost'");
$stmt->execute();
$exists = $stmt->get_result()->num_rows > 0;
$stmt->close();

$escapedPass = $conn->real_escape_string($new_user_pass);
if ($exists) {
    $conn->query("ALTER USER 'webmail_user'@'localhost' IDENTIFIED BY '{$escapedPass}'");
} else {
    $conn->query("CREATE USER 'webmail_user'@'localhost' IDENTIFIED BY '{$escapedPass}'");
}
$conn->query("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$conn->real_escape_string($db_name)}`.* TO 'webmail_user'@'localhost'");
$conn->query("FLUSH PRIVILEGES");

echo "✅ Setup completato! Ora importa schema.sql e compila config/config.local.php con le credenziali.\n";

?>
