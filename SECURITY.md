# Security / Sicurezza

**Please do not report vulnerabilities in public issues.** Use GitHub's private
reporting: *Security › Report a vulnerability* on this repository.

**Non segnalare le vulnerabilità nelle issue pubbliche.** Usa la segnalazione
privata di GitHub: *Security › Report a vulnerability* in questo repository.

## What is in place / Cosa c'è già

- Email content is untrusted: the HTML body is shown in a sandboxed iframe
  without scripts; subject, sender and attachment names are escaped; HTML
  quoted in replies and forwards is sanitized with DOMPurify.
- Session cookie `HttpOnly`, `SameSite=Lax` (`Secure` over HTTPS), new session
  id at login, 30 minutes of inactivity.
- Actions (POST) coming from other sites are refused (`Sec-Fetch-Site` /
  `Origin`); pages cannot be framed by other sites.
- Login throttling: 10 failures in 15 minutes from one IP, or 30 on one user.
- IMAP/SMTP passwords encrypted with AES-256-GCM, key outside the database.
- The "Gestione caselle" page has its own login (scrypt), CSRF token,
  lockout after 5 failures, and should stay on the local network.
