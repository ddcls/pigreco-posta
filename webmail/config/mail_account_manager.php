<?php
/**
 * Gestione degli account email (tabella "mail_accounts").
 *
 * Ogni account ha un IMAP (Dovecot, utente Linux) e un SMTP (provider
 * esterno). Gli account li crea solo l'amministratore e li rende visibili
 * a tutti gli utenti della webmail oppure solo a quelli selezionati
 * (tabella "mail_account_users").
 *
 * Le password IMAP/SMTP sono cifrate con lib/Crypto.php e non vengono mai
 * restituite al browser.
 */

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../lib/Crypto.php';
require_once __DIR__ . '/../lib/ImapClient.php';

class MailAccountManager {
    private $conn;

    public function __construct() {
        $conn = getDBConnection();
        if (!$conn) {
            throw new RuntimeException('Connessione al database non disponibile');
        }
        $this->conn = $conn;
    }

    /** Valori predefiniti per il form "Nuovo account" (da config/config.local.php). */
    public static function defaults() {
        return [
            'imap_host'     => env('IMAP_DEFAULT_HOST', '127.0.0.1'),
            'imap_port'     => (int)env('IMAP_DEFAULT_PORT', '143'),
            'imap_security' => env('IMAP_DEFAULT_SECURITY', 'starttls'),
        ];
    }

    private static function tlsVerify() {
        return filter_var(env('IMAP_TLS_VERIFY', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private function publicRow($row) {
        unset($row['imap_password'], $row['smtp_password']);
        $row['id'] = (int)$row['id'];
        $row['imap_port'] = (int)$row['imap_port'];
        $row['smtp_port'] = (int)$row['smtp_port'];
        $row['attivo'] = (bool)$row['attivo'];
        return $row;
    }

    private function query($sql, $types = '', $params = []) {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Errore database: ' . $this->conn->error);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Errore database: ' . $stmt->error);
        }
        return $stmt;
    }

    // ==================== LETTURA ====================

    /** Tutti gli account (per l'amministratore), con gli id degli utenti assegnati. */
    public function listAccounts() {
        $result = $this->conn->query("SELECT * FROM mail_accounts ORDER BY nome_visualizzato ASC");
        if ($result === false) {
            throw new RuntimeException('Errore database: ' . $this->conn->error);
        }
        $assigned = $this->assignmentsMap();
        $accounts = [];
        while ($row = $result->fetch_assoc()) {
            $acc = $this->publicRow($row);
            $acc['user_ids'] = $assigned[$acc['id']] ?? [];
            $accounts[] = $acc;
        }
        return $accounts;
    }

    /** Account attivi visibili a un utente della webmail (usato dalle fasi successive). */
    public function listAccountsForUser($userId) {
        $stmt = $this->query(
            "SELECT a.* FROM mail_accounts a
             WHERE a.attivo = 1 AND (a.visibilita = 'tutti'
                OR EXISTS (SELECT 1 FROM mail_account_users u WHERE u.account_id = a.id AND u.user_id = ?))
             ORDER BY a.nome_visualizzato ASC",
            'i', [(int)$userId]
        );
        $result = $stmt->get_result();
        $accounts = [];
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $this->publicRow($row);
        }
        return $accounts;
    }

    /** Verifica che l'utente possa usare l'account (protezione IDOR). */
    public function userCanAccess($accountId, $userId) {
        $stmt = $this->query(
            "SELECT 1 FROM mail_accounts a
             WHERE a.id = ? AND a.attivo = 1 AND (a.visibilita = 'tutti'
                OR EXISTS (SELECT 1 FROM mail_account_users u WHERE u.account_id = a.id AND u.user_id = ?))",
            'ii', [(int)$accountId, (int)$userId]
        );
        return (bool)$stmt->get_result()->fetch_row();
    }

    /** Singolo account senza password (per il form di modifica). */
    public function getAccount($id) {
        $row = $this->getRow($id);
        if (!$row) return null;
        $acc = $this->publicRow($row);
        $acc['user_ids'] = $this->assignmentsMap($acc['id'])[$acc['id']] ?? [];
        return $acc;
    }

    /** Singolo account con password decifrate (solo uso interno lato server). */
    public function getAccountWithCredentials($id) {
        $row = $this->getRow($id);
        if (!$row) return null;
        $row['imap_password'] = Crypto::decrypt($row['imap_password']);
        $row['smtp_password'] = Crypto::decrypt($row['smtp_password']);
        return $row;
    }

