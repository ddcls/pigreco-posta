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
 * Configurazione di PiGreco Posta nel container "webmail".
 *
 * Restituisce un array vuoto: tutti i valori (DB_*, MAIL_ENCRYPTION_KEY,
 * IMAP_*, ARCHIVIO_URL, ...) arrivano dalle variabili d'ambiente impostate in
 * docker-compose.yml a partire da .env. Così un eventuale
 * config/config.local.php presente nel clone (usato per un'installazione
 * fuori da Docker) viene ignorato.
 */
return [];
