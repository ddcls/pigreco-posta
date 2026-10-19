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
 * get_emails.php - Elenco paginato delle email di una cartella, letto via IMAP.
 * Restituisce SEMPRE JSON valido, anche in caso di errore.
 *
 * Parametri POST:
 * - account_id (o "user", per compatibilità): id dell'account email
 * - folder: inbox | sent | draft | junk | trash | imap:<nome cartella>
 * - page, limit (0 = "Tutte", massimo 10.000)
 *
 * Ogni email ha "file" = UID IMAP del messaggio nella cartella.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo non consentito. Usare POST.');
    }

    $accountParam = requestParam(['account_id', 'user']);
    $folder = requestParam(['folder'], 'inbox');
    $page = max(1, (int)requestParam(['page'], '1'));

    // Gestione limite con supporto "Tutte" (0) e massimo 500
    $rawLimit = (int)requestParam(['limit'], '20');
    $limit = $rawLimit === 0 ? 10000 : max(1, min(500, $rawLimit));

    if ($accountParam === '') {
        throw new Exception('Nessun account selezionato');
    }
    if (!MailboxService::isFolderParam($folder)) {
        $folder = 'inbox';
    }

    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        http_response_code(403);
        throw new Exception('Account non valido o non autorizzato');
    }

    $result = $mailbox->listMessages($folder, $page, $limit);
    $mailbox->close();

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;

} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    echo json_encode([
        'emails'      => [],
        'total'       => 0,
        'new_count'   => 0,
        'limit'       => 20,
        'offset'      => 0,
        'page'        => 1,
        'total_pages' => 0,
        'error'       => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
