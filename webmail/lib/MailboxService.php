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
 * Accesso in lettura a una casella IMAP (Dovecot) di un account configurato
 * in "mail_accounts". Usato dagli endpoint get_*.php al posto della lettura
 * diretta del Maildir.
 *
 * Le cartelle sono indicate con i nomi logici usati dal frontend
 * (inbox, sent, draft, junk, trash) e risolte nei nomi reali del server
 * tramite SPECIAL-USE. Le altre cartelle del server (create dall'utente)
 * si indicano con "imap:" seguito dal nome IMAP grezzo (es. "imap:INBOX.Clienti").
 * I messaggi sono identificati dal loro UID IMAP.
 */

require_once __DIR__ . '/../config/mail_account_manager.php';
require_once __DIR__ . '/ImapClient.php';
require_once __DIR__ . '/MailHeaders.php';

class MailboxService {
    const LOGICAL_FOLDERS = ['inbox', 'sent', 'draft', 'junk', 'trash'];
    const LIST_HEADERS = 'BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE)]';

    private $account;
    private $imap = null;
    private $folderMap = null;
    private $folders = null;    // risultato di LIST, in cache
    private $selected = null;   // [nome cartella, sola lettura]

    /** @param array $account riga di mail_accounts con password decifrate */
    public function __construct(array $account) {
        $this->account = $account;
    }

    public function __destruct() {
        $this->close();
    }

    public function getAccount() {
        return $this->account;
    }

    private function imap() {
        if ($this->imap === null) {
            $a = $this->account;
            $verify = filter_var(env('IMAP_TLS_VERIFY', '0'), FILTER_VALIDATE_BOOLEAN);
            $client = new ImapClient($a['imap_host'], $a['imap_port'], $a['imap_security'], $verify, 20);
            $client->connect();
            $client->login($a['imap_username'], $a['imap_password']);
            $this->imap = $client;
        }
        return $this->imap;
    }

    public function close() {
        if ($this->imap !== null) {
            $this->imap->logout();
            $this->imap = null;
        }
    }

    // ==================== CARTELLE ====================

    /** Prefisso delle cartelle non speciali: "imap:" + nome IMAP grezzo. */
    const PREFISSO_CARTELLA = 'imap:';

    /**
     * Vero se il parametro "folder" ricevuto dal frontend è ammissibile:
     * una cartella logica o "imap:<nome>" (l'esistenza si verifica dopo).
     */
    public static function isFolderParam($folder) {
        if (in_array($folder, self::LOGICAL_FOLDERS, true)) {
            return true;
        }
        $p = self::PREFISSO_CARTELLA;
        return is_string($folder) && strncmp($folder, $p, strlen($p)) === 0
            && strlen($folder) > strlen($p) && strlen($folder) <= 1000
            && !preg_match('/[\x00-\x1f\x7f]/', $folder);
    }

    private function mailboxes() {
        if ($this->folders === null) {
            $this->folders = $this->imap()->listMailboxes();
        }
        return $this->folders;
    }

    private static function isSelectable(array $f) {
        foreach ($f['flags'] as $flag) {
            $flag = strtoupper((string)$flag);
            if ($flag === '\\NOSELECT' || $flag === '\\NONEXISTENT') {
                return false;
            }
        }
        return true;
    }

    /**
     * Nome reale sul server della cartella (logica o "imap:<nome>"),
     * o null se non esiste o non si può aprire.
     */
    public function folderName($logical) {
        $p = self::PREFISSO_CARTELLA;
        if (strncmp((string)$logical, $p, strlen($p)) === 0) {
            $name = substr($logical, strlen($p));
            foreach ($this->mailboxes() as $f) {
                if ($f['name'] === $name) {
                    return self::isSelectable($f) ? $name : null;
                }
            }
            return null;
        }
        if ($this->folderMap === null) {
            $this->folderMap = ImapClient::detectSpecialFolders($this->mailboxes());
        }
        return $this->folderMap[$logical] ?? null;
    }

    /**
     * Cartelle della casella diverse da quelle speciali (Inbox, Inviata...),
     * nell'ordine dell'albero. Le cartelle intermedie non apribili (\Noselect)
     * sono incluse solo per mostrare la gerarchia.
     * @return array lista di ['id' => "imap:<nome>", 'nome' => ultimo livello,
     *               'percorso' => percorso completo, 'livello' => 0.., 'apribile' => bool,
     *               'modificabile' => si può rinominare/eliminare, vedi folderEditable()]
     */
    public function otherFolders() {
        $special = [];
        foreach (self::LOGICAL_FOLDERS as $logical) {
            $name = $this->folderName($logical);
            if ($name !== null) {
                $special[$name] = true;
            }
        }

        $result = [];
        $created = $this->isPostaDigitale() ? $this->createdFolders() : null;
        foreach ($this->mailboxes() as $f) {
            if (isset($special[$f['name']]) || strcasecmp($f['name'], 'INBOX') === 0) {
                continue;
            }
            $delim = is_string($f['delimiter']) ? $f['delimiter'] : '';
            $parts = $delim !== '' ? explode($delim, $f['display']) : [$f['display']];
            // Le sottocartelle della Inbox (layout Maildir++ "INBOX.Nome")
            // si mostrano al primo livello
            if (count($parts) > 1 && strcasecmp($parts[0], 'INBOX') === 0) {
                array_shift($parts);
            }
            $result[] = [
                'id'       => self::PREFISSO_CARTELLA . $f['name'],
                'nome'     => end($parts),
                'percorso' => implode(' / ', $parts),
                'livello'  => count($parts) - 1,
                'apribile' => self::isSelectable($f),
                'modificabile' => $this->folderEditable($f['name'], $created),
                'parti'    => $parts,
            ];
        }
        // Ordine per livelli, così ogni sottocartella segue la sua cartella
        usort($result, function ($a, $b) {
            for ($i = 0, $n = min(count($a['parti']), count($b['parti'])); $i < $n; $i++) {
                $c = strnatcasecmp($a['parti'][$i], $b['parti'][$i]);
                if ($c !== 0) return $c;
            }
            return count($a['parti']) <=> count($b['parti']);
        });
        foreach ($result as &$r) {
            unset($r['parti']);
        }
        return $result;
    }

    // ==================== GESTIONE CARTELLE ====================

    /** Server IMAP della copia locale (copia locale delle caselle, posta/). */
    const HOST_POSTA_DIGITALE = ['posta', 'posta_dovecot'];

    /**
     * Vero se la casella è la copia locale: cartelle e
     * spostamenti valgono solo lì, e le cartelle che vengono dal fornitore
     * non vanno rinominate né eliminate (mbsync le ricreerebbe e
     * riscaricherebbe le email).
     */
    public function isPostaDigitale() {
        return in_array(strtolower((string)$this->account['imap_host']), self::HOST_POSTA_DIGITALE, true);
    }

    /** Prefisso e separatore del namespace personale (es. ["INBOX.", "."] su Aruba). */
    private function personalNamespace() {
        $prefix = '';
        $delim = null;
        if ($this->imap()->hasCapability('NAMESPACE')) {
            $res = $this->imap()->command('NAMESPACE');
            foreach ($res['untagged'] as $line) {
                if (preg_match('/^\*\s+NAMESPACE\s+\(\("((?:[^"\\\\]|\\\\.)*)"\s+(?:"((?:[^"\\\\]|\\\\.)*)"|NIL)/i', $line, $m)) {
                    $prefix = stripslashes($m[1]);
                    $delim = isset($m[2]) && $m[2] !== '' ? stripslashes($m[2]) : null;
                }
            }
        }
        if ($delim === null) {
            foreach ($this->mailboxes() as $f) {
                if (is_string($f['delimiter']) && $f['delimiter'] !== '') {
                    $delim = $f['delimiter'];
                    break;
                }
            }
        }
        return [$prefix, $delim ?? '/'];
    }

    /** Cartella "imap:<nome>" esistente → [nome grezzo, separatore]. */
    private function userFolder($id) {
        $p = self::PREFISSO_CARTELLA;
        if (strncmp((string)$id, $p, strlen($p)) !== 0) {
            throw new Exception('Le cartelle Posta in arrivo, Inviata, Bozze, Indesiderata e Cestino non si possono modificare');
        }
        $name = substr($id, strlen($p));
        foreach ($this->mailboxes() as $f) {
            if ($f['name'] === $name) {
                foreach (self::LOGICAL_FOLDERS as $logical) {
                    if ($this->folderName($logical) === $name) {
                        throw new Exception('Le cartelle Posta in arrivo, Inviata, Bozze, Indesiderata e Cestino non si possono modificare');
                    }
                }
                return [$name, is_string($f['delimiter']) && $f['delimiter'] !== '' ? $f['delimiter'] : $this->personalNamespace()[1]];
            }
        }
        throw new ImapException('La cartella non esiste più su questo account');
    }

    /** Nome scritto dall'utente → nome IMAP del livello, con i controlli. */
    private static function validFolderName($nome, $delim) {
        $nome = trim(preg_replace('/\s+/u', ' ', (string)$nome));
        if ($nome === '') {
            throw new Exception('Scrivi il nome della cartella');
        }
        if (mb_strlen($nome) > 80) {
            throw new Exception('Nome troppo lungo (al massimo 80 caratteri)');
        }
        // "/" e "." separano le sottocartelle (anche nella copia locale)
        if (preg_match('/[\x00-\x1f\x7f\/.%*\\\\"]/', $nome) || strpos($nome, $delim) !== false) {
            throw new Exception('Il nome non può contenere / . \\ % * " ');
        }
        if (strcasecmp($nome, 'INBOX') === 0) {
            throw new Exception('Nome riservato: scegline un altro');
        }
        return ImapClient::encodeMailboxName($nome);
    }

    private function mailboxExists($name) {
        foreach ($this->mailboxes() as $f) {
            if (strcasecmp($f['name'], $name) === 0) return true;
        }
        return false;
    }

    /** Cartelle create dalla webmail su questo account (nomi grezzi). */
    private function createdFolders() {
        $stmt = getDBConnection()->prepare('SELECT nome FROM cartelle_create WHERE account_id = ?');
        $id = (int)$this->account['id'];
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'nome');
    }

    /**
     * Si può rinominare o eliminare dalla webmail: sempre, tranne sulle
     * caselle copiate in locale, dove solo le cartelle create dalla webmail
     * (le altre vengono dal fornitore).
     */
    public function folderEditable($name, ?array $created = null) {
        if (!$this->isPostaDigitale()) return true;
        return in_array($name, $created ?? $this->createdFolders(), true);
    }

    private function forgetFolderList() {
        $this->folders = null;
        $this->folderMap = null;
    }

    /**
     * Crea una cartella, al primo livello ($parentId vuoto) o dentro
     * "imap:<nome>". @return string id "imap:<nome>" della nuova cartella
     */
    public function createFolder($parentId, $nome) {
        if ($parentId === '' || $parentId === null) {
            [$prefix, $delim] = $this->personalNamespace();
            $full = $prefix . self::validFolderName($nome, $delim);
        } else {
            [$parent, $delim] = $this->userFolder($parentId);
            $full = $parent . $delim . self::validFolderName($nome, $delim);
        }
        if ($this->mailboxExists($full)) {
            throw new Exception('Esiste già una cartella con questo nome');
        }
        $this->imap()->createMailbox($full);
        $this->forgetFolderList();
        if ($this->isPostaDigitale()) {
            $stmt = getDBConnection()->prepare('INSERT IGNORE INTO cartelle_create (account_id, nome) VALUES (?, ?)');
            $id = (int)$this->account['id'];
            $stmt->bind_param('is', $id, $full);
            $stmt->execute();
        }
        return self::PREFISSO_CARTELLA . $full;
    }

    /** Rinomina (stesso livello). @return string nuovo id "imap:<nome>" */
    public function renameFolder($folderId, $nome) {
        [$name, $delim] = $this->userFolder($folderId);
        if (!$this->folderEditable($name)) {
            throw new Exception('Questa cartella viene dal fornitore di posta: si può rinominare solo presso il fornitore');
        }
        $pos = strrpos($name, $delim);
        $full = ($pos === false ? $this->personalNamespace()[0] : substr($name, 0, $pos + strlen($delim)))
            . self::validFolderName($nome, $delim);
        if ($full === $name) {
            return self::PREFISSO_CARTELLA . $name;
        }
        if (strcasecmp($full, $name) !== 0 && $this->mailboxExists($full)) {
            throw new Exception('Esiste già una cartella con questo nome');
        }
        // Query pronta prima del RENAME: se fallisse, il server resta com'era.
        // Anche le sottocartelle cambiano nome (nomi IMAP: ASCII)
        $stmt = null;
        if ($this->isPostaDigitale()) {
            $stmt = getDBConnection()->prepare(
                'UPDATE cartelle_create SET nome = CONCAT(CONVERT(? USING ascii), SUBSTRING(nome, ?))
                 WHERE account_id = ? AND (nome = CONVERT(? USING ascii) OR nome LIKE CONVERT(? USING ascii))');
            if (!$stmt) {
                throw new Exception('Cartella non rinominata: errore del database della webmail');
            }
        }
        $this->closeSelected();
        $this->imap()->command('RENAME ' . $this->imap()->quote($name) . ' ' . $this->imap()->quote($full));
        try {
            $this->imap()->command('SUBSCRIBE ' . $this->imap()->quote($full));
        } catch (ImapException $e) {
            // la sottoscrizione è solo per i client che la usano
        }
        $this->forgetFolderList();
        if ($stmt) {
            $id = (int)$this->account['id'];
            $da = strlen($name) + 1;
            $figli = addcslashes($name . $delim, '%_\\') . '%';
            $stmt->bind_param('siiss', $full, $da, $id, $name, $figli);
            $stmt->execute();
        }
        return self::PREFISSO_CARTELLA . $full;
    }

    /** Elimina una cartella vuota e senza sottocartelle. */
    public function deleteFolder($folderId) {
        [$name, $delim] = $this->userFolder($folderId);
        if (!$this->folderEditable($name)) {
            throw new Exception('Questa cartella viene dal fornitore di posta: si può eliminare solo presso il fornitore');
        }
        foreach ($this->mailboxes() as $f) {
            if (strncmp($f['name'], $name . $delim, strlen($name . $delim)) === 0) {
                throw new Exception('La cartella contiene altre cartelle: eliminale prima');
            }
        }
        $info = $this->imap()->select($name, true);
        $this->selected = [$name, true];
        if ($info['exists'] > 0) {
            throw new Exception($info['exists'] === 1
                ? 'La cartella contiene 1 email: spostala o eliminala prima'
                : "La cartella contiene {$info['exists']} email: spostale o eliminale prima");
        }
        $this->closeSelected();
        try {
            $this->imap()->command('UNSUBSCRIBE ' . $this->imap()->quote($name));
        } catch (ImapException $e) {
            // non era sottoscritta
        }
        $this->imap()->command('DELETE ' . $this->imap()->quote($name));
        $this->forgetFolderList();
        if ($this->isPostaDigitale()) {
            $stmt = getDBConnection()->prepare('DELETE FROM cartelle_create WHERE account_id = ? AND nome = CONVERT(? USING ascii)');
            $id = (int)$this->account['id'];
            $stmt->bind_param('is', $id, $name);
            $stmt->execute();
        }
    }

    /** Chiude la cartella aperta (RENAME/DELETE di quella aperta non sono ammessi ovunque). */
    private function closeSelected() {
        if ($this->selected !== null) {
            // Mai CLOSE: in scrittura cancellerebbe le email marcate \Deleted.
            // Senza UNSELECT si apre la Inbox in sola lettura, che chiude
            // l'altra cartella senza cancellare niente (RFC 3501 6.3.1)
            if ($this->imap()->hasCapability('UNSELECT')) {
                $this->imap()->command('UNSELECT');
                $this->selected = null;
            } else {
                $this->imap()->select('INBOX', true);
                $this->selected = ['INBOX', true];
            }
        }
    }

    private function open($logical, $readOnly) {
        $name = $this->folderName($logical);
        if ($name === null) {
            return null;
        }
        // Una cartella già aperta in scrittura va bene anche per leggere
        if ($this->selected === null || $this->selected[0] !== $name || ($this->selected[1] && !$readOnly)) {
            $this->imap()->select($name, $readOnly);
            $this->selected = [$name, $readOnly];
        }
        return $name;
    }

    // ==================== LETTURA ====================

    /**
     * Elenco paginato, dal più recente (ordine di arrivo), nello stesso
     * formato restituito in precedenza da get_emails.php.
     */
    public function listMessages($logical, $page, $limit) {
        $offset = ($page - 1) * $limit;
        $result = [
            'emails' => [], 'total' => 0, 'new_count' => 0, 'limit' => $limit,
            'offset' => $offset, 'page' => $page, 'total_pages' => 0,
        ];

        if ($this->open($logical, true) === null) {
            $result['error'] = "La cartella '{$logical}' non esiste su questo account";
            return $result;
        }

        $imap = $this->imap();
        $uids = $this->newestFirst('ALL');
        $result['total'] = count($uids);
        $result['new_count'] = count($imap->uidSearch('UNSEEN'));
        $result['total_pages'] = $limit > 0 ? (int)ceil($result['total'] / $limit) : 1;

        $pageUids = array_slice($uids, $offset, $limit);
        $result['emails'] = $this->summaries($pageUids);
        return $result;
    }

    // ==================== RICERCA ====================

    /** Campi di ricerca → criteri IMAP SEARCH. */
    const CAMPI_RICERCA = [
        'mittente'    => ['FROM'],
        'destinatari' => ['TO', 'CC'],
        'oggetto'     => ['SUBJECT'],
        'corpo'       => ['BODY'],
    ];
    /** Cercando in tutte le cartelle: al massimo questi risultati per cartella. */
    const MAX_RISULTATI_CARTELLA = 1000;

    /**
     * Criterio IMAP SEARCH: il testo in uno dei campi scelti (OR), più
     * eventualmente FLAGGED. @return array [criterio, serve CHARSET UTF-8]
     */
    private function searchCriteria($testo, array $campi, $soloImportanti) {
        $termini = [];
        $testo = trim((string)$testo);
        if ($testo !== '') {
            $valore = $this->imap()->quote($testo);
            foreach ($campi as $campo) {
                foreach (self::CAMPI_RICERCA[$campo] ?? [] as $chiave) {
                    $termini[] = "{$chiave} {$valore}";
                }
            }
            if (!$termini) {
                throw new Exception('Scegli almeno un campo in cui cercare');
            }
        }
        // OR in IMAP è binario: OR a OR b c
        $criterio = array_pop($termini) ?? '';
        while ($termini) {
            $criterio = 'OR ' . array_pop($termini) . ' ' . $criterio;
        }
        if ($soloImportanti) {
            $criterio = trim('FLAGGED ' . $criterio);
        }
        if ($criterio === '') {
            throw new Exception('Scrivi il testo da cercare');
        }
        $utf8 = (bool)preg_match('/[\x80-\xFF]/', $testo);
        return [($utf8 ? 'CHARSET UTF-8 ' : '') . $criterio, $utf8];
    }

    /**
     * Cerca nella cartella indicata o, con $logical = null, in tutte quelle
     * apribili. Dal più recente, a pagine.
     * @return array ['emails' => riepiloghi con 'folder', 'total', 'page',
     *               'total_pages', 'limit', 'troncata' => true se in qualche
     *               cartella si sono tenuti solo i risultati più recenti]
     */
    public function search($logical, $testo, array $campi, $soloImportanti, $page, $limit) {
        [$criterio] = $this->searchCriteria($testo, $campi, $soloImportanti);
        $cartelle = $logical !== null ? [$logical] : $this->searchableFolders();
        $trovate = [];      // [cartella, uid, momento]
        $troncata = false;
        foreach ($cartelle as $cartella) {
            if ($this->open($cartella, true) === null) {
                if ($logical !== null) {
                    throw new ImapException("La cartella '{$logical}' non esiste su questo account");
                }
                continue;
            }
            $uids = $this->imap()->uidSearch($criterio);
            if (count($uids) > self::MAX_RISULTATI_CARTELLA && $logical === null) {
                sort($uids);
                $uids = array_slice($uids, -self::MAX_RISULTATI_CARTELLA);
                $troncata = true;
            }
            foreach (array_chunk($uids, 5000) as $chunk) {
                foreach ($this->imap()->uidFetch($chunk, '(UID INTERNALDATE)') as $uid => $item) {
                    $trovate[] = [$cartella, (int)$uid, @strtotime((string)($item['INTERNALDATE'] ?? '')) ?: 0];
                }
            }
        }
        usort($trovate, fn($a, $b) => [$b[2], $b[1]] <=> [$a[2], $a[1]]);

        $total = count($trovate);
        $pagina = $limit > 0 ? array_slice($trovate, ($page - 1) * $limit, $limit) : $trovate;
        // Riepiloghi cartella per cartella, poi nell'ordine dei risultati
        $perCartella = [];
        foreach ($pagina as [$cartella, $uid]) {
            $perCartella[$cartella][] = $uid;
        }
        $riepiloghi = [];
        foreach ($perCartella as $cartella => $uids) {
            $this->open($cartella, true);
            foreach ($this->summaries($uids) as $e) {
                $e['folder'] = $cartella;
                $riepiloghi[$cartella . "\n" . $e['file']] = $e;
            }
        }
        $emails = [];
        foreach ($pagina as [$cartella, $uid]) {
            if (isset($riepiloghi[$cartella . "\n" . $uid])) {
                $emails[] = $riepiloghi[$cartella . "\n" . $uid];
            }
        }
        return [
            'emails' => $emails, 'total' => $total, 'page' => $page, 'limit' => $limit,
            'total_pages' => $limit > 0 ? max(1, (int)ceil($total / $limit)) : 1, 'troncata' => $troncata,
        ];
    }

    /** Cartelle in cui cercare "dappertutto": le cinque speciali e le altre apribili. */
    private function searchableFolders() {
        $cartelle = [];
        foreach (self::LOGICAL_FOLDERS as $logical) {
            if ($this->folderName($logical) !== null) {
                $cartelle[] = $logical;
            }
        }
        foreach ($this->otherFolders() as $f) {
            if ($f['apribile']) {
                $cartelle[] = $f['id'];
            }
        }
        return $cartelle;
    }

    /**
     * UID dei messaggi che soddisfano il criterio, dal più recente per data
     * di arrivo (INTERNALDATE) e, a parità, per UID decrescente. Non si usa
     * UID SORT: a parità di data restituisce i messaggi in ordine crescente,
     * e getmail consegna spesso molte email nello stesso secondo.
     */
    private function newestFirst($criteria) {
        $uids = $this->imap()->uidSearch($criteria);
        if (!$uids) {
            return [];
        }
        $times = [];
        foreach (array_chunk($uids, 5000) as $chunk) {
            foreach ($this->imap()->uidFetch($chunk, '(UID INTERNALDATE)') as $uid => $item) {
                $times[$uid] = @strtotime((string)($item['INTERNALDATE'] ?? '')) ?: 0;
            }
        }
        usort($uids, fn($a, $b) => [($times[$b] ?? 0), $b] <=> [($times[$a] ?? 0), $a]);
        return $uids;
    }

    /**
     * Intestazioni sintetiche dei messaggi indicati, nell'ordine dato.
     * @return array lista di ['file' => uid, 'sender', 'recipient', 'subject',
     *               'date', 'date_raw', 'has_attachments', 'is_new', 'is_flagged']
     */
    private function summaries(array $uids) {
        if (!$uids) {
            return [];
        }
        $fetched = [];
        // A blocchi, per non generare comandi/risposte enormi con "Tutte"
        foreach (array_chunk($uids, 500) as $chunk) {
            $fetched += $this->imap()->uidFetch($chunk,
                '(UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE ' . self::LIST_HEADERS . ')');
        }

        $emails = [];
        foreach ($uids as $uid) {
            if (!isset($fetched[$uid])) continue;
            $item = $fetched[$uid];

            $headerBlock = '';
            foreach ($item as $key => $value) {
                if (strpos($key, 'BODY[HEADER') === 0) {
                    $headerBlock = (string)$value;
                    break;
                }
            }
            $h = MailHeaders::parseBlock($headerBlock);
            $dateRaw = $h['date'] ?? '';
            $date = MailHeaders::formatDate($dateRaw);
            if ($date === null) {
                $dateRaw = (string)($item['INTERNALDATE'] ?? '');
                $date = MailHeaders::formatDate($dateRaw) ?? 'Data sconosciuta';
            }
            $flags = is_array($item['FLAGS'] ?? null) ? array_map('strtoupper', $item['FLAGS']) : [];

            $emails[] = [
                'file'            => (string)$uid,
                'sender'          => MailHeaders::decode($h['from'] ?? ''),
                'recipient'       => MailHeaders::decode($h['to'] ?? ''),
                'subject'         => MailHeaders::decode($h['subject'] ?? ''),
                'date'            => $date,
                'date_raw'        => $dateRaw,
                'size'            => (int)($item['RFC822.SIZE'] ?? 0),
                'has_attachments' => MailHeaders::hasAttachments($item['BODYSTRUCTURE'] ?? null),
                'is_new'          => !in_array('\\SEEN', $flags, true),
                'is_flagged'      => in_array('\\FLAGGED', $flags, true),
            ];
        }
        return $emails;
    }

    /**
     * Messaggio completo per UID. Con $markSeen la cartella viene aperta in
     * scrittura e la lettura imposta \Seen (come l'apertura di un'email).
     */
    public function getRaw($logical, $uid, $markSeen = false) {
        if ($this->open($logical, !$markSeen) === null) {
            return null;
        }
        return $this->imap()->fetchRaw((int)$uid, !$markSeen);
    }

    // ==================== AZIONI ====================

    /** Nomi standard usati se una cartella speciale non esiste e va creata. */
    const DEFAULT_FOLDER_NAMES = ['sent' => 'Sent', 'draft' => 'Drafts', 'trash' => 'Trash', 'junk' => 'Junk'];

    /** Nome reale della cartella, creandola se manca (es. Trash mai usato). */
    private function ensureFolder($logical) {
        $name = $this->folderName($logical);
        if ($name === null && isset(self::DEFAULT_FOLDER_NAMES[$logical])) {
            $name = self::DEFAULT_FOLDER_NAMES[$logical];
            $this->imap()->createMailbox($name);
            $this->folderMap = null;
            $this->folders = null;
        }
        return $name;
    }

    /** Apre la cartella in scrittura e verifica che il messaggio esista. */
    private function openMessage($logical, $uid) {
        if ($this->open($logical, false) === null) {
            throw new ImapException("La cartella '{$logical}' non esiste su questo account");
        }
        if (!$this->imap()->uidSearch('UID ' . (int)$uid)) {
            throw new ImapException('Email non trovata (potrebbe essere stata spostata o eliminata)');
        }
    }

    /** Segna il messaggio come letto ($seen = true) o non letto. */
    public function setSeen($logical, $uid, $seen) {
        $this->openMessage($logical, $uid);
        $this->imap()->uidStore([(int)$uid], $seen ? '+' : '-', ['\\Seen']);
    }

    /** Sposta il messaggio da una cartella logica a un'altra. */
    public function move($logical, $uid, $targetLogical) {
        $target = $this->ensureFolder($targetLogical);
        if ($target === null) {
            throw new ImapException("La cartella '{$targetLogical}' non esiste su questo account");
        }
        if ($target === $this->folderName($logical)) {
            throw new ImapException('Origine e destinazione coincidono');
        }
        $this->openMessage($logical, $uid);
        $this->imap()->uidMove([(int)$uid], $target);
    }

    // ==================== AZIONI SU PIÙ EMAIL ====================

    /**
     * Apre la cartella in scrittura e restituisce gli UID richiesti che
     * esistono ancora (altri potrebbero essere stati spostati nel frattempo).
     * @return int[]
     */
    private function existingUids($logical, array $uids) {
        if ($this->open($logical, false) === null) {
            throw new ImapException("La cartella '{$logical}' non esiste su questo account");
        }
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), function ($u) { return $u > 0; })));
        if (!$uids) {
            return [];
        }
        return $this->imap()->uidSearch('UID ' . ImapClient::uidSet($uids));
    }

    /**
     * Sposta più messaggi in un'altra cartella (logica o "imap:<nome>").
     * @return int quante email sono state spostate
     */
    public function moveMany($logical, array $uids, $targetLogical) {
        $target = $this->ensureFolder($targetLogical);
        if ($target === null) {
            throw new ImapException('La cartella di destinazione non esiste su questo account');
        }
        if ($target === $this->folderName($logical)) {
            throw new ImapException('Le email sono già in questa cartella');
        }
        $found = $this->existingUids($logical, $uids);
        if ($found) {
            $this->imap()->uidMove($found, $target);
        }
        return count($found);
    }

    /**
     * Segna più messaggi come letti ($seen = true) o non letti.
     * @return int quante email sono state modificate
     */
    public function setSeenMany($logical, array $uids, $seen) {
        return $this->setFlagMany($logical, $uids, '\\Seen', $seen);
    }

    /**
     * Segna più messaggi come importanti (\Flagged, la stella che mostrano
     * anche i telefoni) o li toglie dagli importanti.
     * @return int quante email sono state modificate
     */
    public function setFlaggedMany($logical, array $uids, $flagged) {
        return $this->setFlagMany($logical, $uids, '\\Flagged', $flagged);
    }

    private function setFlagMany($logical, array $uids, $flag, $on) {
        $found = $this->existingUids($logical, $uids);
        if ($found) {
            $this->imap()->uidStore($found, $on ? '+' : '-', [$flag]);
        }
        return count($found);
    }

    /**
     * Cancella definitivamente più messaggi: \Deleted + UID EXPUNGE, così
     * spariscono solo questi e non altri già marcati \Deleted da altri
     * programmi. Senza UIDPLUS non si cancella niente.
     * @return int quante email sono state cancellate
     */
    public function deleteMany($logical, array $uids) {
        if (!$this->imap()->hasCapability('UIDPLUS')) {
            throw new ImapException('Il server di posta non permette la cancellazione definitiva sicura (manca UIDPLUS)');
        }
        $found = $this->existingUids($logical, $uids);
        if ($found) {
            $this->imap()->uidStore($found, '+', ['\\Deleted']);
            $this->imap()->command('UID EXPUNGE ' . ImapClient::uidSet($found));
        }
        return count($found);
    }

    /**
     * Salva una bozza nella cartella Bozze (\Draft, letta) e, se indicata,
     * cancella la versione precedente: così resta una sola copia.
     * @return int UID della nuova bozza (0 se il server non lo comunica)
     */
    public function saveDraft($rawMessage, $replaceUid = 0) {
        $drafts = $this->ensureFolder('draft');
        // IMAP vuole righe terminate da CRLF (PHPMailer con "mail" usa LF)
        $rawMessage = preg_replace("/\r?\n/", "\r\n", (string)$rawMessage);
        $res = $this->imap()->append($drafts, $rawMessage, ['\\Draft', '\\Seen']);
        $uid = preg_match('/\[APPENDUID\s+\d+\s+(\d+)\]/i', (string)($res['text'] ?? ''), $m) ? (int)$m[1] : 0;
        if ($replaceUid > 0 && $replaceUid !== $uid) {
            $this->deleteDraft($replaceUid);
        }
        return $uid;
    }

    /**
     * Cancella una bozza (dopo l'invio o quando ne è stata salvata una nuova).
     * Senza UIDPLUS la si sposta nel Cestino, per non toccare altre email.
     */
    public function deleteDraft($uid) {
        if ($this->imap()->hasCapability('UIDPLUS')) {
            $this->deleteMany('draft', [(int)$uid]);
        } else {
            $this->moveMany('draft', [(int)$uid], 'trash');
        }
    }

    /** Salva una copia di un messaggio inviato nella cartella Sent (letto). */
    public function saveToSent($rawMessage) {
        $sent = $this->ensureFolder('sent');
        // APPEND non richiede la cartella selezionata e non cambia quella aperta
        $this->imap()->append($sent, $rawMessage, ['\\Seen']);
    }

    // ==================== FILTRI (lib/FiltriService.php) ====================

    /**
     * Apre la cartella in scrittura e ne restituisce lo stato appena letto.
     * @return array|null ['exists', 'uidvalidity', 'uidnext'] o null se non esiste
     */
    public function folderStatus($logical) {
        $name = $this->folderName($logical);
        if ($name === null) {
            return null;
        }
        $info = $this->imap()->select($name, false);
        $this->selected = [$name, false];
        return $info;
    }

    /**
     * UID SEARCH nella cartella aperta in scrittura con un criterio già
     * composto (FiltriService::criterio), con CHARSET UTF-8 se serve.
     * @return int[]
     */
    public function searchUids($logical, $criterio, $utf8 = false) {
        if ($this->open($logical, false) === null) {
            throw new ImapException("La cartella '{$logical}' non esiste su questo account");
        }
        return $this->imap()->uidSearch(($utf8 ? 'CHARSET UTF-8 ' : '') . $criterio);
    }

    /** Argomento IMAP (quoted string o literal) per comporre i criteri. */
    public function quote($value) {
        return $this->imap()->quote($value);
    }

    /**
     * Id "imap:<nome>" della cartella con questo percorso (livelli come in
     * otherFolders(), confronto senza maiuscole). Con $crea i livelli che
     * mancano vengono creati (createFolder, anche sulla copia locale).
     * @param string[] $parti es. ['Clienti', 'Rossi']
     * @return string|null null se manca e non va creata
     */
    public function folderByPath(array $parti, $crea) {
        $chiave = function (array $p) {
            return mb_strtolower(implode("\n", array_map('trim', $p)));
        };
        // Prima con le stesse maiuscole (ci possono essere "Archivio" e "archivio")
        $trova = function (array $p) use ($chiave) {
            $simile = null;
            foreach ($this->otherFolders() as $f) {
                if ($f['percorso'] === implode(' / ', $p)) {
                    return $f;
                }
                if ($simile === null && $chiave(explode(' / ', $f['percorso'])) === $chiave($p)) {
                    $simile = $f;
                }
            }
            return $simile;
        };
        $f = $trova($parti);
        if ($f !== null) {
            if (!$f['apribile']) {
                throw new ImapException('La cartella "' . implode(' / ', $parti) . '" non può contenere email');
            }
            return $f['id'];
        }
        if (!$crea) {
            return null;
        }
        $genitore = '';
        for ($i = 1; $i <= count($parti); $i++) {
            $livello = $trova(array_slice($parti, 0, $i));
            $genitore = $livello !== null ? $livello['id'] : $this->createFolder($genitore, $parti[$i - 1]);
        }
        return $genitore;
    }

    /**
     * Email non lette della Inbox, dalla più recente.
     * @return array ['total' => n, 'emails' => [...summaries]]
     */
    public function unseenInbox($limit) {
        if ($this->open('inbox', true) === null) {
            return ['total' => 0, 'emails' => []];
        }
        $uids = $this->newestFirst('UNSEEN');
        return [
            'total'  => count($uids),
            'emails' => $this->summaries(array_slice($uids, 0, $limit)),
        ];
    }
}

/**
 * Analizza un messaggio completo con email_parser.py (passato via stdin).
 * @return array risultato del parser, con 'error' in caso di problemi
 */
function parseRawEmail($raw) {
    $script = __DIR__ . '/../email_parser.py';
    $process = proc_open(
        ['python3', $script, '-'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        return ['error' => "Errore nell'avvio del parser email"];
    }
    // Il parser legge tutto stdin prima di scrivere: nessun rischio di stallo
    for ($written = 0, $len = strlen($raw); $written < $len; $written += $n) {
        $n = fwrite($pipes[0], substr($raw, $written, 65536));
        if ($n === false || $n === 0) break;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if ($code !== 0) {
        error_log('email_parser.py: ' . substr($stderr, 0, 1000));
        return ['error' => "Errore parser email (codice {$code})"];
    }
    $result = json_decode($stdout, true);
    if (!is_array($result)) {
        return ['error' => 'Risposta del parser email non valida'];
    }
    return $result;
}
