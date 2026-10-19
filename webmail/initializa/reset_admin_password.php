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
 * Reimposta la password di un utente della webmail (di default "admin").
 *
 * Eseguibile SOLO da riga di comando: esposto via HTTP permetterebbe a
 * chiunque di cambiare la password senza autenticazione.
 *
 *   php initializa/reset_admin_password.php            → utente "admin"
 *   php initializa/reset_admin_password.php mrossi     → utente indicato
 *
 * La nuova password viene chiesta da tastiera, senza mostrarla a video,
 * e non è mai scritta in questo file.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Accesso negato: eseguire questo script da riga di comando.');
}

require_once __DIR__ . '/../config/db_config.php';

$username = $argv[1] ?? 'admin';

function promptHidden($label) {
    echo $label;
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string)fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo');
        echo "\n";
    }
    return $value;
}

$conn = getDBConnection();
if (!$conn) {
    fwrite(STDERR, "Connessione al database non disponibile\n");
    exit(1);
}

$stmt = $conn->prepare("SELECT id, attivo FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param('s', $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    fwrite(STDERR, "Utente '{$username}' non trovato\n");
    exit(1);
}

$password = promptHidden("Nuova password per '{$username}': ");
if (strlen($password) < 8) {
    fwrite(STDERR, "La password deve essere di almeno 8 caratteri\n");
    exit(1);
}
if (promptHidden('Ripeti la password: ') !== $password) {
    fwrite(STDERR, "Le password non coincidono\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param('si', $hash, $user['id']);
if (!$stmt->execute()) {
    fwrite(STDERR, "Errore aggiornamento: {$stmt->error}\n");
    exit(1);
}

echo "Password di '{$username}' aggiornata." . ($user['attivo'] ? '' : " ATTENZIONE: l'utente è disattivato.") . "\n";
