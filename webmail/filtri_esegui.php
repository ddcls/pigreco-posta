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
