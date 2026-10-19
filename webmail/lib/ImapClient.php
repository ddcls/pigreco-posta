<?php
/**
 * Client IMAP minimale, senza dipendenze (né ext-imap né Composer).
 *
 * Pensato per parlare con Dovecot (porta 143 + STARTTLS), implementa solo i
 * comandi che servono alla webmail. In questa prima fase: connessione,
 * STARTTLS, CAPABILITY, LOGIN, LIST, LOGOUT. I comandi di lettura e
 * modifica dei messaggi (SELECT, UID SEARCH/FETCH/STORE/MOVE, APPEND)
 * si appoggeranno allo stesso meccanismo command() + parseTokens().
 *
 * Uso:
 *   $imap = new ImapClient('192.168.1.10', 143, 'starttls');
 *   $imap->connect();
 *   $imap->login('utente', 'password');
 *   $folders = $imap->listMailboxes();
 *   $imap->logout();
 */

class ImapException extends RuntimeException {}

class ImapClient {
    private $host;
    private $port;
    private $security;      // 'starttls' | 'ssl' | 'none'
    private $verifyPeer;
    private $timeout;

    private $stream = null;
    private $tagCounter = 0;
    private $capabilities = [];

    public function __construct($host, $port = 143, $security = 'starttls', $verifyPeer = false, $timeout = 15) {
        $this->host = $host;
        $this->port = (int)$port;
        $this->security = in_array($security, ['starttls', 'ssl', 'none'], true) ? $security : 'starttls';
        $this->verifyPeer = (bool)$verifyPeer;
        $this->timeout = (int)$timeout;
    }

    public function __destruct() {
        $this->close();
    }

    // ==================== CONNESSIONE ====================

    public function connect() {
        $context = stream_context_create(['ssl' => [
            'verify_peer'       => $this->verifyPeer,
            'verify_peer_name'  => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer,
            'peer_name'         => $this->host,
        ]]);

        $scheme = $this->security === 'ssl' ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client("{$scheme}://{$this->host}:{$this->port}", $errno, $errstr,
                                        $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new ImapException("Connessione a {$this->host}:{$this->port} fallita: {$errstr} ({$errno})");
        }
        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        $greeting = $this->readLogicalLine();
        if (stripos($greeting, '* OK') !== 0 && stripos($greeting, '* PREAUTH') !== 0) {
            $this->close();
            throw new ImapException('Risposta di benvenuto inattesa dal server: ' . trim($greeting));
        }
        $this->parseCapabilityFromText($greeting);

        if ($this->security === 'starttls') {
            $this->startTls();
        }
        if (!$this->capabilities) {
            $this->capability();
        }
    }

    private function startTls() {
        if (!$this->capabilities) {
            $this->capability();
        }
        if (!$this->hasCapability('STARTTLS')) {
            throw new ImapException('Il server non supporta STARTTLS');
        }
        $this->command('STARTTLS');

        $methods = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $methods |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $methods |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (@stream_socket_enable_crypto($this->stream, true, $methods) !== true) {
            $err = error_get_last();
            throw new ImapException('Negoziazione TLS fallita' . ($err ? ': ' . $err['message'] : ''));
        }
        // Dopo STARTTLS le capability vanno rilette (RFC 3501 §6.2.1)
        $this->capabilities = [];
        $this->capability();
    }

    public function login($username, $password) {
        if ($this->hasCapability('LOGINDISABLED')) {
            throw new ImapException('Il server rifiuta il login su connessione non cifrata (LOGINDISABLED): usa STARTTLS');
        }
        try {
            $res = $this->command('LOGIN ' . $this->quote($username) . ' ' . $this->quote($password));
        } catch (ImapException $e) {
            throw new ImapException('Login IMAP fallito: ' . $e->getMessage());
        }
        // Dovecot spesso restituisce le nuove capability nel tagged OK
        $this->capabilities = [];
        $this->parseCapabilityFromText($res['text']);
        if (!$this->capabilities) {
            $this->capability();
        }
    }

    public function logout() {
        if ($this->stream) {
            try {
                $this->command('LOGOUT');
            } catch (Throwable $e) {
                // Il server può chiudere subito dopo il BYE: non è un errore
            }
        }
        $this->close();
    }

