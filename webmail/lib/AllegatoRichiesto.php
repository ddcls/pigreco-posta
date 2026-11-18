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
 * L'allegato indicato dalla richiesta (account_id o user, file o uid, index,
 * folder), letto dalla casella dopo il controllo dell'autorizzazione.
 * Usato da xml_allegato.php e fattura_allegato.php.
 *
 * @param callable(string, int): never $errore risponde e termina
 * @return array{nome:string, tipo:string, contenuto:string}
 */
function allegatoRichiesto(callable $errore): array
{
    $accountParam = requestParam(['account_id', 'user']);
    $uid = (int)requestParam(['uid', 'file']);
    $index = (int)requestParam(['index'], '0');
    $folder = requestParam(['folder'], 'inbox');
    if (!MailboxService::isFolderParam($folder)) {
        $folder = 'inbox';
    }

    if ($accountParam === '' || $uid <= 0 || $index < 0) {
        $errore('Parametri non validi', 400);
    }

    try {
        $mailbox = openAuthorizedMailbox($accountParam);
        if ($mailbox === null) {
            $errore('Account non valido o non autorizzato', 403);
        }
        $raw = $mailbox->getRaw($folder, $uid, false);
        $mailbox->close();
    } catch (Throwable $e) {
        $errore('Errore di lettura dal server di posta', 502);
    }

    if ($raw === null) {
        $errore('Email non trovata', 404);
    }

    $email = parseRawEmail($raw);
    if (isset($email['error'])) {
        $errore('Email non leggibile', 500);
    }
    $allegato = $email['attachments'][$index] ?? null;
    if ($allegato === null) {
        $errore('Allegato non trovato', 404);
    }

    $contenuto = base64_decode($allegato['content'] ?? '', true);
    if ($contenuto === false) {
        $errore('Contenuto dell\'allegato non valido', 500);
    }

    return [
        'nome' => (string)($allegato['filename'] ?? 'allegato'),
        'tipo' => (string)($allegato['content_type'] ?? ''),
        'contenuto' => $contenuto,
    ];
}
