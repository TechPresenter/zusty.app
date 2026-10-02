# Installation Guide

Two paths: **local development** and **Hostinger shared hosting**. Both use the same
installer.

---

## Requirements

| | Minimum | Recommended |
|---|---|---|
| PHP | 8.1 | 8.3 |
| MySQL / MariaDB | 5.7 / 10.3 | 8.0 / 10.6 |
| Disk | 500 MB | 2 GB+ (uploads and backups grow) |

**Required PHP extensions** — the installer and `deploy.sh` both refuse to continue
without these:

`pdo_mysql` · `mbstring` · `openssl` · `json` · `curl`

**Optional, and what you lose without each:**

| Extension | Without it |
|---|---|
| `zip` | Excel export falls back to CSV |
| `gd` or `imagick` | No image resizing or WebP conversion on upload |
| `apcu` | Cache falls back to the database table (slower, still correct) |

No Composer. No Node. No build step.

---

## Local installation

### 1. Get the code

Place the repository so that `backend/public` is the web root. With the built-in
server this is handled for you.

### 2. Configure the environment

```bash
cd backend
cp .env.example .env
```

Open `.env` and set, at minimum:

```ini
APP_URL=http://localhost:8000
DB_NAME=zuste_marketplace
DB_USER=root
DB_PASSWORD=

JWT_SECRET=            # see below
APP_ENCRYPTION_KEY=    # see below
```

Generate the two keys — **do not reuse the same value for both**, and never commit
them:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # JWT_SECRET
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # APP_ENCRYPTION_KEY
```

> **`APP_ENCRYPTION_KEY` is not rotatable in place.** It encrypts KYC documents,
> bank details, 2FA secrets and every stored API key. Changing it makes all of that
> unreadable. Set it once, back it up with the database, and keep them together.

Every key is documented in [ENVIRONMENT.md](ENVIRONMENT.md).

### 3. Create the database

```sql
CREATE DATABASE zuste_marketplace
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 4. Run the installer

```bash
php scripts/install.php
```

It will, in order:

1. Check the PHP version and extensions
2. Verify it can connect to the database
3. Apply all 16 migrations (tracked in the `migrations` table, so it is safe to re-run)
4. Load the 7 seed files — roles, permissions, settings, categories, CMS content,
   notification templates, AI provider catalogue, KYC requirements, taxonomies
5. Prompt for the first **Super Admin** account
6. Create the storage directories and print a cron secret

Useful flags:

```bash
php scripts/install.php --migrate-only     # schema only, no seeds
php scripts/install.php --seed-only        # re-run seeds (idempotent)
php scripts/install.php --no-interaction   # CI; skips the admin prompt
php scripts/install.php --fresh            # DROP everything and rebuild
php scripts/install.php --reseal=011       # re-record one migration's checksum
```

`--fresh` destroys all data. It exists for development only.

### "… has changed since it was applied — skipping"

Someone edited a migration file after it had already run. The installer refuses
to re-run it, which is correct — replaying a statement could apply it twice.

Work out which case you are in:

1. **The edit was cosmetic** (a comment, whitespace, a reworded `COMMENT`). The
   live schema already matches the file. Confirm that by comparing the file's
   columns against `information_schema`, then clear the warning:

   ```bash
   php scripts/install.php --reseal=011
   ```

   `--reseal` **applies nothing**. It only re-records the checksum, so it cannot
   create or alter a table — which is why you, not it, must confirm the schema
   first. It prints both checksums and asks for confirmation.

2. **The edit added real DDL.** The database is genuinely behind. Do **not**
   reseal — add a **new** migration with the change and let it apply normally.

> Either way, the lesson holds: **never edit an applied migration.** Add a new
> one. An applied file is a record of what ran, not a document to keep current.

### 5. Serve

```bash
php -S localhost:8000 -t public scripts/serve.php
```

`scripts/serve.php` exists because PHP's built-in server ignores `.htaccess`. It
reproduces the same URL rewriting and path blocking, so local behaviour matches
production. Do not serve `public/` directly without it or `/admin` will 404.

**One caveat with the built-in server: it handles one request at a time.** Anything
that makes the app call itself over HTTP — the Developer REST tester, for example —
will time out locally and works normally on real hosting. The panel detects this and
tells you rather than showing a bare error.

### Verify

```bash
curl -s http://localhost:8000/api/v1/health
php scripts/cron.php --list        # lists every registered job
```

Then sign in at <http://localhost:8000/admin> with the account you just created.

---

## Hostinger installation

### 1. Upload

Upload the repository so the structure is:

```
~/domains/yourdomain.com/
├── backend/          ← the code (app, config, database, routes, scripts, storage)
└── public_html/      ← must point at backend/public
```

In hPanel, set the domain's **document root to `backend/public`**. If you cannot
change it, copy the contents of `backend/public` into `public_html` and edit the two
`require` paths at the top of `index.php` and `admin/index.php` to point at `backend/`.

