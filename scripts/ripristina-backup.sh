#!/usr/bin/env bash
# PiGreco Posta
# Copyright (C) 2026 Criscione Enzo
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
# secondo i termini della GNU Affero General Public License come pubblicata
# dalla Free Software Foundation, versione 3 della licenza o (a tua scelta)
# qualsiasi versione successiva.
#
# Questo programma è distribuito nella speranza che sia utile, ma SENZA
# ALCUNA GARANZIA; senza neppure la garanzia implicita di COMMERCIABILITÀ
# o di IDONEITÀ PER UN PARTICOLARE SCOPO. Vedi la GNU Affero General
# Public License per i dettagli.
#
# Dovresti aver ricevuto una copia della GNU Affero General Public License
# insieme a questo programma (file LICENSE). In caso contrario, vedi
# <https://www.gnu.org/licenses/>.

# Rimette tutta la posta (caselle, utenti e account della webmail, filtri,
# password) da un disco di backup. Per un server nuovo, o dopo la rottura
# del disco dei dati:
#
#   1. installa PiGreco Posta come la prima volta (./installa.sh);
#   2. collega il disco di backup (se il desktop lo apre, va bene lo stesso);
#   3. ./scripts/ripristina-backup.sh
#
# Lo script ferma webmail e copia locale, chiede la password delle copie (è sul
# foglio di ripristino; se è la stessa di questo server non la chiede) e quale
# copia rimettere, poi chiede di scrivere RIPRISTINA: caselle e database di
# questo server vengono SOSTITUITI da quelli della copia. Il disco di backup si
# legge soltanto. Alla fine il .env riprende dal vecchio .env la chiave che
# cifra le password delle caselle e la password delle copie (il .env di prima
# resta in .env.prima-del-ripristino; le password di MySQL restano quelle di
# questo server) e tutto riparte.
#
# Il lavoro lo fa posta/script/backup.py --ripristina in un container usa e
# getta del servizio posta-backup (restic, permesso di montare): niente sudo.
set -euo pipefail
cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.."

valore() { grep -E "^$1=" "${2:-.env}" 2>/dev/null | tail -n1 | cut -d= -f2-; }
imposta() {
    if grep -qE "^$1=" "$3"; then sed -i "s|^$1=.*|$1=$2|" "$3"; else printf '%s=%s\n' "$1" "$2" >> "$3"; fi
}

if [[ ! -f .env ]]; then
    echo "Manca .env: installa prima PiGreco Posta con ./installa.sh (vedi INSTALLA-DEBIAN.md)." >&2
    exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
    echo "Docker non risponde: serve Docker con il plugin compose (e l'utente nel gruppo docker)." >&2
    exit 1
fi

# Si ferma tutto quello che usa caselle e database; alla fine riparte quello
# che era acceso (tutto, su un server appena installato e spento)
fermati=(webmail posta posta-sincronizza posta-gestione posta-backup)
accesi="$(docker compose ps --status running --services 2>/dev/null || true)"
riaccendere=()
for s in "${fermati[@]}"; do
    grep -qx "$s" <<<"$accesi" && riaccendere+=("$s")
done

esito="$(mktemp -d)"
chmod 700 "$esito"
codice=0
riavvia() {
    rm -rf "$esito"
    echo
    echo "Riavvio PiGreco Posta..."
    # db prima (se le password sono cambiate riparte con quelle nuove), poi il resto
    {
        docker compose up -d db &&
        if (( ${#riaccendere[@]} )); then docker compose up -d "${riaccendere[@]}"; else docker compose up -d; fi
    } >/dev/null 2>&1 || echo "Non riesco a riavviarla: cd $(pwd) && docker compose up -d" >&2
}
trap riavvia EXIT

echo "Fermo webmail e copia locale delle caselle (il database resta acceso)..."
docker compose stop "${fermati[@]}" >/dev/null 2>&1 || true
docker compose up -d db >/dev/null
for _ in $(seq 1 90); do
    [[ "$(docker compose ps db --format '{{.Health}}' 2>/dev/null)" == healthy ]] && break
    sleep 2
done

tty=()
[[ -t 0 ]] || tty=(-T)
docker compose run --rm --no-deps "${tty[@]}" \
    -e PROPRIETARIO="$(id -u):$(id -g)" \
    -v "$esito:/ripristino/esito" \
    posta-backup python3 /usr/local/lib/posta/backup.py --ripristina || codice=$?

# Database ricaricato (anche se poi qualcosa si è fermato): da qui gli account
# della webmail hanno le password cifrate con la chiave del vecchio server
if [[ -e "$esito/database_caricato" && -s "$esito/backup_password" ]]; then
    cp -p .env .env.prima-del-ripristino
    chmod 600 .env.prima-del-ripristino
    if [[ -s "$esito/env-dalla-copia" ]]; then
        chiave="$(valore WEBMAIL_ENCRYPTION_KEY "$esito/env-dalla-copia")"
        [[ -n "$chiave" ]] && imposta WEBMAIL_ENCRYPTION_KEY "$chiave" .env
        install -m 600 "$esito/env-dalla-copia" .env.dalla-copia
    fi
    imposta BACKUP_PASSWORD "$(cat "$esito/backup_password")" .env
    # La copia di .env accanto ai dati (installa.sh la usa se il programma viene
    # riscaricato) è tornata quella del vecchio server: si rimette quella di adesso
    docker run --rm -i -v "$(realpath -m -- "$(valore POSTA_DIR)")/config:/d" --entrypoint sh pigreco-posta/posta:locale \
        -c 'cat > /d/pigreco-posta.env && chown 0:0 /d/pigreco-posta.env && chmod 600 /d/pigreco-posta.env' < .env
    echo
    echo "Nel .env ci sono ora la chiave delle caselle e la password delle copie del vecchio server:"
    echo "quello di prima è in .env.prima-del-ripristino, quello del vecchio server in .env.dalla-copia."
fi
if (( codice == 0 || codice == 2 )); then
    echo "Ripristino finito. Entra nella webmail e in Gestione caselle e controlla caselle e account;"
    echo "poi, in Gestione caselle › Copie di backup, stampa di nuovo il foglio di ripristino."
fi
exit "$codice"
