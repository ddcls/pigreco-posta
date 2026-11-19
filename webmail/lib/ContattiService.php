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
 * Rubrica dei contatti della webmail (tabella "contatti", migrations/008_contatti.sql).
 *
 * Una sola rubrica in comune: tutti gli utenti della webmail la vedono e la
 * modificano. Gli stessi campi dell'agenda di PiGreco Archivio, così
 * contatti.js usa una o l'altra con lo stesso codice. Un indirizzo email sta
 * in un solo contatto (senza badare alle maiuscole).
 */

require_once __DIR__ . '/../config/db_config.php';

class ContattiService {
    const CATEGORIE = ['Cliente', 'Fornitore', 'Collega', 'Partner', 'Altro'];
    /** Campi di testo e lunghezza massima (caratteri) */
    const CAMPI = ['nome' => 100, 'cognome' => 100, 'azienda' => 150, 'email' => 150, 'telefono' => 50,
        'sito_web' => 500, 'note' => 5000];
    const MAX_CONTATTI = 20000;

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

    private function righe($sql, $types = '', array $params = []) {
        $stmt = $this->query($sql, $types, $params);
        $righe = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $righe;
    }

    /** Tutti i contatti, in ordine di cognome, nome, azienda. */
    public function elenco() {
        $righe = $this->righe(
            "SELECT id, nome, cognome, azienda, categoria, email, telefono, sito_web, note, creato_da, updated_at
               FROM contatti
              ORDER BY (cognome = '' AND nome = ''), cognome, nome, azienda, email");
        foreach ($righe as &$r) {
            $r['id'] = (int)$r['id'];
            $r['creato_da'] = $r['creato_da'] === null ? null : (int)$r['creato_da'];
        }
        return $righe;
    }

    /** Dati ricevuti → campi puliti; eccezione se non vanno bene. */
    private function pulisci(array $dati) {
        $c = [];
        foreach (self::CAMPI as $campo => $massimo) {
            $valore = trim(str_replace("\0", '', (string)($dati[$campo] ?? '')));
            if ($campo !== 'note') {
                $valore = preg_replace('/\s+/u', ' ', $valore);
            }
            if (mb_strlen($valore) > $massimo) {
                throw new InvalidArgumentException("Il campo $campo è troppo lungo (al massimo $massimo caratteri)");
            }
            $c[$campo] = $valore;
        }
        $categoria = (string)($dati['categoria'] ?? '');
        $c['categoria'] = in_array($categoria, self::CATEGORIE, true) ? $categoria : 'Altro';
        if ($c['nome'] === '' && $c['cognome'] === '') {
            throw new InvalidArgumentException('Inserisci almeno nome o cognome');
        }
        if ($c['email'] !== '' && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Indirizzo email non valido: ' . $c['email']);
        }
        return $c;
    }

    /** Il contatto che ha già questo indirizzo (diverso da $tranneId), o null. */
    private function conEmail($email, $tranneId) {
        if ($email === '') {
            return null;
        }
        $righe = $this->righe('SELECT id, nome, cognome, azienda FROM contatti WHERE email = ? AND id <> ? LIMIT 1',
            'si', [$email, (int)$tranneId]);
        return $righe[0] ?? null;
    }

    /** Nuovo contatto ($id = 0) o modifica; restituisce l'id. */
    public function salva($id, array $dati, $utente) {
        $id = (int)$id;
        $c = $this->pulisci($dati);
        $altro = $this->conEmail($c['email'], $id);
        if ($altro) {
            $nome = trim($altro['nome'] . ' ' . $altro['cognome']) ?: $altro['azienda'];
            throw new InvalidArgumentException("{$c['email']} è già nella rubrica come $nome");
        }
        $utente = $utente > 0 ? (int)$utente : null;
        if ($id > 0) {
            $stmt = $this->query(
                'UPDATE contatti SET nome = ?, cognome = ?, azienda = ?, categoria = ?, email = ?, telefono = ?,
                        sito_web = ?, note = ?, modificato_da = ? WHERE id = ?',
                'ssssssssii', [$c['nome'], $c['cognome'], $c['azienda'], $c['categoria'], $c['email'],
                    $c['telefono'], $c['sito_web'], $c['note'], $utente, $id]);
            $stmt->close();
            if (!$this->righe('SELECT id FROM contatti WHERE id = ?', 'i', [$id])) {
                throw new InvalidArgumentException('Contatto non trovato: forse è stato eliminato');
            }
            return $id;
        }
        $totale = $this->righe('SELECT COUNT(*) AS n FROM contatti')[0]['n'] ?? 0;
        if ((int)$totale >= self::MAX_CONTATTI) {
            throw new InvalidArgumentException('La rubrica è piena (' . self::MAX_CONTATTI . ' contatti)');
        }
        $stmt = $this->query(
            'INSERT INTO contatti (nome, cognome, azienda, categoria, email, telefono, sito_web, note, creato_da,
                                   modificato_da)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'ssssssssii', [$c['nome'], $c['cognome'], $c['azienda'], $c['categoria'], $c['email'], $c['telefono'],
                $c['sito_web'], $c['note'], $utente, $utente]);
        $nuovo = (int)$stmt->insert_id;
        $stmt->close();
        return $nuovo;
    }

