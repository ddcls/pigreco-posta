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
 * Utility per intestazioni e struttura dei messaggi letti via IMAP.
 */

class MailHeaders {

    /**
     * Scompone un blocco di intestazioni (anche con righe ripiegate) in
     * [nome-minuscolo => valore grezzo]. In caso di duplicati vince il primo.
     */
    public static function parseBlock($block) {
        $block = preg_replace("/\r?\n[ \t]+/", ' ', (string)$block); // unfolding RFC 5322
        $headers = [];
        foreach (preg_split("/\r?\n/", $block) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) continue;
            $name = strtolower(trim(substr($line, 0, $colon)));
            if (!isset($headers[$name])) {
                $headers[$name] = trim(substr($line, $colon + 1));
            }
        }
        return $headers;
    }

    /** Decodifica le encoded-word MIME (=?charset?B/Q?...?=) in UTF-8. */
    public static function decode($value) {
        $value = (string)$value;
        if ($value === '') {
            return '';
        }
        if (strpos($value, '=?') !== false) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false) {
                $value = $decoded;
            }
        }
        // Intestazioni 8 bit non dichiarate: si assume Latin-1 se non è UTF-8 valido
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }
        return trim($value);
    }

    /** Formatta una data RFC 2822 come "d/m/Y H:i" (null se non interpretabile). */
    public static function formatDate($raw, $format = 'd/m/Y H:i') {
        $ts = $raw ? @strtotime($raw) : false;
        return $ts ? date($format, $ts) : null;
    }

    /**
     * Stabilisce da BODYSTRUCTURE se il messaggio ha allegati, con le stesse
     * regole usate in precedenza sul file Maildir: Content-Disposition
     * attachment, oppure un nome file (filename= nella disposition o name=
     * nel Content-Type). Scende nelle parti multipart e nei message/rfc822.
     */
    public static function hasAttachments($structure) {
        if (!is_array($structure) || !$structure) {
            return false;
        }

        // Multipart: (parte)(parte)... "SUBTYPE" [estensioni]
        if (is_array($structure[0])) {
            foreach ($structure as $part) {
                if (!is_array($part)) break;
                if (self::hasAttachments($part)) return true;
            }
            return false;
        }

        $type = strtolower((string)$structure[0]);
        $subtype = strtolower((string)($structure[1] ?? ''));

        if (self::paramValue($structure[2] ?? null, 'name') !== null) {
            return true;
        }

        // Posizione della disposition: dopo i 7 campi base, più "lines" per
        // text/*, più envelope+body+lines per message/rfc822, più md5.
        if ($type === 'text') {
            $dispIndex = 9;
        } elseif ($type === 'message' && $subtype === 'rfc822') {
            $dispIndex = 11;
            if (self::hasAttachments($structure[8] ?? null)) {
                return true;
            }
        } else {
            $dispIndex = 8;
        }

        $disposition = $structure[$dispIndex] ?? null;
        if (is_array($disposition) && isset($disposition[0])) {
            if (strtolower((string)$disposition[0]) === 'attachment') {
                return true;
            }
            if (self::paramValue($disposition[1] ?? null, 'filename') !== null) {
                return true;
            }
        }
        return false;
    }

    /** Valore di un parametro in una lista IMAP ("chiave" "valore" ...), case-insensitive. */
    private static function paramValue($params, $name) {
        if (!is_array($params)) {
            return null;
        }
        for ($i = 0; $i + 1 < count($params); $i += 2) {
            $key = strtolower((string)$params[$i]);
            // RFC 2231: filename*=, filename*0*= ...
            if ($key === $name || strpos($key, $name . '*') === 0) {
                return (string)$params[$i + 1];
            }
        }
        return null;
    }
}
