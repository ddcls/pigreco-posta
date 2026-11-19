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
 * contatti.php - Rubrica dei contatti della webmail (lib/ContattiService.php, contatti.js).
 *
 * GET                     {success, contatti: [...]}
 * GET  azione=esporta     la rubrica in CSV (contatti.csv)
 * POST azione =
 *   salva     id (vuoto = nuovo), nome, cognome, azienda, categoria, email, telefono, sito_web, note → id
 *   elimina   id
 *   importa   file "file" (CSV), prova = 1 solo controllo / 0 importa
 *             → {righe, nuovi, presenti, errori, esempi, colonne}
 *
 * Una sola rubrica in comune: tutti gli utenti della webmail la leggono e la modificano.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/ContattiService.php';

try {
    $servizio = new ContattiService();
    $utente = (int)($_SESSION['user_id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['azione'] ?? '') === 'esporta') {
            $csv = $servizio->csv();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="contatti-' . date('Y-m-d') . '.csv"');
            header('Content-Length: ' . strlen($csv));
            echo $csv;
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'contatti' => $servizio->elenco()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Metodo non consentito');
    }

    $azione = requestParam(['azione']);
    $id = (int)requestParam(['id'], '0');
    switch ($azione) {
        case 'salva':
            $nuovoId = $servizio->salva($id, $_POST, $utente);
            $risposta = ['success' => true, 'id' => $nuovoId,
                'message' => $id > 0 ? 'Contatto aggiornato' : 'Contatto aggiunto alla rubrica'];
            break;
        case 'elimina':
            $servizio->elimina($id);
            $risposta = ['success' => true, 'message' => 'Contatto eliminato'];
            break;
        case 'importa':
            $file = $_FILES['file'] ?? null;
            if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                throw new Exception('File non ricevuto');
            }
            if ((int)$file['size'] > ContattiService::MAX_IMPORTA_BYTE) {
                throw new Exception('File troppo grande: al massimo 5 MB');
            }
            $prova = ($_POST['prova'] ?? '1') !== '0';
            $risposta = ['success' => true] + $servizio->importa(file_get_contents($file['tmp_name']), $prova, $utente);
            break;
        default:
            throw new Exception('Azione non valida');
    }
    echo json_encode($risposta, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    if (http_response_code() < 400) {
        http_response_code($e instanceof Exception ? 400 : 500);
    }
    if (!($e instanceof Exception) || $e instanceof RuntimeException) {
        error_log('contatti.php: ' . $e->getMessage());
    }
    $messaggio = $e instanceof Exception && !($e instanceof RuntimeException) ? $e->getMessage() : 'Errore interno';
    echo json_encode(['success' => false, 'error' => $messaggio], JSON_UNESCAPED_UNICODE);
}
