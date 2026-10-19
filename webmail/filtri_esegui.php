<?php
/**
 * filtri_esegui.php - Applica i filtri alle email nuove (lib/FiltriService.php).
 * Solo da riga di comando: nel container docker lo lancia l'entrypoint ogni
 * minuto; installata da sola, con cron:
 *
 *   * * * * * php /percorso/webmail/filtri_esegui.php
 *
 * Scrive una riga solo quando filtra qualcosa o c'è un errore.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib/FiltriService.php';

// Un giro alla volta (un giro lento non si sovrappone al successivo)
$lock = fopen(sys_get_temp_dir() . '/webmail-filtri.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$log = function ($riga) {
    echo date('Y-m-d H:i:s') . " [filtri] {$riga}\n";
};

try {
    (new FiltriService())->eseguiGiro($log);
} catch (Throwable $e) {
    $log('ERRORE ' . $e->getMessage());
    exit(1);
}
