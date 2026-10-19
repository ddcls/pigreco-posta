<?php
/**
 * logout.php
 * Distrugge la sessione e reindirizza al login
 */

require_once __DIR__ . '/lib/session.php';
avviaSessione();

// Logga il logout per audit
if (isset($_SESSION['username'])) {
    require_once 'config/db_config.php';
    if (function_exists('logAccess')) {
        logAccess($_SESSION['username'], true, 'logout');
    }
}

// Distrugge tutti i dati della sessione
$_SESSION = array();

// Distrugge il cookie di sessione se esiste
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Distrugge la sessione
session_destroy();

// Reindirizza al login con messaggio di conferma
header('Location: login.php?logged_out=1');
exit;
?>