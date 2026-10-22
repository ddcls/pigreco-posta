# PiGreco Posta

**Tieni una copia di tutte le tue caselle sul tuo server e leggile con una webmail moderna: si installa con un solo comando.**

*[Read in English](README.md) · Sito: [ddcls.github.io/pigreco-posta](https://ddcls.github.io/pigreco-posta/)*

PiGreco Posta è un pacchetto di posta da installare sul proprio server, pensato per uffici, studi professionali e per chi ha sempre la casella piena:

```
gestore (Gmail, Libero, Aruba, PEC…) ──IMAP──▶ copia sul tuo disco ──IMAP──▶ webmail, telefoni, Thunderbird
                                      mbsync                        Dovecot
```

- **Copia locale delle caselle dei gestori.** A intervalli di pochi minuti le email nuove e i flag (letta, importante…) vengono copiati dal gestore (IMAP, `mbsync`) in una Maildir sul tuo server, servita da Dovecot. La copia **non cancella mai niente**. Lo spazio dipende dal tuo disco, non dalla quota del gestore.
- **Liberare spazio presso il gestore, in sicurezza (facoltativo).** Per ogni casella puoi tenere sul gestore solo gli ultimi N giorni. Un'email viene cancellata dal gestore **solo se la sua copia locale è verificata**. Il valore predefinito è 0: non si cancella mai niente.
- **Webmail.** Più caselle e più utenti. Vista a tre riquadri, che funziona anche sul telefono. Ricerca, cartelle, trascinamento delle email nelle cartelle, selezione multipla, bozze con salvataggio automatico, ⭐ importanti, **filtri** sul server, temi di colore. L'invio passa dall'SMTP di ogni gestore.
- **Pagina "Gestione caselle"** per aggiungere le caselle, provare l'accesso al gestore, vedere lo stato della sincronizzazione e impostare la pulizia.
- **Copie di backup su dischi USB**, automatiche e cifrate (restic), con avvisi per email, foglio di ripristino e ripristino completo con un comando. Gli stessi dischi possono tenere anche le copie di PiGreco Archivio.
- **Tutto in Docker**: webmail (PHP 8.3 + Apache), MySQL 8, Dovecot, mbsync, pagina di gestione.

## Come si presenta

![Webmail: cartelle, elenco delle email e lettura](docs/screenshot/webmail.png)

<p align="center">
  <img src="docs/screenshot/telefono-elenco.png" alt="Webmail sul telefono: elenco" width="260">
  &nbsp;&nbsp;
  <img src="docs/screenshot/telefono-lettura.png" alt="Webmail sul telefono: lettura di un'email" width="260">
</p>

![Pagina Gestione caselle: copie locali e ultima sincronizzazione](docs/screenshot/gestione-caselle.png)

*Nomi, indirizzi e messaggi delle immagini sono inventati.*

## Requisiti

- Un server Linux (o un PC sempre acceso) con **Docker** e il plugin **compose**.
- Porte libere: 8080 (webmail), 8083 (pagina di gestione), 143/993 (IMAP per telefoni e programmi di posta). Si cambiano tutte in `.env`.

## Installazione

```bash
git clone https://github.com/ddcls/pigreco-posta.git
cd pigreco-posta
./installa.sh
```

`installa.sh` crea `.env` con password e chiave di cifratura casuali, costruisce e avvia i container, poi chiede le password dell'utente `admin` della webmail e della pagina di gestione. Rilanciarlo è sicuro. Tiene una copia di `.env` in `/srv/posta/config/` (leggibile solo da root): reinstallando il programma da capo, per esempio da un nuovo ZIP, ritrova dati e password.

Poi:

1. **Gestione caselle**, `http://<server>:8083`: aggiungi le caselle (indirizzo, accesso al gestore). La pagina mostra **una sola volta** la **password locale** di ogni casella.
2. **Webmail**, `http://<server>:8080/webmail/`: entra come `admin` e vai su *Impostazioni › Account Email*. Per ogni casella imposta server IMAP `posta`, porta 143, STARTTLS, utente = indirizzo email, password = password locale. Per l'SMTP usa il server del gestore.

Su un server **Debian** appena installato (sudo, IP fisso, Docker, installazione, backup) segui la guida passo passo [`INSTALLA-DEBIAN.md`](INSTALLA-DEBIAN.md). L'uso della posta è nel manuale, [`manuale/manuale.md`](manuale/manuale.md) (anche in PDF da stampare: [`manuale/manuale.pdf`](manuale/manuale.pdf)).

**Caselle Microsoft** (hotmail/outlook/live/Microsoft 365): accettano solo OAuth2 e non si possono copiare. Gmail, Yahoo e iCloud richiedono una *password per le app*.

## Comandi utili

```bash
docker compose ps                         # stato dei servizi
docker compose logs -f posta-sincronizza  # registro della sincronizzazione
./scripts/password-webmail.sh [utente]    # password di un utente della webmail
./scripts/password-gestione.sh [utente]   # accesso alla pagina di gestione
./scripts/ripristina-backup.sh            # rimette tutta la posta da un disco di backup
sudo ./scripts/backup.sh <cartella>       # backup di caselle, database e .env in un file
./scripts/confronta.sh [indirizzo]        # email per cartella: gestore e copia locale
git pull && docker compose up -d --build  # aggiornamento
```

Caselle e database sono in `/srv/posta`. Le copie di backup si fanno da sole su dischi USB (Gestione caselle › Copie di backup, manuale capitolo 12); `sudo ./scripts/backup.sh <cartella>` ne fa anche una a mano in un solo file, insieme a `.env`. Tieni copie e foglio di ripristino al sicuro: in `.env` c'è la chiave che cifra le password salvate.

## Sicurezza

- Prima di esporre la webmail su Internet mettila dietro HTTPS, con un reverse proxy come Caddy, nginx o Traefik. La pagina di gestione (porta 8083) deve restare raggiungibile solo dalla rete dell'ufficio.
- Le password IMAP/SMTP sono salvate cifrate (AES-256-GCM, chiave in `.env`). Il contenuto HTML delle email viene mostrato in un iframe isolato, senza script.
- Il certificato di Dovecot creato al primo avvio è autofirmato. Sostituisci `/srv/posta/config/ssl/cert.pem` e `key.pem` con uno vero prima di usare la posta dai telefoni fuori dall'ufficio.
- Hai trovato una vulnerabilità? Vedi [SECURITY.md](SECURITY.md): segnalala in privato, non in una issue pubblica.

## PiGreco Archivio

PiGreco Posta è fatta dagli autori di **PiGreco Archivio**, l'archivio documentale per uffici e studi legali (ufficio 3D, faldoni, OCR, modulo legale), di prossima uscita. Insieme alla webmail aggiunge il pulsante **Archivia** (l'email e i suoi allegati in archivio con un clic), la rubrica in comune e l'invio dei documenti archiviati dalla tua casella. Installati sullo stesso server, i due programmi si collegano da soli, in qualsiasi ordine: non si reinstalla niente e caselle, email, utenti e filtri restano come sono. La webmail aperta dall'archivio (`http://<server>:8081/webmail/`) mostra questi pulsanti; sulla sua porta (8080) resta la webmail da sola, e l'installatore dell'archivio propone di chiudere quella porta alla rete.

## Licenza

[GNU Affero General Public License v3.0](LICENSE). Puoi usarla, studiarla, modificarla e condividerla. Se offri una versione modificata come servizio in rete, devi pubblicare le tue modifiche con la stessa licenza.

Componenti di terzi e relative licenze: [THIRD-PARTY.md](THIRD-PARTY.md).
