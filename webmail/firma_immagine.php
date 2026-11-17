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
 * firma_immagine.php?id=N - Un'immagine di una firma (tabella firme_immagini),
 * per l'editor della firma e il compositore. Nell'email inviata l'immagine va
 * dentro il messaggio (cid:, FirmeService::incorporaImmagini()), non qui.
 * Solo per chi può usare la casella della firma.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/FirmeService.php';

$id = (int)($_GET['id'] ?? 0);
$img = null;
try {
    $img = $id > 0 ? (new FirmeService())->immagine($id) : null;
} catch (Throwable $e) {
    error_log('firma_immagine.php: ' . $e->getMessage());
}
if (!$img || getAuthorizedAccountId($img['account_id']) === null
        || FirmeService::tipoImmagine($img['dati']) !== $img['mime']) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Immagine non trovata';
    exit;
}
header('Content-Type: ' . $img['mime']);
header('Content-Length: ' . strlen($img['dati']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'");
header('Cache-Control: private, max-age=3600');
echo $img['dati'];
