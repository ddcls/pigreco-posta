# PiGreco Posta — la webmail

Webmail multi-account scritta in PHP, parte di **PiGreco Posta** (nata come `readmail5`). Ha i propri utenti e il proprio database. Ogni account email legge la posta via **IMAP** (di norma dalla copia locale delle caselle servita da Dovecot, servizio `posta`) e la invia con il server SMTP del proprio gestore. Più caselle possono essere consultate e gestite da uno o più operatori tramite un'unica interfaccia web.

**Installazione consigliata: Docker**, dalla cartella principale del progetto con `./installa.sh` (vedi `README.md` e `manuale/manuale.md`). Qui sotto c'è anche l'installazione a mano su un server Apache + PHP già esistente.

## Cosa fa

- Interfaccia a tre riquadri, come i client di posta (Outlook, Thunderbird): cartelle e account a sinistra, elenco al centro, lettura a destra; su tablet le cartelle diventano icone e sul telefono si vede un riquadro alla volta.
- **Aspetto**: ogni utente sceglie il colore dell'interfaccia (marrone come PiGreco Archivio, blu, arancione, verde, bordeaux, grafite); l'amministratore sceglie anche quello predefinito, usato nella pagina di accesso. I colori sono in `temi.css` (`lib/tema.php`, `tema.js`, `salva_tema.php`).
- Legge Posta in arrivo, Inviata, Bozze, Indesiderata e Cestino via IMAP (cartelle individuate con SPECIAL-USE), con paginazione e ricerca.
- Visualizza il dettaglio delle email (corpo HTML/testo, allegati) tramite uno script Python (`email_parser.py`) che effettua il parsing MIME.
- Il corpo HTML delle email viene mostrato in un `<iframe>` sandboxato, isolato dal resto della pagina: un'email non può eseguire script né alterare il comportamento dell'interfaccia (vedi nota di sicurezza più sotto).
- Segna come letta/non letta, sposta nel cestino, ripristina (via IMAP: lo stato è condiviso con qualsiasi altro client collegato a Dovecot).
- Compone, risponde, inoltra e invia email via SMTP (PHPMailer) con il server dell'account scelto, con supporto allegati; la copia inviata viene salvata nella cartella Sent via IMAP.
- Un login autenticato sceglie da un menu a tendina quale account email consultare, tra quelli che può vedere: non è un modello "1 login = 1 casella".
- Pannello di amministrazione (solo per utenti con ruolo `admin`):
  - **Account Email**: crea gli account (IMAP Dovecot + SMTP), con test della connessione, e decide se sono visibili a tutti gli utenti della webmail o solo ad alcuni.
  - **Utenti webmail**: gestisce gli utenti che accedono alla webapp (creazione, ruolo admin/operatore, attivazione).
  - **Log Accessi**: storico di login riusciti, tentativi falliti e logout.

## Requisiti

- PHP 8.x con estensioni `mysqli`, `zip`, `mbstring`.
- MySQL o MariaDB.
- Python 3 (nessuna libreria esterna, solo standard library) raggiungibile come `python3` dal server web.
- Apache con `mod_php` (o equivalente) e supporto a `proc_open`/`exec` non disabilitato in `php.ini` (servono per lanciare `email_parser.py`).
- Un server Dovecot raggiungibile in IMAP (porta 143 con STARTTLS) con gli utenti Linux delle caselle.

## Installazione a mano (senza Docker)

### 1. Prendi il codice

```bash
git clone https://github.com/ddcls/pigreco-posta.git
cd pigreco-posta/webmail
```

### 2. Crea il database

