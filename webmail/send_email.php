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
 * send_email.php - Invio email dall'account scelto (tabella mail_accounts)
 * Invio tramite il server SMTP dell'account, con allegati manuali e inoltrati
 * (letti via IMAP); copia salvata nella cartella Sent dell'account via IMAP APPEND
 * Pulizia sicura dei file temporanei dopo ogni invio
 * 
 * CORREZIONI APPLICATE:
 * ✅ Rimossa duplicazione caricamento configurazione SMTP
 * ✅ Pulizia garantita file temporanei allegati inoltrati (try/finally)
 * ✅ Validazione completa parametri con logging esplicito
 * ✅ Gestione errori robusta con rollback sicuro
 * ✅ Logging strutturato per debug
 * ✅ Supporto multi-utente con account SMTP separati
 * ✅ Sanitizzazione sicura input con preservazione CASE
 */

// Abilita logging dettagliato (disattivare in produzione)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Verifica autenticazione
require_once __DIR__ . '/check_auth.php';
header('Content-Type: application/json; charset=utf-8');

// Carica PHPMailer
$phpmailerLoaded = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    $phpmailerLoaded = true;
} elseif (file_exists(__DIR__ . '/phpmailer/src/PHPMailer.php')) {
    require __DIR__ . '/phpmailer/src/Exception.php';
    require __DIR__ . '/phpmailer/src/PHPMailer.php';
    require __DIR__ . '/phpmailer/src/SMTP.php';
    $phpmailerLoaded = true;
}

if (!$phpmailerLoaded) {
    echo json_encode([
        'success' => false,
        'error' => 'Errore di configurazione: libreria PHPMailer non disponibile. Contattare l\'amministratore.'
    ]);
    exit;
}

