<?php
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
