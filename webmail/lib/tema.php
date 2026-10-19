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
 * Tema di colore dell'interfaccia (vedi temi.css).
 *
 * Ogni utente può scegliere il proprio colore (users.tema); chi non l'ha
 * scelto, e la pagina di login, usano il colore predefinito deciso
 * dall'amministratore (impostazioni.tema_predefinito). Tabella e colonna
 * arrivano con migrations/003_tema.sql: se mancano si usa sempre il tema
 * di base, senza errori.
 */

require_once __DIR__ . '/../config/db_config.php';

const TEMA_BASE = 'marrone';

/** Temi disponibili: id => nome mostrato (stesso ordine della finestra Aspetto) */
function temiDisponibili() {
    return [
        'marrone'   => 'Marrone (Archivio)',
        'blu'       => 'Blu',
        'arancione' => 'Arancione',
        'verde'     => 'Verde',
        'bordeaux'  => 'Bordeaux',
        'grafite'   => 'Grafite',
    ];
}

function temaValido($tema) {
    return is_string($tema) && array_key_exists($tema, temiDisponibili());
}

/** Colore predefinito per tutti (login e utenti senza una scelta propria) */
function temaPredefinito() {
    $conn = getDBConnection();
    if (!$conn) {
        return TEMA_BASE;
    }
    $result = $conn->query("SELECT valore FROM impostazioni WHERE chiave = 'tema_predefinito' LIMIT 1");
    if (!$result) {
        return TEMA_BASE;   // tabella non ancora creata (migrazione 003)
    }
    $row = $result->fetch_assoc();
    return ($row && temaValido($row['valore'])) ? $row['valore'] : TEMA_BASE;
}

/** Colore scelto dall'utente, NULL se non ne ha scelto uno */
function temaSceltoUtente($userId) {
    $conn = getDBConnection();
    if (!$conn || !$userId) {
        return null;
    }
    $stmt = $conn->prepare("SELECT tema FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;        // colonna non ancora creata (migrazione 003)
    }
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row && temaValido($row['tema'])) ? $row['tema'] : null;
}

/** Colore da usare per l'utente: il suo, altrimenti quello predefinito */
function temaUtente($userId) {
    return temaSceltoUtente($userId) ?? temaPredefinito();
}

/** Attributo da mettere su <html> (vuoto per il tema di base, che è in :root) */
function attributoTema($tema) {
    if (!temaValido($tema) || $tema === TEMA_BASE) {
        return '';
    }
    return ' data-tema="' . htmlspecialchars($tema, ENT_QUOTES) . '"';
}