require_once __DIR__ . '/lib/MailboxService.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ==================== VALIDAZIONE INPUT E CONFIGURAZIONE ====================
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo non consentito. Usare POST.');
    }

    // Account mittente: id bloccato nel campo nascosto "system_user" all'apertura del compose
    $accountParam = requestParam(['account_id', 'system_user']);
    $accountId = getAuthorizedAccountId($accountParam);
    if ($accountId === null) {
        throw new Exception('Account mittente non valido o non autorizzato. Seleziona un account prima di inviare.');
    }

    $accountManager = new MailAccountManager();
    $account = $accountManager->getAccountWithCredentials($accountId);

    $fromEmail = trim($account['email']);
    $fromName = trim($account['societa'] ?: $account['nome_visualizzato']);
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Configurazione non valida: l'email dell'account '{$fromEmail}' non è valida");
    }

    $smtpConfig = [
        'host'       => $account['smtp_host'],
        'port'       => (int)$account['smtp_port'],
        'encryption' => $account['smtp_encryption'],
        'username'   => $account['smtp_username'],
        'password'   => $account['smtp_password'],
    ];

    // Validazione campi obbligatori
    $to = trim($_POST['to'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $cc = trim($_POST['cc'] ?? '');
    $bcc = trim($_POST['bcc'] ?? '');

    $isHtml = isset($_POST['is_html']) && $_POST['is_html'] === '1';
    $forwardRef = trim($_POST['forward_original_email'] ?? '');

    // Bozza (bozze.js): stesso messaggio, salvato nella cartella Bozze invece
    // di essere spedito; bozza_uid = versione precedente, da sostituire (o da
    // cancellare dopo l'invio). Una bozza può essere incompleta.
    $bozza = ($_POST['bozza'] ?? '') === '1';
    $bozzaUid = max(0, (int)($_POST['bozza_uid'] ?? 0));

    // Uno o più destinatari separati da virgola o punto e virgola (PiGreco
    // Archivio invia così i documenti a più indirizzi); il testo è facoltativo
    $destinatari = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $to))));

    if (!$bozza) {
        if (empty($to) || empty($subject)) {
            throw new Exception('Dati mancanti: compilare Destinatario e Oggetto');
        }
        foreach ($destinatari as $indirizzo) {
            if (!filter_var($indirizzo, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Indirizzo destinatario non valido: {$indirizzo}");
            }
        }
        if (!$destinatari) {
            throw new Exception('Dati mancanti: compilare Destinatario e Oggetto');
        }
    }

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== FUNZIONI DI SUPPORTO ====================

/**
 * Recupera via IMAP gli allegati dell'email originale da inoltrare.
 *
 * @param string     $forwardRef       Formato "account:cartella:uid"
 * @param array|null $indicesToInclude null = tutti, array = solo questi indici 0-based
 * @return array Allegati con ['content', 'filename', 'content_type', 'size']
 */
function getForwardedAttachments($forwardRef, $indicesToInclude = null) {
    // La cartella può contenere ":" ("imap:<nome>"): account e uid sono agli estremi
    $parts = explode(':', (string)$forwardRef);
    if (count($parts) < 3) {
        return [];
    }
    $accountParam = array_shift($parts);
    $uid = (int)array_pop($parts);
    $folder = implode(':', $parts);
    if (!MailboxService::isFolderParam($folder) || $uid <= 0) {
        return [];
    }

    // L'email originale può stare su un altro account: va autorizzato anche quello
    $mailbox = openAuthorizedMailbox($accountParam);
    if ($mailbox === null) {
        return [];
    }
    $raw = $mailbox->getRaw($folder, $uid, false);
    $mailbox->close();
    if ($raw === null) {
        return [];
    }

    $result = parseRawEmail($raw);
    if (empty($result['attachments'])) {
        return [];
    }

    $attachments = [];
    foreach ($result['attachments'] as $index => $att) {
        if ($indicesToInclude !== null && !in_array($index, $indicesToInclude, true)) {
            continue;
        }
        if (!isset($att['content'], $att['filename'])) continue;

        $decodedContent = base64_decode($att['content'], true);
        if ($decodedContent === false) continue;

        $attachments[] = [
            'content' => $decodedContent,
            'filename' => $att['filename'],
            'content_type' => $att['content_type'] ?? 'application/octet-stream',
            'size' => strlen($decodedContent)
        ];
    }

    return $attachments;
}

// ==================== ELABORAZIONE ALLEGATI ====================
$attachments = [];          // Array unificato per TUTTI gli allegati
$forwardedTempFiles = [];   // Traccia file temporanei creati per allegati inoltrati
$maxAttachmentSize = 10 * 1024 * 1024; // 10MB
$seenAttachments = [];      // ✅ DEDUPLICAZIONE: traccia file già processati

try {
    // 1. ALLEGATI MANUALI (upload) - CON DEDUPLICAZIONE
    if (!empty($_FILES['attachments']['name'][0])) {
        $files = $_FILES['attachments'];
        
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            
            if ($files['size'][$i] > $maxAttachmentSize) {
                throw new Exception('File troppo grande: ' . $files['name'][$i] . ' (max 10MB)');
            }
            
            // ✅ DEDUPLICAZIONE: salta file già processati (stesso nome + dimensione)
            $uniqueKey = $files['name'][$i] . '_' . $files['size'][$i];
            if (in_array($uniqueKey, $seenAttachments)) {
                continue;
            }
            $seenAttachments[] = $uniqueKey;
            
            $attachments[] = [
                'tmp_name' => $files['tmp_name'][$i],
                'name' => $files['name'][$i],
                'source' => 'manual'
            ];
            
        }
    }
    
    // 2. ALLEGATI DA EMAIL INOLTRATA
    if (!empty($forwardRef)) {

        // Leggi gli indici selezionati dall'utente nella UI.
        // Se il campo non è presente → include tutto (retrocompatibilità).
        // Se è presente ma vuoto → l'utente ha rimosso tutti gli allegati.
        $forwardIndices = null;
        if (isset($_POST['forward_attachment_indices'])) {
            $raw = trim($_POST['forward_attachment_indices']);
            if ($raw === '') {
                $forwardIndices = []; // Nessun allegato da inoltrare
            } else {
                $forwardIndices = array_map('intval', explode(',', $raw));
            }
        }

        $forwardedAttachments = getForwardedAttachments($forwardRef, $forwardIndices);
        
        if (!empty($forwardedAttachments)) {
            foreach ($forwardedAttachments as $fwdAtt) {
                // ✅ DEDUPLICAZIONE anche per allegati inoltrati
                $uniqueKey = $fwdAtt['filename'] . '_' . $fwdAtt['size'];
                if (in_array($uniqueKey, $seenAttachments)) {
                    continue;
                }
                $seenAttachments[] = $uniqueKey;
                
                // Crea file temporaneo per allegato inoltrato
                $tmpFile = tempnam(sys_get_temp_dir(), 'fwd_');
                if ($tmpFile && file_put_contents($tmpFile, $fwdAtt['content'])) {
                    $attachments[] = [
                        'tmp_name' => $tmpFile,
                        'name' => $fwdAtt['filename'],
                        'source' => 'forwarded'
                    ];
                    
                    $forwardedTempFiles[] = $tmpFile; // Traccia per pulizia
                }
            }
        }
    }
    
} catch (\Throwable $e) {
    // Pulizia file temporanei in caso di errore durante elaborazione allegati
    foreach ($forwardedTempFiles as $tmpFile) {
        if (file_exists($tmpFile)) @unlink($tmpFile);
    }
    
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
// ==================== INVIO EMAIL ====================
$mail = new PHPMailer(true);
$savedToSent = false;

// ✅ CORREZIONE FONDAMENTALE: Imposta UTF-8 per TUTTI i contenuti email
$mail->CharSet = 'UTF-8';          // Charset principale
$mail->Encoding = 'base64';        // Codifica sicura per contenuti non ASCII
$mail->setLanguage('it', '/optional/path/to/language/files/'); // Opzionale: italiano

try {
    // Configurazione SMTP
    $mail->isSMTP();
    $mail->Host = $smtpConfig['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $smtpConfig['username'];
    $mail->Password = $smtpConfig['password'];
    $mail->Port = $smtpConfig['port'];
    $mail->Timeout = 15;
    
    // Configurazione crittografia
    if ($smtpConfig['encryption'] === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($smtpConfig['encryption'] === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
    // Niente scambio chiavi DHE: alcuni gestori (es. smtp.tiscali.it) usano
    // chiavi DH da 1024 bit che OpenSSL 3 rifiuta ("dh key too small"); ECDHE
    // e TLS 1.3 restano disponibili, quindi la sicurezza non diminuisce.
    $mail->SMTPOptions = ['ssl' => ['ciphers' => 'DEFAULT:!kDHE']];
    
    // Mittente e destinatari
    $mail->setFrom($fromEmail, $fromName);
    if ($bozza) {
        // Nella bozza resta quello che è stato scritto, anche se incompleto
        PHPMailer::$validator = static function () { return true; };
    }
    foreach ($destinatari as $indirizzo) {
        $mail->addAddress($indirizzo);
    }
    
    // CC e BCC
    if (!empty($cc)) {
        foreach (preg_split('/[,;]/', $cc) as $email) {
            $email = trim($email);
            if (!empty($email) && ($bozza || filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $mail->addCC($email);
            }
        }
    }
    
    if (!empty($bcc)) {
        foreach (preg_split('/[,;]/', $bcc) as $email) {
            $email = trim($email);
            if (!empty($email) && ($bozza || filter_var($email, FILTER_VALIDATE_EMAIL))) {
                $mail->addBCC($email);
            }
        }
    }
    // PHPMailer vuole almeno un destinatario: alla bozza che non ne ha ancora
    // se ne dà uno segnaposto (dominio riservato .invalid), tolto prima del salvataggio
    $segnaposto = 'senza-destinatari@bozza.invalid';
    if ($bozza && !$mail->getToAddresses() && !$mail->getCcAddresses() && !$mail->getBccAddresses()) {
        $mail->addAddress($segnaposto);
    }
    
    // ✅ CORREZIONE FONDAMENTALE: Imposta l'oggetto dell'email
    $mail->Subject = $subject; // ← AGGIUNGI QUESTA RIGA QUI

    // ✅ CORREZIONE AGGIUNTIVA: Aggiungi meta tag UTF-8 se è HTML
    if ($isHtml) {
        // Aggiungi meta charset se non presente nel body
        if (strpos($body, '<meta charset') === false && 
            strpos($body, '<meta http-equiv="Content-Type"') === false) {
            $body = '<meta charset="UTF-8">' . $body;
        }
        $mail->isHTML(true);
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body); // Versione testo per client non HTML
    } else {
        $mail->isHTML(false);
        $mail->Body = $body;
    }
    // Il testo è facoltativo (per esempio un documento inviato dall'archivio
    // con il solo oggetto): senza questa opzione PHPMailer rifiuta il messaggio
    $mail->AllowEmpty = true;
    
    // Aggiungi allegati
    if (!empty($attachments)) {
        foreach ($attachments as $index => $attachment) {
            if (isset($attachment['tmp_name']) && isset($attachment['name'])) {
                try {
                    $mail->addAttachment($attachment['tmp_name'], $attachment['name']);
                } catch (Exception $e) {
                    // Ignora e continua con gli altri allegati
                }
            }
        }
    }
    
    if ($bozza) {
        // Con "mail" PHPMailer scrive anche l'intestazione Bcc, che nella
        // bozza va conservata; preSend prepara il messaggio senza spedirlo
        $mail->isMail();
        $mail->preSend();
        $mailbox = new MailboxService($account);
        $raw = preg_replace('/^To: ' . preg_quote($segnaposto, '/') . '\r?\n/m', '', $mail->getSentMIMEMessage(), 1);
        $nuovoUid = $mailbox->saveDraft($raw, $bozzaUid);
        $mailbox->close();
        $result = [
            'success' => true,
            'message' => 'Bozza salvata',
            'bozza_uid' => $nuovoUid,
            'attachments_count' => count($attachments),
        ];
    } else {
        // Invia email
        $mail->send();
    
        // Salva una copia nella cartella Sent dell'account (via IMAP). L'email è
        // già partita: un errore qui non deve far credere che l'invio sia fallito.
        $sentError = null;
        try {
            $mailbox = new MailboxService($account);
            $mailbox->saveToSent($mail->getSentMIMEMessage());
            $mailbox->close();
            $savedToSent = true;
        } catch (Throwable $e) {
            $sentError = $e->getMessage();
            error_log('send_email.php: salvataggio in Sent fallito: ' . $sentError);
        }

        // Email partita da una bozza: la bozza non serve più
        if ($bozzaUid > 0) {
            try {
                $mailbox = new MailboxService($account);
                $mailbox->deleteDraft($bozzaUid);
                $mailbox->close();
            } catch (Throwable $e) {
                error_log('send_email.php: bozza ' . $bozzaUid . ' non cancellata: ' . $e->getMessage());
            }
        }
    
        $result = [
            'success' => true,
            'message' => 'Email inviata con successo' . ($savedToSent
                ? ' e salvata nella cartella Sent'
                : ' (ATTENZIONE: copia in Sent non salvata: ' . $sentError . ')'),
            'saved_to_sent' => $savedToSent,
            'attachments_count' => count($attachments)
        ];
    }
    
} catch (\Throwable $e) {
    $result = [
        'success' => false,
        'error' => ($bozza ? 'Bozza non salvata: ' : 'Errore SMTP (' . $smtpConfig['host'] . ':' . $smtpConfig['port'] . '): ')
            . strip_tags($mail->ErrorInfo ?: $e->getMessage()),
    ];
} finally {
    // ✅ PULIZIA GARANTITA DEI FILE TEMPORANEI (SEMPRE ESEGUITA)
    foreach ($forwardedTempFiles as $tmpFile) {
        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
?>