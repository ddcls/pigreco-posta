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

"""Genera manuale.pdf da manuale.md.

Uso: python3 manuale/genera_pdf.py   (con Playwright installato: pip install playwright,
     poi playwright install chromium)
Richiede: pandoc.

pandoc -> HTML (copertina, indice, un capitolo per pagina) -> stampa con Chromium
(numeri di pagina a piè). Da rifare a ogni modifica di manuale.md.
"""
import re
import subprocess
import tempfile
from pathlib import Path

from playwright.sync_api import sync_playwright

DIR = Path(__file__).resolve().parent
SRC = DIR / "manuale.md"
TITOLO, SOTTOTITOLO = "PiGreco Posta", "Manuale"
COPYRIGHT = "Copyright © 2026 Criscione Enzo"

CSS = """
@page { size: A4; margin: 18mm 16mm 20mm 16mm; }
body { font-family: 'Lato','DejaVu Sans',Arial,sans-serif; font-size: 10.5pt; line-height: 1.45; color: #222; }
h2 { font-size: 17pt; margin-top: 0; color: #1a3d6d; border-bottom: 3px solid #1a3d6d; padding-bottom: 4px; }
p + h2, ol + h2 { margin-top: 28px; }
h3 { font-size: 12.5pt; color: #2b5a91; margin-top: 18px; }
h2, h3 { page-break-after: avoid; }
table { border-collapse: collapse; width: 100%; margin: 8px 0 12px; font-size: 9.5pt; page-break-inside: avoid; }
th { background: #1a3d6d; color: #fff; text-align: left; padding: 5px 6px; }
td { border-bottom: 1px solid #d8dee8; padding: 4px 6px; vertical-align: top; }
tr:nth-child(even) td { background: #f5f7fa; }
blockquote { margin: 10px 0; padding: 8px 12px; background: #fff8e1; border-left: 4px solid #f0b400; page-break-inside: avoid; }
blockquote p { margin: 2px 0; }
code { font-family: 'DejaVu Sans Mono',monospace; font-size: 9pt; background: #f0f2f5; padding: 0 3px; border-radius: 3px; }
pre { background: #f0f2f5; padding: 8px; border-radius: 4px; font-size: 8.5pt; white-space: pre-wrap; page-break-inside: avoid; }
pre code { background: none; padding: 0; }
.copertina { height: 250mm; display: flex; flex-direction: column; justify-content: center; text-align: center; page-break-after: always; }
.copertina .t { font-size: 38pt; color: #1a3d6d; font-weight: bold; }
.copertina .s { font-size: 22pt; color: #2b5a91; margin-top: 8px; }
.copertina .v { font-size: 12pt; color: #666; margin-top: 40px; }
.copertina .c { font-size: 10pt; color: #888; margin-top: 8px; }
nav#TOC { page-break-after: always; }
nav#TOC::before { content: "Indice"; display: block; font-size: 20pt; color: #1a3d6d; font-weight: bold; margin-bottom: 10px; }
nav#TOC ul { list-style: none; padding-left: 14px; }
nav#TOC > ul { padding-left: 0; }
nav#TOC > ul > li > a { font-weight: bold; }
nav#TOC a { color: #222; text-decoration: none; }
nav#TOC li { margin: 2px 0; }
"""


def prepara(testo):
    """Toglie titolo, versione e indice scritto a mano (copertina e indice li fa il PDF)."""
    versione = re.search(r"^Versione (.+)$", testo, re.M).group(1)
    testo = re.sub(r"\A# .*?\n\nVersione .*?\n", "", testo, count=1, flags=re.S)
    testo = re.sub(r"\n## Indice\n.*?\n---\n", "\n", testo, count=1, flags=re.S)
    # Ogni capitolo in una pagina nuova; le linee tra capitoli non servono
    testo = re.sub(r"\n---\n", "\n", testo)
    # (il capitolo 1 resta nella pagina dell'introduzione)
    testo = re.sub(r"\n## ([2-9]\.|1\d\.)", r'\n<div style="page-break-before:always"></div>\n\n## \1', testo)
    return testo, versione


def main():
    testo, versione = prepara(SRC.read_text(encoding="utf-8"))
    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)
        md = tmp / "manuale.md"
        md.write_text(testo, encoding="utf-8")
        (tmp / "stile.css").write_text(CSS, encoding="utf-8")
        copertina = tmp / "copertina.html"
        copertina.write_text(f"<div class='copertina'><div class='t'>{TITOLO}</div><div class='s'>{SOTTOTITOLO}</div>"
                             f"<div class='v'>Versione {versione}</div><div class='c'>{COPYRIGHT}</div></div>",
                             encoding="utf-8")
        html = tmp / "manuale.html"
        subprocess.run(["pandoc", str(md), "-o", str(html), "--standalone", "--embed-resources",
                        "--from", "markdown+pipe_tables-yaml_metadata_block",
                        "--resource-path", str(DIR), "--no-highlight", "--toc", "--toc-depth=3", "--css", str(tmp / "stile.css"),
                        "-M", f"pagetitle={TITOLO} — {SOTTOTITOLO}", "-M", "lang=it-IT",
                        "-B", str(copertina)], check=True)
        with sync_playwright() as p:
            b = p.chromium.launch()
            page = b.new_page()
            page.goto(html.as_uri())
            page.wait_for_timeout(1000)
            page.pdf(path=str(DIR / "manuale.pdf"), format="A4", print_background=True,
                     display_header_footer=True,
                     header_template="<span></span>",
                     footer_template=("<div style='font-size:8pt;color:#777;width:100%;padding:0 16mm;"
                                      "display:flex;justify-content:space-between'>"
                                      f"<span>{TITOLO} — {SOTTOTITOLO} v{versione}</span>"
                                      "<span>Pagina <span class='pageNumber'></span> di <span class='totalPages'></span></span></div>"),
                     margin={"top": "18mm", "bottom": "20mm", "left": "16mm", "right": "16mm"})
            b.close()
    print("creato", DIR / "manuale.pdf")


if __name__ == "__main__":
    main()
