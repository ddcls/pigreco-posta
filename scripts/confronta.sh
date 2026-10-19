#!/bin/sh
# Confronta il numero di email per cartella tra il gestore e la copia locale.
# Da lanciare sull'host, con la posta avviata:
#   ./scripts/confronta.sh [indirizzo]
set -eu
cd "$(dirname "$0")/.."

echo "--- Gestore ---"
docker compose exec -T -u 5000 posta-sincronizza conta-gestore "$@"

echo "--- Copia locale (Dovecot) ---"
for utente in $(docker compose exec -T posta cut -d: -f1 /srv/posta/config/generati/caselle.passwd); do
    [ $# -gt 0 ] && [ "$utente" != "$1" ] && continue
    echo "== $utente"
    docker compose exec -T posta doveadm -f tab mailbox status -u "$utente" messages '*' \
        | tail -n +2 | sort -f -t "$(printf '\t')" -k1 \
        | awk -F'\t' '{ printf "%8d  %s\n", $2, $1; t += $2 } END { printf "%8d  TOTALE\n", t }'
done
