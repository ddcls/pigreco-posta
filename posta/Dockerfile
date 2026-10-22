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

# PiGreco Posta: copia locale delle caselle dei gestori (mbsync) servita via
# IMAP da Dovecot. Una sola immagine per i servizi del compose:
#   dovecot      server IMAP sulle caselle in /srv/posta/<indirizzo>/Maildir
#   sincronizza  mbsync a intervalli, dal gestore verso la copia locale
#   gestione     pagina web per aggiungere/modificare le caselle
#   backup       copie di backup su dischi USB (restic), vedi script/backup.py
FROM debian:bookworm-slim

# e2fsprogs/fdisk: preparare e controllare i dischi di backup (lsblk, mount,
# wipefs sono in util-linux); client MariaDB: mysqldump del database della
# webmail; curl/bzip2: solo per scaricare restic qui sotto
RUN apt-get update && apt-get install -y --no-install-recommends \
        dovecot-imapd isync python3 openssl ca-certificates tzdata \
        e2fsprogs fdisk mariadb-client curl bzip2 \
    && rm -rf /var/lib/apt/lists/*

# restic (github.com/restic/restic, BSD-2): copie cifrate e deduplicate sui
# dischi USB. Debian ne ha una versione vecchia: binario della release con
# impronta SHA-256 controllata. Per aggiornare cambiare versione e impronte
# insieme (SHA256SUMS della release). Stessa versione di PiGreco Archivio.
ARG RESTIC_VERSIONE=0.19.1
ARG RESTIC_SHA256_AMD64=f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c
ARG RESTIC_SHA256_ARM64=a5f64aaab53d51e311fa3829124c5b703f2d14cf187d8640b6be3b2b49376465
ARG TARGETARCH=amd64
RUN case "$TARGETARCH" in \
        amd64) impronta="$RESTIC_SHA256_AMD64" ;; \
        arm64) impronta="$RESTIC_SHA256_ARM64" ;; \
        *) echo "restic: architettura $TARGETARCH non prevista" >&2; exit 1 ;; \
    esac \
    && curl -fsSL -o /tmp/restic.bz2 \
        "https://github.com/restic/restic/releases/download/v${RESTIC_VERSIONE}/restic_${RESTIC_VERSIONE}_linux_${TARGETARCH}.bz2" \
    && echo "${impronta}  /tmp/restic.bz2" | sha256sum -c - \
    && bunzip2 -c /tmp/restic.bz2 > /usr/local/bin/restic \
    && chmod 755 /usr/local/bin/restic \
    && rm /tmp/restic.bz2

# Proprietario delle caselle (uguale a quello di /srv/posta sul server)
# L'autenticazione di Dovecot (utente dovecot) legge il file utenti delle
# caselle: entra nel gruppo vmail, che ha accesso solo a quello
RUN groupadd -g 5000 vmail && useradd -u 5000 -g vmail -d /srv/posta -s /usr/sbin/nologin -M vmail \
    && usermod -aG vmail dovecot

COPY dovecot/dovecot.conf /etc/dovecot/dovecot.conf
COPY script/postaconf.py script/gestione.py script/copie.py script/backup.py /usr/local/lib/posta/
COPY script/avvia.sh /usr/local/bin/avvia
COPY script/conta-gestore.py /usr/local/bin/conta-gestore
COPY script/genera-config.sh /usr/local/bin/genera-config
RUN chmod 755 /usr/local/bin/genera-config /usr/local/bin/avvia /usr/local/bin/conta-gestore

EXPOSE 143 993 8080
ENTRYPOINT ["/usr/local/bin/avvia"]
CMD ["dovecot"]
