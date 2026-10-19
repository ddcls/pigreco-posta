<?php
/**
 * Filtri sulle email in arrivo (tabelle "filtri" e "filtri_stato",
 * migrations/005_filtri.sql).
 *
 * Un filtro ha una o più condizioni (mittente, destinatari, oggetto, testo,
 * ovunque; "contiene" o "non contiene"), tutte o almeno una, e cosa fare:
 * spostare l'email in una cartella, nell'Indesiderata o nel Cestino, segnarla
 * come letta, come importante. Valgono per tutte le caselle (o per quella
 * indicata) chiunque li abbia creati, e li applica filtri_esegui.php ogni
 * minuto alle email nuove della Posta in arrivo, nell'ordine dei filtri.
 *
 * Le condizioni diventano un UID SEARCH sul server di posta, quindi valgono
 * le sue regole: il confronto è "contiene", senza maiuscole/minuscole.
 * Nessun filtro cancella definitivamente: "nel Cestino" è uno spostamento.
 * Sulle caselle copiate in locale cambia solo la copia locale, come gli
 * spostamenti fatti a mano.
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/mail_account_manager.php';
require_once __DIR__ . '/MailboxService.php';

class FiltriService {
    /** Campo → chiavi IMAP SEARCH (più chiavi = una qualsiasi). */
    const CAMPI = [
        'mittente'    => ['FROM'],
        'destinatari' => ['TO', 'CC'],
        'oggetto'     => ['SUBJECT'],
        'testo'       => ['BODY'],
        'ovunque'     => ['TEXT'],
    ];
    const OPERATORI = ['contiene', 'non_contiene'];
    const MAX_CONDIZIONI = 10;
    /** Destinazioni speciali di "sposta"; le altre sono "cartella:<percorso>". */
    const SPOSTA_SPECIALI = ['trash', 'junk'];
    const PREFISSO_CARTELLA = 'cartella:';

    private $conn;

    public function __construct() {
        $conn = getDBConnection();
        if (!$conn) {
            throw new RuntimeException('Connessione al database non disponibile');
        }
        $this->conn = $conn;
    }

    private function query($sql, $types = '', array $params = []) {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Errore database: ' . $this->conn->error);
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException('Errore database: ' . $stmt->error);
        }
        return $stmt;
    }

    // ==================== LETTURA ====================

    /** Tutti i filtri nell'ordine in cui si applicano, con chi li ha creati. */
    public function elenco() {
        $res = $this->conn->query(
            "SELECT f.*, a.email AS account_email, TRIM(CONCAT(u.nome, ' ', u.cognome)) AS creato_da_nome
             FROM filtri f
             LEFT JOIN mail_accounts a ON a.id = f.account_id
             LEFT JOIN users u ON u.id = f.creato_da
             ORDER BY f.ordine, f.id");
        if ($res === false) {
            throw new RuntimeException('Errore database: ' . $this->conn->error);
        }
        $filtri = [];
        while ($row = $res->fetch_assoc()) {
            $filtri[] = self::pubblico($row);
        }
        return $filtri;
    }

    private function riga($id) {
        $row = $this->query('SELECT * FROM filtri WHERE id = ?', 'i', [(int)$id])->get_result()->fetch_assoc();
        if (!$row) {
            throw new Exception('Filtro non trovato (forse è stato eliminato)');
        }
        return $row;
    }

    private static function pubblico(array $row) {
        foreach (['id', 'tutte', 'segna_letta', 'segna_importante', 'ferma', 'ordine', 'attivo',
                  'applica_esistenti', 'applicate'] as $k) {
            $row[$k] = (int)$row[$k];
        }
        $row['account_id'] = $row['account_id'] !== null ? (int)$row['account_id'] : null;
        $row['creato_da'] = $row['creato_da'] !== null ? (int)$row['creato_da'] : null;
        $row['condizioni'] = json_decode($row['condizioni'], true) ?: [];
        return $row;
    }

    // ==================== MODIFICA ====================

    /**
     * Controlla e normalizza i dati del form.
     * @return array colonne da salvare (condizioni già in JSON)
     */
    public static function valida(array $d) {
        $nome = trim(preg_replace('/\s+/u', ' ', (string)($d['nome'] ?? '')));
        if ($nome === '') {
            throw new Exception('Dai un nome al filtro');
        }
        if (mb_strlen($nome) > 100) {
            throw new Exception('Nome troppo lungo (al massimo 100 caratteri)');
        }

        $condizioni = [];
        foreach ((array)($d['condizioni'] ?? []) as $c) {
            $valore = trim((string)($c['valore'] ?? ''));
            if ($valore === '') {
                continue;   // riga lasciata vuota
            }
            $campo = (string)($c['campo'] ?? '');
            $operatore = (string)($c['operatore'] ?? 'contiene');
            if (!isset(self::CAMPI[$campo]) || !in_array($operatore, self::OPERATORI, true)) {
                throw new Exception('Condizione non valida');
            }
            if (mb_strlen($valore) > 200 || preg_match('/[\x00-\x1f\x7f]/', $valore)) {
                throw new Exception('Il testo di una condizione è troppo lungo o contiene caratteri non ammessi');
            }
            $condizioni[] = ['campo' => $campo, 'operatore' => $operatore, 'valore' => $valore];
        }
        if (!$condizioni) {
            throw new Exception('Scrivi almeno una condizione (es. il mittente contiene "rossi@esempio.it")');
        }
        if (count($condizioni) > self::MAX_CONDIZIONI) {
            throw new Exception('Al massimo ' . self::MAX_CONDIZIONI . ' condizioni per filtro');
        }
        $soloNegative = !array_filter($condizioni, fn($c) => $c['operatore'] === 'contiene');
        if ($soloNegative && count($condizioni) === 1) {
            // "non contiene X" da solo prenderebbe quasi tutta la posta: va bene,
            // ma solo se lo si chiede insieme a un'altra condizione
            throw new Exception('Una sola condizione "non contiene" vale per quasi tutte le email: aggiungine una "contiene"');
        }

        $sposta = trim((string)($d['sposta'] ?? ''));
        if ($sposta === '') {
            $sposta = null;
        } elseif (!in_array($sposta, self::SPOSTA_SPECIALI, true)) {
            $parti = self::partiPercorso($sposta);
            $sposta = self::PREFISSO_CARTELLA . implode(' / ', $parti);
        }
        $letta = !empty($d['segna_letta']) ? 1 : 0;
        $importante = !empty($d['segna_importante']) ? 1 : 0;
        if ($sposta === null && !$letta && !$importante) {
            throw new Exception('Scegli cosa fare con le email (spostarle, segnarle come lette o importanti)');
        }

        $account = $d['account_id'] ?? null;
        $account = ($account === null || $account === '' || $account === 'tutte') ? null : (int)$account;
        if ($account !== null) {
            $acc = (new MailAccountManager())->getAccount($account);
            if (!$acc) {
                throw new Exception('Casella non trovata');
            }
        }

        return [
            'nome' => $nome,
            'account_id' => $account,
            'condizioni' => json_encode($condizioni, JSON_UNESCAPED_UNICODE),
            'tutte' => !isset($d['tutte']) || !empty($d['tutte']) ? 1 : 0,
            'sposta' => $sposta,
            'segna_letta' => $letta,
            'segna_importante' => $importante,
            'ferma' => !empty($d['ferma']) ? 1 : 0,
            'attivo' => !isset($d['attivo']) || !empty($d['attivo']) ? 1 : 0,
        ];
    }

    /**
     * "Clienti / Rossi" (o "Clienti/Rossi") → ['Clienti', 'Rossi'], con le
     * stesse regole dei nomi di cartella (MailboxService::validFolderName).
     */
    public static function partiPercorso($percorso) {
        $parti = array_values(array_filter(array_map(
            fn($p) => trim(preg_replace('/\s+/u', ' ', $p)), explode('/', (string)$percorso)),
            fn($p) => $p !== ''));
        if (!$parti) {
            throw new Exception('Scrivi la cartella in cui spostare le email');
        }
        if (count($parti) > 5) {
            throw new Exception('Cartella troppo annidata (al massimo 5 livelli)');
        }
        foreach ($parti as $p) {
            if (mb_strlen($p) > 80) {
                throw new Exception('Nome di cartella troppo lungo (al massimo 80 caratteri)');
            }
            if (preg_match('/[\x00-\x1f\x7f.%*\\\\"]/', $p)) {
                throw new Exception('Il nome della cartella non può contenere . \\ % * "');
            }
        }
        if (count($parti) === 1 && strcasecmp($parti[0], 'INBOX') === 0) {
            throw new Exception('Scegli una cartella diversa dalla Posta in arrivo');
        }
        return $parti;
    }

    /** Nuovo filtro (in fondo all'elenco) o modifica. @return int id */
    public function salva(array $dati, $utenteId, $id = 0) {
        $v = self::valida($dati);
        if ($id > 0) {
            $this->riga($id);
            $this->query(
                'UPDATE filtri SET nome = ?, account_id = ?, condizioni = ?, tutte = ?, sposta = ?,
                    segna_letta = ?, segna_importante = ?, ferma = ?, attivo = ?, ultimo_errore = NULL
                 WHERE id = ?',
                'sisisiiiii', [$v['nome'], $v['account_id'], $v['condizioni'], $v['tutte'], $v['sposta'],
                    $v['segna_letta'], $v['segna_importante'], $v['ferma'], $v['attivo'], (int)$id]);
            return (int)$id;
        }
        $ordine = (int)($this->conn->query('SELECT COALESCE(MAX(ordine), 0) + 1 FROM filtri')->fetch_row()[0] ?? 1);
        $utente = $utenteId > 0 ? (int)$utenteId : null;
        $this->query(
            'INSERT INTO filtri (nome, account_id, condizioni, tutte, sposta, segna_letta, segna_importante,
                ferma, attivo, ordine, creato_da)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'sisisiiiiii', [$v['nome'], $v['account_id'], $v['condizioni'], $v['tutte'], $v['sposta'],
                $v['segna_letta'], $v['segna_importante'], $v['ferma'], $v['attivo'], $ordine, $utente]);
        return (int)$this->conn->insert_id;
    }

    public function elimina($id) {
        $this->riga($id);
        $this->query('DELETE FROM filtri WHERE id = ?', 'i', [(int)$id]);
    }

    public function attiva($id, $attivo) {
        $this->riga($id);
        $this->query('UPDATE filtri SET attivo = ?, ultimo_errore = NULL WHERE id = ?', 'ii', [$attivo ? 1 : 0, (int)$id]);
    }

    /** Sposta il filtro di un posto in su (-1) o in giù (+1). */
    public function sposta($id, $verso) {
        $ids = array_column($this->elenco(), 'id');
        $i = array_search((int)$id, $ids, true);
        if ($i === false) {
            throw new Exception('Filtro non trovato (forse è stato eliminato)');
        }
        $j = $i + ($verso < 0 ? -1 : 1);
        if ($j < 0 || $j >= count($ids)) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        foreach ($ids as $pos => $fid) {
            $this->query('UPDATE filtri SET ordine = ? WHERE id = ?', 'ii', [$pos + 1, $fid]);
        }
    }

    /** "Applica anche alle email già arrivate": lo fa filtri_esegui.php al prossimo giro. */
    public function richiediEsistenti($id) {
        $f = $this->riga($id);
        if (!$f['attivo']) {
            throw new Exception('Il filtro è spento: accendilo prima di applicarlo');
        }
        $this->query('UPDATE filtri SET applica_esistenti = 1 WHERE id = ?', 'i', [(int)$id]);
    }

    // ==================== APPLICAZIONE ====================

    /**
     * Criterio UID SEARCH delle condizioni (senza l'intervallo di UID).
     * @return array [criterio, serve CHARSET UTF-8]
     */
    public static function criterio(array $condizioni, $tutte, callable $quote) {
        $termini = [];
        $utf8 = false;
        foreach ($condizioni as $c) {
            $valore = $quote($c['valore']);
            $utf8 = $utf8 || (bool)preg_match('/[\x80-\xFF]/', $c['valore']);
            $chiavi = array_map(fn($k) => "{$k} {$valore}", self::CAMPI[$c['campo']]);
            // destinatari = TO o CC: "OR TO x CC x"
            $t = count($chiavi) > 1 ? 'OR ' . implode(' ', $chiavi) : $chiavi[0];
            $termini[] = ($c['operatore'] === 'non_contiene' ? 'NOT ' : '') . "({$t})";
        }
        if ($tutte) {
            return [implode(' ', $termini), $utf8];
        }
        // OR in IMAP è binario: OR a (OR b c)
        $criterio = array_pop($termini);
        while ($termini) {
            $criterio = 'OR ' . array_pop($termini) . " ({$criterio})";
        }
        return [$criterio, $utf8];
    }

    /** Filtri accesi che valgono per la casella, nell'ordine. */
    private static function perCasella(array $filtri, $accountId) {
        return array_values(array_filter($filtri,
            fn($f) => $f['attivo'] && ($f['account_id'] === null || $f['account_id'] === (int)$accountId)));
    }

    /**
     * Un giro: per ogni casella attiva con almeno un filtro, applica i filtri
     * alle email arrivate dopo l'ultimo giro e, a quelli che lo chiedono, a
     * tutta la Posta in arrivo. Le caselle senza filtri perdono il loro
     * stato: quando ne avranno uno si riparte dalle email nuove da lì.
     * @param callable|null $log fn(string) per le righe da scrivere nel log
     */
    public function eseguiGiro(?callable $log = null) {
        $log = $log ?? function ($r) {};
        $filtri = $this->elenco();
        $manager = new MailAccountManager();
        $caselle = array_filter($manager->listAccounts(), fn($a) => $a['attivo']);

        $richiesti = array_column(array_filter($filtri, fn($f) => $f['applica_esistenti']), 'id');
        $errori = [];   // id filtro → errore

        foreach ($caselle as $acc) {
            $suoi = self::perCasella($filtri, $acc['id']);
            if (!$suoi) {
                $this->query('DELETE FROM filtri_stato WHERE account_id = ?', 'i', [$acc['id']]);
                continue;
            }
            $mailbox = null;
            try {
                $mailbox = new MailboxService($manager->getAccountWithCredentials($acc['id']));
                $esistenti = array_values(array_filter($suoi, fn($f) => $f['applica_esistenti']));
                foreach ($esistenti as $f) {
                    $n = $this->applica($mailbox, [$f], null, $errori);
                    if ($n) {
                        $log("{$acc['email']}: filtro \"{$f['nome']}\" applicato a {$n} email già arrivate");
                    }
                }
                $n = $this->applicaNuove($mailbox, $suoi, $errori);
                if ($n) {
                    $log("{$acc['email']}: email nuove filtrate: {$n}");
                }
            } catch (Throwable $e) {
                $errore = mb_substr($e->getMessage(), 0, 250);
                $this->query('UPDATE filtri_stato SET errore = ?, controllato = NOW() WHERE account_id = ?',
                    'si', [$errore, $acc['id']]);
                $log("{$acc['email']}: {$errore}");
            } finally {
                if ($mailbox) {
                    $mailbox->close();
                }
            }
        }

        foreach ($richiesti as $id) {
            $this->query('UPDATE filtri SET applica_esistenti = 0 WHERE id = ?', 'i', [$id]);
        }
        foreach ($filtri as $f) {
            if ($f['attivo'] && isset($errori[$f['id']])) {
                $this->query('UPDATE filtri SET ultimo_errore = ? WHERE id = ?', 'si',
                    [mb_substr($errori[$f['id']], 0, 250), $f['id']]);
            }
        }
    }

    /** Email nuove della Posta in arrivo (dopo l'ultimo UID visto). @return int email filtrate */
    private function applicaNuove(MailboxService $mailbox, array $filtri, array &$errori) {
        $id = (int)$mailbox->getAccount()['id'];
        $info = $mailbox->folderStatus('inbox');
        if ($info === null) {
            return 0;
        }
        $stato = $this->query('SELECT * FROM filtri_stato WHERE account_id = ?', 'i', [$id])->get_result()->fetch_assoc();
        $ultimo = $stato && (int)$stato['uidvalidity'] === (int)$info['uidvalidity'] ? (int)$stato['ultimo_uid'] : null;

        if ($ultimo === null) {
            // Primo giro (o cartella ricreata dal server): si parte da qui,
            // le email già presenti si filtrano solo se lo si chiede
            $tutti = $info['exists'] > 0 ? $mailbox->searchUids('inbox', 'ALL') : [];
            $max = $info['uidnext'] > 0 ? $info['uidnext'] - 1 : ($tutti ? max($tutti) : 0);
            $this->salvaStato($id, $info['uidvalidity'], $max);
            return 0;
        }

        // "n:*" restituisce comunque l'ultima email anche se ha UID < n
        $nuovi = array_values(array_filter($mailbox->searchUids('inbox', 'UID ' . ($ultimo + 1) . ':*'),
            fn($u) => $u > $ultimo));
        if (!$nuovi) {
            $this->salvaStato($id, $info['uidvalidity'], $ultimo);
            return 0;
        }
        $n = $this->applica($mailbox, $filtri, [$ultimo + 1, max($nuovi)], $errori);
        $this->salvaStato($id, $info['uidvalidity'], max($nuovi));
        return $n;
    }

    private function salvaStato($accountId, $uidvalidity, $ultimo) {
        $this->query(
            'INSERT INTO filtri_stato (account_id, uidvalidity, ultimo_uid, controllato, errore)
             VALUES (?, ?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE uidvalidity = VALUES(uidvalidity), ultimo_uid = VALUES(ultimo_uid),
                controllato = NOW(), errore = NULL',
            'iii', [(int)$accountId, (int)$uidvalidity, (int)$ultimo]);
    }

    /**
     * Applica i filtri, nell'ordine, alle email della Posta in arrivo con UID
     * nell'intervallo (null = tutte). Per ogni email: i "segna" si sommano,
     * vince il primo spostamento, dopo uno spostamento o un filtro con
     * "ferma" gli altri non valgono più.
     * @return int quante email sono state toccate da almeno un filtro
     */
    private function applica(MailboxService $mailbox, array $filtri, ?array $intervallo, array &$errori) {
        $quote = fn($v) => $mailbox->quote($v);
        $prefisso = $intervallo ? "UID {$intervallo[0]}:{$intervallo[1]} " : '';

        $trovate = [];   // id filtro → [uid => true]
        foreach ($filtri as $f) {
            [$criterio, $utf8] = self::criterio($f['condizioni'], $f['tutte'], $quote);
            $uids = $mailbox->searchUids('inbox', $prefisso . $criterio, $utf8);
            if ($intervallo) {
                $uids = array_filter($uids, fn($u) => $u >= $intervallo[0] && $u <= $intervallo[1]);
            }
            $trovate[$f['id']] = array_fill_keys($uids, true);
        }

        $letta = $importante = [];   // uid → true
        $sposta = [];                // destinazione → ['uids' => [...], 'filtri' => [id => true]]
        $perFiltro = [];             // id filtro → quante
        $tutteLeUid = [];
        foreach ($trovate as $t) {
            $tutteLeUid += $t;
        }
        $tutteLeUid = array_keys($tutteLeUid);
        sort($tutteLeUid);
        foreach ($tutteLeUid as $uid) {
            foreach ($filtri as $f) {
                if (!isset($trovate[$f['id']][$uid])) {
                    continue;
                }
                $perFiltro[$f['id']] = ($perFiltro[$f['id']] ?? 0) + 1;
                if ($f['segna_letta']) $letta[$uid] = true;
                if ($f['segna_importante']) $importante[$uid] = true;
                if ($f['sposta'] !== null) {
                    $sposta[$f['sposta']]['uids'][] = $uid;
                    $sposta[$f['sposta']]['filtri'][$f['id']] = true;
                    break;
                }
                if ($f['ferma']) {
                    break;
                }
            }
        }

        if ($letta) {
            $mailbox->setSeenMany('inbox', array_keys($letta), true);
        }
        if ($importante) {
            $mailbox->setFlaggedMany('inbox', array_keys($importante), true);
        }
        foreach ($sposta as $destinazione => ['uids' => $uids, 'filtri' => $ids]) {
            $ids = array_keys($ids);
            try {
                $target = in_array($destinazione, self::SPOSTA_SPECIALI, true)
                    ? $destinazione
                    : $mailbox->folderByPath(self::partiPercorso(substr($destinazione, strlen(self::PREFISSO_CARTELLA))), true);
                $mailbox->moveMany('inbox', $uids, $target);
            } catch (Throwable $e) {
                // Le altre azioni sono fatte: le email restano nella Posta in arrivo
                foreach ($ids as $fid) {
                    $errori[$fid] = 'Spostamento non riuscito: ' . $e->getMessage();
                }
            }
        }

        foreach ($perFiltro as $fid => $n) {
            $this->query('UPDATE filtri SET applicate = applicate + ?, ultima_applicazione = NOW() WHERE id = ?',
                'ii', [$n, $fid]);
            if (!isset($errori[$fid])) {
                $this->query('UPDATE filtri SET ultimo_errore = NULL WHERE id = ?', 'i', [$fid]);
            }
        }
        return count($tutteLeUid);
    }
}
