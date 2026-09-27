# Zmail Secure Edition

This build keeps the original Zmail System behavior but adds security hardening.

## Important
- Mail account passwords/app passwords are encrypted with AES-256-GCM before storage.
- A random application key is created at `data/.app_key` on first use. Keep it private and back it up separately.
- You can set `ZMAIL_APP_KEY` as a base64-encoded 32-byte secret instead; this is preferable when your host supports environment variables.
- Set `ZMAIL_DATA_DIR` to a directory outside the public web root when your hosting supports it.
- HTTPS is strongly recommended.
- Management POST requests use CSRF tokens.
- Mutating GET links for accounts/rules were converted to POST.
- Mail HTML is displayed inside a sandboxed iframe without same-origin access.
- Attachments are served as downloads and their client-supplied MIME type is ignored.

## Existing database migration
If you upgrade an existing installation, the first authenticated database access automatically converts legacy plaintext account passwords to encrypted values. Do not delete `data/.app_key` after migration.

## Cron
Run `fetch_mail.php` from CLI, e.g.:
`* * * * * php /path/to/Zmail-system/fetch_mail.php`

## External forwarding
Telegram, Server酱 and Webhook forwarding remain available. Webhook destinations are administrator-controlled; only configure trusted HTTPS endpoints.

## Backup
Back up both the SQLite databases and the application key. Without the key, encrypted IMAP credentials cannot be recovered.
