# PiGreco Posta: copia locale delle caselle

Copia locale delle caselle dei gestori (Libero, Tiscali, Aruba, …) su un disco
del server, servita via IMAP: la webmail e i telefoni leggono da qui, lo
spazio è limitato solo dal disco e non dalla quota del gestore.

```
gestore (IMAP) ──mbsync──▶ /srv/posta/<indirizzo>/Maildir ──Dovecot──▶ webmail, telefoni
```

L'invio resta sull'SMTP del gestore. La sincronizzazione scarica email nuove
e flag e **non cancella mai** niente, né sul gestore né in locale
(`Sync PullNew PullFlags`, `Expunge None`).

## Servizi (`docker-compose.yml`, una sola immagine)

| Servizio | Cosa fa |
|---|---|
| `posta` | Dovecot: IMAP STARTTLS su `POSTA_IMAP_PORT` (143), IMAPS su `POSTA_IMAPS_PORT` (993). Utenti = indirizzi delle caselle. Dagli altri container: host `posta`, porta 143 |
| `posta-sincronizza` | mbsync per ogni casella ogni `POSTA_INTERVALLO` secondi (stato in `config/stato/`) |
| `posta-gestione` | pagina web "Gestione caselle" su `POSTA_GESTIONE_PORT` (8083), con login proprio |

Le porte predefinite sono quelle standard: su un server nuovo sono libere, e
**sull'host non va installato Dovecot** (occuperebbe 143/993).

## Installazione

Questi servizi sono definiti nel `docker-compose.yml` principale di PiGreco
Posta, insieme alla webmail: si installano con `./installa.sh` dalla cartella
del progetto (vedi il README principale). La cartella dei dati (`POSTA_DIR`,
di norma `/srv/posta`) viene assegnata a 5000:5000 dal container, senza
sudo. Il login della pagina di gestione:

```bash
./scripts/password-gestione.sh admin
```

Poi, da `http://<server>:8083`:

- **＋ Aggiungi casella**: indirizzo (server e porta dei gestori noti si
  compilano da soli), utente e password del gestore, cartelle da escludere.
  **Prova l'accesso al gestore** mostra le cartelle e dove finirà ognuna;
  **Salva** prova comunque l'accesso e, se non riesce, non salva (a meno di
  spuntare "salva anche se la prova non riesce"). La password locale, se
  lasciata vuota, viene generata e mostrata **una sola volta**.
- La casella è subito accessibile (Dovecot rilegge da solo il file utenti) e la
  prima copia parte entro pochi secondi; l'elenco mostra email copiate, spazio
  ed esito dell'ultima sincronizzazione.
- **Sospendi** (la copia resta leggibile ma non si aggiorna), **Rimuovi** (la
  casella non è più accessibile; le email copiate restano sul disco),
  **Sincronizza ora**.

Stessa cosa a mano: blocchi in `config/account.conf` della cartella dei dati (modello in
`account.conf.example`), letti a ogni giro di sincronizzazione.

```bash
docker compose run --rm posta-sincronizza una-volta   # una sincronizzazione con i dettagli
./scripts/confronta.sh [indirizzo]                    # email per cartella: gestore e copia locale
```

## Pulizia del gestore (`tieni_giorni`)

Per liberare la quota del gestore, ogni casella può tenere sul gestore solo
le email degli ultimi N giorni. Si imposta nella pagina di gestione (campo
**Giorni da tenere sul gestore** della casella) oppure in `account.conf`:

```
tieni_giorni = 0     ; 0 = non cancellare mai dal gestore (valore predefinito)
tieni_giorni = 30    ; dopo ogni sincronizzazione riuscita cancella dal gestore
                     ; le email arrivate da più di 30 giorni (minimo 7)
```

Dalla pagina, quando il nuovo valore fa cancellare di più (da 0 a N, o da un
numero a uno più piccolo) serve spuntare una conferma; **Prova l'accesso al
gestore** mostra, cartella per cartella, quante email verrebbero cancellate.
La configurazione di mbsync non cambia: la copia locale non perde niente,
perché mbsync non propaga le cancellazioni del gestore.

Una email viene cancellata dal gestore **solo se la sua copia locale è
confermata**: lo stato di mbsync della cartella locale (`.mbsyncstate`) ha la
stessa numerazione (UIDVALIDITY) della cartella del gestore, associa l'email a
una copia locale, e il file di quella copia esiste. Le altre restano sul
gestore. La cancellazione è immediata e riguarda solo quelle email
(`UID EXPUNGE`). Un gestore senza UIDPLUS permette solo `EXPUNGE`, che elimina
anche le email già segnate da cancellare da altri programmi: lì una cartella
viene pulita solo se anche quelle hanno la copia locale. Le cartelle escluse
non vengono toccate.

La stessa anteprima da riga di comando (non cancella niente, apre il gestore
in sola lettura):

```bash
docker compose exec -u 5000 posta-sincronizza genera-config pulizia-prova [indirizzo] [giorni]
```

Con la pulizia attiva la copia locale diventa l'unica per le email più
vecchie: **i backup della cartella dei dati sono obbligatori**. Telefoni o programmi
collegati direttamente al gestore vedranno solo gli ultimi N giorni. Su Gmail
cancellare da una cartella toglie solo l'etichetta: lo spazio non si libera.

## Cartelle

- Tutte le cartelle del gestore vengono copiate, con le sottocartelle
  (`escludi = …` in `account.conf` per saltarne alcune).
- Nella copia locale il punto separa le sottocartelle: una cartella del
  gestore con un punto nel nome (es. `INBOX.Sent` su Tiscali, che separa con
  `/`) viene copiata con `_` al posto del punto.
- Le cartelle speciali del gestore (riconosciute dal flag SPECIAL-USE o dal
  nome: "Posta inviata", "Spam", "Cestino", …) finiscono in `Sent`, `Junk`,
  `Trash`, `Drafts` della copia locale. Una cartella del gestore che si chiama
  come una speciale ma non lo è (es. `Sent` vuota accanto a `outbox` \Sent su
  Libero) viene copiata in `<nome>_gestore`.

## Dati (`POSTA_DIR`, nel container `/srv/posta`)

```
/srv/posta/
├── config/
│   ├── account.conf           caselle e password dei gestori (pagina di gestione o a mano)
│   ├── gestione.passwd        login della pagina di gestione
│   ├── generati/              utenti Dovecot, mbsyncrc, password dei gestori (rigenerati)
│   ├── stato/                 esito dell'ultima sincronizzazione (e pulizia) di ogni casella
│   └── ssl/                   certificato (autofirmato al primo avvio)
└── <indirizzo>/Maildir/       una casella per indirizzo
```

Il certificato autofirmato va sostituito con uno vero (`cert.pem`, `key.pem`)
prima di usare la posta dai telefoni fuori dall'ufficio.
