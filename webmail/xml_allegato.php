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
 * xml_allegato.php - Un allegato XML reso leggibile (lib/XmlAllegato.php).
 *
 * Parametri GET come get_attachment_content.php:
 * - account_id (o "user"), file (o "uid"), index, folder
 *
 * Risposta JSON: {success, nome, tipo, titolo, esito, frase, righe, sezioni,
 * avvisi, testo, firmato}. Solo testi: la pagina li mostra con textContent.
 * Ricevute PEC (daticert.xml), notifiche SdI, fatture elettroniche (anche
 * .xml.p7m), qualsiasi altro XML come testo ordinato.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/MailboxService.php';
require_once __DIR__ . '/lib/XmlAllegato.php';
require_once __DIR__ . '/lib/AllegatoRichiesto.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function xmlErrore(string $messaggio, int $codice): never {
    http_response_code($codice);
    echo json_encode(['success' => false, 'error' => $messaggio], JSON_UNESCAPED_UNICODE);
    exit;
}

$allegato = allegatoRichiesto('xmlErrore');
$nome = $allegato['nome'];
if (!XmlAllegato::eXml($nome, $allegato['tipo'])) {
    xmlErrore('L\'allegato non è un file XML', 400);
}

$risultato = XmlAllegato::analizza($allegato['contenuto'], $nome);
$json = json_encode(['success' => true, 'nome' => $nome] + $risultato,
    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
echo $json === false ? json_encode(['success' => false, 'error' => 'Risposta non codificabile']) : $json;
