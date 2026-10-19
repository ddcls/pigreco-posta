#!/usr/bin/env python3
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
