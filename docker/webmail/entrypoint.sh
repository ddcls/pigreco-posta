#!/bin/bash
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

# Avvio del container "webmail": prepara (solo se mancano) database, utente
# MySQL dedicato, tabelle e utente amministratore della webmail, poi avvia Apache.
# Rilanciarlo è sicuro: non cancella né sovrascrive nulla di esistente.
set -euo pipefail

if [[ -z "${DB_ROOT_PASS:-}" ]]; then
    echo "ERRORE: DB_ROOT_PASS mancante (serve per creare il database della webmail)" >&2
    exit 1
fi

APP=/var/www/webmail

if [[ ! -f "$APP/index.php" ]]; then
    echo "ERRORE: webmail non trovata in $APP: controlla il volume ./webmail in docker-compose.yml" >&2
    exit 1
fi
if [[ -z "${MAIL_ENCRYPTION_KEY:-}" ]]; then
    echo "ATTENZIONE: WEBMAIL_ENCRYPTION_KEY vuota in .env: non sarà possibile salvare gli account email" >&2
fi
if [[ -z "${DB_PASS:-}" ]]; then
    echo "ERRORE: WEBMAIL_DB_PASS vuota in .env" >&2
    exit 1
fi

# Apici singoli raddoppiati: le password vanno dentro stringhe SQL
sql_str() { printf "%s" "$1" | sed "s/'/''/g"; }

# Il client MariaDB di Debian verifica il certificato di MySQL 8, che è
# autofirmato: la connessione resta cifrata, sulla rete interna di docker.
mysql_root() { MYSQL_PWD="$DB_ROOT_PASS" mysql --skip-ssl-verify-server-cert -h "$DB_HOST" -u root "$@"; }

for i in $(seq 1 30); do
    mysql_root -e "SELECT 1" >/dev/null 2>&1 && break
    echo "In attesa di MySQL ($i)..."
    sleep 2
done

mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$(sql_str "$DB_USER")'@'%' IDENTIFIED BY '$(sql_str "$DB_PASS")';
ALTER USER '$(sql_str "$DB_USER")'@'%' IDENTIFIED BY '$(sql_str "$DB_PASS")';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.* TO '$(sql_str "$DB_USER")'@'%';
SQL

if ! mysql_root "$DB_NAME" -e "SELECT 1 FROM users LIMIT 1" >/dev/null 2>&1; then
    echo "Creazione delle tabelle della webmail in ${DB_NAME}..."
    mysql_root "$DB_NAME" < "$APP/schema.sql"
fi

# Aggiornamenti dello schema su un database già esistente (idempotenti)
if [[ "$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = '${DB_NAME}' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tema'")" == "0" ]]; then
    echo "Applico migrations/003_tema.sql (colore dell'interfaccia)..."
    mysql_root "$DB_NAME" < "$APP/migrations/003_tema.sql"
fi

if [[ "$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '${DB_NAME}' AND TABLE_NAME = 'cartelle_create'")" == "0" ]]; then
    echo "Applico migrations/004_cartelle_create.sql (cartelle create dalla webmail)..."
    mysql_root "$DB_NAME" < "$APP/migrations/004_cartelle_create.sql"
fi

if [[ "$(mysql_root -N -e "SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '${DB_NAME}' AND TABLE_NAME = 'filtri'")" == "0" ]]; then
    echo "Applico migrations/005_filtri.sql (filtri sulle email in arrivo)..."
    mysql_root "$DB_NAME" < "$APP/migrations/005_filtri.sql"
fi

if [[ "$(mysql_root -N "$DB_NAME" -e "SELECT COUNT(*) FROM users")" == "0" ]]; then
    # Password casuale mai mostrata: va impostata con reset_admin_password.php
    HASH=$(php -r 'echo password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);')
    mysql_root "$DB_NAME" -e "INSERT INTO users (nome, cognome, username, password, email, attivo, tipo_user)
        VALUES ('Amministratore', 'Webmail', 'admin', '${HASH}', '', 1, 'admin')"
    echo "Creato l'utente 'admin' della webmail. Imposta la sua password con:"
    echo "  ./scripts/password-webmail.sh admin"
fi

# Filtri sulle email in arrivo: ogni minuto, come utente www-data, in
# background accanto ad Apache. Se il file non c'è (codice di una versione
# precedente) non fa niente; un errore non ferma il ciclo.
(
    while true; do
        sleep 60
        if [[ -f "$APP/filtri_esegui.php" ]]; then
            setpriv --reuid=www-data --regid=www-data --init-groups \
                php "$APP/filtri_esegui.php" || true
        fi
    done
) &

exec "$@"
