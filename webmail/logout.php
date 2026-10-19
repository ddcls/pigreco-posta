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