    private function getRow($id) {
        $stmt = $this->query("SELECT * FROM mail_accounts WHERE id = ? LIMIT 1", 'i', [(int)$id]);
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function assignmentsMap($accountId = null) {
        if ($accountId !== null) {
            $stmt = $this->query("SELECT account_id, user_id FROM mail_account_users WHERE account_id = ?", 'i', [(int)$accountId]);
            $result = $stmt->get_result();
        } else {
            $result = $this->conn->query("SELECT account_id, user_id FROM mail_account_users");
        }
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[(int)$row['account_id']][] = (int)$row['user_id'];
        }
        return $map;
    }

    // ==================== SCRITTURA ====================

    /**
     * Valida e normalizza i dati del form. Le password vuote in modifica
     * significano "mantieni quella salvata".
     * @return array [array $clean, string|null $error]
     */
    private function validate($data, $isNew) {
        $c = [
            'nome_visualizzato' => trim($data['nome_visualizzato'] ?? ''),
            'email'             => trim($data['email'] ?? ''),
            'societa'           => trim($data['societa'] ?? ''),
            'imap_host'         => trim($data['imap_host'] ?? ''),
            'imap_port'         => (int)($data['imap_port'] ?? 143),
            'imap_security'     => $data['imap_security'] ?? 'starttls',
            'imap_username'     => trim($data['imap_username'] ?? ''),
            'imap_password'     => (string)($data['imap_password'] ?? ''),
            'smtp_host'         => trim($data['smtp_host'] ?? ''),
            'smtp_port'         => (int)($data['smtp_port'] ?? 587),
            'smtp_encryption'   => $data['smtp_encryption'] ?? 'tls',
            'smtp_username'     => trim($data['smtp_username'] ?? ''),
            'smtp_password'     => (string)($data['smtp_password'] ?? ''),
            'visibilita'        => ($data['visibilita'] ?? 'tutti') === 'selezionati' ? 'selezionati' : 'tutti',
            'attivo'            => filter_var($data['attivo'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
        ];

        $required = [
            'nome_visualizzato' => 'Nome account', 'email' => 'Email',
            'imap_host' => 'Server IMAP', 'imap_username' => 'Utente IMAP',
            'smtp_host' => 'Server SMTP', 'smtp_username' => 'Utente SMTP',
        ];
        if ($isNew) {
            $required['imap_password'] = 'Password IMAP';
            $required['smtp_password'] = 'Password SMTP';
        }
        foreach ($required as $field => $label) {
            if ($c[$field] === '') {
                return [null, "Campo obbligatorio mancante: {$label}"];
            }
        }
        if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            return [null, 'Email non valida'];
        }
        if (!preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/', $c['imap_host']) || !preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/', $c['smtp_host'])) {
            return [null, 'Nome server non valido'];
        }
        foreach (['imap_port', 'smtp_port'] as $p) {
            if ($c[$p] < 1 || $c[$p] > 65535) {
                return [null, 'Porta non valida'];
            }
        }
        if (!in_array($c['imap_security'], ['starttls', 'ssl', 'none'], true)) $c['imap_security'] = 'starttls';
        if (!in_array($c['smtp_encryption'], ['tls', 'ssl', 'none'], true)) $c['smtp_encryption'] = 'tls';

        return [$c, null];
    }

    /** Crea (id vuoto) o aggiorna un account e le sue assegnazioni. */
    public function saveAccount($data, array $userIds = []) {
        $id = (int)($data['id'] ?? 0);
        $isNew = $id === 0;
        if (!$isNew && !$this->getRow($id)) {
            return ['success' => false, 'error' => 'Account non trovato'];
        }

        [$c, $error] = $this->validate($data, $isNew);
        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        $this->conn->begin_transaction();
        try {
            $fields = ['nome_visualizzato', 'email', 'societa', 'imap_host', 'imap_port', 'imap_security',
                       'imap_username', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username',
                       'visibilita', 'attivo'];
            $types = 'ssssisssisssi';
            $values = array_map(fn($f) => $c[$f], $fields);
            foreach (['imap_password', 'smtp_password'] as $pw) {
                if ($c[$pw] !== '') {
                    $fields[] = $pw;
                    $types .= 's';
                    $values[] = Crypto::encrypt($c[$pw]);
                }
            }

            if ($isNew) {
                $sql = 'INSERT INTO mail_accounts (' . implode(',', $fields) . ') VALUES ('
                     . implode(',', array_fill(0, count($fields), '?')) . ')';
                $this->query($sql, $types, $values);
                $id = (int)$this->conn->insert_id;
            } else {
                $sql = 'UPDATE mail_accounts SET ' . implode(',', array_map(fn($f) => "{$f} = ?", $fields)) . ' WHERE id = ?';
                $this->query($sql, $types . 'i', array_merge($values, [$id]));
            }

            $this->query("DELETE FROM mail_account_users WHERE account_id = ?", 'i', [$id]);
            if ($c['visibilita'] === 'selezionati') {
                foreach (array_unique(array_map('intval', $userIds)) as $uid) {
                    if ($uid > 0) {
                        $this->query("INSERT INTO mail_account_users (account_id, user_id) VALUES (?, ?)", 'ii', [$id, $uid]);
                    }
                }
            }
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }

        return ['success' => true, 'message' => $isNew ? 'Account creato' : 'Account aggiornato', 'id' => $id];
    }

