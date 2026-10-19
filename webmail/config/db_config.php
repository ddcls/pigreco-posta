<?php
/**
 * Configurazione database MySQL
 * Le credenziali vivono in config/config.local.php (non versionato), mai in questo file.
 */

require_once __DIR__ . '/env_loader.php';

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_USER', env('DB_USER'));
define('DB_PASS', env('DB_PASS'));
define('DB_NAME', env('DB_NAME', 'webmail_digitale'));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// Fuso orario dell'applicazione (date delle email, log): non dipende dal php.ini del server
date_default_timezone_set(env('APP_TIMEZONE', 'Europe/Rome'));

/**
 * Connessione sicura al database
 * @return mysqli|false Oggetto connessione o false in caso di errore
 */
function getDBConnection() {
    static $conn = null;
    
    if ($conn === null) {
        // Da PHP 8.1, mysqli lancia un'eccezione sugli errori invece di
        // impostare solo connect_error: qui sotto vogliamo il comportamento
        // "return false" storico, gestito da chi chiama questa funzione.
        mysqli_report(MYSQLI_REPORT_OFF);

        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            return false;
        }
        
        $conn->set_charset(DB_CHARSET);
        
        // Abilita modalità strict per sicurezza
        $conn->query("SET sql_mode = 'STRICT_ALL_TABLES'");
    }
    
    return $conn;
}

/**
 * Log accessi sicuro
 * @param string $username Username tentato
 * @param bool $success Esito accesso
 * @param string $action Azione (login, logout, failed)
 * @param string $ip IP address (opzionale)
 */
function logAccess($username, $success, $action = 'login', $ip = null) {
    $conn = getDBConnection();
    if (!$conn) return false;
    
    // Sanitizza input
    $username = $conn->real_escape_string(substr($username, 0, 50));
    $action = $conn->real_escape_string(substr($action, 0, 50));
    $ip = $ip ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip = $conn->real_escape_string(substr($ip, 0, 45));
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $userAgent = $conn->real_escape_string(substr($userAgent, 0, 255));
    
    $query = "INSERT INTO access_log (username, ip_address, user_agent, success, action) 
              VALUES ('$username', '$ip', '$userAgent', " . ($success ? '1' : '0') . ", '$action')";
    
    return $conn->query($query);
}

/**
 * Verifica se utente è admin
 * @return bool
 */
function isAdmin() {
    return isset($_SESSION['tipo_user']) && $_SESSION['tipo_user'] === 'admin';
}
?>
