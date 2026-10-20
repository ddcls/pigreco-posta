# Installare PiGreco Posta su un server Debian

Questa guida porta da un PC con **Debian appena installato** a PiGreco Posta funzionante. Vale per
Debian 12 (bookworm) e 13 (trixie) a 64 bit, con o senza interfaccia grafica.

I comandi si scrivono nel terminale del server (o da un altro PC via SSH). Dove compare `<...>`
va messo il valore vero, senza `< >`.

1. [Cosa serve](#1-cosa-serve)
2. [Preparare Debian](#2-preparare-debian)
3. [Installare Docker](#3-installare-docker)
4. [Scaricare PiGreco Posta](#4-scaricare-pigreco-posta)
5. [Facoltativo: i dati su un disco a parte](#5-facoltativo-i-dati-su-un-disco-a-parte)
6. [Installare](#6-installare)
7. [Primi passi e controlli](#7-primi-passi-e-controlli)
8. [Riavvii, backup, aggiornamenti](#8-riavvii-backup-aggiornamenti)
9. [Problemi frequenti](#9-problemi-frequenti)

Per usare la posta dopo l'installazione: [manuale/manuale.md](manuale/manuale.md) (anche in PDF: [manuale/manuale.pdf](manuale/manuale.pdf)).

---

## 1. Cosa serve

- Debian 12 o 13, 64 bit, con un **utente normale** (quello creato installando Debian).
- **Accesso a Internet** durante l'installazione: si scaricano i pacchetti di Debian e le immagini
  Docker (circa 1 GB).
- **RAM**: 2 GB bastano, 4 GB consigliati.
- **Disco**: circa 3 GB per i programmi, più lo spazio della posta: almeno quanto occupano oggi le
  caselle presso i gestori, meglio il doppio.
- **Porte libere**: 8080 (webmail), 8083 (gestione caselle), 143 e 993 (IMAP per telefoni e
  Thunderbird). **Non installare Dovecot** o altri server di posta su Debian: occuperebbero 143 e 993.
- Un **indirizzo IP fisso** per il server (paragrafo 2.4): webmail, telefoni e PC lo usano per
  raggiungerlo.
- Per ogni casella da copiare: indirizzo, **password del gestore** (Gmail, Yahoo e iCloud vogliono una
  *password per le app*) e dati SMTP. Le caselle Microsoft (hotmail, outlook, live, Microsoft 365)
  **non si possono copiare**: accettano solo OAuth2.

---

## 2. Preparare Debian

### 2.1 Abilitare sudo

Se installando Debian hai dato una password a **root**, il tuo utente non può usare `sudo` (errore
*"is not in the sudoers file"* o *"sudo: command not found"*). Una volta sola:

```bash
su -                          # chiede la password di ROOT
apt-get update
apt-get install -y sudo
usermod -aG sudo <utente>     # es. usermod -aG sudo mario
exit
```

Poi **esci e rientra** (logout/login, o chiudi e riapri SSH) e prova:

```bash
sudo whoami                   # deve rispondere: root
```

Se la password di root era stata lasciata vuota, sudo è già attivo e questo passo non serve.

### 2.2 Aggiornare e installare i pacchetti di base

```bash
sudo apt-get update
sudo apt-get -y upgrade
sudo apt-get install -y ca-certificates curl gnupg unzip openssl git
```

### 2.3 Fuso orario

```bash
sudo timedatectl set-timezone Europe/Rome
timedatectl                   # controlla data e ora
```

### 2.4 Indirizzo IP fisso

Guarda l'indirizzo attuale, il nome della scheda di rete e il router:

```bash
ip -4 addr                    # es. "inet 192.168.1.10/24" sulla scheda enp1s0
ip route | grep default       # il router, es. 192.168.1.1
```

**Modo consigliato**: sul router, *prenotazione DHCP* (o "IP statico per indirizzo MAC") per il server.
Su Debian non si tocca niente.

In alternativa, su Debian:

**a) Con interfaccia grafica** (NetworkManager):

```bash
nmcli connection show                                  # nome della connessione
sudo nmcli connection modify "<nome connessione>" \
    ipv4.method manual \
    ipv4.addresses 192.168.1.10/24 \
    ipv4.gateway 192.168.1.1 \
    ipv4.dns "192.168.1.1 1.1.1.1"
sudo nmcli connection up "<nome connessione>"
```

**b) Senza interfaccia grafica**: `sudo nano /etc/network/interfaces` e sostituisci la riga
`iface <scheda> inet dhcp` con:

```
iface enp1s0 inet static
    address 192.168.1.10/24
    gateway 192.168.1.1
    dns-nameservers 192.168.1.1 1.1.1.1
```

(`enp1s0` = il nome visto con `ip -4 addr`), poi `sudo systemctl restart networking`. Se eri
collegato via SSH la connessione cade: ricollegati al nuovo indirizzo.

Controlla con `ip -4 addr` e `ping -c 3 deb.debian.org`.

### 2.5 Firewall

Debian non attiva un firewall da solo: di solito non c'è niente da fare. Se usi **ufw**
(`sudo ufw status` risponde *Status: active*), apri le porte solo alla rete dell'ufficio:

```bash
sudo ufw allow from 192.168.1.0/24 to any port 8080,8083,143,993 proto tcp
```

La pagina **Gestione caselle (8083) non va mai aperta su Internet**.

---

## 3. Installare Docker

Docker si installa dal suo repository ufficiale per Debian:

```bash
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg | \
    sudo gpg --dearmor --yes -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
    sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"
```

**Esci e rientra** (l'appartenenza al gruppo `docker` vale dalla sessione successiva), poi:

```bash
docker compose version          # risponde con la versione
docker run --rm hello-world     # deve stampare "Hello from Docker!"
```

---

## 4. Scaricare PiGreco Posta

Il programma va in `/opt/pigreco-posta`, di proprietà del tuo utente:

```bash
sudo mkdir -p /opt/pigreco-posta
sudo chown "$USER":"$USER" /opt/pigreco-posta
```

**Se il repository GitHub è pubblico**:

```bash
git clone https://github.com/ddcls/pigreco-posta.git /opt/pigreco-posta
```

**Senza git, o con il server senza Internet**, usa lo ZIP della versione:

1. dalla pagina [Releases](https://github.com/ddcls/pigreco-posta/releases) scarica
   `pigreco-posta-<versione>.zip` (per esempio `pigreco-posta-1.2.zip`);
2. portalo sul server con una pennetta, oppure dalla rete dell'ufficio:
   `scp pigreco-posta-1.2.zip <utente>@<ip-del-server>:~`
3. sul server:

```bash
cd ~
unzip pigreco-posta-1.2.zip
cp -a pigreco-posta-1.2/. /opt/pigreco-posta/
rm -r pigreco-posta-1.2
chmod +x /opt/pigreco-posta/installa.sh /opt/pigreco-posta/scripts/*.sh
```

Con una pennetta su Debian **senza interfaccia grafica**, montala prima:
`lsblk` (trova la partizione, es. `sdb1`), `sudo mkdir -p /mnt/usb`,
`sudo mount /dev/sdb1 /mnt/usb`, e copia lo zip da `/mnt/usb`. Alla fine `sudo umount /mnt/usb`.

---

## 5. Facoltativo: i dati su un disco a parte

Tutti i dati stanno in **`/srv/posta`**, come in PiGreco Archivio:

```
/srv/posta/
├── config/                       caselle, password locali, certificato, stato della copia
├── <indirizzo>/Maildir/          una cartella per ogni casella copiata
└── mysql/                        database della webmail (utenti, account, filtri)
```

**Non creare `/srv/posta`**: la prepara il programma al primo avvio. Se il server ha un **disco
dedicato ai dati** (consigliato quando la posta è tanta), montalo in `/srv/posta` (o in `/srv`)
**prima di installare**.

### 5.1 Spostare i dati dopo l'installazione

Le prime versioni mettevano posta e database in `/opt/pigreco-posta/dati/`. Si spostano senza
riscaricare niente: dentro i container le cartelle non cambiano.

```bash
cd /opt/pigreco-posta
grep -E '^(POSTA_DIR|WEBMAIL_DB_DIR)' .env  # le cartelle attuali
sudo ls -A /srv/posta                       # se c'è già la posta (spostata prima), deve mancare solo mysql
docker compose down                         # ferma tutto (i dati restano)
sudo cp -a dati/posta /srv/posta            # SOLO se la posta non è ancora in /srv/posta
sudo cp -a dati/mysql /srv/posta/mysql
sed -i 's|^POSTA_DIR=.*|POSTA_DIR=/srv/posta|; s|^WEBMAIL_DB_DIR=.*|WEBMAIL_DB_DIR=/srv/posta/mysql|' .env
docker compose up -d
docker compose ps                           # tutti Up, db "healthy"
```

Controlla che entrare nella webmail funzioni, che account e filtri ci siano e che le email si
leggano; nella Gestione caselle che la sincronizzazione riesca. Solo dopo cancella la vecchia
copia: `sudo rm -r dati`.

---

## 6. Installare

```bash
cd /opt/pigreco-posta
./installa.sh
```

Lo script:

1. crea `.env` (se non c'è) con **password e chiave di cifratura casuali**. Se in `/srv/posta` ci sono
   già i dati di un'installazione precedente, riprende invece il `.env` di allora, di cui tiene una
   copia in `/srv/posta/config/pigreco-posta.env` (vedi "Reinstallare dallo ZIP", paragrafo 8);
2. controlla che le porte siano libere;
3. scarica e costruisce i programmi e li avvia. **La prima volta ci vogliono alcuni minuti**;
4. chiede la password dell'utente **admin** della webmail e quella della pagina **Gestione caselle**
   (almeno 8 caratteri ciascuna).

Alla fine mostra i due indirizzi, per esempio:

```
Webmail:          http://192.168.1.10:8080/webmail/
Gestione caselle: http://192.168.1.10:8083/
```

> **SUBITO: copia `.env` in un posto sicuro** (pennetta, gestore di password). Contiene la
> **chiave che cifra le password delle caselle**: senza, dopo un ripristino le password salvate
> nella webmail non si leggono più e vanno reinserite tutte.
>
> ```bash
> cp /opt/pigreco-posta/.env /media/<pennetta>/pigreco-posta.env
> ```

Controlla che tutto sia partito:

```bash
docker compose ps
```

Devono comparire **db**, **webmail**, **posta**, **posta-sincronizza** e **posta-gestione**, tutti
*Up* (db anche *healthy*).

Se una porta è già occupata, lo script si ferma e dice quale: cambiala in `.env` (es.
`POSTA_IMAP_PORT=1143`) e rilancia `./installa.sh`. Rilanciarlo è sempre sicuro.

---

## 7. Primi passi e controlli

Da un PC dell'ufficio:

1. **Gestione caselle**, `http://<ip-del-server>:8083/`: entra come `admin` e premi **＋ Aggiungi
   casella** per ogni casella (indirizzo, utente e password del gestore). **Prova l'accesso al
   gestore** mostra le cartelle e quante email verranno copiate. Salvando, la pagina mostra **una
   sola volta** la **password locale** della casella: **annotala**. La prima copia parte subito; per
   caselle grandi può durare un'ora o più.
2. **Webmail**, `http://<ip-del-server>:8080/webmail/`: entra come `admin`, menu utente (cerchio
   con l'iniziale, in alto a destra) › **Impostazioni › Account Email › Nuovo account**:
   - Server IMAP `posta`, porta `143`, sicurezza STARTTLS
   - Utente: l'indirizzo email · Password: la **password locale**
   - SMTP: quello del gestore, con utente e password del gestore

   - Visibilità: tutti gli utenti della webmail, oppure solo quelli selezionati

   poi **Testa connessione** e **Salva**.
3. **Utenti della webmail**: Impostazioni › **Utenti webmail**, uno per persona.

Controlli rapidi:

- [ ] La Gestione caselle mostra "riuscita" nell'ultima sincronizzazione di ogni casella.
- [ ] Nella webmail compaiono le email e le cartelle del gestore.
- [ ] Una email di prova inviata dalla webmail arriva e compare in *Inviata*.
- [ ] Dal telefono, sul Wi-Fi dell'ufficio, `http://<ip-del-server>:8080/webmail/` si apre.

Telefoni e Thunderbird possono leggere la copia locale direttamente: capitolo 11 del
[manuale](manuale/manuale.md).

**Da fuori ufficio**: non aprire le porte sul router così come sono. Serve HTTPS (un reverse proxy
come Caddy o nginx con un certificato vero) oppure una VPN. La pagina Gestione caselle resta solo in
ufficio.

---

## 8. Riavvii, backup, aggiornamenti

**Riavvio del server**: Docker e PiGreco Posta ripartono da soli. Per controllare:

```bash
cd /opt/pigreco-posta && docker compose ps
```

**Backup**: un solo comando, anche con tutto acceso:

```bash
cd /opt/pigreco-posta
sudo ./scripts/backup.sh /media/<disco-esterno>
```

Crea `pigreco-posta-AAAAMMGG-HHMM.tar.gz` con le caselle (`/srv/posta`), il database salvato con
`mysqldump` e il file `.env`. I file di MySQL (`/srv/posta/mysql`) non ci sono: copiati mentre il
database lavora potrebbero non ripartire, il dump invece sì. Il file contiene la chiave di cifratura
e le password: tienilo al sicuro.

Fallo regolarmente, su un disco esterno o un NAS. Se presso i gestori tieni solo gli ultimi giorni
(capitolo 10 del manuale), **la copia sul server è l'unica**: il backup diventa obbligatorio.

**Ripristino** (su un server nuovo, dopo i paragrafi 2, 3 e 4):

```bash
cd /opt/pigreco-posta
B=/media/<disco-esterno>/pigreco-posta-AAAAMMGG-HHMM.tar.gz
sudo tar -xzf "$B" -C /srv posta                     # caselle e configurazione
sudo tar -xzf "$B" -O env > .env && chmod 600 .env   # password e chiave di prima
./installa.sh                                        # alle due domande sulle password rispondi n
sudo tar -xzf "$B" -O database.sql | \
    docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot'
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "FLUSH PRIVILEGES"'
docker compose restart webmail
```

Utenti, account, filtri, caselle e password tornano come al momento del backup.

**Aggiornare**: con `git clone` basta

```bash
cd /opt/pigreco-posta
git pull
docker compose up -d --build
```

Con lo ZIP: scarica dalla pagina Releases lo zip della nuova versione, estrailo e copialo sopra con
`cp -a pigreco-posta-<versione>/. /opt/pigreco-posta/`, poi `docker compose up -d --build`. `.env` non è
nello zip e resta com'è; i dati sono in `/srv/posta`, fuori dal programma.

**Reinstallare dallo ZIP** (cartella del programma cancellata e rimessa da capo): caselle, database
e utenti restano in `/srv/posta`. Ogni volta che gira, `installa.sh` salva una copia di `.env` in
`/srv/posta/config/pigreco-posta.env`, leggibile solo da root. Su una cartella nuova, senza
`.env`, la ritrova e riprende password e chiave di prima:

```bash
cd /opt/pigreco-posta && docker compose down   # ferma la versione vecchia
cd /opt && sudo rm -r /opt/pigreco-posta        # SOLO se i dati sono in /srv/posta (paragrafo 5)
# poi paragrafo 4 (zip) e paragrafo 6: ./installa.sh scrive "Ripreso .env dell'installazione precedente"
```

Se c'è il database ma non la copia di `.env` (installazioni fatte prima di questa versione),
`installa.sh` si ferma e non tocca niente: rimetti il vecchio `.env` in `/opt/pigreco-posta` e
rilancialo.

**Ripartire da zero** (perdendo caselle copiate, utenti e account della webmail):

```bash
cd /opt/pigreco-posta && docker compose down
sudo rm -r /srv/posta
./installa.sh
```

---

## 9. Problemi frequenti

**"is not in the sudoers file" / "sudo: command not found"**
→ Paragrafo 2.1, poi esci e rientra.

**`installa.sh`: "c'è già il database di un'installazione precedente, ma manca il suo .env"**
→ Rimetti il `.env` di quell'installazione (copia su pennetta o, da un backup:
`sudo tar -xzf <backup>.tar.gz -O env > .env`) e rilancia; oppure riparti da zero (paragrafo 8).

**"permission denied while trying to connect to the Docker daemon socket"**
→ La sessione è di prima dell'aggiunta al gruppo `docker`: esci e rientra (o `newgrp docker`).

**`git clone`: "Repository not found"**
→ Il repository è privato: usa lo ZIP (paragrafo 4).

**`./installa.sh`: "Permission denied"**
→ Lo zip non ha conservato i permessi: `chmod +x installa.sh scripts/*.sh`.

**`installa.sh` dice che una porta è già usata**
→ Guarda chi la usa: `sudo ss -ltnp | grep -E ':(8080|8083|143|993) '`. Se è un Dovecot installato
su Debian e non serve: `sudo apt-get remove dovecot-core`. Altrimenti cambia la porta in `.env`.

**Il browser non apre la webmail da un altro PC**
→ Dal server: `curl -I http://localhost:8080/webmail/` (deve rispondere 200 o 302). Se dal server
funziona e dagli altri PC no: firewall (paragrafo 2.5) o indirizzo sbagliato (`ip -4 addr`).

**Password dimenticata**
```bash
cd /opt/pigreco-posta
./scripts/password-webmail.sh admin      # utente della webmail
./scripts/password-gestione.sh admin     # pagina Gestione caselle
```

**Una casella non si sincronizza**
→ In Gestione caselle compare "errore" con il messaggio del gestore (di solito password cambiata, o
manca la *password per le app*). Registro completo: `docker compose logs --tail 100 posta-sincronizza`.

Per gli altri casi: capitolo 14 del [manuale](manuale/manuale.md).
