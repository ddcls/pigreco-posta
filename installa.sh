#!/usr/bin/env bash
# Installazione di PiGreco Posta su un server con Docker.
#
#   ./installa.sh
#
# 1. crea .env da .env.example (se non c'è) e vi scrive password e chiave
#    di cifratura casuali al posto di quelle vuote; se in POSTA_DIR ci sono
#    i dati di un'installazione precedente riprende il suo .env (ne tiene una
#    copia in POSTA_DIR/config/pigreco-posta.env);
# 2. costruisce e avvia i container;
# 3. chiede la password dell'amministratore della webmail e quella della
#    pagina "Gestione caselle".
# Rilanciarlo è sicuro: non cancella né sovrascrive dati o password esistenti.
# Le due password si possono saltare e impostare dopo con gli script in scripts/.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    echo "Serve Docker con il plugin compose (comando \"docker compose\")." >&2
    echo "Vedi https://docs.docker.com/engine/install/" >&2
    exit 1
fi

casuale() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

if ! command -v openssl >/dev/null 2>&1; then
    echo "Serve openssl per generare le password (es. apt install openssl)." >&2
    exit 1
fi
valore() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2-; }
assoluto() { realpath -m -- "$1"; }
# Le cartelle dei dati (di root o di 5000) si guardano da un container:
# l'utente normale non può entrare in /srv/posta. Stessa immagine di base
# della posta, che serve comunque.
# nel_container <cartella:/d[:ro]> <argomenti di docker run>
nel_container() { local cartella="$1"; shift; docker run --rm -v "$cartella" "$@"; }
# c_e <cartella> <percorso dentro>: montando la cartella madre, così Docker
# non crea la cartella se non esiste
c_e() { local madre; madre="$(dirname "$1")"
        [[ -d "$madre" ]] && nel_container "$madre:/d:ro" $IMMAGINE test -e "/d/$(basename "$1")/$2"; }
IMMAGINE=debian:bookworm-slim
COPIA_ENV=config/pigreco-posta.env   # dentro POSTA_DIR

if [[ -f .env ]]; then
    echo "Uso il file .env esistente."
else
    cp .env.example .env
    chmod 600 .env
    posta="$(assoluto "$(valore POSTA_DIR)")"
    database="$(assoluto "$(valore WEBMAIL_DB_DIR)")"
    if c_e "$posta" "$COPIA_ENV"; then
        # Programma riscaricato (es. dallo zip) con i dati di prima ancora
        # lì: si riprendono password e chiave di cifratura dell'installazione
        # precedente, altrimenti il database esistente non si aprirebbe più
        nel_container "$posta:/d:ro" $IMMAGINE cat "/d/$COPIA_ENV" > .env
        echo "Ripreso .env dell'installazione precedente ($posta/$COPIA_ENV)."
    elif c_e "$database" mysql; then
        rm .env
        echo "In $database c'è già il database di un'installazione precedente," >&2
        echo "ma manca il suo .env con le password. Due possibilità:" >&2
        echo "  - rimetti il vecchio .env (copia o backup) in $(pwd) e rilancia ./installa.sh;" >&2
        echo "  - per ripartire da zero, cancella i dati: sudo rm -r $database" >&2
        echo "    (e sudo rm -r $posta per togliere anche le caselle copiate)." >&2
        exit 1
    else
        echo "Creato .env da .env.example."
    fi
fi
# Password e chiave lasciate vuote (anche in un .env copiato e modificato a
# mano, es. per mettere i dati su un altro disco): generate qui. Quelle già
# scritte non si toccano.
generate=0
for coppia in "DB_ROOT_PASS:$(casuale 32)" "WEBMAIL_DB_PASS:$(casuale 32)" \
              "WEBMAIL_ENCRYPTION_KEY:$(openssl rand -base64 32)"; do
    var="${coppia%%:*}"
    if grep -qE "^$var=$" .env; then
        sed -i "s|^$var=$|$var=${coppia#*:}|" .env
        generate=1
    fi
done
if (( generate )); then
    echo "Password casuali scritte in .env (conservane una copia: contiene la"
    echo "chiave che cifra le password delle caselle)."
fi

# Porte già occupate: meglio dirlo prima che Docker fallisca a metà
occupate=()
for var in WEBMAIL_PORT POSTA_IMAP_PORT POSTA_IMAPS_PORT POSTA_GESTIONE_PORT; do
    porta="$(valore "$var")"
    [[ -z "$porta" ]] && continue
    if command -v ss >/dev/null 2>&1 && ss -ltnH "sport = :$porta" | grep -q .; then
        # Se è già nostra (reinstallazione) va bene
        if ! docker compose ps --format '{{.Ports}}' 2>/dev/null | grep -q ":$porta->"; then
            occupate+=("$var=$porta")
        fi
    fi
done
if (( ${#occupate[@]} )); then
    echo "Queste porte sono già usate da un altro programma: ${occupate[*]}" >&2
    echo "Cambiale in .env (es. POSTA_IMAP_PORT=1143) e rilancia ./installa.sh" >&2
    exit 1
fi

# Cartelle dei dati create come utente normale: altrimenti le crea Docker
# di root e poi non si possono spostare o cancellare senza sudo. Il contenuto
# lo assegnano i container (MySQL al suo utente, la posta a 5000:5000).
for var in WEBMAIL_DB_DIR POSTA_DIR; do
    dir="$(valore "$var")"
    # (/srv/posta non è scrivibile dall'utente: la crea Docker e il container
    # della posta la assegna a 5000:5000)
    [[ -n "$dir" ]] && mkdir -p "$dir" 2>/dev/null || true
done

docker compose up -d --build

echo
echo "In attesa che la webmail sia pronta..."
for i in $(seq 1 60); do
    if docker compose exec -T webmail php -r 'require "/var/www/webmail/config/db_config.php"; exit(getDBConnection() ? 0 : 1);' </dev/null >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

# Copia di .env accanto ai dati (solo root può leggerla): se il programma
# viene cancellato e riscaricato, ./installa.sh la ritrova da sé
nel_container "$(assoluto "$(valore POSTA_DIR)"):/d" -i $IMMAGINE sh -c \
    "[ -d /d/config ] || install -d -o 5000 -g 5000 /d/config; cat > /d/$COPIA_ENV && chown 0:0 /d/$COPIA_ENV && chmod 600 /d/$COPIA_ENV" < .env

echo
# Senza terminale (es. installazione automatica) le password si saltano
read -r -p "Impostare ora la password dell'utente \"admin\" della webmail? [S/n] " r || r=n
if [[ ! "$r" =~ ^[nN] ]]; then
    ./scripts/password-webmail.sh admin
fi
echo
read -r -p "Impostare ora la password della pagina \"Gestione caselle\"? [S/n] " r || r=n
if [[ ! "$r" =~ ^[nN] ]]; then
    ./scripts/password-gestione.sh admin
fi

ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
ip="${ip:-<ip-server>}"
echo
echo "PiGreco Posta è attiva:"
echo "  Webmail:          http://$ip:$(valore WEBMAIL_PORT)/webmail/"
echo "  Gestione caselle: http://$ip:$(valore POSTA_GESTIONE_PORT)/"
echo "Primi passi: aggiungi le caselle da \"Gestione caselle\", poi entra nella"
echo "webmail come admin e crea gli account (vedi manuale/manuale.md)."
