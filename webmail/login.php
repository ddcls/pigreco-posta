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

// Gli errori vanno nel log, mai nella pagina (rivelerebbero percorsi e dettagli)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/lib/session.php';
avviaSessione();
require_once __DIR__ . '/lib/tema.php';
require_once __DIR__ . '/lib/info.php';
require_once __DIR__ . '/lib/accesso.php';
require_once __DIR__ . '/lib/totp.php';
// Messaggio di logout
$logoutMessage = '';
if (isset($_GET['totp_scaduto'])) {
    $error = 'Tempo scaduto per il codice di verifica: rientra con la password';
}
if (isset($_GET['logged_out'])) {
    $logoutMessage = 'Logout effettuato con successo. Torna presto!';
}
define('DEBUG_MODE', false); // ✅ CAMBIA QUI: true per debug, false per produzione

// Se già loggato, vai all'index
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = $error ?? '';
$success = '';

// Gestione POST login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (richiestaDaAltroSito()) {
        $error = 'Richiesta non valida: apri la pagina di accesso e riprova';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username e password sono obbligatori';
    } elseif (troppiTentativiFalliti($username)) {
        $error = 'Troppi tentativi falliti: riprova tra qualche minuto';
        logAccess($username, false, 'blocked');
    } else {
        // Carica configurazione database
        require_once 'config/db_config.php';

        // Connessione database
        $conn = getDBConnection();

        if (!$conn) {
            $error = 'Errore di connessione al database';
        } else {
            // Query utente
            $stmt = $conn->prepare("SELECT id, username, password, attivo, tipo_user FROM users WHERE username = ? LIMIT 1");

            if (!$stmt) {
                $error = 'Errore preparazione query';
            } else {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();

                    if (!$user['attivo']) {
                        $error = 'Account disattivato';
                    } elseif (password_verify($password, $user['password'])) {
                        if (!totpRichiesto()) {
                            completaLogin($conn, $user);
                        }
                        if (dispositivoRiconosciuto($conn, (int)$user['id'])) {
                            completaLogin($conn, $user, 'login_dispositivo');
                        }
                        // Da fuori ufficio: password giusta, manca il codice
                        // dell'app Authenticator (totp.php). Non si è ancora dentro.
                        session_regenerate_id(true);
                        $_SESSION['totp_attesa'] = [
                            'id' => (int)$user['id'], 'username' => $user['username'],
                            'tipo_user' => $user['tipo_user'], 'dal' => time(),
                        ];
                        unset($_SESSION['totp_nuovo']);
                        header('Location: totp.php');
                        exit;
                    } else {
                        $error = 'Credenziali non valide';
                        logAccess($username, false, 'failed_password');
                    }
                } else {
                    $error = 'Credenziali non valide';
                    logAccess($username, false, 'failed_notfound');
                }

                $stmt->close();
            }
            // Niente $conn->close(): la connessione è condivisa
            // (getDBConnection) e serve ancora per il tema della pagina.
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it"<?php echo attributoTema(temaPredefinito()); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PiGreco Posta</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="temi.css?v=1">
    <link rel="stylesheet" href="fontawesome/css/all.min.css">
    <link rel="stylesheet" href="accesso.css?v=1">
</head>
<body>
    <div class="login-barra"></div>
    <div class="login-pagina">
    <div class="login-container">
        <div class="login-header">
            <h1><i class="fas fa-envelope-open-text"></i> PiGreco Posta</h1>
            <p>Accedi al tuo account</p>
        </div>
        <div class="login-corpo">
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($logoutMessage): ?>
            <div class="alert alert-success" style="background: var(--ok-chiaro); color: #1e7a45; border-left: 3px solid var(--ok);">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($logoutMessage); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" id="loginForm">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn-login" id="submitBtn">
                <i class="fas fa-sign-in-alt"></i> Accedi
            </button>
        </form>
        </div>

        <div class="login-piede">
            <p>PiGreco Posta <?php echo htmlspecialchars(PIGRECO_POSTA_VERSIONE); ?> · © 2026 <?php echo htmlspecialchars(PIGRECO_POSTA_AUTORE); ?> ·
               software libero <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener noreferrer">AGPL-3.0</a>, senza garanzia ·
               <a href="<?php echo htmlspecialchars(PIGRECO_POSTA_SORGENTE); ?>" target="_blank" rel="noopener noreferrer">codice sorgente</a></p>
        </div>
    </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const submitBtn = document.getElementById('submitBtn');

            form.addEventListener('submit', function(e) {
                // Disabilita pulsante per evitare doppi click
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Accesso in corso...';
            });
        });
    </script>
</body>
</html>
