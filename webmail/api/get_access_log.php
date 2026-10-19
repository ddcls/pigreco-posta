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
 * Consultazione log accessi (tabella "access_log").
 * Endpoint API per la sezione "Log Accessi" della finestra Impostazioni.
 *
 * Riservato all'amministratore: espone username, IP e user agent di tutti
 * gli account, quindi non deve mai essere raggiungibile da un utente normale.
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

try {
    $conn = getDBConnection();
    if (!$conn) {
        throw new Exception('Connessione al database non disponibile');
    }

    $action = $_GET['action'] ?? 'list';
    if ($action !== 'list') {
        throw new Exception('Azione non valida: ' . $action);
    }

    // Paginazione: massimo 200 righe per richiesta
    $limit = intval($_GET['limit'] ?? 100);
    $limit = max(1, min($limit, 200));
    $offset = max(0, intval($_GET['offset'] ?? 0));

    $search = trim($_GET['username'] ?? '');

    if ($search !== '') {
        $stmt = $conn->prepare(
            "SELECT id, username, ip_address, user_agent, attempt_time, success, action
             FROM access_log WHERE username LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $likeSearch = '%' . $search . '%';
        $stmt->bind_param('sii', $likeSearch, $limit, $offset);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, username, ip_address, user_agent, attempt_time, success, action
             FROM access_log ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('ii', $limit, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $row['success'] = (bool)$row['success'];
        $row['id'] = (int)$row['id'];
        $logs[] = $row;
    }

    echo json_encode(['success' => true, 'logs' => $logs], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
