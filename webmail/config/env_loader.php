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
 * Loader della configurazione (nessuna dipendenza esterna).
 *
 * La configurazione vive in config/config.local.php - MAI versionato, vedi
 * .gitignore - un file PHP che restituisce un array chiave => valore
 * (modello: config/config.example.php). Essendo PHP, se qualcuno lo richiede
 * dal browser il server lo esegue e non restituisce nulla: l'applicazione
 * resta autonoma dentro la webroot (es. /var/www/html/webmail) senza
 * dipendere da .htaccess, dalla configurazione di Apache o da percorsi
 * esterni legati a un utente.
 *
 * In alternativa si può ancora usare un file .env FUORI dalla webroot,
 * creando config/env_path.local.php (non versionato) con dentro solo:
 *   <?php define('WEBMAIL_ENV_PATH', '/percorso/assoluto/.env');
 * Un .env dentro la cartella dell'app NON viene letto: sarebbe scaricabile.
 * I vecchi nomi READMAIL5_ENV_PATH e READMAIL5_CONFIG_FILE (prima del nome
 * "Webmail Digitale") sono ancora accettati.
 */

// Nel container docker di PiGreco Archivio la variabile d'ambiente
// WEBMAIL_CONFIG_FILE indica un file di configurazione diverso, che lascia
// arrivare i valori dalle variabili d'ambiente del container.
define('WEBMAIL_CONFIG_FILE', getenv('WEBMAIL_CONFIG_FILE') ?: (getenv('READMAIL5_CONFIG_FILE') ?: __DIR__ . '/config.local.php'));

$webmailLocalPathOverride = __DIR__ . '/env_path.local.php';
if (is_readable($webmailLocalPathOverride)) {
    require_once $webmailLocalPathOverride;
}
if (!defined('WEBMAIL_ENV_PATH') && defined('READMAIL5_ENV_PATH')) {
    define('WEBMAIL_ENV_PATH', READMAIL5_ENV_PATH);
}

/**
 * Carica (una sola volta per richiesta) la configurazione in $_ENV/getenv().
 */
function loadEnv() {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $values = [];
    if (is_readable(WEBMAIL_CONFIG_FILE)) {
        $config = require WEBMAIL_CONFIG_FILE;
        if (is_array($config)) {
            $values = $config;
        }
    }
    if (defined('WEBMAIL_ENV_PATH') && is_readable(WEBMAIL_ENV_PATH)) {
        $values = array_merge($values, parseEnvFile(WEBMAIL_ENV_PATH));
    }

    foreach ($values as $key => $value) {
        if (!is_string($key) || $key === '' || $value === null) {
            continue;
        }
        $value = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }
}

/**
 * Legge un file in formato .env (KEY=valore, commenti con #).
 */
function parseEnvFile($path) {
    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $values;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        // Rimuove eventuali virgolette che racchiudono l'intero valore
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && substr($value, -1) === '"') ||
            ($value[0] === "'" && substr($value, -1) === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $values[$key] = $value;
        }
    }
    return $values;
}

/**
 * Legge una variabile di configurazione, con eventuale default.
 * Lancia un errore chiaro se mancante e nessun default è fornito: meglio
 * fallire subito che connettersi con credenziali vuote.
 */
function env($key, $default = null) {
    loadEnv();
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    if ($default !== null) {
        return $default;
    }
    throw new RuntimeException("Variabile di configurazione mancante: {$key} (controlla config/config.local.php)");
}
