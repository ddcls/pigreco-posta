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

# Pubblica PiGreco Posta nella webroot copiando SOLO i file dell'applicazione.
#
#   ./deploy.sh                          → /var/www/html/webmail
#   ./deploy.sh /var/www/html/posta      → destinazione a scelta
#   sudo ./deploy.sh                     → in più assegna i file a root:www-data
#
# Non copia: .git, note e documentazione, migrazioni SQL, file di sviluppo.
# Nella destinazione li rimuove se presenti (es. dopo una copia manuale).
# Non sovrascrive mai la configurazione della destinazione
# (config/config.local.php): se manca, viene copiata una sola volta da
# questa cartella.

set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="${1:-/var/www/html/webmail}"
DEST="${DEST%/}"

if [[ "$DEST" == "$SRC" ]]; then
    echo "Errore: la destinazione coincide con la cartella sorgente" >&2
    exit 1
fi
if [[ -z "$DEST" || "$DEST" == "/" ]]; then
    echo "Errore: destinazione non valida" >&2
    exit 1
fi
if [[ -d "$DEST" && -n "$(ls -A "$DEST")" && ! -f "$DEST/index.php" ]]; then
    echo "Errore: $DEST esiste ma non sembra una copia di PiGreco Posta (manca index.php): non la tocco" >&2
    exit 1
fi

mkdir -p "$DEST"

# File di configurazione della destinazione: mai sovrascritti né cancellati
RUNTIME_FILES=(
    config/config.local.php
    config/env_path.local.php
)

FILTERS=()
for f in "${RUNTIME_FILES[@]}"; do
    FILTERS+=(--filter="P /$f" --filter="H /$f")
done

rsync -rlt --omit-dir-times --delete --delete-excluded \
    "${FILTERS[@]}" \
    --exclude='/.git/' \
    --exclude='/.gitignore' \
    --exclude='/.env' \
    --exclude='/.claude/' \
    --exclude='*.md' \
    --exclude='/todolist.txt' \
    --exclude='/analisi_aggiornata.txt' \
    --exclude='/migrations/' \
    --exclude='/schema.sql' \
    --exclude='/deploy.sh' \
    --exclude='/config/*.json' \
    --exclude='__pycache__/' \
    --exclude='*.pyc' \
    --exclude='*.swp' \
    --chmod=D755,F644 \
    "$SRC/" "$DEST/"

if [[ ! -f "$DEST/config/config.local.php" ]]; then
    if [[ -f "$SRC/config/config.local.php" ]]; then
        cp "$SRC/config/config.local.php" "$DEST/config/config.local.php"
        echo "Copiata config/config.local.php (prima pubblicazione)"
    else
        echo "ATTENZIONE: manca config/config.local.php in $DEST: crealo da config/config.example.php" >&2
    fi
fi

if [[ $EUID -eq 0 ]]; then
    # Proprietà a root, sola lettura per il server web: l'app non scrive
    # mai nella propria cartella (usa il database e la cartella temporanea)
    chown -R root:www-data "$DEST"
    find "$DEST" -type d -exec chmod 750 {} +
    find "$DEST" -type f -exec chmod 640 {} +
else
    # Senza root si normalizzano solo i file di cui si è proprietari (la
    # cartella di destinazione può appartenere a root)
    find "$DEST" -mindepth 1 -user "$(id -u)" -type d -exec chmod 755 {} +
    find "$DEST" -mindepth 1 -user "$(id -u)" -type f -exec chmod 644 {} +
    echo "Nota: eseguito senza sudo, i file restano dell'utente $(id -un). Con 'sudo ./deploy.sh' vengono assegnati a root:www-data."
fi

echo "Pubblicato $SRC → $DEST"
