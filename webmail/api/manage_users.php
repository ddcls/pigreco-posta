<?php
/**
 * Gestione account di accesso alla webapp (tabella "users").
 * Endpoint API per la sezione "Account" della finestra Impostazioni.
 *
 * Riservato all'amministratore: le password vengono assegnate/cambiate
 * esclusivamente da qui, non esiste un cambio password self-service.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../check_auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accesso riservato all\'amministratore'], JSON_UNESCAPED_UNICODE);
    exit;
}

class UserAccountManager {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
    }

    private function countActiveAdmins($excludeId = null) {
        if ($excludeId !== null) {
            $stmt = $this->conn->prepare("SELECT COUNT(*) c FROM users WHERE tipo_user = 'admin' AND attivo = 1 AND id != ?");
            $stmt->bind_param('i', $excludeId);
        } else {
            $stmt = $this->conn->prepare("SELECT COUNT(*) c FROM users WHERE tipo_user = 'admin' AND attivo = 1");
        }
        $stmt->execute();
        return (int)$stmt->get_result()->fetch_assoc()['c'];
    }

    public function listUsers() {
        $result = $this->conn->query(
            "SELECT id, nome, cognome, username, email, attivo, tipo_user, ultimo_accesso, created_at
             FROM users ORDER BY username ASC"
        );
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $row['attivo'] = (bool)$row['attivo'];
            $users[] = $row;
        }
        return $users;
    }

    public function createUser($data) {
        $nome = trim($data['nome'] ?? '');
        $cognome = trim($data['cognome'] ?? '');
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $tipoUser = ($data['tipo_user'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $attivo = filter_var($data['attivo'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $password = (string)($data['password'] ?? '');

        if ($nome === '' || $cognome === '' || $username === '' || $email === '') {
            return ['success' => false, 'error' => 'Nome, cognome, username ed email sono obbligatori'];
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            return ['success' => false, 'error' => 'Username non valido (usa lettere, numeri, punto, trattino, underscore - minimo 3 caratteri)'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Email non valida'];
        }
        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'La password deve essere di almeno 6 caratteri'];
        }

        $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            return ['success' => false, 'error' => "Username '{$username}' già esistente"];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $this->conn->prepare(
            "INSERT INTO users (nome, cognome, username, password, email, attivo, tipo_user)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->bind_param('sssssis', $nome, $cognome, $username, $hash, $email, $attivo, $tipoUser);

        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Errore creazione account: ' . $stmt->error];
        }

        return ['success' => true, 'message' => 'Account creato con successo', 'id' => $stmt->insert_id];
    }

    public function updateUser($data) {
        $id = intval($data['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Account non specificato'];
        }

        $stmt = $this->conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        if (!$current) {
            return ['success' => false, 'error' => 'Account non trovato'];
        }

        $nome = trim($data['nome'] ?? '');
        $cognome = trim($data['cognome'] ?? '');
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $tipoUser = ($data['tipo_user'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $attivo = filter_var($data['attivo'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        if ($nome === '' || $cognome === '' || $username === '' || $email === '') {
            return ['success' => false, 'error' => 'Nome, cognome, username ed email sono obbligatori'];
        }
        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username)) {
            return ['success' => false, 'error' => 'Username non valido (usa lettere, numeri, punto, trattino, underscore - minimo 3 caratteri)'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Email non valida'];
        }

        $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $stmt->bind_param('si', $username, $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            return ['success' => false, 'error' => "Username '{$username}' già esistente"];
        }

        // Impedisce di restare senza nessun amministratore attivo
        $wasActiveAdmin = ($current['tipo_user'] === 'admin' && (int)$current['attivo'] === 1);
        $willBeActiveAdmin = ($tipoUser === 'admin' && $attivo === 1);
        if ($wasActiveAdmin && !$willBeActiveAdmin && $this->countActiveAdmins($id) === 0) {
            return ['success' => false, 'error' => 'Impossibile: deve restare almeno un amministratore attivo'];
        }

        $stmt = $this->conn->prepare(
            "UPDATE users SET nome=?, cognome=?, username=?, email=?, tipo_user=?, attivo=? WHERE id=?"
        );
        $stmt->bind_param('sssssii', $nome, $cognome, $username, $email, $tipoUser, $attivo, $id);

        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Errore aggiornamento account: ' . $stmt->error];
        }

        return ['success' => true, 'message' => 'Account aggiornato con successo'];
    }

    public function setPassword($id, $password) {
        $id = intval($id);
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Account non specificato'];
        }
        if (strlen($password) < 6) {
            return ['success' => false, 'error' => 'La password deve essere di almeno 6 caratteri'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param('si', $hash, $id);

        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Errore cambio password: ' . $stmt->error];
        }
        if ($stmt->affected_rows === 0) {
            return ['success' => false, 'error' => 'Account non trovato'];
        }

        return ['success' => true, 'message' => 'Password aggiornata con successo'];
    }

    public function deleteUser($id) {
        $id = intval($id);
        if ($id <= 0) {
            return ['success' => false, 'error' => 'Account non specificato'];
        }

        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            return ['success' => false, 'error' => 'Non puoi eliminare il tuo account mentre sei collegato'];
        }

        $stmt = $this->conn->prepare("SELECT tipo_user, attivo FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        if (!$current) {
            return ['success' => false, 'error' => 'Account non trovato'];
        }

        if ($current['tipo_user'] === 'admin' && (int)$current['attivo'] === 1 && $this->countActiveAdmins($id) === 0) {
            return ['success' => false, 'error' => 'Impossibile: deve restare almeno un amministratore attivo'];
        }

        $stmt = $this->conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            return ['success' => true, 'message' => 'Account eliminato con successo'];
        }
        return ['success' => false, 'error' => 'Errore eliminazione account'];
    }
}

// ==================== ELABORAZIONE RICHIESTA ====================
try {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('Connessione al database non disponibile');
    }

    $manager = new UserAccountManager($conn);
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        case 'list':
            echo json_encode(['success' => true, 'users' => $manager->listUsers()], JSON_UNESCAPED_UNICODE);
            break;

        case 'create':
            $result = $manager->createUser($_POST);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'update':
            $result = $manager->updateUser($_POST);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'set_password':
            $id = $_POST['id'] ?? 0;
            $password = $_POST['password'] ?? '';
            $result = $manager->setPassword($id, $password);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        case 'delete':
            $id = $_POST['id'] ?? 0;
            $result = $manager->deleteUser($id);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception('Azione non valida: ' . $action);
    }

    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
