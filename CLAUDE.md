# CLAUDE.md

Indicazioni per Claude Code (claude.ai/code) su questo repository.

## Licenza: intestazione obbligatoria

PiGreco Posta è sotto **AGPL-3.0-or-later**, titolare **Criscione Enzo** (file `LICENSE`). Ogni file sorgente nostro (php, js, css, py, sh, sql, Dockerfile, conf, ini, yml) inizia con l'intestazione lunga della licenza, anche i file nuovi:

```
PiGreco Posta
Copyright (C) 2026 Criscione Enzo
SPDX-License-Identifier: AGPL-3.0-or-later

Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
secondo i termini della GNU Affero General Public License come pubblicata
dalla Free Software Foundation, versione 3 della licenza o (a tua scelta)
qualsiasi versione successiva.

Questo programma è distribuito nella speranza che sia utile, ma SENZA
ALCUNA GARANZIA; senza neppure la garanzia implicita di COMMERCIABILITÀ
o di IDONEITÀ PER UN PARTICOLARE SCOPO. Vedi la GNU Affero General
Public License per i dettagli.

Dovresti aver ricevuto una copia della GNU Affero General Public License
insieme a questo programma (file LICENSE). In caso contrario, vedi
<https://www.gnu.org/licenses/>.
```

- Commento del linguaggio: `/* … */` con ` * ` per php/js/css (in php subito dopo `<?php`), `#` per sh/py/yml/conf/Dockerfile (dopo lo shebang e l'eventuale riga `coding` di Python), `;` per ini, `--` per sql.
- Niente intestazione nei componenti di terzi (`webmail/phpmailer/`, `webmail/fontawesome/`, `webmail/vendor/`, vedi `THIRD-PARTY.md`), né in json/md/file di esempio.
- L'anno resta quello della prima pubblicazione (2026).

## Collegamento con PiGreco Archivio

Archivio e Posta sono due programmi separati, ognuno funziona da solo. Installati sullo stesso server si parlano sulla rete Docker `pigreco`: la dichiarano **tutti e due** con `name: pigreco` e la chiave `pigreco` (non `external`, mai creata a mano con `docker network create`, che compose poi rifiuta), così funziona in qualsiasi ordine di installazione. L'Archivio pubblica la webmail su `:8081/webmail/` con un proxy verso l'alias fisso **`pigreco-webmail`** e aggiunge a ogni richiesta `X-PiGreco-Archivio: /`: solo le pagine arrivate da lì accendono Archivio/Archivia/Contatti (`$archivioUrl` in `webmail/index.php`, unico punto dove si decide; si accetta solo `/`). `ARCHIVIO_URL` nel `.env` resta per forzarlo sempre. La porta della webmail si chiude alla rete con `WEBMAIL_ASCOLTO=127.0.0.1` (lo propone l'installatore dell'Archivio). Piano completo nel repo dell'Archivio, `servizio/PIANO-ARCHIVIO-POSTA.md`.

## Versione e informazioni

La versione è in due punti da cambiare insieme: `webmail/lib/info.php` (`PIGRECO_POSTA_VERSIONE`, con autore e indirizzo del codice sorgente) e `posta/script/gestione.py` (`VERSIONE`, `SORGENTE`). Si vede nella finestra **Informazioni** della webmail (menu utente, `#informazioniModal` in `index.php`), in fondo alla pagina di login e alla pagina Gestione caselle: copyright, licenza, nessuna garanzia e link al sorgente sono richiesti dalla AGPL (§5 d, §13), non toglierli. Ogni versione pubblicata ha un tag `vX.Y` e una release su GitHub.

## Manuale

`manuale/manuale.md` è il testo; `manuale/manuale.pdf` si rigenera dopo ogni modifica con `manuale/genera_pdf.py` (pandoc + Playwright/Chromium; sul PC di sviluppo `~/venv-manuale/bin/python manuale/genera_pdf.py`) e va nello stesso commit. La versione in copertina e a piè di pagina viene dalla riga `Versione …` in testa al manuale.
