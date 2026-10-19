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
 * Cifratura reversibile delle password degli account email (IMAP/SMTP).
 *
 * Le password servono in chiaro per autenticarsi su Dovecot e sul server
 * SMTP, quindi non possono essere salvate come hash: vengono cifrate con
 * AES-256-GCM usando la chiave MAIL_ENCRYPTION_KEY di config/config.local.php
 * (32 byte codificati in base64, generabili con:
 *   php -r 'echo base64_encode(random_bytes(32)), "\n";'
 * ).
 *
 * Formato salvato nel DB: "v1:" . base64(iv[12] . tag[16] . ciphertext)
 * ATTENZIONE: se la chiave cambia o va persa, le password salvate non sono
 * più decifrabili e vanno reinserite dalle Impostazioni.
 */

require_once __DIR__ . '/../config/env_loader.php';

class Crypto {
    const PREFIX = 'v1:';
    const CIPHER = 'aes-256-gcm';

    private static function key() {
        static $key = null;
        if ($key === null) {
            $raw = base64_decode(env('MAIL_ENCRYPTION_KEY'), true);
            if ($raw === false || strlen($raw) !== 32) {
                throw new RuntimeException('MAIL_ENCRYPTION_KEY non valida: deve essere di 32 byte codificati in base64');
            }
            $key = $raw;
        }
        return $key;
    }

    public static function encrypt($plain) {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt((string)$plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Cifratura password fallita');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt($stored) {
        if (strpos((string)$stored, self::PREFIX) !== 0) {
            throw new RuntimeException('Formato password cifrata non riconosciuto');
        }
        $bin = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($bin === false || strlen($bin) < 28) {
            throw new RuntimeException('Password cifrata corrotta');
        }
        $plain = openssl_decrypt(substr($bin, 28), self::CIPHER, self::key(), OPENSSL_RAW_DATA,
                                 substr($bin, 0, 12), substr($bin, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('Decifratura password fallita (MAIL_ENCRYPTION_KEY cambiata?)');
        }
        return $plain;
    }
}
