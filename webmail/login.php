<?php
// DEBUG: Abilita tutti gli errori
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/lib/session.php';
avviaSessione();
require_once __DIR__ . '/lib/tema.php';
// Messaggio di logout
$logoutMessage = '';
if (isset($_GET['logged_out'])) {
    $logoutMessage = 'Logout effettuato con successo. Torna presto!';
}
define('DEBUG_MODE', false); // ✅ CAMBIA QUI: true per debug, false per produzione

// Se già loggato, vai all'index
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// Gestione POST login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Username e password sono obbligatori';
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
                        // Imposta sessione
                        $_SESSION['logged_in'] = true;
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['tipo_user'] = $user['tipo_user'];
                        $_SESSION['login_time'] = time();

                        // Aggiorna ultimo accesso
                        $updateStmt = $conn->prepare("UPDATE users SET ultimo_accesso = NOW() WHERE id = ?");
                        if ($updateStmt) {
                            $updateStmt->bind_param("i", $user['id']);
                            $updateStmt->execute();
                            $updateStmt->close();
                        }

                        // Log accesso
                        logAccess($username, true, 'login');

                        // Redirect
                        header('Location: index.php');
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
    <style>
        /* Colori da temi.css: il tema è quello predefinito scelto dall'amministratore */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-testo);
            font-size: 14px;
            color: var(--testo);
            background: var(--pannello);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .login-barra {
            height: 54px;
            background: linear-gradient(180deg, var(--accento) 0%, var(--accento-scuro) 100%);
        }
        .login-pagina {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px 16px;
        }
        .login-container {
            background: var(--bianco);
            border: 1px solid var(--bordo);
            border-radius: 10px;
            box-shadow: var(--ombra);
            width: 100%;
            max-width: 400px;
            overflow: hidden;
        }
        .login-header {
            padding: 28px 32px 8px;
            text-align: center;
        }
        .login-header h1 {
            font-family: var(--font-titoli);
            font-size: 26px;
            color: var(--testo);
            margin-bottom: 6px;
        }
        .login-header h1 i { color: var(--accento); margin-right: 4px; }
        .login-header p { color: var(--testo-tenue); }
        .login-corpo { padding: 18px 32px 26px; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
            font-size: 13px;
            color: var(--testo-secondario);
        }
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--bordo);
            border-radius: var(--raggio);
            font: inherit;
            color: var(--testo);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--accento);
            box-shadow: 0 0 0 3px var(--selezione);
        }
        .btn-login {
            width: 100%;
            padding: 11px;
            margin-top: 4px;
            background: var(--accento);
            color: #fff;
            border: 0;
            border-radius: var(--raggio);
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-login:hover { background: var(--accento-scuro); }
        .btn-login:disabled { opacity: .7; cursor: wait; }
        .alert {
            padding: 10px 12px;
            border-radius: var(--raggio);
            margin-bottom: 16px;
        }
        .alert-error {
            background: var(--pericolo-chiaro);
            color: var(--pericolo);
            border-left: 3px solid var(--pericolo);
        }
        .login-piede {
            padding: 12px;
            border-top: 1px solid var(--bordo);
            background: var(--sfondo);
            text-align: center;
            color: var(--testo-tenue);
            font-size: 12px;
        }
        .debug-info {
            background: var(--pannello);
            padding: 10px;
            border-radius: var(--raggio);
            margin-bottom: 16px;
            font-size: 12px;
            display: none; /* Nascondi per default, mostra solo in debug */
        }
    </style>
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
        
        <div class="debug-info">
            <strong>Debug Info:</strong><br>
            PHP Version: <?php echo phpversion(); ?><br>
            Session Status: <?php echo session_status(); ?><br>
            Session ID: <?php echo session_id(); ?><br>
            Metodo: <?php echo $_SERVER['REQUEST_METHOD']; ?>
        </div>
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
            <p>© <?php echo date('Y'); ?> PiGreco Posta</p>
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
