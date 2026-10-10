# Environment Configuration

Every key in `.env`, what it controls, and what breaks if it is wrong.

`.env` is read once per request by `Zuste\Core\Env` and exposed through
`Zuste\Core\Config`. It is **not** a PHP file and is never executed — values are
parsed as plain text, so no key can run code.

> Runtime settings — payment keys, SMTP, feature toggles — mostly live in the
> `settings` **table**, edited in the admin panel, not here. `.env` holds only what
> the application needs *before* it can reach the database.

---

## Application

| Key | Example | Notes |
|---|---|---|
| `APP_NAME` | `"Zuste+"` | Shown in emails, invoices and the admin header. |
| `APP_ENV` | `production` | `local`, `staging` or `production`. Production tightens error handling. |
| `APP_DEBUG` | `false` | **Never `true` in production.** Exposes stack traces with file paths and SQL. `deploy.sh` refuses to deploy if this is `true` while `APP_ENV=production`. |
| `APP_URL` | `https://zusty.app` | Canonical base URL. Used for the website's canonical and share links, sitemap links, webhook callback URLs, email links and the deploy health check. Taken from configuration, never from the request `Host` header — a spoofed header would otherwise poison generated URLs. Settings → SEO → canonical base only overrides it for the website when it is an `https://` URL on this same host. |
| `API_URL` | `https://zusty.app/api` | Advertised to the apps via `/api/v1/app/config`. |
| `ADMIN_URL` | `https://zusty.app/admin` | Used in admin notification emails. |
| `APP_TIMEZONE` | `Asia/Kolkata` | Every timestamp is stored and displayed in this zone. Changing it later does **not** convert existing rows. |
| `APP_LOCALE` | `en` | Default language. |

---

## Database

| Key | Example | Notes |
|---|---|---|
| `DB_HOST` | `localhost` | |
| `DB_PORT` | `3306` | |
| `DB_NAME` | `zuste_marketplace` | Hostinger prefixes this with your account name. |
| `DB_USER` | `zuste_user` | Also prefixed on Hostinger. |
| `DB_PASSWORD` | | |
| `DB_CHARSET` | `utf8mb4` | Do not change. `utf8` (3-byte) cannot store emoji, which appear in creator bios and messages. |
| `DB_SOCKET` | | Set only when connecting over a Unix socket instead of TCP. Takes precedence over host and port. |

PDO runs with `EMULATE_PREPARES=false`, so prepared statements are genuine
server-side statements.

---

## Authentication and encryption

| Key | Notes |
|---|---|
| `JWT_SECRET` | Signs API access and refresh tokens (HS256). Generate with `php -r "echo bin2hex(random_bytes(32));"`. **Changing it signs every user out.** |
| `JWT_ISSUER` | The `iss` claim. |
| `JWT_AUDIENCE` | The `aud` claim. Both are verified, so mismatched values reject every token. |
| `JWT_ACCESS_TTL` | Seconds. Default `3600` (1 hour). |
| `JWT_REFRESH_TTL` | Seconds. Default `2592000` (30 days). Refresh tokens rotate on use, and reuse of a spent token revokes the whole family. |
| `APP_ENCRYPTION_KEY` | AES-256-GCM key for KYC documents, bank accounts, admin 2FA secrets, integration credentials and AI provider keys. |

> ### The encryption key cannot be rotated in place
>
> There is no re-encryption routine. Changing `APP_ENCRYPTION_KEY` makes every
> encrypted value permanently unreadable — KYC records, bank details, 2FA secrets and
> every stored API key.
>
> Generate it once, before the first install. Back it up **with** the database, not
> separately: a database restore without the matching key recovers rows you cannot
> decrypt. Use two different random values for `JWT_SECRET` and this key.

---

## Sessions (admin panel)

| Key | Example | Notes |
|---|---|---|
| `SESSION_NAME` | `zuste_admin_session` | Cookie name. |
| `SESSION_LIFETIME` | `7200` | Seconds of idle time before sign-out. |
| `SESSION_SECURE` | `true` | Cookie only sent over HTTPS. **Set `false` for local HTTP or you cannot sign in**, and `true` everywhere else. |
| `SESSION_SAMESITE` | `Lax` | `Lax` is correct here. `None` requires `Secure` and weakens CSRF defence. |

Sessions are also fingerprinted and idle-timed server-side, and every session is
listed under Security → Sessions where it can be revoked.

---

## CORS

| Key | Notes |
|---|---|
| `CORS_ALLOWED_ORIGINS` | Comma-separated exact origins allowed to call the API from a browser. |

