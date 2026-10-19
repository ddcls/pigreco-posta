# PiGreco Posta

**Tieni una copia di tutte le tue caselle sul tuo server e leggile con una webmail moderna: si installa con un solo comando.**

*[Read in English](README.md)*

PiGreco Posta è un pacchetto di posta da installare in proprio, pensato per uffici, studi professionali e per chi ha sempre la casella piena:

```
gestore (Gmail, Libero, Aruba, PEC…) ──IMAP──▶ copia sul tuo disco ──IMAP──▶ webmail, telefoni, Thunderbird
                                     mbsync                    Dovecot
```

- **Copia locale delle caselle dei gestori.** Ogni pochi minuti email nuove e flag vengono copiati dal gestore (IMAP, `mbsync`) in una Maildir sul tuo server, servita da Dovecot. La copia **non cancella mai niente**. Lo spazio dipende dal tuo disco, non dalla quota del gestore.
- **Liberare spazio presso il gestore, in sicurezza (facoltativo).** Per ogni casella puoi tenere sul gestore solo gli ultimi N giorni. Un'email viene cancellata dal gestore **solo se la sua copia locale è verificata**. Il valore predefinito è 0: non si cancella mai niente.
- **Webmail.** Più caselle e più utenti. Tre riquadri, funziona anche sul telefono. Ricerca, cartelle, trascinamento, selezione multipla, bozze con salvataggio automatico, ⭐ importanti, **filtri** sul server, temi di colore. L'invio passa dall'SMTP di ogni gestore.
- **Pagina "Gestione caselle"** per aggiungere le caselle, provare l'accesso al gestore, vedere lo stato della sincronizzazione e impostare la pulizia.
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

`installa.sh` crea `.env` con password e chiave di cifratura casuali, costruisce e avvia i container, poi chiede le password dell'utente `admin` della webmail e della pagina di gestione. Rilanciarlo è sicuro.

Poi:

1. **Gestione caselle**, `http://<server>:8083`: aggiungi le caselle (indirizzo, accesso al gestore). La pagina mostra **una sola volta** la **password locale** di ogni casella.
2. **Webmail**, `http://<server>:8080/webmail/`: entra come `admin` e vai su *Impostazioni › Account Email*. Per ogni casella imposta server IMAP `posta`, porta 143, STARTTLS, utente = indirizzo email, password = password locale. Per l'SMTP usa il server del gestore.

La guida completa è in [`manuale/manuale.md`](manuale/manuale.md).

**Caselle Microsoft** (hotmail/outlook/live/Microsoft 365): accettano solo OAuth2 e non si possono copiare. Gmail, Yahoo e iCloud vogliono una *password per le app*.

## Comandi utili

```bash
docker compose ps                         # stato dei servizi
docker compose logs -f posta-sincronizza  # registro della sincronizzazione
./scripts/password-webmail.sh [utente]    # password di un utente della webmail
./scripts/password-gestione.sh [utente]   # accesso alla pagina di gestione
./scripts/confronta.sh [indirizzo]        # email per cartella: gestore e copia locale
git pull && docker compose up -d --build  # aggiornamento
```

I dati sono in `dati/` (MySQL e caselle). **Fanne il backup** insieme a `.env`: in `.env` c'è la chiave che cifra le password salvate.

## Sicurezza

- Prima di esporre la webmail su Internet mettila dietro HTTPS, con un reverse proxy come Caddy, nginx o Traefik. La pagina di gestione (porta 8083) va lasciata solo nella rete dell'ufficio.
- Le password IMAP/SMTP sono salvate cifrate (AES-256-GCM, chiave in `.env`). Il testo HTML delle email è mostrato in un iframe isolato, senza script.
- Il certificato di Dovecot creato al primo avvio è autofirmato. Sostituisci `dati/posta/config/ssl/cert.pem` e `key.pem` con uno vero prima di usare la posta dai telefoni fuori dall'ufficio.
- Hai trovato una vulnerabilità? Vedi [SECURITY.md](SECURITY.md): segnalala in privato, non in una issue pubblica.

## PiGreco Archivio

PiGreco Posta è fatta dagli autori di **PiGreco Archivio**, l'archivio documentale per uffici e studi legali (ufficio 3D, faldoni, OCR, modulo legale). Se installi PiGreco Archivio sullo stesso server, si collega alla webmail che hai già, senza reinstallare e senza perdere dati. Ottieni il pulsante **Archivia** (l'email e i suoi allegati in archivio con un clic), la rubrica in comune e l'invio dei documenti dalla tua casella.

## Licenza

[GNU Affero General Public License v3.0](LICENSE). Puoi usarla, studiarla, modificarla e condividerla. Se offri una versione modificata come servizio in rete, devi pubblicare le tue modifiche con la stessa licenza.

Componenti di terzi e relative licenze: [THIRD-PARTY.md](THIRD-PARTY.md).
