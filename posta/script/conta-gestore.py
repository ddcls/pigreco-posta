#!/usr/bin/env python3
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

"""
Numero di email per cartella sul gestore, per confrontarlo con la copia
locale (doveadm mailbox status). Uso: conta-gestore [indirizzo]
"""
import sys

sys.path.insert(0, '/usr/local/lib/posta')
import postaconf as pc  # noqa: E402

for a in pc.leggi_account():
    if len(sys.argv) > 1 and a['indirizzo'] != sys.argv[1].lower():
        continue
    print(f"== {a['indirizzo']} ({a['server']})")
    totale = 0
    for c in sorted(pc.cartelle_gestore(a, conta=True), key=lambda c: c['nome'].lower()):
        if c['messaggi'] is None:
            continue
        totale += c['messaggi']
        print(f"{c['messaggi']:8d}  {c['nome']}")
    print(f'{totale:8d}  TOTALE')
