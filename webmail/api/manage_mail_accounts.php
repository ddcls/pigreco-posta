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
 * Gestione account email IMAP (Dovecot) + SMTP.
 * Endpoint API per il pannello "Account Email" delle Impostazioni.
 *
 * Riservato all'amministratore. Azioni:
 *   GET  ?action=list      → account + utenti webmail (per le assegnazioni) + valori predefiniti
 *   GET  ?action=get&id=N  → singolo account (senza password)
 *   POST action=save       → crea/aggiorna (user_ids[] per visibilità "selezionati")
 *   POST action=delete     → elimina (id)
 *   POST action=test       → prova login IMAP e SMTP con i dati del form
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../check_auth.php';
require_once __DIR__ . '/../config/mail_account_manager.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accesso riservato all\'amministratore'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $manager = new MailAccountManager();
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        case 'list':
            $users = [];
            $result = getDBConnection()->query(
                "SELECT id, nome, cognome, username FROM users WHERE attivo = 1 ORDER BY cognome, nome"
            );
            while ($row = $result->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $users[] = $row;
            }
            echo json_encode([
                'success'  => true,
                'accounts' => $manager->listAccounts(),
                'users'    => $users,
                'defaults' => MailAccountManager::defaults(),
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'get':
            $account = $manager->getAccount((int)($_GET['id'] ?? 0));
            echo json_encode($account
                ? ['success' => true, 'account' => $account]
                : ['success' => false, 'error' => 'Account non trovato'], JSON_UNESCAPED_UNICODE);
            break;

        case 'save':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Metodo non consentito');
            }
            $userIds = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? $_POST['user_ids'] : [];
            echo json_encode($manager->saveAccount($_POST, $userIds), JSON_UNESCAPED_UNICODE);
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Metodo non consentito');
            }
            echo json_encode($manager->deleteAccount((int)($_POST['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
            break;

        case 'test':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Metodo non consentito');
            }
            echo json_encode($manager->testConnection($_POST), JSON_UNESCAPED_UNICODE);
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
