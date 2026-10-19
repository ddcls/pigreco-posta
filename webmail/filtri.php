<?php
/**
 * filtri.php - Filtri sulle email in arrivo (lib/FiltriService.php, filtri.js).
 *
 * GET: {success, filtri: [...], caselle: [{id, email, nome}]}
 * POST azione =
 *   salva     dati = JSON del filtro (id per modificarlo)
 *   elimina   id
 *   attiva    id, attivo (1/0)
 *   sposta    id, verso (-1 su, 1 giù)
 *   esistenti id  ("applica anche alle email già arrivate", al prossimo giro)
 *
 * I filtri valgono per tutte le caselle, chiunque li abbia creati: li vedono
 * e li modificano tutti gli utenti della webmail. Nella scelta "solo questa
 * casella" ognuno vede le caselle che può usare.
 */

require_once __DIR__ . '/check_auth.php';
require_once __DIR__ . '/lib/FiltriService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $servizio = new FiltriService();
    $manager = new MailAccountManager();
    $utente = (int)($_SESSION['user_id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $caselle = isAdmin() ? array_filter($manager->listAccounts(), fn($a) => $a['attivo'])
                             : $manager->listAccountsForUser($utente);
        echo json_encode([
            'success' => true,
            'filtri' => $servizio->elenco(),
            'caselle' => array_values(array_map(fn($a) => [
                'id' => $a['id'], 'email' => $a['email'], 'nome' => $a['nome_visualizzato'],
            ], $caselle)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Metodo non consentito');
    }

    $azione = requestParam(['azione']);
    $id = (int)requestParam(['id'], '0');
    switch ($azione) {
        case 'salva':
            $dati = json_decode((string)($_POST['dati'] ?? ''), true);
            if (!is_array($dati)) {
                throw new Exception('Dati del filtro non validi');
            }
            $account = $dati['account_id'] ?? null;
            if ($account !== null && $account !== '' && $account !== 'tutte'
                    && getAuthorizedAccountId($account) === null) {
                http_response_code(403);
                throw new Exception('Casella non valida o non autorizzata');
            }
            $id = $servizio->salva($dati, $utente, (int)($dati['id'] ?? 0));
            $messaggio = !empty($dati['id']) ? 'Filtro salvato' : 'Filtro creato: vale per le email che arrivano da ora';
            break;
        case 'elimina':
            $servizio->elimina($id);
            $messaggio = 'Filtro eliminato';
            break;
        case 'attiva':
            $acceso = requestParam(['attivo'], '0') === '1';
            $servizio->attiva($id, $acceso);
            $messaggio = $acceso ? 'Filtro acceso' : 'Filtro spento';
            break;
        case 'sposta':
            $servizio->sposta($id, (int)requestParam(['verso'], '1'));
            $messaggio = 'Ordine cambiato';
            break;
        case 'esistenti':
            $servizio->richiediEsistenti($id);
            $messaggio = 'Entro un minuto il filtro viene applicato anche alle email già nella Posta in arrivo';
            break;
        default:
            throw new Exception('Azione non valida');
    }
    echo json_encode(['success' => true, 'message' => $messaggio, 'id' => $id], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code($e instanceof Exception ? 400 : 500);
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
