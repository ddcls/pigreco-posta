#!/bin/sh
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

# Avvio dei servizi della copia locale (stessa immagine, comando diverso):
#   avvia dovecot       genera utenti/cartelle/certificato e avvia Dovecot
#   avvia sincronizza   mbsync per ogni casella ogni POSTA_INTERVALLO secondi
#   avvia gestione      pagina web "Gestione caselle"
#   avvia backup        copie di backup su dischi USB (root: monta i dischi)
#   avvia una-volta     una sola sincronizzazione con i dettagli (es. la prima, a mano)
set -eu

come_vmail() { exec setpriv --reuid=5000 --regid=5000 --clear-groups -- "$@"; }

# Cartella dei dati: se Docker l'ha appena creata (di root) la si assegna a
# vmail, così su una macchina nuova non serve prepararla a mano con sudo
if [ "$(id -u)" = 0 ]; then
    mkdir -p /srv/posta
    if [ "$(stat -c %u /srv/posta)" != 5000 ]; then
        chown 5000:5000 /srv/posta
        chmod 750 /srv/posta
    fi
fi

case "${1:-dovecot}" in
    dovecot)
        genera-config dovecot
        exec dovecot -F
        ;;
    sincronizza)
        come_vmail genera-config sincronizza
        ;;
    gestione)
        come_vmail python3 -u /usr/local/lib/posta/gestione.py
        ;;
    backup)
        exec python3 -u /usr/local/lib/posta/backup.py
        ;;
    una-volta)
        setpriv --reuid=5000 --regid=5000 --clear-groups -- genera-config mbsync
        come_vmail mbsync -a -V -c /srv/posta/config/generati/mbsyncrc
        ;;
    *)
        exec "$@"
        ;;
esac
