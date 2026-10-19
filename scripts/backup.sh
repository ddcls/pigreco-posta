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

# Backup completo in un solo file: le caselle (POSTA_DIR), il database della
# webmail e il file .env (con la chiave che cifra le password delle caselle).
#   sudo ./scripts/backup.sh /media/disco-esterno
# Non ferma niente: il database è salvato con mysqldump (copiare i file di un
# MySQL acceso darebbe un backup che può non ripartire), la sua cartella dati
# resta fuori dall'archivio. Ripristino: INSTALLA-DEBIAN.md, paragrafo 8.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

destinazione="${1:-}"
if [[ -z "$destinazione" || ! -d "$destinazione" ]]; then
    echo "Uso: sudo $0 <cartella di destinazione>   (es. un disco esterno)" >&2
    exit 1
fi
if [[ $EUID -ne 0 ]]; then
    echo "Lancialo con sudo: i file delle caselle non sono del tuo utente." >&2
    exit 1
fi

valore() { grep -E "^$1=" .env | tail -n1 | cut -d= -f2-; }
assoluto() { realpath -m -- "$1"; }
posta="$(assoluto "$(valore POSTA_DIR)")"
database="$(assoluto "$(valore WEBMAIL_DB_DIR)")"

lavoro="$(mktemp -d)"
trap 'rm -rf -- "$lavoro"' EXIT

echo "Salvo il database..."
docker compose exec -T db sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --events --all-databases' \
    > "$lavoro/database.sql"
cp .env "$lavoro/env"

# La cartella dei file di MySQL, se sta dentro quella della posta, non serve:
# c'è il dump
escludi=()
if [[ "$database" == "$posta"/* ]]; then
    escludi=(--exclude="$(basename "$posta")/${database#"$posta"/}")
fi

file="$destinazione/pigreco-posta-$(date +%Y%m%d-%H%M).tar.gz"
echo "Salvo le caselle da $posta..."
tar -czf "$file" "${escludi[@]}" \
    -C "$(dirname "$posta")" "$(basename "$posta")" \
    -C "$lavoro" database.sql env
chmod 600 "$file"
echo "Backup pronto: $file ($(du -h "$file" | cut -f1))"
echo "Contiene la chiave di cifratura e le password: conservalo in un posto sicuro."