    public function close() {
        if ($this->stream) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    // ==================== CAPABILITY ====================

    public function capability() {
        $res = $this->command('CAPABILITY');
        foreach ($res['untagged'] as $line) {
            $this->parseCapabilityFromText($line);
        }
        return $this->capabilities;
    }

    public function hasCapability($name) {
        return in_array(strtoupper($name), $this->capabilities, true);
    }

    public function getCapabilities() {
        return $this->capabilities;
    }

    private function parseCapabilityFromText($text) {
        if (preg_match('/(?:^\*\s+|\[)CAPABILITY\s+([^\]\r\n]+)/i', $text, $m)) {
            $this->capabilities = array_values(array_unique(array_merge(
                $this->capabilities,
                array_map('strtoupper', preg_split('/\s+/', trim($m[1])))
            )));
        }
    }

    // ==================== CARTELLE ====================

    /**
     * Elenca le cartelle della casella.
     * @return array Lista di ['name' => nome IMAP grezzo (da usare nei comandi),
     *                         'display' => nome in UTF-8, 'delimiter' => separatore,
     *                         'flags' => ['\HasNoChildren', '\Sent', ...]]
     */
    public function listMailboxes() {
        $res = $this->command('LIST "" "*"');
        $folders = [];
        foreach ($res['untagged'] as $line) {
            $tokens = $this->parseTokens($line);
            // * LIST (flags) delimiter name
            if (count($tokens) < 5 || strtoupper((string)$tokens[1]) !== 'LIST') {
                continue;
            }
            $flags = is_array($tokens[2]) ? $tokens[2] : [];
            $name = (string)$tokens[4];
            $folders[] = [
                'name'      => $name,
                'display'   => self::decodeMailboxName($name),
                'delimiter' => $tokens[3],
                'flags'     => $flags,
            ];
        }
        return $folders;
    }

    /**
     * Individua le cartelle speciali (RFC 6154 SPECIAL-USE), con fallback
     * sui nomi standard di Dovecot.
     * @return array ['inbox' => 'INBOX', 'sent' => ..., 'draft' => ..., 'trash' => ..., 'junk' => ...]
     */
    public static function detectSpecialFolders(array $folders) {
        $map = ['inbox' => 'INBOX', 'sent' => null, 'draft' => null, 'trash' => null, 'junk' => null];
        $byFlag = ['\\SENT' => 'sent', '\\DRAFTS' => 'draft', '\\TRASH' => 'trash', '\\JUNK' => 'junk'];
        $byName = ['sent' => ['Sent', 'Sent Messages', 'Sent Items', 'Inviata'],
                   'draft' => ['Drafts', 'Bozze'],
                   'trash' => ['Trash', 'Cestino', 'Deleted Messages'],
                   'junk' => ['Junk', 'Spam']];

        foreach ($folders as $f) {
            foreach ($f['flags'] as $flag) {
                $key = $byFlag[strtoupper((string)$flag)] ?? null;
                if ($key && $map[$key] === null) {
                    $map[$key] = $f['name'];
                }
            }
        }
        foreach ($byName as $key => $candidates) {
            if ($map[$key] !== null) continue;
            foreach ($folders as $f) {
                $leaf = $f['delimiter'] ? substr(strrchr($f['delimiter'] . $f['display'], $f['delimiter']), 1) : $f['display'];
                foreach ($candidates as $c) {
                    if (strcasecmp($leaf, $c) === 0) {
                        $map[$key] = $f['name'];
                        break 2;
                    }
                }
            }
        }
        return $map;
    }

    /** Decodifica i nomi cartella da UTF-7 modificato (RFC 3501 §5.1.3) a UTF-8. */
    public static function decodeMailboxName($name) {
        if (strpos($name, '&') === false) {
            return $name;
        }
        $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
        return $decoded !== false ? $decoded : $name;
    }

    /** Codifica un nome cartella UTF-8 in UTF-7 modificato. */
    public static function encodeMailboxName($name) {
        $encoded = @mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
        return $encoded !== false ? $encoded : $name;
    }

    // ==================== MESSAGGI ====================

    /**
     * Apre una cartella. In sola lettura (EXAMINE) la lettura del corpo
     * non imposta \Seen; con SELECT un FETCH BODY[] marca il messaggio letto.
     * @return array ['exists' => n, 'uidvalidity' => n, 'uidnext' => n (0 se il server non lo dice)]
     */
    public function select($mailbox, $readOnly = true) {
        $res = $this->command(($readOnly ? 'EXAMINE ' : 'SELECT ') . $this->quote($mailbox));
        $info = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($res['untagged'] as $line) {
            if (preg_match('/^\*\s+(\d+)\s+EXISTS/i', $line, $m)) {
                $info['exists'] = (int)$m[1];
            } elseif (preg_match('/UIDVALIDITY\s+(\d+)/i', $line, $m)) {
                $info['uidvalidity'] = (int)$m[1];
            } elseif (preg_match('/UIDNEXT\s+(\d+)/i', $line, $m)) {
                $info['uidnext'] = (int)$m[1];
            }
        }
        return $info;
    }

    /** UID SEARCH: restituisce gli UID (interi) che soddisfano il criterio. */
    public function uidSearch($criteria = 'ALL') {
        $res = $this->command('UID SEARCH ' . $criteria);
        return $this->collectNumbers($res['untagged'], 'SEARCH');
    }

    /**
     * UID SORT (RFC 5256) se disponibile, altrimenti UID SEARCH: in quel caso
     * l'ordine è per UID, che per Dovecot coincide con l'ordine di arrivo.
     * @param string $criteria es. 'REVERSE ARRIVAL'
     */
    public function uidSort($sortCriteria = 'REVERSE ARRIVAL', $searchCriteria = 'ALL') {
        if ($this->hasCapability('SORT')) {
            $res = $this->command("UID SORT ({$sortCriteria}) UTF-8 {$searchCriteria}");
            return $this->collectNumbers($res['untagged'], 'SORT');
        }
        $uids = $this->uidSearch($searchCriteria);
        sort($uids);
        return stripos($sortCriteria, 'REVERSE') !== false ? array_reverse($uids) : $uids;
    }

    private function collectNumbers(array $lines, $keyword) {
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\*\s+' . $keyword . '\b(.*)$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $n) {
                    if (ctype_digit($n)) $out[] = (int)$n;
                }
            }
        }
        return $out;
    }

    /**
     * UID FETCH: restituisce [uid => [ITEM => valore]] con i nomi degli item
     * in maiuscolo (es. 'FLAGS', 'BODYSTRUCTURE', 'BODY[]',
     * 'BODY[HEADER.FIELDS (FROM SUBJECT)]').
     * @param int[] $uids
     * @param string $items es. '(UID FLAGS BODY.PEEK[HEADER])'
     */
    public function uidFetch(array $uids, $items) {
        $uids = array_values(array_filter(array_map('intval', $uids), fn($u) => $u > 0));
        if (!$uids) {
            return [];
        }
        $res = $this->command('UID FETCH ' . self::uidSet($uids) . ' ' . $items);
        $out = [];
        foreach ($res['untagged'] as $line) {
            if (!preg_match('/^\*\s+\d+\s+FETCH\s/i', $line)) {
                continue;
            }
            $tokens = $this->parseTokens($line);
            $list = $tokens[3] ?? null;
            if (!is_array($list)) continue;
            $data = [];
            for ($i = 0; $i + 1 < count($list); $i += 2) {
                $key = strtoupper((string)$list[$i]);
                // BODY.PEEK[...] viene restituito dal server come BODY[...]
                $key = preg_replace('/^BODY\[(.*)\]<\d+>$/', 'BODY[$1]', $key);
                $data[$key] = $list[$i + 1];
            }
            if (isset($data['UID'])) {
                $out[(int)$data['UID']] = $data;
            }
        }
        return $out;
    }

    /** Messaggio completo (RFC 822) per UID, o null se non esiste. */
    public function fetchRaw($uid, $peek = true) {
        $res = $this->uidFetch([$uid], $peek ? '(UID BODY.PEEK[])' : '(UID BODY[])');
        return isset($res[(int)$uid]['BODY[]']) ? $res[(int)$uid]['BODY[]'] : null;
    }

    /**
     * Aggiunge (+) o toglie (-) flag ai messaggi, senza risposta FETCH.
     * @param string $op '+' o '-'
     * @param string[] $flags es. ['\Seen']
     */
    public function uidStore(array $uids, $op, array $flags) {
        $op = $op === '-' ? '-' : '+';
        $this->command('UID STORE ' . self::uidSet($uids) . " {$op}FLAGS.SILENT (" . implode(' ', $flags) . ')');
    }

    /**
     * Sposta messaggi in un'altra cartella: UID MOVE (RFC 6851) se
     * disponibile, altrimenti COPY + \Deleted + EXPUNGE.
     */
    public function uidMove(array $uids, $mailbox) {
        $set = self::uidSet($uids);
        if ($this->hasCapability('MOVE')) {
            $this->command("UID MOVE {$set} " . $this->quote($mailbox));
            return;
        }
        $this->command("UID COPY {$set} " . $this->quote($mailbox));
        $this->uidStore($uids, '+', ['\\Deleted']);
        // Con UIDPLUS si cancellano solo questi messaggi, non altri già marcati \Deleted
        $this->command($this->hasCapability('UIDPLUS') ? "UID EXPUNGE {$set}" : 'EXPUNGE');
    }

    /**
     * Aggiunge un messaggio completo a una cartella (es. copia in Sent).
     * Il messaggio viaggia come literal: non sincronizzante {n+} con
     * LITERAL+, altrimenti {n} attendendo la continuazione "+" del server.
     */
    public function append($mailbox, $message, array $flags = []) {
        $message = (string)$message;
        $prefix = 'APPEND ' . $this->quote($mailbox) . ($flags ? ' (' . implode(' ', $flags) . ')' : '');
        $size = strlen($message);

        if ($this->hasCapability('LITERAL+')) {
            return $this->command("{$prefix} {{$size}+}\r\n{$message}");
        }

        if (!$this->stream) {
            throw new ImapException('Connessione IMAP non aperta');
        }
        $tag = sprintf('A%04d', ++$this->tagCounter);
        $this->write("{$tag} {$prefix} {{$size}}\r\n");
        $line = $this->readLogicalLine();
        if (strpos($line, '+') !== 0) {
            throw new ImapException('APPEND rifiutato: ' . trim(preg_replace('/^\S+\s+/', '', $line)));
        }
        $this->write($message . "\r\n");
        while (true) {
            $line = $this->readLogicalLine();
            if (strpos($line, $tag . ' ') === 0) {
                if (!preg_match('/^\S+\s+OK\b/i', $line)) {
                    throw new ImapException('APPEND fallito: ' . trim(substr($line, strlen($tag) + 1)));
                }
                return ['status' => 'OK', 'text' => trim($line), 'untagged' => []];
            }
        }
    }

    public function createMailbox($mailbox) {
        $this->command('CREATE ' . $this->quote($mailbox));
        try {
            $this->command('SUBSCRIBE ' . $this->quote($mailbox));
        } catch (ImapException $e) {
            // La sottoscrizione è solo per i client che la usano: non è un errore
        }
    }

    /** Compatta una lista di UID in un set IMAP (es. 1:5,8,10:12). */
    public static function uidSet(array $uids) {
        sort($uids);
        $ranges = [];
        $start = $prev = null;
        foreach ($uids as $u) {
            if ($start === null) {
                $start = $prev = $u;
            } elseif ($u === $prev + 1) {
                $prev = $u;
            } elseif ($u !== $prev) {
                $ranges[] = $start === $prev ? "{$start}" : "{$start}:{$prev}";
                $start = $prev = $u;
            }
        }
        if ($start !== null) {
            $ranges[] = $start === $prev ? "{$start}" : "{$start}:{$prev}";
        }
        return implode(',', $ranges);
    }

    // ==================== PROTOCOLLO ====================

    /**
     * Esegue un comando e attende la risposta con lo stesso tag.
     * @return array ['status' => 'OK', 'text' => testo del tagged response,
     *                'untagged' => righe logiche "* ..." ricevute (con eventuali literal inclusi)]
     * @throws ImapException se la risposta è NO/BAD o la connessione cade
     */
    public function command($command) {
        if (!$this->stream) {
            throw new ImapException('Connessione IMAP non aperta');
        }
        $tag = sprintf('A%04d', ++$this->tagCounter);
        $data = "{$tag} {$command}\r\n";
        $untagged = [];

        // Literal sincronizzanti {n} (server senza LITERAL+, vedi quote()): si
        // spedisce fino a "{n}\r\n", si attende il "+" del server e si
        // prosegue. Il contenuto dei literal ({n} e {n+}) viene saltato.
        $sent = 0;
        $search = 0;
        while (preg_match('/\{(\d+)(\+?)\}\r\n/', $data, $m, PREG_OFFSET_CAPTURE, $search)) {
            $cut = $m[0][1] + strlen($m[0][0]);
            $search = $cut + (int)$m[1][0];
            if ($m[2][0] === '+') {
                continue;
            }
            $this->write(substr($data, $sent, $cut - $sent));
            $sent = $cut;
            while (true) {
                $line = $this->readLogicalLine();
                if (strpos($line, '+') === 0) {
                    break;
                }
                if (strpos($line, $tag . ' ') === 0) {
                    throw new ImapException('Comando rifiutato: ' . trim(substr($line, strlen($tag) + 1)));
                }
                $untagged[] = rtrim($line, "\r\n");
            }
        }
        $this->write(substr($data, $sent));

        while (true) {
            $line = $this->readLogicalLine();
            if (strpos($line, $tag . ' ') === 0) {
                $rest = substr($line, strlen($tag) + 1);
                $sp = strpos($rest, ' ');
                $status = strtoupper($sp === false ? rtrim($rest) : substr($rest, 0, $sp));
                $text = $sp === false ? '' : rtrim(substr($rest, $sp + 1));
                if ($status !== 'OK') {
                    throw new ImapException($text !== '' ? $text : "Comando rifiutato ({$status})");
                }
                return ['status' => $status, 'text' => $text, 'untagged' => $untagged];
            }
            if (strpos($line, '+') === 0) {
                throw new ImapException('Richiesta di continuazione inattesa dal server');
            }
            if (stripos($line, '* BYE') === 0 && stripos($command, 'LOGOUT') !== 0) {
                $this->close();
                throw new ImapException('Il server ha chiuso la connessione: ' . trim(substr($line, 5)));
            }
            $untagged[] = rtrim($line, "\r\n");
        }
    }

    /**
     * Formatta una stringa come argomento IMAP: quoted string se possibile,
     * altrimenti literal non sincronizzante {n+} (LITERAL+, supportato da
     * Dovecot) o, in sua assenza, literal sincronizzante {n}, che command()
     * spedisce attendendo il "+" del server (es. imap.tiscali.it).
     */
    public function quote($value) {
        $value = (string)$value;
        if (!preg_match('/[\r\n\x00\x80-\xFF]/', $value)) {
            return '"' . addcslashes($value, '"\\') . '"';
        }
        return '{' . strlen($value) . ($this->hasCapability('LITERAL+') ? '+' : '') . "}\r\n" . $value;
    }

    private function write($data) {
        $len = strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @fwrite($this->stream, substr($data, $written));
            if ($n === false || $n === 0) {
                $this->close();
                throw new ImapException('Scrittura verso il server IMAP fallita');
            }
            $written += $n;
        }
    }

    /**
     * Legge una "riga logica" di risposta: una riga terminata da CRLF che può
     * contenere literal {n}, il cui contenuto (n byte, anche con CRLF interni)
     * viene incluso così com'è prima di proseguire con il resto della riga.
     */
    private function readLogicalLine() {
        $result = '';
        while (true) {
            $line = fgets($this->stream);
            if ($line === false) {
                $meta = stream_get_meta_data($this->stream);
                $this->close();
                throw new ImapException(!empty($meta['timed_out'])
                    ? 'Timeout nella risposta del server IMAP'
                    : 'Connessione IMAP chiusa dal server');
            }
            $result .= $line;
            if (!preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                return $result;
            }
            $result .= $this->readBytes((int)$m[1]);
        }
    }

    private function readBytes($n) {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = fread($this->stream, min(65536, $n - strlen($data)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                if (!empty($meta['timed_out']) || feof($this->stream)) {
                    $this->close();
                    throw new ImapException('Connessione interrotta durante la lettura di un literal');
                }
                continue;
            }
            $data .= $chunk;
        }
        return $data;
    }

    /**
     * Scompone una riga logica in token: stringhe (atom, quoted, literal),
     * null per NIL e array per le liste tra parentesi. Gli atom con sezioni
     * tra quadre (es. BODY[HEADER.FIELDS (FROM)]) restano un unico token.
     */
    public function parseTokens($line) {
        $pos = 0;
        $tokens = $this->parseList(rtrim($line, "\r\n"), $pos, false);
        return $tokens;
    }

    private function parseList($s, &$pos, $nested) {
        $out = [];
        $len = strlen($s);
        while ($pos < $len) {
            $c = $s[$pos];
            if ($c === ' ') {
                $pos++;
            } elseif ($c === '(') {
                $pos++;
                $out[] = $this->parseList($s, $pos, true);
            } elseif ($c === ')') {
                $pos++;
                if ($nested) return $out;
            } elseif ($c === '"') {
                $pos++;
                $str = '';
                while ($pos < $len && $s[$pos] !== '"') {
                    if ($s[$pos] === '\\' && $pos + 1 < $len) {
                        $pos++;
                    }
                    $str .= $s[$pos++];
                }
                $pos++; // virgoletta di chiusura
                $out[] = $str;
            } elseif ($c === '{' && preg_match('/\G\{(\d+)\+?\}\r\n/', $s, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                $out[] = substr($s, $pos, (int)$m[1]);
                $pos += (int)$m[1];
            } else {
                $start = $pos;
                $depth = 0;
                while ($pos < $len) {
                    $ch = $s[$pos];
                    if ($ch === '[') $depth++;
                    elseif ($ch === ']') $depth--;
                    elseif ($depth <= 0 && ($ch === ' ' || $ch === '(' || $ch === ')')) break;
                    $pos++;
                }
                $atom = substr($s, $start, $pos - $start);
                $out[] = strtoupper($atom) === 'NIL' ? null : $atom;
            }
        }
        return $out;
    }
}
