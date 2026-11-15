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
 * Secondo fattore: codice a 6 cifre dell'app Authenticator (TOTP, RFC 6238:
 * HMAC-SHA1, intervalli di 30 secondi), chiesto dopo la password.
 *
 * Quando si chiede (variabile WEBMAIL_TOTP nel .env):
 *   mai     (predefinito) mai;
 *   fuori   solo a chi non arriva da WEBMAIL_RETI_INTERNE (la rete
 *           dell'ufficio, es. "192.168.1.0/24"): da Internet sì, dai PC
 *           dell'ufficio no;
 *   sempre  a tutti.
 * L'indirizzo è quello vero anche dietro Caddy o PiGreco Archivio: Apache lo
 * prende da X-Forwarded-For solo se la richiesta arriva da una rete Docker
 * (mod_remoteip, docker/webmail/000-default.conf).
 *
 * Al primo accesso da fuori la pagina totp.php mostra il QR da inquadrare con
 * l'app; la chiave si salva cifrata come le password delle caselle
 * (lib/Crypto.php, MAIL_ENCRYPTION_KEY). L'amministratore la azzera da
 * Impostazioni › Account (api/manage_users.php, azione reset_totp).
 * "Ricorda questo dispositivo" lascia nel browser un token casuale valido
 * TOTP_GIORNI_DISPOSITIVO giorni (nel database solo il suo SHA-256).
 */

require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/Crypto.php';

const TOTP_PERIODO = 30;
const TOTP_CIFRE = 6;
const TOTP_GIORNI_DISPOSITIVO = 30;
const TOTP_COOKIE_DISPOSITIVO = 'WEBMAIL_DISPOSITIVO';
// Tempo per scrivere il codice dopo la password
const TOTP_ATTESA_MAX = 300;

function totpModalita() {
    $m = strtolower(trim((string)env('WEBMAIL_TOTP', 'mai')));
    return in_array($m, ['mai', 'fuori', 'sempre'], true) ? $m : 'sempre';
}

/**
 * Vero se $ip è in una delle reti dell'elenco (separate da virgole o spazi,
 * IPv4 o IPv6, con o senza /n). Una voce che inizia con "!" esclude: per
 * esempio "192.168.1.0/24, !192.168.1.1" se il router presenta come suo
 * indirizzo gli accessi da Internet.
 */
function ipNelleReti($ip, $reti) {
    $bin = @inet_pton((string)$ip);
    if ($bin === false) {
        return false;
    }
    $dentro = false;
    foreach (preg_split('/[\s,;]+/', (string)$reti, -1, PREG_SPLIT_NO_EMPTY) as $voce) {
        $escludi = $voce[0] === '!';
        $voce = ltrim($voce, '!');
        [$rete, $bit] = array_pad(explode('/', $voce, 2), 2, null);
        $reteBin = @inet_pton($rete);
        if ($reteBin === false || strlen($reteBin) !== strlen($bin)) {
            continue;
        }
        $bit = $bit === null ? strlen($bin) * 8 : max(0, min((int)$bit, strlen($bin) * 8));
        $byte = intdiv($bit, 8);
        $resto = $bit % 8;
        $uguale = substr($bin, 0, $byte) === substr($reteBin, 0, $byte);
        if ($uguale && $resto) {
            $maschera = (0xFF << (8 - $resto)) & 0xFF;
            $uguale = (ord($bin[$byte]) & $maschera) === (ord($reteBin[$byte]) & $maschera);
        }
        if ($uguale && $escludi) {
            return false;
        }
        $dentro = $dentro || $uguale;
    }
    return $dentro;
}

function accessoDaReteInterna() {
    return ipNelleReti($_SERVER['REMOTE_ADDR'] ?? '', env('WEBMAIL_RETI_INTERNE', ' '));
}

function totpRichiesto() {
    $m = totpModalita();
    return $m === 'sempre' || ($m === 'fuori' && !accessoDaReteInterna());
}

// ---------- calcolo del codice ----------

function base32Codifica($bin) {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bit = '';
    foreach (str_split($bin) as $c) {
        $bit .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $testo = '';
    foreach (str_split($bit, 5) as $gruppo) {
        $testo .= $alfabeto[bindec(str_pad($gruppo, 5, '0'))];
    }
    return $testo;
}

function base32Decodifica($testo) {
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $testo = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', (string)$testo));
    $bit = '';
    foreach (str_split($testo) as $c) {
        $bit .= str_pad(decbin(strpos($alfabeto, $c)), 5, '0', STR_PAD_LEFT);
    }
    $bin = '';
    foreach (str_split($bit, 8) as $gruppo) {
        if (strlen($gruppo) === 8) {
            $bin .= chr(bindec($gruppo));
        }
    }
    return $bin;
}

function totpCodice($segretoBin, $passo) {
    $hash = hash_hmac('sha1', pack('J', $passo), $segretoBin, true);
    $inizio = ord($hash[19]) & 0x0F;
    $numero = unpack('N', substr($hash, $inizio, 4))[1] & 0x7FFFFFFF;
    return str_pad((string)($numero % (10 ** TOTP_CIFRE)), TOTP_CIFRE, '0', STR_PAD_LEFT);
}

/**
 * Passo (intervallo di 30 s) del codice se è giusto, altrimenti null.
 * Vale anche il codice dell'intervallo prima e di quello dopo (orologio del
 * telefono un po' avanti o indietro), ma mai un passo già usato.
 */
function totpVerifica($segretoBin, $codice, $ultimoPasso = null) {
    $codice = preg_replace('/\D/', '', (string)$codice);
    if (strlen($codice) !== TOTP_CIFRE || $segretoBin === '') {
        return null;
    }
    $adesso = intdiv(time(), TOTP_PERIODO);
    foreach ([0, -1, 1] as $scarto) {
        $passo = $adesso + $scarto;
        if ($ultimoPasso !== null && $passo <= (int)$ultimoPasso) {
            continue;
        }
        if (hash_equals(totpCodice($segretoBin, $passo), $codice)) {
            return $passo;
        }
    }
    return null;
}

function totpNuovoSegreto() {
    return base32Codifica(random_bytes(20));
}

/** Indirizzo otpauth:// per il QR e per il tasto "Apri nell'app". */
function totpIndirizzo($segretoBase32, $username) {
    $nome = (string)env('WEBMAIL_TOTP_NOME', 'PiGreco Posta');
    return 'otpauth://totp/' . rawurlencode($nome . ':' . $username)
        . '?secret=' . $segretoBase32 . '&issuer=' . rawurlencode($nome)
        . '&algorithm=SHA1&digits=' . TOTP_CIFRE . '&period=' . TOTP_PERIODO;
}

// ---------- dati dell'utente ----------

/** Chiave in base32 dell'utente, '' se non l'ha ancora attivata. */
function totpSegretoUtente($conn, $userId) {
    $stmt = $conn->prepare("SELECT totp_segreto FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $riga = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (empty($riga['totp_segreto'])) {
        return '';
    }
    return Crypto::decrypt($riga['totp_segreto']);
}

function totpUltimoPasso($conn, $userId) {
    $stmt = $conn->prepare("SELECT totp_ultimo_passo FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $riga = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $riga['totp_ultimo_passo'] ?? null;
}

/** Codice giusto e mai usato: lo segna come usato. */
function totpControlla($conn, $userId, $segretoBase32, $codice) {
    $passo = totpVerifica(base32Decodifica($segretoBase32), $codice, totpUltimoPasso($conn, $userId));
    if ($passo === null) {
        return false;
    }
    // Il WHERE evita che due richieste insieme usino lo stesso codice
    $stmt = $conn->prepare("UPDATE users SET totp_ultimo_passo = ?
        WHERE id = ? AND (totp_ultimo_passo IS NULL OR totp_ultimo_passo < ?)");
    $stmt->bind_param('iii', $passo, $userId, $passo);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    return $ok;
}

function totpSalvaSegreto($conn, $userId, $segretoBase32) {
    $cifrato = Crypto::encrypt($segretoBase32);
    $stmt = $conn->prepare("UPDATE users SET totp_segreto = ?, totp_attivo_dal = NOW() WHERE id = ?");
    $stmt->bind_param('si', $cifrato, $userId);
    $stmt->execute();
    $stmt->close();
}

/** Azzera chiave e dispositivi ricordati: al prossimo accesso da fuori ricompare il QR. */
function totpAzzera($conn, $userId) {
    $stmt = $conn->prepare("UPDATE users SET totp_segreto = NULL, totp_attivo_dal = NULL, totp_ultimo_passo = NULL
        WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $ok = $stmt->affected_rows === 1;
    $stmt->close();
    dimenticaDispositivi($conn, $userId);
    return $ok;
}

// ---------- "Ricorda questo dispositivo" ----------

function parametriCookieDispositivo($scade) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ['expires' => $scade, 'path' => percorsoCookie(), 'secure' => $https,
            'httponly' => true, 'samesite' => 'Lax'];
}

function dispositivoRiconosciuto($conn, $userId) {
    $token = $_COOKIE[TOTP_COOKIE_DISPOSITIVO] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare("SELECT id FROM totp_dispositivi
        WHERE token_hash = ? AND user_id = ? AND scadenza > NOW() LIMIT 1");
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
    $trovato = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $trovato;
}

function ricordaDispositivo($conn, $userId) {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $giorni = TOTP_GIORNI_DISPOSITIVO;
    $stmt = $conn->prepare("INSERT INTO totp_dispositivi (user_id, token_hash, scadenza, ip_address, user_agent)
        VALUES (?, ?, NOW() + INTERVAL ? DAY, ?, ?)");
    $stmt->bind_param('isiss', $userId, $hash, $giorni, $ip, $ua);
    $stmt->execute();
    $stmt->close();
    $conn->query("DELETE FROM totp_dispositivi WHERE scadenza < NOW()");
    setcookie(TOTP_COOKIE_DISPOSITIVO, $token, parametriCookieDispositivo(time() + $giorni * 86400));
}

function dimenticaDispositivi($conn, $userId) {
    $stmt = $conn->prepare("DELETE FROM totp_dispositivi WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}
