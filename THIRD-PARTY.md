# Third-party components / Componenti di terzi

PiGreco Posta is licensed under the AGPL-3.0 (see [LICENSE](LICENSE)). It bundles or uses the following components, each under its own license.

## Bundled in this repository

| Component | Path | License |
|---|---|---|
| [PHPMailer](https://github.com/PHPMailer/PHPMailer) | `webmail/phpmailer/` (without the `get_oauth_token.php` example) | LGPL-2.1 (`webmail/phpmailer/LICENSE`) |
| [Font Awesome Free 6.4.0](https://fontawesome.com) | `webmail/fontawesome/` | Icons CC BY 4.0, fonts SIL OFL 1.1, code MIT ([details](https://fontawesome.com/license/free)) |
| [DOMPurify 3.2.4](https://github.com/cure53/DOMPurify) | `webmail/vendor/dompurify/` | Apache-2.0 or MPL-2.0 (`webmail/vendor/dompurify/LICENSE`) |

## Installed in the Docker images (not redistributed in this repository)

| Component | Image | License |
|---|---|---|
| PHP, Apache httpd | `php:8.3-apache` | PHP License 3.01, Apache 2.0 |
| MySQL Community Server | `mysql:8.0` | GPL-2.0 |
| Dovecot | `debian:bookworm-slim` (`dovecot-imapd`) | MIT and LGPL-2.1 |
| isync / mbsync | `debian:bookworm-slim` (`isync`) | GPL-2.0-or-later |
| Python 3 | Debian package | PSF License |
