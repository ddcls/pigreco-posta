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
 * Avvio della sessione della webmail con un cookie dal nome proprio.
 *
 * Il nome predefinito di PHP (PHPSESSID) è lo stesso usato da altre
 * applicazioni PHP sullo stesso server, per esempio PiGreco Archivio: i
 * cookie non distinguono le porte, quindi due app sullo stesso host con lo
 * stesso nome di cookie si sovrascrivono la sessione a vicenda (entrando in
 * una si esce dall'altra).
 */
function avviaSessione() {
    session_name('WEBMAIL_SID');
    // HttpOnly: il cookie non è leggibile da JavaScript. SameSite=Lax: il
    // browser non lo manda con le richieste POST partite da altri siti.
    // Secure quando la pagina è in https (anche dietro un reverse proxy).
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Rifiuta id di sessione inventati da altri (session fixation)
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/**
 * Vero se la richiesta arriva da una pagina di un altro sito: il browser lo
 * dice con Sec-Fetch-Site (tutti i browser recenti) o, in mancanza, con
 * Origin. Le azioni che cambiano qualcosa (POST) vanno rifiutate (CSRF).
 */
function richiestaDaAltroSito() {
    $sito = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($sito !== '') {
        // "none" = indirizzo scritto a mano o segnalibro
        return !in_array($sito, ['same-origin', 'none'], true);
    }
    $origine = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origine === '' || $origine === 'null') {
        return $origine === 'null';
    }
    $host = parse_url($origine, PHP_URL_HOST);
    $porta = parse_url($origine, PHP_URL_PORT);
    $atteso = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    return strcasecmp($host . ($porta ? ':' . $porta : ''), $atteso) !== 0;
}
