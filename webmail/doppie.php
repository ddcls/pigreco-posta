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
 * doppie.php - Email doppie in una cartella (Impostazioni › Email doppie, doppie.js). Solo amministratore.
 *
 * Parametri POST: account_id, folder (logica o "imap:<nome>"), azione = cerca | cestino.
 *   cerca   → {success, totale, gruppi: [{tieni, copie[], oggetto, mittente, data}], copie}
 *   cestino → ricalcola i doppioni e sposta nel Cestino le copie in più, tenendo la prima arrivata
 *             (MailboxService::moveDuplicatesToTrash()); {success, message, spostate}
 * Mai una cancellazione definitiva.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(600);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Metodo non consentito. Usare POST.');
    }
    if (!isAdmin()) {
        http_response_code(403);
        throw new Exception("Accesso riservato all'amministratore");
    }
    $accountParam = requestParam(['account_id']);
    $folder = requestParam(['folder']);
    $azione = requestParam(['azione'], 'cerca');
    if ($accountParam === '' || !MailboxService::isFolderParam($folder)) {
        http_response_code(400);
        throw new Exception('Casella o cartella non valida');
    }
    if (!in_array($azione, ['cerca', 'cestino'], true)) {
        http_response_code(400);
        throw new Exception('Azione non valida');
    }
    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        http_response_code(403);
        throw new Exception('Account non valido o non autorizzato');
    }

    if ($azione === 'cerca') {
        $r = $mailbox->duplicates($folder);
        $mailbox->close();
        $copie = 0;
        $gruppi = [];
        foreach ($r['gruppi'] as $g) {
            $copie += count($g['copie']);
            unset($g['flag']);
            $gruppi[] = $g;
        }
        echo json_encode(['success' => true, 'totale' => $r['totale'], 'gruppi' => $gruppi, 'copie' => $copie],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } else {
        $r = $mailbox->moveDuplicatesToTrash($folder);
        $mailbox->close();
        $messaggio = $r['spostate'] === 0
            ? 'Nessuna copia da spostare'
            : ($r['spostate'] === 1 ? '1 copia spostata' : "{$r['spostate']} copie spostate") . ' nel Cestino';
        echo json_encode(['success' => true, 'message' => $messaggio, 'spostate' => $r['spostate']],
            JSON_UNESCAPED_UNICODE);
    }

} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code($e instanceof ImapException ? 502 : ($e instanceof Exception ? 400 : 500));
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
