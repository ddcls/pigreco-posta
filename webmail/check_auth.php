<?php
/**
 * Verifica autenticazione utente e fornisce helper di autorizzazione
 * per gli endpoint che accedono a un account email (IMAP).
 */

require_once __DIR__ . '/lib/session.php';
avviaSessione();
require_once __DIR__ . '/config/db_config.php';

/**
 * Interrompe una richiesta non autenticata. La pagina principale viene
 * rimandata al login; gli endpoint chiamati via fetch ricevono invece un
 * 401 JSON (un redirect relativo da api/ porterebbe a api/login.php, che
 * non esiste, e il frontend vedrebbe solo un errore generico).
 */
function denyUnauthenticated($query = '') {
    if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'index.php') {
        header('Location: login.php' . $query);
    } else {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Sessione scaduta: effettua di nuovo il login',
            'session_expired' => true,
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Verifica sessione attiva
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Salva URL di destinazione per redirect dopo login
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    denyUnauthenticated();
}

// Verifica timeout sessione (30 minuti di inattività)
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > 1800)) {
    session_unset();
    session_destroy();
    denyUnauthenticated('?timeout=1');
}

// Verifica che l'utente esista ancora ed sia attivo (account disattivato
// dopo il login non deve mantenere l'accesso fino allo scadere della sessione)
$conn = getDBConnection();
if ($conn && isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT attivo FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row || !$row['attivo']) {
            session_unset();
            session_destroy();
            denyUnauthenticated('?disabled=1');
        }
    }
}

// Aggiorna timestamp attività
$_SESSION['login_time'] = time();

// Rilascia subito il lock sul file di sessione: PHP lo tiene altrimenti
// aperto per tutta la durata dello script (anche minuti, se questo endpoint
// fa I/O lento come parsing email o scansioni di cartelle) e nel frattempo
// blocca in coda OGNI altra richiesta con lo stesso session id. Aprendo
// più email in sequenza velocemente, o passando da una cartella all'altra,
// le richieste si accumulano una dietro l'altra finché il browser non va
// in timeout ("Failed to fetch") — e restano bloccate anche i click
// successivi, perché anche loro devono aspettare lo stesso lock in coda.
// Nessun endpoint scrive più nella sessione dopo questo punto, quindi è
// sicuro chiuderla qui: i dati restano comunque leggibili da $_SESSION.
session_write_close();

/**
 * Valida che $input sia l'id di un account email attivo che l'utente
 * loggato può usare: l'amministratore li vede tutti, gli altri utenti
 * quelli con visibilità "tutti" o assegnati a loro (protezione IDOR).
 *
 * @param mixed $input Valore grezzo ricevuto da POST/GET
 * @return int|null Id dell'account se autorizzato, altrimenti null
 */
function getAuthorizedAccountId($input) {
    $id = filter_var($input, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        return null;
    }
    require_once __DIR__ . '/config/mail_account_manager.php';
    $manager = new MailAccountManager();
    if (isAdmin()) {
        $account = $manager->getAccount($id);
        return ($account && $account['attivo']) ? $id : null;
    }
    return $manager->userCanAccess($id, (int)($_SESSION['user_id'] ?? 0)) ? $id : null;
}

/**
 * Apre la casella IMAP dell'account richiesto (parametro "account_id",
 * o "user" per compatibilità con il frontend) dopo averne verificato
 * l'autorizzazione.
 * @return MailboxService|null null se l'account non è valido o non autorizzato
 */
function openAuthorizedMailbox($input) {
    $id = getAuthorizedAccountId($input);
    if ($id === null) {
        return null;
    }
    require_once __DIR__ . '/lib/MailboxService.php';
    $manager = new MailAccountManager();
    return new MailboxService($manager->getAccountWithCredentials($id));
}

/** Primo parametro non vuoto tra quelli indicati (POST, poi GET). */
function requestParam(array $names, $default = '') {
    foreach ($names as $name) {
        foreach ([$_POST, $_GET] as $source) {
            if (isset($source[$name]) && is_string($source[$name]) && trim($source[$name]) !== '') {
                return trim($source[$name]);
            }
        }
    }
    return $default;
}