    public function elimina($id) {
        $stmt = $this->query('DELETE FROM contatti WHERE id = ?', 'i', [(int)$id]);
        $tolti = $stmt->affected_rows;
        $stmt->close();
        if ($tolti < 1) {
            throw new InvalidArgumentException('Contatto non trovato: forse è già stato eliminato');
        }
    }

    // ==================== IMPORTAZIONE DA CSV ====================

    const MAX_IMPORTA_BYTE = 5242880;   // 5 MB
    const MAX_IMPORTA_RIGHE = 10000;

    /**
     * Nomi di colonna riconosciuti, per campo, in ordine di preferenza: il CSV
     * esportato da qui, da Outlook, Google Contatti e Thunderbird (anche in
     * italiano). Si confrontano dopo normalizza(): minuscole, senza accenti e
     * punteggiatura ("E-mail 1 - Value" → "e mail 1 value").
     */
    const COLONNE = [
        'nome' => ['nome', 'first name', 'given name', 'firstname', 'nome proprio'],
        'cognome' => ['cognome', 'last name', 'family name', 'surname', 'lastname'],
        'completo' => ['nome visualizzato', 'display name', 'name', 'nome completo', 'full name', 'nominativo'],
        'azienda' => ['azienda', 'societa', 'company', 'organization', 'organization name', 'organization 1 name',
            'organizzazione', 'ditta', 'ragione sociale'],
        'email' => ['email', 'e mail', 'mail', 'e mail address', 'email address', 'primary email', 'e mail 1 value',
            'indirizzo email', 'indirizzo e mail', 'indirizzo posta elettronica', 'indirizzo email principale',
            'posta elettronica', 'e mail 1', 'email 1', 'e mail 2 address', 'e mail 2 value', 'secondary email',
            'indirizzo email secondario', 'e mail 2', 'email 2', 'e mail 3 address', 'e mail 3 value'],
        'telefono' => ['telefono', 'tel', 'phone', 'cellulare', 'mobile', 'mobile phone', 'business phone',
            'phone 1 value', 'telefono ufficio', 'telefono cellulare', 'numero cellulare', 'work phone',
            'mobile number', 'primary phone', 'telefono principale', 'phone number', 'home phone',
            'telefono abitazione', 'phone 2 value', 'business phone 2', 'telefono ufficio 2'],
        'sito_web' => ['sito web', 'sito', 'website', 'web site', 'web page', 'web page 1', 'pagina web',
            'pagina web 1', 'website 1 value', 'web'],
        'note' => ['note', 'notes', 'annotazioni'],
        'categoria' => ['categoria', 'category', 'categories'],
    ];