Crea un database dedicato e un utente MySQL con privilegi **limitati** a quel database (mai usare `root` nell'app):

```sql
CREATE DATABASE webmail_digitale CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'webmail_user'@'localhost' IDENTIFIED BY 'una-password-lunga-e-casuale';
GRANT SELECT, INSERT, UPDATE, DELETE ON webmail_digitale.* TO 'webmail_user'@'localhost';
FLUSH PRIVILEGES;
```

Importa lo schema:

```bash
mysql -u root -p webmail_digitale < schema.sql
```

### 3. Configura le credenziali (`config/config.local.php`)

Copia il modello e compila i valori (password del DB, chiave di cifratura, IP del server Dovecot):

```bash
cp config/config.example.php config/config.local.php
php -r 'echo base64_encode(random_bytes(32)), "\n";'   # valore per MAIL_ENCRYPTION_KEY
```

La configurazione è un file **PHP** che restituisce un array, non un `.env`: se qualcuno lo richiede dal browser il server lo esegue e non restituisce nulla. Per questo l'app è autonoma dentro la webroot e non dipende da `.htaccess`, da `AllowOverride` o da percorsi esterni legati a un utente. `config.local.php` non è versionato.

**Non cambiare `MAIL_ENCRYPTION_KEY`** dopo aver salvato degli account email: le password salvate non sarebbero più leggibili.

Un file `.env` dentro la cartella dell'app **non viene letto** (sarebbe scaricabile dal browser). Se preferisci comunque un `.env`, mettilo fuori dalla webroot e indicane il percorso in `config/env_path.local.php` (non versionato):

```php
<?php
define('WEBMAIL_ENV_PATH', '/percorso/assoluto/fuori/dalla/webroot/.env');
```

### 4. Pubblica nella webroot

Dalla cartella di lavoro:

```bash
sudo ./deploy.sh                      # → /var/www/html/webmail, file di root:www-data
./deploy.sh /var/www/html/posta       # destinazione a scelta (senza sudo i file restano tuoi)
```

`deploy.sh` copia solo i file dell'applicazione: **non** pubblica `.git`, documentazione, note, migrazioni SQL e file di sviluppo, e li rimuove dalla destinazione se ci sono (es. dopo una copia manuale della cartella). Non sovrascrive mai la configurazione già presente nella destinazione: `config/config.local.php` viene copiato solo alla prima pubblicazione.

Evita di copiare a mano l'intera cartella nella webroot: insieme all'app finirebbe online anche `.git` (codice e cronologia scaricabili). Il file `.htaccess` incluso blocca file nascosti e non-PHP, ma solo dove Apache ha `AllowOverride` attivo (su Debian/Ubuntu/Mint di default è disattivato per `/var/www`).

### 5. Crea il primo utente amministratore

Non esiste un endpoint pubblico per creare il primo account (di proposito): la gestione utenti richiede già di essere autenticati come admin. Genera l'hash della password e inseriscilo a mano:

```bash
php -r "echo password_hash('la-tua-password', PASSWORD_DEFAULT) . PHP_EOL;"
```

```sql
INSERT INTO users (nome, cognome, username, password, email, attivo, tipo_user)
VALUES ('Nome', 'Cognome', 'admin', '<hash-generato-sopra>', 'tuamail@esempio.it', 1, 'admin');
```

### 6. Primo accesso

Vai su `https://tuo-dominio/webmail/login.php`, accedi con l'account appena creato. Da **Impostazioni → Account Email** crea gli account: ognuno legge la posta da Dovecot (IMAP sulla porta 143 con STARTTLS, con utente e password Linux) e la invia con il proprio server SMTP; puoi renderlo visibile a tutti gli utenti della webmail o solo ad alcuni. Il pulsante **Testa connessione** verifica login IMAP e SMTP prima di salvare.

L'amministratore vede tutti gli account attivi; gli altri utenti solo quelli visibili a tutti o assegnati a loro.

## Docker e collegamento con PiGreco Archivio

Nel `docker-compose.yml` del progetto la webmail è il servizio `webmail` (immagine in `docker/webmail/`): il codice di questa cartella è montato in sola lettura e la configurazione arriva dalle variabili d'ambiente del container, a partire da `.env` (`WEBMAIL_CONFIG_FILE` indica un file vuoto al posto di `config/config.local.php`). Database, utente MySQL, tabelle, migrazioni e utente `admin` sono creati all'avvio da `docker/webmail/entrypoint.sh`, che lancia anche i filtri ogni minuto.

Con **PiGreco Archivio** sullo stesso server, l'Apache dell'archivio inoltra `/webmail/` a questo container (rete Docker `pigreco`) e `ARCHIVIO_URL` vale `/`: compaiono il pulsante **Archivio** in alto, **Archivia** sul dettaglio dell'email (`archivio.js`, `archivio.css`) e la rubrica **Contatti** (`contatti.js`). "Archivia" invia l'`.eml` originale all'API dell'archivio, raggiunta su `/api/` dalla stessa origine, con la sessione e i permessi dell'utente **dell'archivio**: se non è collegato, la finestra chiede il suo login.

Con `ARCHIVIO_URL` vuota (webmail da sola) questi pulsanti non compaiono. Il cookie di sessione si chiama `WEBMAIL_SID` (`lib/session.php`), per non sovrascrivere la sessione di altre applicazioni PHP sullo stesso server.

## Filtri sulle email in arrivo

Dal menu utente › **Filtri** (o dal pulsante con l'imbuto mentre si legge un'email) si creano regole come "se il mittente contiene rossi@esempio.it, sposta l'email nella cartella Fornitori / Rossi e segnala come letta". Condizioni su mittente, destinatari, oggetto, testo o ovunque ("contiene" / "non contiene", tutte o almeno una); azioni: sposta in una cartella (creata se manca), nell'Indesiderata o nel Cestino, segna come letta, segna come importante, "non applicare gli altri filtri". Nessun filtro cancella definitivamente.

I filtri valgono per tutte le caselle (o per quella scelta), chiunque li abbia creati, e li applica `filtri_esegui.php` alle email nuove della Posta in arrivo, nell'ordine dell'elenco (`lib/FiltriService.php`, tabelle `filtri` e `filtri_stato` di `migrations/005_filtri.sql`). Nel container docker lo lancia l'entrypoint ogni minuto; installata da sola, aggiungi la migrazione e una riga di cron per l'utente del server web:

```bash
mysql -u root -p webmail_digitale < migrations/005_filtri.sql
# crontab -e -u www-data
* * * * * php /var/www/html/webmail/filtri_esegui.php >> /var/log/webmail-filtri.log 2>&1
```

## Aggiornare il database

Da una versione precedente della webmail (già con gli account IMAP), per la scelta del colore:

```bash
mysql -u root -p webmail_digitale < migrations/003_tema.sql
```

Nel container docker questa migrazione è applicata da sola all'avvio. Finché non è applicata la webmail funziona lo stesso, sempre con il colore di base.

## Aggiornare un database della versione Maildir

Le versioni precedenti leggevano direttamente le cartelle Maildir e avevano una tabella `identities` (un'identità SMTP per utente Linux). Per aggiornare:

```bash
# 1. nuove tabelle degli account email
mysql -u root -p webmail_digitale < migrations/001_mail_accounts.sql

# 2. importa le identità come account email (prima in anteprima, poi davvero)
php initializa/import_identities.php
php initializa/import_identities.php --esegui

# 3. rimuove tabella identities e colonna users.system_user
mysql -u root -p webmail_digitale < migrations/002_remove_maildir.sql
```

Gli account importati hanno già IMAP (utente Linux dell'identità, server `IMAP_DEFAULT_HOST`) e SMTP compilati, ma sono **disattivati** e senza password IMAP, che il vecchio sistema non conosceva: da **Impostazioni → Account Email** inserisci la password, premi **Testa connessione** e attiva l'account.

La migrazione 002 va applicata insieme al codice aggiornato: senza di essa la creazione di nuovi utenti webmail fallisce (la colonna `system_user` è obbligatoria nello schema vecchio).

## Note di sicurezza

- Le password IMAP e SMTP degli account email (tabella `mail_accounts`) sono cifrate con AES-256-GCM (`lib/Crypto.php`) con la chiave `MAIL_ENCRYPTION_KEY`, che sta in `config/config.local.php` e non nel database: chi legge solo il database non le recupera.
- La connessione a Dovecot usa STARTTLS. Con `IMAP_TLS_VERIFY = 0` il certificato non viene verificato (necessario con certificati autofirmati): il traffico è cifrato ma non protetto da un attacco man-in-the-middle sulla rete; con un certificato valido imposta `1`.
- Gli allegati si aprono in anteprima nel browser solo se sono PDF, immagini (non SVG) o testo: tutti gli altri tipi, HTML compreso, vengono scaricati, così non possono eseguire codice con i permessi della webmail.
- `initializa/setup_db.php`, `initializa/reset_admin_password.php` e `initializa/import_identities.php` sono eseguibili solo da riga di comando (bloccati via HTTP): non renderli raggiungibili reintroducendo l'accesso web.
- La visibilità degli account è controllata lato server su ogni richiesta (non basta cambiare l'id nella richiesta per leggere un account non assegnato). Gli account con visibilità "tutti" sono condivisi tra tutti gli utenti della webmail.