The application refuses to serve anything outside `public/`, and `.htaccess` blocks
direct access to `app/`, `config/`, `database/` and `storage/`. Verify after deploying:

```bash
curl -I https://yourdomain.com/app/Core/Database.php   # must be 403 or 404
```

### 2. Create the database

hPanel → **Databases → MySQL Databases**. Create a database and user, grant all
privileges, and note the values — Hostinger prefixes both with your account name.

### 3. Configure

```bash
cd ~/domains/yourdomain.com/backend
cp .env.example .env
nano .env
```

Production values that differ from local:

```ini
APP_ENV=production
APP_DEBUG=false                  # deploy.sh refuses to run if this is true in production
APP_URL=https://yourdomain.com
SESSION_SECURE=true              # requires HTTPS
MYSQLDUMP_PATH=/usr/bin/mysqldump
```

`APP_DEBUG=true` in production leaks stack traces containing file paths and queries.
The deploy script blocks it deliberately.

### 4. Install

```bash
php8.3 scripts/install.php
```

Use the versioned binary — Hostinger's default `php` may be an older release.

### 5. Set permissions

```bash
chmod 600 .env
chmod -R 775 storage public/uploads
```

`.env` holds database and payment credentials. On shared hosting, `600` matters.

### 6. Register the cron jobs

hPanel → **Advanced → Cron Jobs**. The simplest setup is **one** entry, run every minute
(`* * * * *`), with the command:

```
/opt/alt/php83/usr/bin/php /home/USER/domains/DOMAIN/backend/scripts/cron.php --due
```

`--due` starts each job when its schedule (System → Jobs) comes round, catches up
minutes a late run skipped, and never runs a job twice for the same minute. Hostinger
has no `crontab` command over SSH, so this saves entering every job by hand.

Alternatively, add every job `php scripts/cron.php --list` reports as its own entry:

| Schedule | Command |
|---|---|
| `* * * * *` | `php8.3 ~/domains/DOMAIN/backend/scripts/cron.php queue:dispatch` |
| `*/5 * * * *` | `… cron.php marketing:dispatch` |
| `*/5 * * * *` | `… cron.php automation:run` |
| `*/15 * * * *` | `… cron.php bookings:expire` |
| `*/30 * * * *` | `… cron.php integrations:sync` |
| `0 * * * *` | `… cron.php bookings:autocomplete` |
| `0 * * * *` | `… cron.php escrow:release` |
| `15 * * * *` | `… cron.php privacy:purge-exports` |
| `10 0 * * *` | `… cron.php analytics:rollup` |
| `30 0 * * *` | `… cron.php creators:refresh` |
| `20 1 * * *` | `… cron.php kyc:expiry` |
| `40 1 * * *` | `… cron.php kyc:retention` |
| `0 2 * * *` | `… cron.php backup:database` |
| `0 3 * * *` | `… cron.php tokens:prune` |
| `0 4 * * 0` | `… cron.php logs:rotate` |

These match the schedules **System → Jobs** shows. Take the list from
`cron.php --list` rather than from here — jobs are added as modules land, and a job
with no crontab entry fails silently.

`queue:dispatch` sends queued email, SMS and push. Without it the platform accepts
bookings and sends nothing.

If CLI cron is unavailable, the installer prints an HTTP fallback URL carrying
`CRON_SECRET`. Use it only as a last resort — the secret travels in the query string.

### 7. Enable SSL

hPanel → **Security → SSL**. Install the free certificate and force HTTPS. Then set
`SESSION_SECURE=true`, or session cookies will be sent over plain HTTP.

---

## After installing

1. **Settings → General** — site name, currency, timezone, contact details
2. **Settings → Payments** — Razorpay or Stripe keys; Cashfree lives in Integrations
3. **Integrations → SMTP** — email credentials, then *Test connection*
4. **Integrations** — connect whichever of the 54 services you use
5. **AI → Providers** — paste a key if you want the AI features
6. **Access → Admin Users** — create real accounts; give each person the narrowest role
7. **Security → Two-factor** — turn it on for your own account first
8. **System → Backups** — take one manually and confirm it downloads

---

## Troubleshooting

**Blank page or 500** — check `storage/logs/app-YYYY-MM-DD.log`. Set `APP_DEBUG=true`
temporarily *on a non-production install* for the full trace.

**`/admin` returns 404** — the document root is not `backend/public`, or you are
serving `public/` without `scripts/serve.php` locally.

**"Database connection failed"** — confirm credentials, and remember Hostinger
prefixes database and user names. Test with `php scripts/deploy_helper.php check`.

**Sign-in says the account is locked** — five failed attempts lock it for 15 minutes
(both configurable under Settings → Security). Wait, or clear `locked_until` for that
row in `admins`.

**Nothing is being emailed** — check that `queue:dispatch` is registered and running.
System → Jobs shows the last run of every job.

**Migration fails partway** — the ledger records what applied. Fix the cause and
re-run `install.php --migrate-only`; completed migrations are skipped.
