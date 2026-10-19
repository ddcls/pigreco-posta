#!/usr/bin/env bash
# Imposta (o reimposta) la password di un utente della webmail, di default "admin".
#   ./scripts/password-webmail.sh [utente]
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
docker compose exec -it webmail php /var/www/webmail/initializa/reset_admin_password.php "${1:-admin}"
