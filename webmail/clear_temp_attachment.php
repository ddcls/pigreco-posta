<?php
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
    
    // 4. Cancella eventuali file temporanei di inoltro (prefissati con fwd_)
    $fwdFiles = glob(sys_get_temp_dir() . '/fwd_*');
    $fwdCleared = 0;
    
    foreach ($fwdFiles as $fwdFile) {
        if (is_file($fwdFile)) {
            // Verifica che il file sia stato creato in questa sessione (ultimi 5 minuti)
            $fileAge = time() - filemtime($fwdFile);
            if ($fileAge < 300) { // 5 minuti
                if (unlink($fwdFile)) {
                    $fwdCleared++;
                }
            }
        }
    }
    
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
        'error' => $error_msg,
        'debug' => [
            'php_version' => PHP_VERSION,
            'script' => basename(__FILE__),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>
