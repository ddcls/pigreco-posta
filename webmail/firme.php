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
 * firme.php - Firme delle email di una casella (lib/FirmeService.php, firme.js).
 *
 * GET  account_id: {success, firme: [...], predefinite: {nuove, risposte}}
 * GET  (senza account_id): {success, caselle: [{id, email, nome, societa}]}
 * POST azione =
 *   crea               account_id, nome              → id
 *   salva              id, nome, tipo, html, dati (JSON) → html salvato
 *   elimina            id
 *   predefinite        account_id, nuove, risposte (id o vuoto = nessuna)
 *   immagine           id (firma), file "immagine"   → immagine {id, ...}
 *   elimina_immagine   id (immagine)
 *
 * Le firme sono della casella: le vedono, le usano e le modificano tutti gli
 * utenti che possono usare quella casella (getAuthorizedAccountId).
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/config/mail_account_manager.php';
require_once __DIR__ . '/lib/FirmeService.php';

header('Content-Type: application/json; charset=utf-8');

/** La casella, se l'utente la può usare; altrimenti 403. */
function casellaAutorizzata($id) {
    if ($id === null || getAuthorizedAccountId($id) === null) {
        http_response_code(403);
        throw new Exception('Casella non valida o non autorizzata');
    }
    return (int)$id;
}

try {
    $servizio = new FirmeService();
    $utente = (int)($_SESSION['user_id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $account = requestParam(['account_id']);
        if ($account === '') {
            $manager = new MailAccountManager();
            $caselle = isAdmin() ? array_filter($manager->listAccounts(), fn($a) => $a['attivo'])
                                 : $manager->listAccountsForUser($utente);
            echo json_encode(['success' => true, 'caselle' => array_values(array_map(fn($a) => [
                'id' => (int)$a['id'], 'email' => $a['email'], 'nome' => $a['nome_visualizzato'],
                'societa' => $a['societa'] ?? '',
            ], $caselle))], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $account = casellaAutorizzata($account);
        echo json_encode(['success' => true] + $servizio->elenco($account), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Metodo non consentito');
    }

    $azione = requestParam(['azione']);
    $id = (int)requestParam(['id'], '0');
    $risposta = ['success' => true];
    switch ($azione) {
        case 'crea':
            $account = casellaAutorizzata(requestParam(['account_id']));
            $risposta['id'] = $servizio->crea($account, $_POST['nome'] ?? '', $utente);
            $risposta['message'] = 'Firma creata';
            break;
        case 'salva':
            casellaAutorizzata($servizio->accountDellaFirma($id));
            $dati = json_decode((string)($_POST['dati'] ?? ''), true);
            $risposta['html'] = $servizio->salva($id, $_POST['nome'] ?? '', (string)($_POST['tipo'] ?? ''),
                (string)($_POST['html'] ?? ''), is_array($dati) ? $dati : null, $utente);
            $risposta['message'] = 'Firma salvata';
            break;
        case 'elimina':
            casellaAutorizzata($servizio->accountDellaFirma($id));
            $servizio->elimina($id);
            $risposta['message'] = 'Firma eliminata';
            break;
        case 'predefinite':
            $account = casellaAutorizzata(requestParam(['account_id']));
            $servizio->impostaPredefinite($account, $_POST['nuove'] ?? '', $_POST['risposte'] ?? '');
            $risposta['message'] = 'Firme della casella salvate';
            break;
        case 'immagine':
            casellaAutorizzata($servizio->accountDellaFirma($id));
            $file = $_FILES['immagine'] ?? null;
            if (!$file || !is_uploaded_file($file['tmp_name'] ?? '') || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
                throw new Exception('Immagine non ricevuta');
            }
            if ((int)$file['size'] > FirmeService::MAX_IMMAGINE) {
                throw new Exception('Immagine troppo grande: al massimo 256 KB');
            }
            $risposta['immagine'] = $servizio->aggiungiImmagine($id, (string)$file['name'],
                file_get_contents($file['tmp_name']));
            break;
        case 'elimina_immagine':
            casellaAutorizzata($servizio->accountDellImmagine($id));
            $servizio->eliminaImmagine($id);
            break;
        default:
            throw new Exception('Azione non valida');
    }
    echo json_encode($risposta, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code($e instanceof Exception ? 400 : 500);
    }
    if (!($e instanceof Exception)) {
        error_log('firme.php: ' . $e->getMessage());
    }
    echo json_encode(['success' => false, 'error' => $e instanceof Exception ? $e->getMessage() : 'Errore interno'],
        JSON_UNESCAPED_UNICODE);
}
