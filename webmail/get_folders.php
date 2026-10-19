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
 * get_folders.php - Cartelle della casella diverse da quelle speciali
 * (Posta in arrivo, Inviata, Bozze, Indesiderata, Cestino), lette via IMAP.
 *
 * Parametri POST: account_id (o "user").
 * Risposta JSON: {cartelle: [{id, nome, percorso, livello, apribile, modificabile}],
 * solo_locale} oppure {cartelle: [], error}. "id" va passato come "folder" agli
 * altri endpoint; solo_locale = casella copiata in locale (le modifiche alle
 * cartelle valgono solo per la copia sul server).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Metodo non consentito. Usare POST.');
    }

    $accountParam = requestParam(['account_id', 'user']);
    if ($accountParam === '') {
        http_response_code(400);
        throw new Exception('Nessun account selezionato');
    }

    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        http_response_code(403);
        throw new Exception('Account non valido o non autorizzato');
    }

    $cartelle = $mailbox->otherFolders();
    $soloLocale = $mailbox->isPostaDigitale();
    $mailbox->close();

    echo json_encode(['cartelle' => $cartelle, 'solo_locale' => $soloLocale], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code($e instanceof ImapException ? 502 : 500);
    }
    echo json_encode(['cartelle' => [], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
