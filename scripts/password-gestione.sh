#!/usr/bin/env bash
# Imposta (o reimposta) l'utente e la password della pagina "Gestione caselle".
#   ./scripts/password-gestione.sh [utente]
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
docker compose exec -it posta-gestione genera-config imposta-password "${1:-admin}"
