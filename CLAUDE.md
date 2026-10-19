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
