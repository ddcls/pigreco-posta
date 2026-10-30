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
 * cerca_email.php - Ricerca sul server di posta (IMAP SEARCH), nella
 * cartella indicata o in tutte, con i risultati dal più recente a pagine
 * (ricerca.js). Prima la ricerca guardava solo le email della pagina.
 *
 * Parametri POST:
 *   account_id (o "user"),
 *   folder      cartella (logica o "imap:<nome>") oppure "tutte",
 *   q           testo da cercare (può mancare con persona o solo_importanti=1),
 *   persona     mittente (nelle cartelle in uscita: destinatario) che deve
 *               contenere questo testo, insieme a q,
 *   campi       mittente,destinatari,oggetto,corpo (separati da virgola),
 *   solo_importanti 1 = solo le email con la stella,
 *   page, limit (limit 0 = tutti).
 *
 * Risposta JSON: {emails: [... con "folder"], total, page, total_pages,
 * limit, troncata} oppure {emails: [], error}.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Metodo non consentito. Usare POST.');
    }

    $accountParam = requestParam(['account_id', 'user']);
    $folder = requestParam(['folder'], 'inbox');
    $testo = (string)($_POST['q'] ?? '');
    $persona = (string)($_POST['persona'] ?? '');
    $campi = array_values(array_intersect(array_keys(MailboxService::CAMPI_RICERCA),
        array_map('trim', explode(',', requestParam(['campi'], 'mittente,destinatari,oggetto')))));
    $soloImportanti = requestParam(['solo_importanti']) === '1';
    $page = max(1, (int)requestParam(['page'], '1'));
    $limit = max(0, min(500, (int)requestParam(['limit'], '50')));

    if ($accountParam === '') {
        http_response_code(400);
        throw new Exception('Nessun account selezionato');
    }
    if ($folder !== 'tutte' && !MailboxService::isFolderParam($folder)) {
        http_response_code(400);
        throw new Exception('Cartella non valida');
    }
    if (mb_strlen($testo) > 200 || mb_strlen($persona) > 200) {
        http_response_code(400);
        throw new Exception('Testo da cercare troppo lungo');
    }

    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        http_response_code(403);
        throw new Exception('Account non valido o non autorizzato');
    }

    $result = $mailbox->search($folder === 'tutte' ? null : $folder, $testo, $campi, $soloImportanti, $page, $limit, $persona);
    $mailbox->close();

    echo json_encode($result, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code($e instanceof ImapException ? 502 : ($e instanceof Exception ? 400 : 500));
    }
    echo json_encode(['emails' => [], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
