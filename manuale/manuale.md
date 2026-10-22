# PiGreco Posta — Manuale

Versione 1.3 · ottobre 2026

PiGreco Posta fa due cose:

1. **copia sul tuo server le caselle dei gestori** (Libero, Tiscali, Aruba, Gmail, PEC…), con
   tutte le cartelle, e ogni pochi minuti scarica le email nuove;
2. ti fa **leggere e scrivere la posta dal browser** con una webmail, anche sul telefono.

I fornitori di posta danno poco spazio e chiedono di pagare quando la casella è piena. Con
PiGreco Posta lo spazio dipende solo dal disco del tuo server.

## Indice

1. [Come funziona](#1-come-funziona)
2. [Installazione](#2-installazione)
3. [Gestione caselle: copiare le caselle sul server](#3-gestione-caselle-copiare-le-caselle-sul-server)
4. [Collegare le caselle alla webmail](#4-collegare-le-caselle-alla-webmail)
5. [Usare la webmail](#5-usare-la-webmail)
6. [Scrivere, rispondere, inoltrare](#6-scrivere-rispondere-inoltrare)
7. [Filtri sulle email in arrivo](#7-filtri-sulle-email-in-arrivo)
8. [Aspetto: il colore della webmail](#8-aspetto-il-colore-della-webmail)
9. [Amministrare la webmail](#9-amministrare-la-webmail)
10. [Liberare spazio presso il gestore](#10-liberare-spazio-presso-il-gestore)
11. [La posta sul telefono e in Thunderbird](#11-la-posta-sul-telefono-e-in-thunderbird)
12. [Backup e aggiornamenti](#12-backup-e-aggiornamenti)
13. [Collegare PiGreco Archivio](#13-collegare-pigreco-archivio)
14. [Se qualcosa non va](#14-se-qualcosa-non-va)

---

## 1. Come funziona

```
gestore (IMAP) ──copia──▶ disco del server ──▶ webmail, telefoni, Thunderbird
```

- La copia **non cancella mai niente**: né dal gestore né sul server. Scarica le email nuove e
  i cambiamenti di stato (letta, importante).
- L'**invio** resta quello del gestore: per ogni casella la webmail invia con il server SMTP del
  gestore, e una copia finisce nella cartella **Inviata** del server.
- Le cartelle speciali del gestore ("Posta inviata", "Spam", "Cestino"…), comunque si chiamino,
  finiscono in **Inviata**, **Indesiderata**, **Cestino** e **Bozze**.
- Spostare o cancellare email dalla webmail cambia **solo la copia sul server**: presso il
  gestore le email restano dove sono.

PiGreco Posta è fatto di cinque programmi che girano in Docker sullo stesso server:

| Servizio | A cosa serve | Indirizzo |
|---|---|---|
| `webmail` | La webmail | `http://<server>:8080/webmail/` |
| `db` | Il database della webmail (utenti, account, filtri) | solo interno |
| `posta` | Il server IMAP (Dovecot) che serve la copia locale | porte 143 e 993 |
| `posta-sincronizza` | Copia dal gestore le email nuove, ogni 5 minuti | — |
| `posta-gestione` | La pagina **Gestione caselle** | `http://<server>:8083` |

I due login sono **separati**:

- la pagina **Gestione caselle** ha un solo amministratore, quello che copia le caselle;
- la **webmail** ha i suoi utenti (amministratori e operatori), che l'amministratore della
  webmail crea dalle Impostazioni.

---

## 2. Installazione

### 2.1 Cosa serve

- Un server Linux (va bene anche un PC sempre acceso) con **Docker** e il plugin **compose**:
  il comando `docker compose version` deve rispondere. Istruzioni:
  <https://docs.docker.com/engine/install/>. Su un server **Debian** appena installato c'è la guida
  passo passo [`INSTALLA-DEBIAN.md`](../INSTALLA-DEBIAN.md), da Debian vuoto alla posta funzionante.
- **Spazio su disco** per la posta: almeno quanto occupano oggi le caselle dai gestori, meglio il
  doppio.
- Porte libere sul server: **8080** (webmail), **8083** (gestione caselle), **143** e **993**
  (IMAP per telefoni e programmi di posta). Se sono già occupate si cambiano (paragrafo 2.3).

### 2.2 Installare

```bash
git clone https://github.com/ddcls/pigreco-posta.git
cd pigreco-posta
./installa.sh
```

Lo script:

1. crea il file **`.env`** con password e chiave di cifratura casuali;
2. scarica e costruisce i programmi e li avvia (la prima volta ci vogliono alcuni minuti);
3. chiede la password dell'utente **admin** della webmail e quella della pagina **Gestione
   caselle**. Le password devono avere almeno 8 caratteri.

Alla fine mostra gli indirizzi della webmail e della pagina di gestione.

> **ATTENZIONE** Il file `.env` contiene la **chiave che cifra le password delle caselle**.
> Conservane una copia in un posto sicuro: senza, dopo un ripristino da backup le password
> salvate nella webmail non sono più leggibili e vanno reinserite.

Rilanciare `./installa.sh` è sicuro: non cancella né sovrascrive niente. Tiene una copia di `.env` in `/srv/posta/config/pigreco-posta.env` (leggibile solo da root): se la cartella del programma viene cancellata e rimessa da capo, la ritrova e riprende password e chiave di prima.

### 2.3 Cambiare le porte e le cartelle

Le impostazioni sono nel file `.env`, nella cartella di PiGreco Posta:

| Voce | Valore predefinito | Cosa indica |
|---|---|---|
| `WEBMAIL_PORT` | 8080 | Porta della webmail |
| `WEBMAIL_ASCOLTO` | 0.0.0.0 | `0.0.0.0` = webmail aperta a tutta la rete; `127.0.0.1` = porta chiusa alla rete (con PiGreco Archivio, paragrafo 13) |
| `POSTA_GESTIONE_PORT` | 8083 | Porta della pagina Gestione caselle |
| `POSTA_IMAP_PORT` / `POSTA_IMAPS_PORT` | 143 / 993 | Porte IMAP per telefoni e programmi di posta |
| `POSTA_DIR` | `/srv/posta` | Dove stanno le caselle copiate (una cartella per indirizzo, con la sua `Maildir`) |
| `WEBMAIL_DB_DIR` | `/srv/posta/mysql` | Dove sta il database della webmail |
| `POSTA_INTERVALLO` | 300 | Secondi tra una copia e la successiva |
| `BACKUP_PASSWORD` | (casuale) | Password che cifra le copie di backup sui dischi USB (capitolo 12): la crea `installa.sh`, è sul foglio di ripristino |

Se `installa.sh` dice che una porta è già usata (per esempio perché sul server c'è già un altro
server di posta), cambia il valore in `.env`, ad esempio `POSTA_IMAP_PORT=1143`, e rilancia
`./installa.sh`. Dopo l'installazione, ogni cambiamento di `.env` si applica con:

```bash
docker compose up -d
```

### 2.4 Le password, se le dimentichi

```bash
./scripts/password-webmail.sh admin      # utente della webmail (anche altri utenti: ./scripts/password-webmail.sh mrossi)
./scripts/password-gestione.sh admin     # pagina Gestione caselle
```

---

## 3. Gestione caselle: copiare le caselle sul server

Apri dal browser `http://<server>:8083` (per esempio `http://192.168.1.10:8083`) e accedi con
l'utente e la password impostati durante l'installazione. L'elenco mostra per ogni casella le
email copiate, lo spazio occupato e l'esito dell'ultima copia.

### 3.1 Aggiungere una casella

1. Premi **＋ Aggiungi casella**.
2. Scrivi l'**indirizzo email**: per i gestori più diffusi server e porta si compilano da soli.
3. Scrivi **utente** e **password del gestore** (quelli con cui oggi leggi la posta).
4. Se vuoi, in **Cartelle da non copiare** indica le cartelle del gestore da non copiare.
5. Lascia **Giorni da tenere sul gestore** a **0** (capitolo 10).
6. Premi **Prova l'accesso al gestore**: compaiono le cartelle della casella e dove finirà
   ognuna nella copia.
7. Premi **Salva**. Se l'accesso al gestore non riesce la casella non viene salvata, a meno di
   spuntare "salva anche se la prova non riesce".
8. La pagina mostra **una sola volta** la **password locale** della casella: **annotala**,
   serve alla webmail (capitolo 4) e ai telefoni (capitolo 11). Se lasci vuoto il campo, la
   password locale viene generata dal programma.

La prima copia parte subito. Per caselle grandi può richiedere un'ora o più: l'elenco mostra il
numero di email copiate che cresce.

### 3.2 Le altre azioni

- Un clic sull'**indirizzo** della casella apre la sua pagina: cambi password del gestore,
  cartelle da non copiare, giorni da tenere sul gestore.
- **Sospendi la sincronizzazione**: la copia resta leggibile ma non si aggiorna.
- **Rimuovi la casella**: la casella non è più leggibile; le email già copiate restano sul disco.
- **⟳ Sincronizza ora**: copia subito le email nuove, senza aspettare il giro successivo.

### 3.3 Caselle che non si possono aggiungere

PiGreco Posta entra nella casella del gestore con **utente e password**. Alcuni gestori non lo
permettono più e accettano solo **OAuth2**: un accesso in cui si dà il consenso su una pagina del
gestore e il programma riceve un "permesso" al posto della password. PiGreco Posta non usa
OAuth2, quindi queste caselle non si possono copiare, anche con utente e password giusti:

- caselle personali **Microsoft**: @hotmail, @outlook, @live (.it, .com…) e @msn.com;
- caselle aziendali su **Microsoft 365** (server `outlook.office365.com`), anche con il dominio
  della ditta.

Se scrivi uno di questi indirizzi o server, si apre la finestra **Caselle che non si possono
aggiungere** con la spiegazione, e la casella non viene salvata.

- **Gmail**, **Yahoo** e **iCloud** si possono aggiungere, ma al posto della password normale
  vogliono una **password per le app**, da creare nelle impostazioni di sicurezza dell'account.
- Libero, Virgilio, Tiscali, Aruba (anche PEC), Legalmail e in genere i gestori italiani si
  aggiungono normalmente.
- Per una casella Microsoft l'alternativa è **inoltrare** la posta in arrivo a una casella che si
  può aggiungere. Si copierà però solo la posta che arriva da quel momento.

### 3.4 Le cartelle

- Tutte le cartelle del gestore vengono copiate, con le sottocartelle (tranne quelle escluse).
- Una cartella del gestore con un punto nel nome viene copiata con `_` al posto del punto.
- Una cartella del gestore che si chiama come una speciale ma non lo è (per esempio una `Sent`
  vuota) viene copiata come `<nome>_gestore`.
- Le cartelle create dalla webmail esistono **solo sulla copia sul server**: la copia dal gestore
  le ignora. Le cartelle che **vengono dal gestore** non si rinominano né si eliminano dalla
  webmail (il menu **⋯** lo dice), perché andrebbero ricopiate da capo. Dentro di loro però puoi
  creare sottocartelle tue.

---

## 4. Collegare le caselle alla webmail

1. Apri la webmail, `http://<server>:8080/webmail/`, ed entra come **admin**.
2. Menu utente (cerchio con l'iniziale, in alto a destra) → **Impostazioni** → **Account
   Email** → **Nuovo account**.
3. Compila:
   - **Ricezione (IMAP)**: server **`posta`**, porta **143**, sicurezza **STARTTLS**, utente =
     l'**indirizzo email**, password = la **password locale** del capitolo 3;
   - **Invio (SMTP)**: server, porta, utente e password **del gestore** (per esempio
     `smtp.libero.it`, porta 465 SSL);
   - **Visibilità**: **tutti** gli utenti della webmail, oppure solo quelli **selezionati**.
4. Premi **Testa connessione**: prova sia la ricezione sia l'invio.
5. Premi **Salva**.

> **NOTA** La webmail può leggere anche una casella **direttamente dal gestore**, senza copia
> locale: in quel caso indica come server IMAP quello del gestore. Le funzioni sono le stesse,
> ma spazio e cartelle sono quelli del gestore.

---

## 5. Usare la webmail

Apri `http://<server>:8080/webmail/`, scrivi **Username** e **Password** della webmail e premi
**Accedi**.

La schermata è divisa in tre parti, come nei programmi di posta più diffusi.

**In alto** — la barra con:

| Elemento | A cosa serve |
|---|---|
| **Cerca nella posta…** | Cerca tra le email (vedi sotto); il pulsante a destra apre le **opzioni di ricerca** |
| **🔔** con un numero | Le email **non lette** di tutte le caselle |
| **⚙** | Impostazioni (amministratore della webmail) oppure **Aspetto** |
| **Cerchio con l'iniziale** | Menu utente: **Impostazioni**, **Aspetto**, **Filtri**, **Informazioni** (versione, autore, licenza e codice sorgente), **Esci** |

**A sinistra** — **✏ Nuova email**, il menu **Account** con la casella da consultare e le
cartelle **Posta in arrivo**, **Inviata**, **Bozze**, **Indesiderata** e **Cestino**. Sotto
compaiono le **altre cartelle** della casella, con le sottocartelle spostate un po' a destra.

**Al centro** — l'elenco delle email della cartella, con il numero totale in alto. Le email non
lette sono in **grassetto**; 📎 indica gli allegati. In fondo ci sono le frecce **‹ ›** per
cambiare pagina e il numero di email per pagina.

**A destra** — l'email aperta: in alto i pulsanti, poi oggetto, mittente, destinatari, data,
testo e allegati.

### 5.1 Leggere la posta

1. Scegli la casella nel menu **Account** (vedi solo le caselle che l'amministratore ti ha
   assegnato).
2. Scegli la cartella e fai clic su un'email: si apre a destra e diventa "letta".
3. Il testo si vede come è stato scritto (**HTML**); con **Testo** lo vedi in forma semplice. I
   collegamenti nel testo si aprono in una nuova scheda.
4. Gli allegati sono in fondo: 👁 mostra l'anteprima di immagini e PDF, ⬇ scarica il file,
   **Scarica tutti (.zip)** li scarica insieme.

I pulsanti sopra l'email:

| Pulsante | Cosa fa |
|---|---|
| **Rispondi** / **Rispondi a tutti** | Risponde al mittente (o anche a tutti i destinatari) |
| **Inoltra** | Inoltra l'email ad altri, con i suoi allegati |
| ✉ | Segna l'email come **non letta** |
| ⬇ | Salva l'email sul computer: **OK** = file `.eml` completo, **Annulla** = solo testo `.txt` |
| 🖨 | Stampa l'email |
| ☆ / ⭐ | Segna l'email come **importante** o la toglie dalle importanti |
| 📂 | Sposta l'email in un'altra cartella |
| Imbuto | Crea un **filtro** per questo mittente (capitolo 7) |
| ✏ **Modifica bozza** | Solo nella cartella **Bozze**: riapre la bozza |
| 🗑 | Sposta l'email nel **Cestino**. Nel Cestino il pulsante diventa **Ripristina** |

### 5.2 Più email insieme

1. Spunta la casella a sinistra delle email che ti interessano. Con **Maiusc** premuto, la spunta
   sceglie anche tutte quelle in mezzo dall'ultima spuntata; con **Ctrl** premuto basta un clic
   sulla riga.
2. Sopra l'elenco compare quante ne hai scelte e i pulsanti:

| Pulsante | Cosa fa |
|---|---|
| 📂 **Sposta** | Sposta le email scelte in un'altra cartella |
| 🗑 **Cestino** | Le sposta nel Cestino (chiede conferma) |
| ☆ / ⭐ | Le segna come **importanti** o le toglie dalle importanti |
| ✉ aperta / ✉ chiusa | Le segna come **lette** / **non lette** |
| ✕ | Toglie la selezione (anche il tasto **Esc**) |

La casella accanto al numero sceglie in un colpo **tutte le email della pagina**.

Nel **Cestino** i pulsanti sono **Ripristina** (le riporta nella Posta in arrivo) ed
**Elimina**, che le **cancella per sempre**, dopo una conferma.

Sul computer puoi anche **trascinare** un'email (o quelle spuntate) su una cartella a sinistra.

### 5.3 Le tue cartelle

- **Nuova cartella** (sotto l'elenco delle cartelle): scrivi il nome e scegli **Dentro a**
  (**Primo livello** oppure un'altra cartella) → **Crea**.
- Il pulsante **⋯** accanto a una cartella apre il menu: **Nuova sottocartella**, **Rinomina**,
  **Elimina**.
- Una cartella si **elimina** solo se è **vuota** e non contiene altre cartelle.
- Il nome non può contenere i caratteri / . \ % * ". Posta in arrivo, Inviata, Bozze,
  Indesiderata e Cestino non si rinominano né si eliminano.

### 5.4 Email importanti

A destra dell'oggetto di ogni email c'è una stella: un clic la riempie ⭐ e l'email diventa
**importante**, un altro clic la toglie. La stella è salvata nella casella: la vedono anche il
telefono e gli altri programmi di posta.

### 5.5 Cercare

1. Scrivi nella barra **Cerca nella posta…** e premi **Invio**: si apre **Ricerca Email**.
2. In **Dove cercare** scegli **Nella cartella aperta** oppure **In tutte le cartelle**.
3. Spunta dove cercare la parola: **Mittente e destinatari**, **Oggetto**, **Testo
   dell'email**. Con **Solo email importanti** vedi solo quelle con la stella.
4. Premi **Cerca**.

La ricerca guarda **tutte** le email della cartella (o di tutte le cartelle), non solo quelle
della pagina; maiuscole e minuscole non contano. Cercare nel testo è più lento. **✕ Annulla** in
alto (o un clic su una cartella) torna all'elenco normale.

### 5.6 Le email non lette di tutte le caselle

Premi **🔔**: si apre l'elenco delle email non lette di tutte le caselle che puoi vedere. **Apri**
la mostra, **Cancella** la sposta nel Cestino.

### 5.7 Sul telefono

Sul telefono si vede una parte alla volta: l'elenco, poi l'email aperta (con **←** torni
all'elenco). Le cartelle e la casella si scelgono con **☰** in alto a sinistra, oppure toccando
la barra con la casella e la cartella in cima all'elenco; il pulsante tondo **＋** in basso a
destra scrive una nuova email.

---

## 6. Scrivere, rispondere, inoltrare

1. Premi **✏ Nuova email** (oppure **Rispondi**, **Rispondi a tutti**, **Inoltra** su un'email
   aperta). Serve aver scelto prima la casella nel menu **Account**.
2. La riga **Da** mostra la casella da cui parte l'email.
3. Compila **A**, se serve **Cc** (copia) e **Ccn** (copia nascosta) e **Oggetto**. Più
   indirizzi si separano con la virgola.
4. Scrivi il testo. Con **Formato** scegli **HTML** (grassetto, elenchi, link, immagini) oppure
   **Testo** semplice.
5. **Allega file** aggiunge allegati; la **×** accanto a un allegato lo toglie. Inoltrando, gli
   allegati dell'email originale sono già inclusi.
6. Premi **Invia**. Una copia viene salvata nella cartella **Inviata**.

Con **–** in alto la finestra si riduce in basso a destra, per consultare altre email mentre
scrivi.

**Le bozze**

- **Salva bozza** salva l'email, con i suoi allegati, nella cartella **Bozze**. Mentre scrivi,
  la webmail la salva anche da sola ogni mezzo minuto, se hai cambiato qualcosa.
- Se chiudi la finestra con modifiche non salvate, la webmail chiede se salvarle nelle Bozze.
- Per riprendere una bozza: cartella **Bozze** → apri la bozza → **✏ Modifica bozza**.
- Resta sempre una sola copia della bozza; dopo l'invio la bozza viene cancellata.

---

## 7. Filtri sulle email in arrivo

I filtri smistano da soli le email nuove della **Posta in arrivo**, per esempio: "se il mittente
contiene `rossi@esempio.it`, sposta l'email nella cartella Fornitori / Rossi e segnala come
letta".

1. Menu utente → **Filtri** → **Nuovo filtro**. Oppure, leggendo un'email, il pulsante con
   l'**imbuto**: il filtro è già compilato con il mittente.
2. **Condizioni**: su **mittente**, **destinatari**, **oggetto**, **testo** o **ovunque**, con
   "contiene" o "non contiene". Scegli se devono valere **tutte** o **almeno una**.
3. **Azioni**:
   - **sposta** in una cartella (scrivi «Fornitori / Rossi» per una sottocartella: se manca
     viene creata), nell'**Indesiderata** o nel **Cestino**;
   - **segna come letta**, **segna come importante**;
   - **non applicare gli altri filtri**.
4. **Casella**: tutte le caselle, oppure una sola.
5. Premi **Salva**.
6. Per smistare anche la posta che c'è già, nell'elenco dei filtri premi ▶ (**Applica anche
   alle email già nella Posta in arrivo**) accanto al filtro.

- I filtri vengono applicati **ogni minuto**, nell'ordine dell'elenco: il primo spostamento
  vince.
- Nessun filtro **cancella definitivamente**: al massimo sposta nel Cestino.
- I filtri valgono per tutti gli utenti della webmail e ognuno può modificarli.
- Sulle caselle copiate in locale il filtro cambia solo la copia sul server.

---

## 8. Aspetto: il colore della webmail

1. Menu utente → **Aspetto**.
2. Fai clic su un colore: **Marrone**, **Blu**, **Arancione**, **Verde**, **Bordeaux** o
   **Grafite**. La webmail cambia subito colore, come anteprima.
3. Premi **Salva** per tenerlo, **Annulla** per tornare al colore di prima.

Il colore vale per il **tuo utente**, su qualsiasi computer. L'amministratore della webmail può
spuntare **Usa il colore scelto sopra come predefinito**: diventa il colore della pagina di
accesso e di chi non ha scelto un colore.

---

## 9. Amministrare la webmail

L'amministratore della webmail gestisce caselle e utenti dal menu utente → **Impostazioni**:

- **Account Email**: crea le caselle (capitolo 4). Le password sono salvate cifrate.
- **Utenti webmail**: crea gli utenti che entrano nella webmail (ruolo **Amministratore** o
  **Operatore**), ne cambia la password, li disattiva. Gli operatori vedono solo le caselle
  visibili a tutti o assegnate a loro.
- **Log Accessi**: elenco degli accessi alla webmail, riusciti e falliti.

---

## 10. Liberare spazio presso il gestore

Nella pagina di una casella (Gestione caselle → clic sull'indirizzo), il campo **Giorni da tenere sul
gestore** decide quanta posta resta presso il gestore. Il valore iniziale è **0**: **dal gestore
non viene cancellato niente**, e l'elenco mostra "sul gestore: tutte (non si cancella)".

Con un numero di giorni (almeno 7, per esempio 30), dopo ogni copia riuscita le email arrivate
da più giorni vengono **cancellate dal gestore**, ma **solo quelle di cui il programma ha
verificato la copia sul server**. Sul server resta tutto.

- Prima di salvare, **Prova l'accesso al gestore** mostra la stima: quante email verrebbero
  cancellate, cartella per cartella. La stima non cancella niente.
- Quando il nuovo valore fa cancellare **di più** (da 0 a un numero, oppure da 60 a 30 giorni)
  compare la casella **Ho capito: …**: va spuntata, altrimenti la casella non viene salvata.
- Per **fermare** la cancellazione basta rimettere **0**.

La stessa stima da terminale, senza cancellare niente:

```bash
docker compose exec -u 5000 posta-sincronizza genera-config pulizia-prova [indirizzo] [giorni]
```

> **ATTENZIONE** Con questa impostazione attiva, la copia sul server diventa l'**unica** copia
> delle email più vecchie: i **backup** (capitolo 12) sono indispensabili. Telefoni e programmi
> collegati **direttamente al gestore**, e il sito web del gestore, mostrano solo le email degli
> ultimi giorni: per vedere tutta la posta usa la webmail o collega il telefono alla copia sul
> server. Su Gmail cancellare da una cartella toglie solo l'etichetta: lo spazio non si libera.

---

## 11. La posta sul telefono e in Thunderbird

Telefoni e programmi di posta possono leggere la **copia sul server**, con tutte le email:

| Voce | Valore |
|---|---|
| Server di ricezione (IMAP) | l'indirizzo del server, per esempio `192.168.1.10` |
| Porta e sicurezza | **993** SSL/TLS (oppure 143 STARTTLS) |
| Utente | l'**indirizzo email** |
| Password | la **password locale** della casella |
| Server di invio (SMTP) | quello **del gestore**, con utente e password del gestore |

Il telefono può segnalare il certificato come "non attendibile": è quello di prova creato
all'installazione. Per leggere la posta **fuori dall'ufficio** servono un indirizzo raggiungibile
da Internet e un certificato vero (`/srv/posta/config/ssl/cert.pem` e `key.pem`): chiedi al
tecnico.

---

## 12. Backup e aggiornamenti

Le copie di backup della posta si fanno da sole su **dischi USB**, dalla pagina **Gestione caselle ›
💾 Copie di backup**.

### 12.1 Come funzionano le copie

- Ogni copia contiene **tutto**: le caselle copiate (`/srv/posta`), il database della webmail
  (utenti, account, filtri) e il file `.env` con la chiave che cifra le password delle caselle.
- Le copie sono **cifrate** con la password delle copie (`BACKUP_PASSWORD` in `.env`): senza
  quella non si aprono, da nessuno. La password è sul **foglio di ripristino** (paragrafo 12.6).
- Sul disco si aggiungono solo le **novità**: la prima copia è lunga, le altre durano pochi minuti.
  Ogni copia però è completa.
- Il disco si usa **solo durante la copia**: per il resto del tempo lo puoi staccare.
- Gli **stessi dischi** possono tenere anche le copie di **PiGreco Archivio**: ognuno ha la sua
  cartella (`pigreco-posta/`, `pigreco-archivio/`) e la sua password.

> Consiglio: **due dischi** (per esempio BK1 e BK2), uno collegato al server e l'altro **a casa**,
> da scambiare una volta alla settimana. Così una copia è sempre fuori dall'ufficio.

### 12.2 Preparare o usare un disco

Collega il disco al server: dopo qualche secondo compare in **Dischi USB collegati**.

- Disco **già preparato** (da PiGreco Posta o da PiGreco Archivio, partizione `PIGRECO-<NOME>`):
  premi **✅ Usa questo disco**. Non si cancella niente: le copie della posta vanno nella loro
  cartella.
- Disco **nuovo**: **🧹 Prepara questo disco…**, scrivi il nome (da 1 a 8 lettere o cifre, es.
  **BK1**) e la parola **FORMATTA**. **Tutto quello che c'è sul disco viene cancellato.**

Il programma non formatta mai un disco già preparato, né uno che contiene copie di PiGreco (le
cerca dentro il disco prima di formattare), né un disco aperto sul desktop o in uso: in quel caso
spiega perché. **Non usarlo più** smette di usare un disco, ma le copie già fatte restano sopra.

### 12.3 Quando si fanno le copie

Nella sezione **Impostazioni › Quando** scegli giorni e ora (di serie dal lunedì al venerdì alle
**23:00**, un'ora dopo PiGreco Archivio). Se a quell'ora il server era spento o il disco staccato,
la copia parte appena possibile, una volta sola. Se colleghi un disco la cui ultima copia ha più di
un giorno, la copia parte subito: porti il disco da casa, lo colleghi e si aggiorna da solo.
**💾 Fai la copia adesso** la fa subito.

Mentre lavora la pagina mostra "Copia in corso… **non staccare il disco**"; alla fine "Puoi staccare
il disco".

### 12.4 Quante copie tenere, controlli e avvisi

- **Quante copie tenere**: le ultime 7 giornaliere, 4 settimanali e 12 mensili (si cambiano); le più
  vecchie si cancellano da sole.
- **Verifica**: ogni 30 giorni il programma rilegge una parte delle copie del disco collegato, per
  accorgersi di un disco che si rovina (anche subito con **🔍 Verifica**).
- **Avvisi**: in cima alle pagine di Gestione caselle compare una striscia, e parte un'email
  all'amministratore (al massimo una al giorno per tipo), quando l'ultima copia è più vecchia dei
  giorni scelti (3), non è riuscita, un disco è quasi pieno, la verifica ha trovato errori, o con
  due dischi lo stesso è collegato da più di 7 giorni ("scambialo con quello a casa").

### 12.5 Email dell'amministratore

Gli avvisi partono con un server di posta in uscita apposta, in **Gestione caselle › ⚙️
Impostazioni**: server SMTP, porta, sicurezza, utente, password, mittente e destinatari. Puoi
usare la casella del titolare. **✉️ Salva e manda un'email di prova** controlla subito che funzioni.
Senza SMTP gli avvisi restano solo nella striscia.

### 12.6 Foglio di ripristino

In fondo a **Copie di backup** scrivi la password della pagina e premi **🖨️ Apri il foglio di
ripristino**: c'è la **password delle copie**, l'elenco dei dischi e i passi per rimettere tutto su
un server nuovo. **Stampalo**, chiudilo in una busta con il disco che tieni a casa e chiudi la
scheda. Chi ha il foglio e un disco può leggere tutta la posta; senza il foglio, se il server si
rompe, nessuno può aprire le copie.

### 12.7 Rimettere tutta la posta su un server nuovo

1. Installa PiGreco Posta come la prima volta (capitolo 2).
2. Collega il disco di backup più recente.
3. Dalla cartella del programma: `./scripts/ripristina-backup.sh`. Scrivi la password delle copie
   (dal foglio; gli spazi non contano), scegli la copia e conferma scrivendo **RIPRISTINA**.
4. Caselle, utenti, account e filtri tornano come al momento della copia; il programma riparte da
   solo. Controlla webmail e Gestione caselle e stampa di nuovo il foglio di ripristino.

I dettagli per il tecnico sono nella guida [`INSTALLA-DEBIAN.md`](../INSTALLA-DEBIAN.md), paragrafo 8.

### 12.8 Una copia a mano in un file

Resta anche il vecchio comando, che salva tutto in un file `.tar.gz` (per esempio prima di un
intervento sul server):

```bash
sudo ./scripts/backup.sh /percorso/backup
```

### 12.9 Aggiornare

```bash
git pull
docker compose up -d --build
```

Gli aggiornamenti del database vengono applicati da soli all'avvio.

---

## 13. Collegare PiGreco Archivio

**PiGreco Archivio** è l'archivio documentale degli stessi autori (ufficio 3D, faldoni, OCR,
modulo per studi legali), di prossima uscita. Installati sullo stesso server, i due programmi
**si collegano da soli**, in qualsiasi ordine: non si reinstalla niente e caselle, email, utenti
e filtri restano come sono.

- **La posta c'è già**: si installa l'archivio, che la trova e si collega.
- **L'archivio c'è già**: si installa la posta con questo manuale; l'archivio la trova subito,
  senza essere riavviato.
- **Tutti e due da zero**: l'installatore dell'archivio chiede se installare anche la posta e la
  installa per prima.

Dall'archivio la webmail si apre con il pulsante **📧 Posta**, all'indirizzo
`http://<ip-server>:8081/webmail/`. Aperta da lì, nella webmail compaiono:

- il pulsante **Archivia**: l'email e i suoi allegati entrano nell'archivio con un clic, nel
  faldone che scegli;
- la **rubrica** dei contatti in comune con l'archivio, con i suggerimenti mentre scrivi;
- dall'archivio, l'invio dei documenti **dalla tua casella**, con la copia in Inviata.

Gli utenti e le caselle della webmail restano quelli di sempre, diversi dagli utenti
dell'archivio; per Archivia e Contatti serve anche l'accesso all'archivio, che la webmail chiede
la prima volta.

Sulla sua porta (`http://<ip-server>:8080/webmail/`) la webmail resta quella di sempre, senza i
pulsanti dell'archivio. Installando l'archivio viene proposto di **chiudere alla rete la porta
8080**: la webmail resta raggiungibile solo dall'archivio (riga `WEBMAIL_ASCOLTO=127.0.0.1` in
`.env`). Per riaprirla: `WEBMAIL_ASCOLTO=0.0.0.0` e `docker compose up -d webmail`.

I passi dell'installazione sono nella guida di PiGreco Archivio (`INSTALLA-DEBIAN.md`).

---

## 14. Se qualcosa non va

| Problema | Cosa fare |
|---|---|
| `installa.sh` dice che una porta è già usata | Cambia la porta in `.env` (paragrafo 2.3) e rilancialo |
| La pagina della webmail non si apre | `docker compose ps`: tutti i servizi devono essere "Up". Poi `docker compose logs webmail` |
| La pagina Gestione caselle dice "Password non impostata" | `./scripts/password-gestione.sh admin` |
| Una casella non si aggiorna | Nell'elenco di Gestione caselle guarda l'esito dell'ultima copia; poi `docker compose logs posta-sincronizza` |
| Mancano email rispetto al gestore | `./scripts/confronta.sh indirizzo@esempio.it` mostra, cartella per cartella, quante email ci sono sul gestore e nella copia |
| "Testa connessione" non riesce sull'IMAP | Server `posta`, porta 143, STARTTLS, utente = indirizzo email, password = password **locale** (non quella del gestore) |
| "Testa connessione" non riesce sull'SMTP | Controlla con il gestore server, porta e sicurezza dell'invio |
| Dopo un ripristino le caselle chiedono di nuovo la password | La chiave in `.env` non è quella di prima: rimetti il `.env` del backup |
| Striscia "Il servizio delle copie di backup non risponde" | `docker compose up -d posta-backup`, poi `docker compose logs posta-backup` |
| "L'ultima copia di backup della posta riuscita è di … giorni fa" | Collega un disco di backup: la copia parte da sola (paragrafo 12.3) |
| Un disco USB "non si può usare" | È aperto sul desktop o in uso (smontalo e riprova), oppure contiene volumi speciali (LVM, cifrati) |
| "Il disco è in uso" preparando un disco | Smontalo dal desktop (**Smonta**), aspetta qualche secondo e riprova |
| "Sul disco ci sono copie di PiGreco … non si formatta" | È un disco di backup: ricollegalo, deve comparire come già preparato (**Usa questo disco**) |
| L'email di prova degli avvisi non parte | Controlla server, porta e sicurezza (465 SSL, 587 STARTTLS) e utente e password della casella |
