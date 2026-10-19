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

# ============================================
# PIGRECO POSTA - webmail
#
# Serve la webmail su /webmail/ (la radice porta lì). Collegata a PiGreco
# Archivio, è l'Apache dell'archivio a inoltrare qui /webmail/: così la
# finestra "Archivia" chiama /api/ dell'archivio dalla stessa origine.
# Il codice NON è in questa immagine: è la cartella webmail/ del progetto,
# montata in sola lettura.
# ============================================
FROM php:8.3-apache

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Europe/Rome

# python3: parsing MIME delle email (email_parser.py, solo libreria standard)
# client mysql: creazione del database della webmail al primo avvio (entrypoint)
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 \
    libzip-dev \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN docker-php-ext-install -j$(nproc) mysqli zip

RUN a2enmod headers

COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/webmail.ini
# Configurazione della webmail nel container: i valori arrivano dalle variabili
# d'ambiente (WEBMAIL_CONFIG_FILE la indica al posto di config/config.local.php)
COPY config.php /etc/webmail/config.php
COPY entrypoint.sh /usr/local/bin/webmail-entrypoint
RUN chmod 755 /usr/local/bin/webmail-entrypoint

EXPOSE 80
ENTRYPOINT ["webmail-entrypoint"]
CMD ["apache2-foreground"]