    private static function normalizza($testo) {
        $testo = mb_strtolower(trim((string)$testo));
        $testo = strtr($testo, ['à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'í' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ù' => 'u', 'ú' => 'u']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $testo));
    }

    /** Testo del file in UTF-8, senza BOM (UTF-16 di Excel/Outlook e Windows-1252 convertiti). */
    private static function testoUtf8($dati) {
        if (strncmp($dati, "\xFF\xFE", 2) === 0) {
            return mb_convert_encoding(substr($dati, 2), 'UTF-8', 'UTF-16LE');
        }
        if (strncmp($dati, "\xFE\xFF", 2) === 0) {
            return mb_convert_encoding(substr($dati, 2), 'UTF-8', 'UTF-16BE');
        }
        if (strncmp($dati, "\xEF\xBB\xBF", 3) === 0) {
            $dati = substr($dati, 3);
        }
        return mb_check_encoding($dati, 'UTF-8') ? $dati : mb_convert_encoding($dati, 'UTF-8', 'Windows-1252');
    }

    /** Righe del CSV (array di array); separatore ; , o tabulazione, scelto dalla prima riga. */
    private static function righeCsv($testo) {
        $prima = strtok($testo, "\n");
        $conta = [';' => substr_count($prima, ';'), ',' => substr_count($prima, ','), "\t" => substr_count($prima, "\t")];
        arsort($conta);
        $separatore = (string)array_key_first($conta);
        $f = fopen('php://temp', 'r+');
        fwrite($f, $testo);
        rewind($f);
        $righe = [];
        while (($r = fgetcsv($f, 0, $separatore, '"', '')) !== false) {
            if ($r === [null] || implode('', array_map('trim', $r)) === '') {
                continue;
            }
            $righe[] = $r;
            if (count($righe) > self::MAX_IMPORTA_RIGHE + 1) {
                fclose($f);
                throw new InvalidArgumentException('Il file ha più di ' . self::MAX_IMPORTA_RIGHE . ' righe: dividilo in più parti');
            }
        }
        fclose($f);
        return $righe;
    }

    /** Intestazione → [campo => [indici delle colonne in ordine di preferenza]] */
    private static function mappaColonne(array $intestazione) {
        $norm = array_map([self::class, 'normalizza'], $intestazione);
        $mappa = [];
        foreach (self::COLONNE as $campo => $nomi) {
            foreach ($nomi as $nome) {
                foreach (array_keys($norm, $nome, true) as $i) {
                    $mappa[$campo][] = $i;
                }
            }
        }
        return $mappa;
    }

    /** Primo valore non vuoto delle colonne del campo ("a ::: b" di Google → "a"; via l'apostrofo dell'export). */
    private static function valore(array $riga, array $mappa, $campo) {
        foreach ($mappa[$campo] ?? [] as $i) {
            $v = trim(explode(':::', (string)($riga[$i] ?? ''))[0]);
            if ($v !== '') {
                return preg_match("/^'[=+\\-@]/", $v) ? substr($v, 1) : $v;
            }
        }
        return '';
    }

    /** Particelle che fanno parte del cognome: "Paolo De Santis" → Paolo + De Santis */
    const PARTICELLE = ['de', 'di', 'da', 'del', 'della', 'dello', 'dei', 'degli', 'delle', 'dal', 'dalla', 'dall',
        'd', 'lo', 'la', 'li', 'van', 'von', 'der', 'le'];

    /** Parole del nome → [nome, cognome]: l'ultima parola (con le particelle davanti) è il cognome. */
    private static function dividiNome(array $parti) {
        if (count($parti) < 2) {
            return [$parti[0] ?? '', ''];
        }
        $cognome = [array_pop($parti)];
        while (count($parti) > 1 && in_array(self::normalizza(end($parti)), self::PARTICELLE, true)) {
            array_unshift($cognome, array_pop($parti));
        }
        return [implode(' ', $parti), implode(' ', $cognome)];
    }

    /**
     * Importa i contatti di un file CSV. Con $prova non scrive niente e dice
     * cosa succederebbe. Saltati: gli indirizzi già in rubrica (o già visti nel
     * file), i contatti senza email uguali a uno presente, le righe non valide.
     * Senza nome e cognome si usa il nome visualizzato, poi l'azienda, poi la
     * parte dell'email prima della @.
     *
     * @return array {righe, nuovi, presenti, errori: [{riga, motivo}], esempi: [...], colonne: {campo: nome}}
     */
    public function importa($dati, $prova, $utente) {
        if (strlen($dati) > self::MAX_IMPORTA_BYTE) {
            throw new InvalidArgumentException('File troppo grande: al massimo 5 MB');
        }
        $righe = self::righeCsv(self::testoUtf8($dati));
        if (count($righe) < 2) {
            throw new InvalidArgumentException('Il file è vuoto o ha solo la riga di intestazione');
        }
        $intestazione = array_shift($righe);
        $mappa = self::mappaColonne($intestazione);
        if (empty($mappa['email']) && empty($mappa['nome']) && empty($mappa['cognome']) && empty($mappa['completo'])) {
            throw new InvalidArgumentException('Colonne non riconosciute: la prima riga deve avere i nomi delle colonne, '
                . 'per esempio Nome;Cognome;Azienda;Email;Telefono (va bene anche il CSV di Outlook, Google o Thunderbird)');
        }
        $colonne = [];
        foreach ($mappa as $campo => $indici) {
            if ($campo !== 'completo' || (empty($mappa['nome']) && empty($mappa['cognome']))) {
                $colonne[$campo] = implode(', ', array_map(fn($i) => trim((string)$intestazione[$i]), $indici));
            }
        }

        $emailPresenti = [];
        $senzaEmail = [];
        foreach ($this->righe('SELECT nome, cognome, azienda, email, telefono FROM contatti') as $c) {
            if ($c['email'] !== '') {
                $emailPresenti[mb_strtolower($c['email'])] = true;
            } else {
                $senzaEmail[mb_strtolower("{$c['nome']}|{$c['cognome']}|{$c['azienda']}|{$c['telefono']}")] = true;
            }
        }
        $totale = count($emailPresenti) + count($senzaEmail);

        $nuovi = [];
        $presenti = 0;
        $errori = [];
        foreach ($righe as $n => $riga) {
            $numero = $n + 2;               // la riga 1 è l'intestazione
            $c = [];
            foreach (['nome', 'cognome', 'azienda', 'email', 'telefono', 'sito_web', 'note', 'categoria'] as $campo) {
                $c[$campo] = self::valore($riga, $mappa, $campo);
            }
            $c['email'] = trim($c['email'], " <>\t");
            $c['note'] = mb_substr($c['note'], 0, self::CAMPI['note']);
            $categoria = array_values(array_filter(self::CATEGORIE,
                fn($k) => self::normalizza($k) === self::normalizza($c['categoria'])));
            $c['categoria'] = $categoria[0] ?? 'Altro';
            if ($c['nome'] === '' && $c['cognome'] === '') {
                $parti = preg_split('/\s+/u', self::valore($riga, $mappa, 'completo'), -1, PREG_SPLIT_NO_EMPTY);
                if (!$parti && $c['azienda'] !== '') {
                    $parti = [$c['azienda']];
                }
                if (!$parti && $c['email'] !== '') {
                    $parti = array_map(fn($p) => mb_convert_case($p, MB_CASE_TITLE),
                        preg_split('/[._-]+/', explode('@', $c['email'])[0], -1, PREG_SPLIT_NO_EMPTY));
                    $parti = array_values(array_filter($parti, fn($p) => !ctype_digit($p)));
                }
                [$c['nome'], $c['cognome']] = self::dividiNome($parti);
            }
            if (implode('', $c) === 'Altro') {
                continue;                   // riga senza dati
            }
            try {
                $c = $this->pulisci($c);
            } catch (InvalidArgumentException $e) {
                if (count($errori) < 100) {
                    $errori[] = ['riga' => $numero, 'motivo' => $e->getMessage()];
                }
                continue;
            }
            if ($c['email'] !== '') {
                $chiave = mb_strtolower($c['email']);
                if (isset($emailPresenti[$chiave])) {
                    $presenti++;
                    continue;
                }
                $emailPresenti[$chiave] = true;
            } else {
                $chiave = mb_strtolower("{$c['nome']}|{$c['cognome']}|{$c['azienda']}|{$c['telefono']}");
                if (isset($senzaEmail[$chiave])) {
                    $presenti++;
                    continue;
                }
                $senzaEmail[$chiave] = true;
            }
            $nuovi[] = $c;
        }
        if ($totale + count($nuovi) > self::MAX_CONTATTI) {
            throw new InvalidArgumentException('Con questi contatti la rubrica supererebbe il massimo di '
                . self::MAX_CONTATTI);
        }

        if (!$prova && $nuovi) {
            $utente = $utente > 0 ? (int)$utente : null;
            $this->conn->begin_transaction();
            try {
                foreach ($nuovi as $c) {
                    $this->query(
                        'INSERT INTO contatti (nome, cognome, azienda, categoria, email, telefono, sito_web, note,
                                               creato_da, modificato_da)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        'ssssssssii', [$c['nome'], $c['cognome'], $c['azienda'], $c['categoria'], $c['email'],
                            $c['telefono'], $c['sito_web'], $c['note'], $utente, $utente])->close();
                }
                $this->conn->commit();
            } catch (Throwable $e) {
                $this->conn->rollback();
                throw $e;
            }
        }
        return [
            'righe' => count($righe),
            'nuovi' => count($nuovi),
            'presenti' => $presenti,
            'errori' => $errori,
            'esempi' => array_slice($nuovi, 0, 10),
            'colonne' => $colonne,
        ];
    }

    /**
     * La rubrica in CSV per Excel (UTF-8 con BOM, separatore ";"). Un valore che
     * inizia con = + - @ prende un apostrofo davanti: Excel non lo esegue come formula.
     */
    public function csv() {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        $colonne = ['nome', 'cognome', 'azienda', 'categoria', 'email', 'telefono', 'sito_web', 'note'];
        fputcsv($out, ['Nome', 'Cognome', 'Azienda', 'Categoria', 'Email', 'Telefono', 'Sito web', 'Note'], ';', '"', '');
        foreach ($this->elenco() as $c) {
            fputcsv($out, array_map(function ($k) use ($c) {
                $v = (string)($c[$k] ?? '');
                return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
            }, $colonne), ';', '"', '');
        }
        rewind($out);
        $testo = stream_get_contents($out);
        fclose($out);
        return $testo;
    }
}
