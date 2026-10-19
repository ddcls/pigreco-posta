<?php
/**
 * Modello di configurazione di PiGreco Posta.
 *
 * Copialo in config/config.local.php (NON versionato) e compila i valori:
 *   cp config/config.example.php config/config.local.php
 *
 * È un file PHP (e non un .env) di proposito: se qualcuno lo richiede dal
 * browser il server lo esegue e non restituisce nulla, quindi può stare
 * dentro la webroot senza regole speciali di Apache.
 */

return [
    // Database MySQL/MariaDB
    'DB_HOST'               => 'localhost',
    'DB_USER'               => 'webmail_user',
    'DB_PASS'               => '',
    'DB_NAME'               => 'webmail_digitale',
    'DB_CHARSET'            => 'utf8mb4',

    // Fuso orario usato per mostrare le date
    'APP_TIMEZONE'          => 'Europe/Rome',

    // Chiave AES-256 per cifrare le password IMAP/SMTP degli account email
    // (32 byte in base64). Generala con:
    //   php -r 'echo base64_encode(random_bytes(32)), "\n";'
    // NON cambiarla dopo aver salvato degli account: le password non
    // sarebbero più leggibili e andrebbero reinserite.
    'MAIL_ENCRYPTION_KEY'   => '',

    // Server Dovecot proposto di default nel form "Nuovo account email"
    'IMAP_DEFAULT_HOST'     => '127.0.0.1',
    'IMAP_DEFAULT_PORT'     => '143',
    'IMAP_DEFAULT_SECURITY' => 'starttls',
    // 1 = verifica il certificato TLS di Dovecot (0 se è autofirmato)
    'IMAP_TLS_VERIFY'       => '0',
];
