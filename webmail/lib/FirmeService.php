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
 * Firme delle email (tabelle "firme" e "firme_immagini", migrations/007_firme.sql).
 *
 * Ogni casella ha le sue firme: le vedono, le usano e le modificano tutti gli
 * utenti che usano la casella. La casella ne sceglie una per le email nuove e
 * una per le risposte e gli inoltri (mail_accounts.firma_nuove_id e
 * firma_risposte_id, NULL = nessuna). Il metodo è quello di MailCraft: una
 * firma si compone dai dati (nome, qualifica, società, telefoni, email, sito,
 * indirizzo, logo a sinistra o sopra) oppure si scrive come testo formattato
 * o come sorgente HTML.
 *
 * L'HTML si pulisce qui al salvataggio (pulisciHtml(), con DOMDocument e un
 * elenco di elementi e attributi ammessi) e di nuovo con DOMPurify nel browser
 * prima di metterlo nella pagina. Le immagini della firma stanno nel database
 * e nell'HTML hanno l'indirizzo firma_immagine.php?id=N; send_email.php le
 * mette dentro l'email (cid:) al momento dell'invio, incorporaImmagini().
 */

require_once __DIR__ . '/../config/db_config.php';

class FirmeService {
    const TIPI = ['dati', 'testo', 'html'];
    const MAX_FIRME = 20;               // per casella
    const MAX_NOME = 100;
    const MAX_HTML = 65536;             // byte dell'HTML pulito
    const MAX_DATI = 8192;              // byte del JSON dei dati
    const MAX_IMMAGINE = 262144;        // byte di un'immagine
    const MAX_IMMAGINI_BYTE = 524288;   // byte di tutte le immagini di una firma
    const MAX_IMMAGINI = 10;            // immagini di una firma
    const MAX_LATO = 4000;              // pixel, larghezza o altezza
    const URL_IMMAGINE = 'firma_immagine.php?id=';

