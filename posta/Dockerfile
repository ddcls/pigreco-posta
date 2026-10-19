# PiGreco Posta: copia locale delle caselle dei gestori (mbsync) servita via
# IMAP da Dovecot. Una sola immagine per i due servizi del compose:
#   dovecot      server IMAP sulle caselle in /srv/posta/<indirizzo>/Maildir
#   sincronizza  mbsync a intervalli, dal gestore verso la copia locale
#   gestione     pagina web per aggiungere/modificare le caselle
FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        dovecot-imapd isync python3 openssl ca-certificates tzdata \
    && rm -rf /var/lib/apt/lists/*

# Proprietario delle caselle (uguale a quello di /srv/posta sul server)
# L'autenticazione di Dovecot (utente dovecot) legge il file utenti delle
# caselle: entra nel gruppo vmail, che ha accesso solo a quello
RUN groupadd -g 5000 vmail && useradd -u 5000 -g vmail -d /srv/posta -s /usr/sbin/nologin -M vmail \
    && usermod -aG vmail dovecot

COPY dovecot/dovecot.conf /etc/dovecot/dovecot.conf
COPY script/postaconf.py script/gestione.py /usr/local/lib/posta/
COPY script/avvia.sh /usr/local/bin/avvia
COPY script/conta-gestore.py /usr/local/bin/conta-gestore
COPY script/genera-config.sh /usr/local/bin/genera-config
RUN chmod 755 /usr/local/bin/genera-config /usr/local/bin/avvia /usr/local/bin/conta-gestore

EXPOSE 143 993 8080
ENTRYPOINT ["/usr/local/bin/avvia"]
CMD ["dovecot"]
