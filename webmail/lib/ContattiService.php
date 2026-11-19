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