    public function deleteAccount($id) {
        $stmt = $this->query("DELETE FROM mail_accounts WHERE id = ?", 'i', [(int)$id]);
        if ($stmt->affected_rows > 0) {
            return ['success' => true, 'message' => 'Account eliminato'];
        }
        return ['success' => false, 'error' => 'Account non trovato'];
    }

    private function recordCheck($id, $error) {
        if ($id > 0) {
            $err = $error === null ? null : mb_substr($error, 0, 255);
            $this->query("UPDATE mail_accounts SET last_check = NOW(), last_error = ? WHERE id = ?", 'si', [$err, (int)$id]);
        }
    }

    // ==================== TEST CONNESSIONE ====================

    /**
     * Prova login IMAP + elenco cartelle e login SMTP con i dati del form.
     * Se il form non contiene una password e c'è un id, usa quella salvata.
     */
    public function testConnection($data) {
        $id = (int)($data['id'] ?? 0);
        [$c, $error] = $this->validate($data, false);
        if ($error) {
            return ['success' => false, 'error' => $error];
        }
        if ($id > 0 && ($c['imap_password'] === '' || $c['smtp_password'] === '')) {
            $saved = $this->getAccountWithCredentials($id);
            if ($saved) {
                if ($c['imap_password'] === '') $c['imap_password'] = $saved['imap_password'];
                if ($c['smtp_password'] === '') $c['smtp_password'] = $saved['smtp_password'];
            }
        }

        $imap = $this->testImap($c);
        $smtp = $this->testSmtp($c);
        $this->recordCheck($id, $imap['ok'] ? ($smtp['ok'] ? null : 'SMTP: ' . $smtp['message'])
                                            : 'IMAP: ' . $imap['message']);

        return ['success' => true, 'imap' => $imap, 'smtp' => $smtp];
    }

    private function testImap($c) {
        if ($c['imap_password'] === '') {
            return ['ok' => false, 'message' => 'Password IMAP mancante'];
        }
        $client = new ImapClient($c['imap_host'], $c['imap_port'], $c['imap_security'], self::tlsVerify(), 10);
        try {
            $client->connect();
            $client->login($c['imap_username'], $c['imap_password']);
            $folders = $client->listMailboxes();
            $client->logout();
            return [
                'ok'       => true,
                'message'  => 'Login riuscito, ' . count($folders) . ' cartelle trovate',
                'folders'  => array_column($folders, 'display'),
                'special'  => ImapClient::detectSpecialFolders($folders),
            ];
        } catch (ImapException $e) {
            $client->close();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function testSmtp($c) {
        if ($c['smtp_password'] === '') {
            return ['ok' => false, 'message' => 'Password SMTP mancante'];
        }
        require_once __DIR__ . '/../phpmailer/src/Exception.php';
        require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/../phpmailer/src/SMTP.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $c['smtp_host'];
        $mail->Port = $c['smtp_port'];
        $mail->SMTPAuth = true;
        $mail->Username = $c['smtp_username'];
        $mail->Password = $c['smtp_password'];
        $mail->Timeout = 10;
        if ($c['smtp_encryption'] === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($c['smtp_encryption'] === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        // Come in send_email.php: niente DHE (chiavi DH deboli di alcuni gestori)
        $mail->SMTPOptions = ['ssl' => ['ciphers' => 'DEFAULT:!kDHE']];
        try {
            $mail->smtpConnect();
            $mail->smtpClose();
            return ['ok' => true, 'message' => 'Login SMTP riuscito'];
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            return ['ok' => false, 'message' => $mail->ErrorInfo ?: $e->getMessage()];
        }
    }
}
