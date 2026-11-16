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
 * Passi comuni dell'accesso alla webmail (login.php e totp.php): freno ai
 * tentativi a caso e ingresso vero e proprio, dopo la password e, quando
 * serve, il codice dell'app Authenticator (lib/totp.php).
 */

require_once __DIR__ . '/../config/db_config.php';

/**
 * Freno ai tentativi a caso (access_log, scritto da logAccess): accesso
 * rifiutato dopo 10 tentativi falliti in 15 minuti dallo stesso indirizzo IP,
 * o dopo 30 sullo stesso utente da indirizzi diversi. Valgono sia le password
 * sia i codici TOTP sbagliati (action "failed_..."). Soglia per utente più
 * alta: chi sbaglia apposta non deve poter chiudere fuori il vero utente. I
 * tentativi rifiutati ("blocked") non allungano il blocco. L'IP è quello vero
 * anche dietro un proxy (mod_remoteip, docker/webmail/000-default.conf).
 */
function troppiTentativiFalliti($username) {
    $conn = getDBConnection();
    if (!$conn) {
        return false;
    }
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
    $utente = substr($username, 0, 50);
    $stmt = $conn->prepare("SELECT
            SUM(ip_address = ?) AS da_ip,
            SUM(username = ?) AS su_utente
        FROM access_log
        WHERE success = 0 AND action LIKE 'failed%'
          AND attempt_time > NOW() - INTERVAL 15 MINUTE");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $ip, $utente);
    $stmt->execute();
    $riga = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($riga['da_ip'] ?? 0) >= 10 || (int)($riga['su_utente'] ?? 0) >= 30;
}

/**
 * L'utente entra: nuova sessione, ultimo accesso, registro. $user = riga di
 * users con id, username, tipo_user. Non ritorna (redirect all'index).
 */
function completaLogin($conn, array $user, $azione = 'login') {
    // Nuovo id di sessione: quello di prima del login non vale più
    session_regenerate_id(true);
    unset($_SESSION['totp_attesa'], $_SESSION['totp_nuovo']);
    $_SESSION['logged_in'] = true;
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['tipo_user'] = $user['tipo_user'];
    $_SESSION['login_time'] = time();

    $stmt = $conn->prepare("UPDATE users SET ultimo_accesso = NOW() WHERE id = ?");
    if ($stmt) {
        $id = (int)$user['id'];
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    logAccess($user['username'], true, $azione);
    header('Location: index.php');
    exit;
}
