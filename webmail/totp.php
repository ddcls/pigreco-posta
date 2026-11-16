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
 * Secondo passo dell'accesso da fuori ufficio (lib/totp.php): dopo la
 * password giusta (login.php mette in sessione "totp_attesa") si chiede il
 * codice a 6 cifre dell'app Authenticator. La prima volta la pagina mostra il
 * QR da inquadrare e la chiave da scrivere a mano; la chiave si salva solo
 * dopo un codice giusto, così chi non finisce l'attivazione la rifà da capo.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/lib/session.php';
avviaSessione();
require_once __DIR__ . '/lib/tema.php';
require_once __DIR__ . '/lib/info.php';
require_once __DIR__ . '/lib/accesso.php';
require_once __DIR__ . '/lib/totp.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$attesa = $_SESSION['totp_attesa'] ?? null;
if (isset($_GET['annulla']) || !is_array($attesa) || time() - (int)($attesa['dal'] ?? 0) > TOTP_ATTESA_MAX) {
    $scaduto = is_array($attesa) && !isset($_GET['annulla']);
    unset($_SESSION['totp_attesa'], $_SESSION['totp_nuovo']);
    header('Location: login.php' . ($scaduto ? '?totp_scaduto=1' : ''));
    exit;
}

$userId = (int)$attesa['id'];
$username = (string)$attesa['username'];
$error = '';
$conn = getDBConnection();

try {
    $segreto = $conn ? totpSegretoUtente($conn, $userId) : '';
} catch (Throwable $e) {
    error_log('totp.php: ' . $e->getMessage());
    $segreto = '';
    $error = 'Non riesco a leggere la chiave del secondo fattore: chiedi all\'amministratore di azzerarla';
}
$nuovo = $segreto === '';
if ($nuovo) {
    if (empty($_SESSION['totp_nuovo'])) {
        $_SESSION['totp_nuovo'] = totpNuovoSegreto();
    }
    $segreto = $_SESSION['totp_nuovo'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn && $error === '') {
    if (richiestaDaAltroSito()) {
        $error = 'Richiesta non valida: ricarica la pagina e riprova';
    } elseif (troppiTentativiFalliti($username)) {
        $error = 'Troppi tentativi falliti: riprova tra qualche minuto';
        logAccess($username, false, 'blocked');
    } elseif (totpControlla($conn, $userId, $segreto, $_POST['codice'] ?? '')) {
        if ($nuovo) {
            totpSalvaSegreto($conn, $userId, $segreto);
        }
        if (!empty($_POST['ricorda'])) {
            ricordaDispositivo($conn, $userId);
        }
        completaLogin($conn, ['id' => $userId, 'username' => $username, 'tipo_user' => $attesa['tipo_user']],
                      $nuovo ? 'login_totp_attivato' : 'login_totp');
    } else {
        $error = 'Codice non valido: scrivi quello che l\'app mostra adesso';
        logAccess($username, false, 'failed_totp');
    }
}

$indirizzo = totpIndirizzo($segreto, $username);
$chiaveLeggibile = trim(chunk_split($segreto, 4, ' '));
?>
<!DOCTYPE html>
<html lang="it"<?php echo attributoTema(temaPredefinito()); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Codice di verifica - PiGreco Posta</title>
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
            <h1><i class="fas fa-shield-halved"></i> Codice di verifica</h1>
            <p><?php echo $nuovo ? 'Attiva il secondo fattore per ' : 'Accesso da fuori ufficio: '; ?>
               <strong><?php echo htmlspecialchars($username); ?></strong></p>
        </div>
        <div class="login-corpo">

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($nuovo): ?>
            <ol class="totp-passi">
                <li>Sul telefono installa <b>Google Authenticator</b> o <b>Microsoft Authenticator</b>.</li>
                <li>Nell'app tocca <b>+</b> e <b>Scansiona codice QR</b>, poi inquadra questo QR.</li>
                <li>Scrivi qui sotto il numero di 6 cifre che compare nell'app.</li>
            </ol>
            <div class="totp-qr" id="totpQr" data-indirizzo="<?php echo htmlspecialchars($indirizzo); ?>"></div>
            <a class="totp-app" href="<?php echo htmlspecialchars($indirizzo); ?>">
                <i class="fas fa-mobile-screen"></i> Sei sul telefono? Apri nell'app Authenticator
            </a>
            <div class="form-group">
                <label>Oppure scrivi questa chiave nell'app ("Inserisci chiave di configurazione")</label>
                <div class="totp-chiave"><?php echo htmlspecialchars($chiaveLeggibile); ?></div>
            </div>
        <?php else: ?>
            <p class="totp-passi" style="margin-left:0">Apri l'app <b>Authenticator</b> sul telefono e scrivi il numero
               di 6 cifre della riga <b><?php echo htmlspecialchars((string)env('WEBMAIL_TOTP_NOME', 'PiGreco Posta')); ?></b>.</p>
        <?php endif; ?>

        <form method="POST" action="totp.php" id="totpForm" autocomplete="off">
            <div class="form-group">
                <label for="codice">Codice dell'app</label>
                <input type="text" id="codice" name="codice" class="totp-codice" required autofocus
                       inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code">
            </div>
            <label class="form-check">
                <input type="checkbox" name="ricorda" value="1">
                Ricorda questo dispositivo per <?php echo TOTP_GIORNI_DISPOSITIVO; ?> giorni (solo sul tuo telefono o PC)
            </label>
            <button type="submit" class="btn-login" id="submitBtn">
                <i class="fas fa-check"></i> <?php echo $nuovo ? 'Attiva ed entra' : 'Entra'; ?>
            </button>
        </form>
        <a class="login-annulla" href="totp.php?annulla=1">Annulla e torna all'accesso</a>
        </div>

        <div class="login-piede">
            <p>PiGreco Posta <?php echo htmlspecialchars(PIGRECO_POSTA_VERSIONE); ?> · © 2026 <?php echo htmlspecialchars(PIGRECO_POSTA_AUTORE); ?> ·
               software libero <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener noreferrer">AGPL-3.0</a>, senza garanzia ·
               <a href="<?php echo htmlspecialchars(PIGRECO_POSTA_SORGENTE); ?>" target="_blank" rel="noopener noreferrer">codice sorgente</a></p>
        </div>
    </div>
    </div>

    <script src="vendor/qrcode-generator/qrcode.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const riquadro = document.getElementById('totpQr');
            if (riquadro && typeof qrcode === 'function') {
                const qr = qrcode(0, 'M');
                qr.addData(riquadro.dataset.indirizzo);
                qr.make();
                riquadro.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
            }
            const form = document.getElementById('totpForm');
            const submitBtn = document.getElementById('submitBtn');
            form.addEventListener('submit', function() {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Controllo...';
            });
        });
    </script>
</body>
</html>