List real origins only. `*` would let any site make credentialed requests on behalf of
a signed-in user. Native apps are unaffected — CORS is a browser mechanism.

---

## Storage and uploads

| Key | Example | Notes |
|---|---|---|
| `STORAGE_DRIVER` | `local` | `local` or `firebase`. |
| `UPLOAD_PATH` | `public/uploads` | Must stay under `public/` to be served. |
| `UPLOAD_URL` | `/uploads` | Public prefix matching the path above. |
| `STORAGE_PATH` | `storage` | Logs, cache, backups, temp. Must **not** be publicly reachable. |
| `LOG_CHANNEL` | `daily` | `daily` (rotated files), `single`, or `database`. |

Uploads are validated by real MIME type and extension, never by the client-supplied
name, and stored under generated names.

---

## Firebase (optional)

| Key | Notes |
|---|---|
| `FIREBASE_PROJECT_ID` | |
| `FIREBASE_WEB_API_KEY` | |
| `FIREBASE_CREDENTIALS` | Path to the service account JSON. Keep it **outside** the web root — the default `../firebase-service-account.json` does exactly that. |
| `FIREBASE_STORAGE_BUCKET` | |

Leave blank to run without Firebase. Email/password and phone OTP sign-in continue to
work; Google and Apple sign-in and push notifications do not.

---

## Payments

| Key | Notes |
|---|---|
| `RAZORPAY_KEY_ID` / `RAZORPAY_KEY_SECRET` / `RAZORPAY_WEBHOOK_SECRET` | |
| `STRIPE_PUBLISHABLE_KEY` / `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` | |

These can also be set in **Settings → Payments**, which is the preferred place — the
database value wins so keys can be rotated without a deploy. The webhook secrets are
mandatory when webhooks are enabled: an unverified webhook is an unauthenticated
request that can mark a booking paid.

Cashfree is configured under **Integrations**.

---

## Mail

| Key | Example |
|---|---|
| `MAIL_DRIVER` | `smtp` (`smtp`, `mail`, `log`) |
| `MAIL_HOST` | `smtp.hostinger.com` |
| `MAIL_PORT` | `465` |
| `MAIL_ENCRYPTION` | `ssl` (`ssl`, `tls`, `none`) |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | |

Use port `465` with `ssl`, or `587` with `tls`. Mixing them silently fails to connect.

`MAIL_DRIVER=log` writes messages to the log instead of sending — useful in
development. These values are mirrored from **Integrations → SMTP**, which is the
easier place to edit and offers a connection test.

---

## SMS

| Key | Example |
|---|---|
| `SMS_ENABLED` | `false` |
| `SMS_PROVIDER` | `msg91` (`msg91`, `twilio`, `textlocal`) |
| `SMS_API_KEY` | |
| `SMS_SENDER_ID` | `ZUSTEP` — 6 characters, DLT registered for India |
| `SMS_OTP_TEMPLATE_ID` | Required for DLT compliance in India |

With `SMS_ENABLED=false`, OTPs are written to the log rather than sent — fine for
development, useless in production.

---

## Cron and backups

| Key | Example | Notes |
|---|---|---|
| `CRON_SECRET` | | Only for the HTTP cron fallback. Generate a long random value. Prefer real CLI cron: this secret travels in the query string and ends up in access logs. |
| `BACKUP_PATH` | `storage/backups` | Relative to the project root, or absolute. |
| `BACKUP_RETENTION_DAYS` | `14` | Dumps older than this are pruned. |
| `MYSQLDUMP_PATH` | `mysqldump` | Full path if it is not on `PATH`. Hostinger: `/usr/bin/mysqldump`. |

`backup.sh` also honours any of these as an exported shell variable, so a cron entry
can override one without editing `.env`.

---

## Environment checklist before going live

- [ ] `APP_ENV=production` and `APP_DEBUG=false`
- [ ] `APP_URL` is the real https URL
- [ ] `JWT_SECRET` and `APP_ENCRYPTION_KEY` are set, different, and 64 hex characters
- [ ] `APP_ENCRYPTION_KEY` is backed up alongside the database
- [ ] `SESSION_SECURE=true` and SSL is installed
- [ ] `CORS_ALLOWED_ORIGINS` lists real origins, no `*`
- [ ] `.env` is `chmod 600` and not in version control
- [ ] Firebase credentials JSON is outside the web root
- [ ] Payment webhook secrets are set
- [ ] `MYSQLDUMP_PATH` is correct — confirm with `bash scripts/backup.sh --verify`
