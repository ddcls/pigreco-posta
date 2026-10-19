<?php
/**
 * Importa le vecchie identità SMTP (tabella "identities", modello Maildir)
 * come account email IMAP + SMTP (tabella "mail_accounts").
 *
 * Eseguibile SOLO da riga di comando:
 *   php initializa/import_identities.php            → anteprima, non scrive nulla
 *   php initializa/import_identities.php --esegui   → crea gli account
 *
 * Per ogni identità viene creato un account con:
 *   - IMAP: server/porta/sicurezza predefiniti (IMAP_DEFAULT_* in
 *     config/config.local.php), utente = utente Linux dell'identità,
 *     password VUOTA (il vecchio sistema non la conosceva);
 *   - SMTP: host, porta, sicurezza, utente e password dell'identità
 *     (la password, salvata in base64, viene cifrata con Crypto);
 *   - visibilità "tutti" e stato DISATTIVATO.
 * Poi da Impostazioni → Account Email si inserisce la password IMAP, si
 * prova la connessione e si attiva l'account.
 *
 * Le identità la cui email esiste già in mail_accounts vengono saltate,
 * quindi lo script si può rilanciare senza creare doppioni.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Accesso negato: eseguire questo script da riga di comando.');
}

require_once __DIR__ . '/../config/mail_account_manager.php';

$execute = in_array('--esegui', $argv, true);
$conn = getDBConnection();
if (!$conn) {
    fwrite(STDERR, "Connessione al database non disponibile\n");
    exit(1);
}

$table = $conn->query("SHOW TABLES LIKE 'identities'");
if (!$table || $table->num_rows === 0) {
    echo "Tabella 'identities' non presente: niente da importare.\n";
    exit(0);
}

$defaults = MailAccountManager::defaults();
$existing = [];
$result = $conn->query("SELECT LOWER(email) AS email FROM mail_accounts");
while ($row = $result->fetch_assoc()) {
    $existing[$row['email']] = true;
}

$insert = $conn->prepare(
    "INSERT INTO mail_accounts (nome_visualizzato, email, societa, imap_host, imap_port, imap_security,
        imap_username, imap_password, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password,
        visibilita, attivo)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'tutti',0)"
);

$created = $skipped = 0;
$identities = $conn->query("SELECT * FROM identities ORDER BY utente");
while ($id = $identities->fetch_assoc()) {
    $email = trim($id['email']);
    $label = "{$id['utente']} <{$email}>";

    if (isset($existing[strtolower($email)])) {
        echo "  salto   {$label}: esiste già un account con questa email\n";
        $skipped++;
        continue;
    }

    $smtpPassword = base64_decode($id['password'], true);
    if ($smtpPassword === false) {
        echo "  salto   {$label}: password SMTP non leggibile\n";
        $skipped++;
        continue;
    }

    $nome = trim($id['societa']) !== '' ? trim($id['societa']) : $email;
    $encryption = in_array($id['encryption'], ['tls', 'ssl'], true) ? $id['encryption'] : 'tls';
    $imapPort = (int)$defaults['imap_port'];
    $smtpPort = (int)$id['port'];

    echo ($execute ? "  creo    " : "  creerei ") . "{$label} → IMAP {$id['utente']}@{$defaults['imap_host']}:{$imapPort}, "
        . "SMTP {$id['host']}:{$smtpPort} ({$encryption})\n";

    if ($execute) {
        $imapPasswordEnc = Crypto::encrypt('');
        $smtpPasswordEnc = Crypto::encrypt($smtpPassword);
        $insert->bind_param('ssssissssisss',
            $nome, $email, $id['societa'], $defaults['imap_host'], $imapPort, $defaults['imap_security'],
            $id['utente'], $imapPasswordEnc, $id['host'], $smtpPort, $encryption, $id['username'], $smtpPasswordEnc);
        if (!$insert->execute()) {
            echo "  ERRORE  {$label}: {$insert->error}\n";
            continue;
        }
        $existing[strtolower($email)] = true;
    }
    $created++;
}

echo "\n" . ($execute ? "Creati" : "Da creare") . ": {$created} account, saltati: {$skipped}.\n";
if (!$execute) {
    echo "Anteprima: nessuna modifica. Rilancia con --esegui per creare gli account.\n";
} else {
    echo "Gli account sono DISATTIVATI: inserisci la password IMAP da Impostazioni → Account Email, testa e attiva.\n";
}
