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
 * clear_temp_attachments.php
 * Cancella TUTTI gli allegati temporanei dell'utente corrente
 * Deve essere chiamato DOPO ogni invio email riuscito
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/check_auth.php';
header('Content-Type: application/json; charset=utf-8');

try {
    
    // 1. Cancella allegati dalla sessione
    $attachmentsCleared = 0;
    
    if (isset($_SESSION['attachments'])) {
        $attachmentsCleared = count($_SESSION['attachments']);
        unset($_SESSION['attachments']);
    }
    
    // 2. Cancella cartella temporanea allegati (se esiste)
    $tempDir = sys_get_temp_dir() . '/mail_attachments_' . session_id();
    
    $filesCleared = 0;
    if (is_dir($tempDir)) {
        $files = glob($tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                if (unlink($file)) {
                    $filesCleared++;
                }
            }
        }
        
        // Cancella la cartella
        rmdir($tempDir);
    }
    
    // 3. Resetta contatore allegati nella sessione
    if (isset($_SESSION['attachment_count'])) {
        unset($_SESSION['attachment_count']);
    }
    
    // 4. I file temporanei degli allegati inoltrati (fwd_*) li cancella già
    //    send_email.php alla fine dell'invio: qui non si toccano, perché
    //    potrebbero essere di un altro utente che sta inviando in quel momento.
    $fwdCleared = 0;

    // Risultato
    $totalCleared = $attachmentsCleared + $filesCleared + $fwdCleared;
    
    echo json_encode([
        'success' => true,
        'message' => 'Allegati temporanei cancellati con successo',
        'attachments_cleared' => $attachmentsCleared,
        'files_cleared' => $filesCleared,
        'forwarded_cleared' => $fwdCleared,
        'total_cleared' => $totalCleared
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    $error_msg = $e->getMessage();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $error_msg
    ], JSON_UNESCAPED_UNICODE);
}
?>
