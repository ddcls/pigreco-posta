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

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/tema.php';
header('Content-Type: application/json; charset=utf-8');

/**
 * salva_tema.php - Salva il colore dell'interfaccia (finestra "Aspetto").
 *
 * Parametri POST:
 * - tema: id del tema (vedi lib/tema.php)
 * - predefinito: "1" per usarlo anche come colore predefinito per tutti
 *   (solo amministratore)
 */

function rispondi($codice, array $dati) {
    http_response_code($codice);
    echo json_encode($dati, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rispondi(405, ['success' => false, 'error' => 'Metodo non consentito']);
}
// Solo richieste dalla pagina della webmail stessa (non da altri siti)
$origine = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
if ($origine !== '' && $origine !== 'same-origin') {
    rispondi(403, ['success' => false, 'error' => 'Richiesta non consentita']);
}

$tema = $_POST['tema'] ?? '';
if (!temaValido($tema)) {
    rispondi(400, ['success' => false, 'error' => 'Colore non valido']);
}
$predefinito = ($_POST['predefinito'] ?? '') === '1';
if ($predefinito && !isAdmin()) {
    rispondi(403, ['success' => false, 'error' => 'Solo l\'amministratore può cambiare il colore predefinito']);
}

$conn = getDBConnection();
if (!$conn) {
    rispondi(500, ['success' => false, 'error' => 'Database non raggiungibile']);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$stmt = $conn->prepare("UPDATE users SET tema = ? WHERE id = ?");
if (!$stmt) {
    rispondi(500, ['success' => false, 'error' => 'Manca l\'aggiornamento del database (migrations/003_tema.sql)']);
}
$stmt->bind_param("si", $tema, $userId);
$stmt->execute();
$stmt->close();

if ($predefinito) {
    $stmt = $conn->prepare("INSERT INTO impostazioni (chiave, valore) VALUES ('tema_predefinito', ?)
                            ON DUPLICATE KEY UPDATE valore = VALUES(valore)");
    if (!$stmt) {
        rispondi(500, ['success' => false, 'error' => 'Manca l\'aggiornamento del database (migrations/003_tema.sql)']);
    }
    $stmt->bind_param("s", $tema);
    $stmt->execute();
    $stmt->close();
}

rispondi(200, ['success' => true, 'tema' => $tema, 'predefinito' => temaPredefinito()]);