    /** Elementi tolti insieme a tutto quello che contengono. */
    const ELEMENTI_VIA = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form',
        'input', 'button', 'textarea', 'select', 'option', 'svg', 'math', 'link', 'meta', 'base', 'title', 'head',
        'noscript', 'template', 'audio', 'video', 'source', 'track', 'canvas', 'param', 'xml'];
    /** Elementi ammessi; gli altri si tolgono lasciando il loro contenuto. */
    const ELEMENTI = ['a', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'ins', 'span', 'div', 'p', 'br', 'hr',
        'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'col', 'colgroup', 'font', 'small',
        'big', 'sub', 'sup', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'center', 'blockquote', 'pre',
        'code', 'address', 'cite', 'abbr'];
    /** Attributi ammessi (href, src e style hanno controlli in più). */
    const ATTRIBUTI = ['style', 'href', 'src', 'alt', 'title', 'width', 'height', 'align', 'valign', 'border',
        'cellpadding', 'cellspacing', 'bgcolor', 'color', 'face', 'size', 'colspan', 'rowspan', 'dir', 'data-firma'];

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
            if ($stmt->errno === 1062) {
                throw new InvalidArgumentException('Questa casella ha già una firma con questo nome');
            }
            throw new RuntimeException('Errore database: ' . $stmt->error);
        }
        return $stmt;
    }

    private function righe($sql, $types = '', array $params = []) {
        $stmt = $this->query($sql, $types, $params);
        $righe = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $righe;
    }

    // ==================== LETTURA ====================

    /** Le firme della casella, con le loro immagini (senza i byte), e le due predefinite. */
    public function elenco($accountId) {
        $firme = $this->righe(
            "SELECT f.id, f.nome, f.tipo, f.html, f.dati, f.updated_at,
                    TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS modificato_da_nome
             FROM firme f LEFT JOIN users u ON u.id = f.modificato_da
             WHERE f.account_id = ? ORDER BY f.nome",
            'i', [(int)$accountId]
        );
        $immagini = [];
        foreach ($this->righe(
            "SELECT i.id, i.firma_id, i.nome, i.mime, i.larghezza, i.altezza, LENGTH(i.dati) AS byte
             FROM firme_immagini i JOIN firme f ON f.id = i.firma_id
             WHERE f.account_id = ? ORDER BY i.id",
            'i', [(int)$accountId]
        ) as $img) {
            $immagini[(int)$img['firma_id']][] = [
                'id' => (int)$img['id'], 'nome' => $img['nome'], 'mime' => $img['mime'],
                'larghezza' => (int)$img['larghezza'], 'altezza' => (int)$img['altezza'], 'byte' => (int)$img['byte'],
            ];
        }
        foreach ($firme as &$f) {
            $f['id'] = (int)$f['id'];
            $f['dati'] = $f['dati'] !== null ? json_decode($f['dati'], true) : null;
            $f['immagini'] = $immagini[$f['id']] ?? [];
        }
        unset($f);
        return ['firme' => $firme, 'predefinite' => $this->predefinite($accountId)];
    }

    /** @return array{nuove: ?int, risposte: ?int} */
    public function predefinite($accountId) {
        $r = $this->righe("SELECT firma_nuove_id, firma_risposte_id FROM mail_accounts WHERE id = ?", 'i', [(int)$accountId]);
        $r = $r[0] ?? [];
        return [
            'nuove' => isset($r['firma_nuove_id']) ? (int)$r['firma_nuove_id'] : null,
            'risposte' => isset($r['firma_risposte_id']) ? (int)$r['firma_risposte_id'] : null,
        ];
    }

    /** Casella a cui appartiene la firma (null se non esiste). */
    public function accountDellaFirma($firmaId) {
        $r = $this->righe("SELECT account_id FROM firme WHERE id = ?", 'i', [(int)$firmaId]);
        return $r ? (int)$r[0]['account_id'] : null;
    }

    /** Immagine con i suoi byte e la casella della firma (firma_immagine.php). */
    public function immagine($immagineId) {
        $r = $this->righe(
            "SELECT i.id, i.firma_id, i.nome, i.mime, i.dati, f.account_id
             FROM firme_immagini i JOIN firme f ON f.id = i.firma_id WHERE i.id = ?",
            'i', [(int)$immagineId]
        );
        return $r[0] ?? null;
    }

    /** Casella a cui appartiene l'immagine (null se non esiste). */
    public function accountDellImmagine($immagineId) {
        $r = $this->righe(
            "SELECT f.account_id FROM firme_immagini i JOIN firme f ON f.id = i.firma_id WHERE i.id = ?",
            'i', [(int)$immagineId]
        );
        return $r ? (int)$r[0]['account_id'] : null;
    }

    private function idImmaginiDellaFirma($firmaId) {
        return array_map(fn($r) => (int)$r['id'],
            $this->righe("SELECT id FROM firme_immagini WHERE firma_id = ?", 'i', [(int)$firmaId]));
    }

    // ==================== MODIFICA ====================

    private static function nomePulito($nome) {
        $nome = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$nome));
        if ($nome === '') {
            throw new InvalidArgumentException('Scrivi il nome della firma');
        }
        if (mb_strlen($nome) > self::MAX_NOME) {
            throw new InvalidArgumentException('Il nome della firma può avere al massimo ' . self::MAX_NOME . ' caratteri');
        }
        return $nome;
    }

    /** Nuova firma vuota nella casella. @return int id */
    public function crea($accountId, $nome, $utente) {
        $nome = self::nomePulito($nome);
        $quante = $this->righe("SELECT COUNT(*) AS n FROM firme WHERE account_id = ?", 'i', [(int)$accountId]);
        if ((int)$quante[0]['n'] >= self::MAX_FIRME) {
            throw new InvalidArgumentException('Una casella può avere al massimo ' . self::MAX_FIRME . ' firme');
        }
        $utente = $utente > 0 ? (int)$utente : null;
        $stmt = $this->query(
            "INSERT INTO firme (account_id, nome, tipo, html, dati, creato_da, modificato_da) VALUES (?, ?, 'dati', '', NULL, ?, ?)",
            'isii', [(int)$accountId, $nome, $utente, $utente]
        );
        $id = (int)$stmt->insert_id;
        $stmt->close();
        // La prima firma della casella diventa quella di serie per le email nuove e le risposte
        $this->query(
            "UPDATE mail_accounts SET firma_nuove_id = COALESCE(firma_nuove_id, ?),
                                      firma_risposte_id = COALESCE(firma_risposte_id, ?)
             WHERE id = ? AND NOT EXISTS (SELECT 1 FROM firme WHERE account_id = ? AND id <> ?)",
            'iiiii', [$id, $id, (int)$accountId, (int)$accountId, $id]
        )->close();
        return $id;
    }

    /**
     * Salva nome, tipo, HTML e dati della firma. Le immagini che l'HTML non
     * usa più si cancellano. @return string HTML come è stato salvato
     */
    public function salva($firmaId, $nome, $tipo, $html, $dati, $utente) {
        $nome = self::nomePulito($nome);
        if (!in_array($tipo, self::TIPI, true)) {
            throw new InvalidArgumentException('Tipo di firma non valido');
        }
        $datiJson = null;
        if (is_array($dati)) {
            $datiJson = json_encode(self::datiPuliti($dati), JSON_UNESCAPED_UNICODE);
            if (strlen($datiJson) > self::MAX_DATI) {
                throw new InvalidArgumentException('Dati della firma troppo lunghi');
            }
        }
        $proprie = $this->idImmaginiDellaFirma($firmaId);
        $html = self::pulisciHtml((string)$html, $proprie);
        if (strlen($html) > self::MAX_HTML) {
            throw new InvalidArgumentException('La firma è troppo lunga (al massimo 64 KB di HTML)');
        }
        $utente = $utente > 0 ? (int)$utente : null;
        $this->query(
            "UPDATE firme SET nome = ?, tipo = ?, html = ?, dati = ?, modificato_da = ? WHERE id = ?",
            'ssssii', [$nome, $tipo, $html, $datiJson, $utente, (int)$firmaId]
        )->close();

        $usate = self::immaginiUsate($html);
        foreach (array_diff($proprie, $usate) as $id) {
            $this->query("DELETE FROM firme_immagini WHERE id = ?", 'i', [$id])->close();
        }
        return $html;
    }

    /** I dati del modulo "Crea dai dati", solo i campi noti, come testo corto. */
    private static function datiPuliti(array $dati) {
        $campi = ['nome', 'qualifica', 'societa', 'telefono', 'cellulare', 'email', 'sito', 'indirizzo',
            'impaginazione', 'colore', 'logo', 'logo_larghezza', 'separatore'];
        $puliti = [];
        foreach ($campi as $campo) {
            $v = $dati[$campo] ?? '';
            if (is_bool($v)) {
                $v = $v ? '1' : '';
            }
            if (!is_scalar($v)) {
                continue;
            }
            $v = (string)$v;
            // L'indirizzo tiene gli a capo, gli altri campi stanno su una riga
            $v = $campo === 'indirizzo' ? preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $v)
                                        : preg_replace('/[\x00-\x1F\x7F]/u', '', $v);
            $puliti[$campo] = mb_substr(trim($v), 0, 200);
        }
        return $puliti;
    }

    public function elimina($firmaId) {
        $this->query("DELETE FROM firme WHERE id = ?", 'i', [(int)$firmaId])->close();
    }

    /** Firme predefinite della casella; null o 0 = nessuna. */
    public function impostaPredefinite($accountId, $nuove, $risposte) {
        $valide = array_map(fn($r) => (int)$r['id'],
            $this->righe("SELECT id FROM firme WHERE account_id = ?", 'i', [(int)$accountId]));
        $controlla = function ($id) use ($valide) {
            $id = (int)$id;
            if ($id <= 0) {
                return null;
            }
            if (!in_array($id, $valide, true)) {
                throw new InvalidArgumentException('La firma scelta non è di questa casella');
            }
            return $id;
        };
        $nuove = $controlla($nuove);
        $risposte = $controlla($risposte);
        $this->query(
            "UPDATE mail_accounts SET firma_nuove_id = ?, firma_risposte_id = ? WHERE id = ?",
            'iii', [$nuove, $risposte, (int)$accountId]
        )->close();
    }

    /**
     * Aggiunge un'immagine alla firma: PNG, JPEG o GIF riconosciuti dai primi
     * byte, al massimo 256 KB (il browser la rimpicciolisce prima di mandarla).
     * @return array id, nome, mime, larghezza, altezza, byte
     */
    public function aggiungiImmagine($firmaId, $nome, $byte) {
        $byte = (string)$byte;
        if ($byte === '') {
            throw new InvalidArgumentException('Immagine vuota');
        }
        if (strlen($byte) > self::MAX_IMMAGINE) {
            throw new InvalidArgumentException('Immagine troppo grande: al massimo 256 KB');
        }
        $mime = self::tipoImmagine($byte);
        $info = $mime ? @getimagesizefromstring($byte) : false;
        if (!$mime || !$info || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidArgumentException('Sono ammesse solo immagini PNG, JPEG o GIF');
        }
        if ($info[0] > self::MAX_LATO || $info[1] > self::MAX_LATO) {
            throw new InvalidArgumentException('Immagine troppo grande: al massimo ' . self::MAX_LATO . ' pixel per lato');
        }
        $tot = $this->righe("SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(dati)), 0) AS byte FROM firme_immagini WHERE firma_id = ?",
            'i', [(int)$firmaId])[0];
        if ((int)$tot['n'] >= self::MAX_IMMAGINI) {
            throw new InvalidArgumentException('Una firma può avere al massimo ' . self::MAX_IMMAGINI . ' immagini');
        }
        if ((int)$tot['byte'] + strlen($byte) > self::MAX_IMMAGINI_BYTE) {
            throw new InvalidArgumentException('Le immagini di una firma possono pesare al massimo 512 KB in tutto: '
                . 'togline una o usane di più piccole');
        }
        $nome = basename(str_replace('\\', '/', (string)$nome));
        $nome = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F"]+/u', '', $nome)), 0, 150);
        if ($nome === '') {
            $nome = 'immagine';
        }
        $stmt = $this->query(
            "INSERT INTO firme_immagini (firma_id, nome, mime, larghezza, altezza, dati) VALUES (?, ?, ?, ?, ?, ?)",
            'issiis', [(int)$firmaId, $nome, $mime, (int)$info[0], (int)$info[1], $byte]
        );
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return ['id' => $id, 'nome' => $nome, 'mime' => $mime, 'larghezza' => (int)$info[0],
            'altezza' => (int)$info[1], 'byte' => strlen($byte)];
    }

    public function eliminaImmagine($immagineId) {
        $this->query("DELETE FROM firme_immagini WHERE id = ?", 'i', [(int)$immagineId])->close();
    }

    /** image/png, image/jpeg o image/gif dai primi byte; null per tutto il resto. */
    public static function tipoImmagine($byte) {
        if (strncmp($byte, "\x89PNG\r\n\x1A\n", 8) === 0) return 'image/png';
        if (strncmp($byte, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
        if (strncmp($byte, 'GIF87a', 6) === 0 || strncmp($byte, 'GIF89a', 6) === 0) return 'image/gif';
        return null;
    }

    // ==================== INVIO ====================

    /** Id delle immagini firma_immagine.php?id=N usate in un HTML. */
    public static function immaginiUsate($html) {
        preg_match_all('/firma_immagine\.php\?id=(\d+)/', (string)$html, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /**
     * Mette dentro l'email le immagini delle firme della casella usate nel
     * corpo (src="firma_immagine.php?id=N" diventa cid:) con
     * addStringEmbeddedImage(). Un'immagine di un'altra casella o non più
     * esistente si toglie dal corpo.
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $mail
     * @return string il corpo con gli indirizzi cid:
     */
    public function incorporaImmagini($html, $accountId, $mail) {
        $ids = self::immaginiUsate($html);
        if (!$ids) {
            return $html;
        }
        $segnaposti = implode(',', array_fill(0, count($ids), '?'));
        $righe = $this->righe(
            "SELECT i.id, i.nome, i.mime, i.dati FROM firme_immagini i JOIN firme f ON f.id = i.firma_id
             WHERE f.account_id = ? AND i.id IN ($segnaposti)",
            'i' . str_repeat('i', count($ids)), array_merge([(int)$accountId], $ids)
        );
        $cid = [];
        foreach ($righe as $r) {
            // Unico nel messaggio, senza niente della casella o dell'utente
            $c = 'firma' . (int)$r['id'] . '.' . bin2hex(random_bytes(8)) . '@pigreco-posta';
            $mail->addStringEmbeddedImage($r['dati'], $c, $r['nome'], 'base64', $r['mime']);
            $cid[(int)$r['id']] = $c;
        }
        return preg_replace_callback(
            '/(\ssrc\s*=\s*)(["\']?)firma_immagine\.php\?id=(\d+)[^"\'\s>]*\2/i',
            function ($m) use ($cid) {
                $id = (int)$m[3];
                return $m[1] . '"' . (isset($cid[$id]) ? 'cid:' . $cid[$id] : '') . '"';
            },
            $html
        );
    }

    // ==================== PULIZIA HTML ====================

    /**
     * HTML della firma ridotto a quello che serve in un'email: elementi e
     * attributi di un elenco fisso, link solo http(s), mailto e tel,
     * immagini solo dal web (http/https) o della firma stessa
     * (firma_immagine.php?id=N con N in $immaginiProprie), stili senza
     * url(), expression, position e simili. Si lavora sull'albero di
     * DOMDocument, mai con espressioni regolari sull'HTML.
     *
     * @param int[] $immaginiProprie id delle immagini della firma
     */
    public static function pulisciHtml($html, array $immaginiProprie = []) {
        $html = trim((string)$html);
        if ($html === '') {
            return '';
        }
        if (strlen($html) > 4 * self::MAX_HTML) {
            throw new InvalidArgumentException('La firma è troppo lunga (al massimo 64 KB di HTML)');
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prima = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><!DOCTYPE html><html><head></head><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prima);
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }
        self::pulisciNodo($body, array_map('intval', $immaginiProprie));
        $out = '';
        foreach ($body->childNodes as $figlio) {
            $out .= $doc->saveHTML($figlio);
        }
        return trim($out);
    }

    private static function pulisciNodo(DOMNode $nodo, array $proprie) {
        foreach (iterator_to_array($nodo->childNodes) as $figlio) {
            if ($figlio instanceof DOMText) {
                continue;   // anche DOMCdataSection, che si salva come testo
            }
            if (!($figlio instanceof DOMElement)) {
                $nodo->removeChild($figlio);   // commenti, istruzioni di elaborazione
                continue;
            }
            $tag = strtolower($figlio->tagName);
            if (in_array($tag, self::ELEMENTI_VIA, true)) {
                $nodo->removeChild($figlio);
                continue;
            }
            self::pulisciNodo($figlio, $proprie);
            if (!in_array($tag, self::ELEMENTI, true)) {
                while ($figlio->firstChild) {
                    $nodo->insertBefore($figlio->firstChild, $figlio);
                }
                $nodo->removeChild($figlio);
                continue;
            }
            self::pulisciAttributi($figlio, $tag, $proprie);
            if ($tag === 'img' && !$figlio->hasAttribute('src')) {
                $nodo->removeChild($figlio);
            }
        }
    }

    private static function pulisciAttributi(DOMElement $el, $tag, array $proprie) {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $nome = strtolower($attr->name);
            $valore = trim($attr->value);
            $tieni = in_array($nome, self::ATTRIBUTI, true) && mb_strlen($valore) <= 2000;
            if ($tieni) {
                // Senza spazi e caratteri di controllo, per riconoscere "java\tscript:" e simili
                $compatto = strtolower(preg_replace('/[\x00-\x20\x7F]+/', '', $valore));
                switch ($nome) {
                    case 'href':
                        $tieni = $tag === 'a' && preg_match('#^(https?://|mailto:|tel:)#', $compatto);
                        break;
                    case 'src':
                        if ($tag !== 'img') {
                            $tieni = false;
                        } elseif (preg_match('/^firma_immagine\.php\?id=(\d+)(&(amp;)?v=\d+)?$/', $compatto, $m)) {
                            $tieni = in_array((int)$m[1], $proprie, true);
                            $valore = self::URL_IMMAGINE . (int)$m[1];
                        } else {
                            $tieni = (bool)preg_match('#^https?://#', $compatto);
                        }
                        break;
                    case 'style':
                        $tieni = !preg_match('/expression|javascript|vbscript|url\s*\(|@import|behavior|binding|position|[\\\\<>]/i',
                            $valore);
                        break;
                    case 'width':
                    case 'height':
                        $tieni = (bool)preg_match('/^\d{1,4}%?$/', $valore);
                        break;
                }
            }
            if ($tieni) {
                $el->setAttribute($nome, $valore);
                if ($attr->name !== $nome) {
                    $el->removeAttributeNode($attr);
                }
            } else {
                $el->removeAttributeNode($attr);
            }
        }
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener');
        }
    }
}
