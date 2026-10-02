# Deployment Guide

Target: **Hostinger business shared hosting** — SSH, PHP 8.3, MySQL, free SSL, cron.
No root, no systemd, no Composer. Everything below works within those limits.

---

## The deploy script

```bash
cd ~/domains/yourdomain.com/backend
bash scripts/deploy.sh
```

It runs, in order:

1. **Preflight** — PHP version floor, required extensions, `.env` completeness,
   database reachability. **Refuses to continue if `APP_DEBUG=true` while
   `APP_ENV=production`.**
2. **Lint gate** — parses every PHP file. One syntax error and nothing is deployed.
3. **Backup** — a full dump before the database is touched.
4. **Maintenance on** — the API returns `503` with your message; `/health` stays up.
5. **Migrate** — pending migrations only. **On failure it restores the backup
   automatically** and aborts.
6. **Maintenance off** — via an `EXIT` trap, so a crash or a `Ctrl-C` can never strand
   the site behind the maintenance page.
7. **Permissions** — 755 directories, 644 files, 775 on writable paths, 600 on `.env`.
8. **Caches** — cleared and warmed; entry points touched to invalidate OPcache.
9. **Prune** — backups past the retention window.
10. **Verify** — requests `/api/v1/health` and reports the result.

### Options

```bash
bash scripts/deploy.sh --dry-run       # show every step, change nothing
bash scripts/deploy.sh --skip-backup   # not advised
bash scripts/deploy.sh --rollback      # restore the pre-deploy database backup
```

Always run `--dry-run` first on a host you have not deployed to before.

Every run appends to `storage/logs/deploy.log`.

---

## First deployment

1. Complete [INSTALLATION.md](INSTALLATION.md) — code uploaded, document root set to
   `backend/public`, `.env` filled in, `install.php` run, SSL enabled.

   Run `install.php` **without** `--with-demo`. The demo dataset in
   `database/seeds/demo/` creates 65 active, email-verified accounts that all share one
   password, and that password is printed in the seed file — anyone reading the repository
   could sign in as them. `--with-demo` is for local work only, and the installer refuses
   it outright when `APP_ENV=production`.

2. Register the cron jobs (below).
3. Confirm the security posture:

```bash
curl -I https://yourdomain.com/app/Core/Database.php    # 403 or 404
curl -I https://yourdomain.com/.env                     # 403 or 404
curl -s  https://yourdomain.com/api/v1/health           # {"success":true,…}
php backend/scripts/preflight.php --url=https://yourdomain.com
```

   `preflight.php` fails outright if any account is still using the demo seed password,
   which covers a site installed before `--with-demo` existed.

4. Sign in, enable two-factor on your own account, take a manual backup and confirm it
   downloads.

## Subsequent deployments

```bash
cd ~/domains/yourdomain.com/backend
git pull                      # or upload changed files
bash scripts/deploy.sh
```

---

## Cron jobs

hPanel → **Advanced → Cron Jobs**. Either one entry every minute running
`php scripts/cron.php --due`, which starts each job below on its schedule (as stored
under System → Jobs), or one entry per job. Every job `php scripts/cron.php --list` reports:

| Schedule | Job | Does |
|---|---|---|
| `* * * * *` | `queue:dispatch` | Sends queued email, SMS and push |
| `*/5 * * * *` | `automation:run` | Evaluates active automation rules and performs their actions |
| `*/5 * * * *` | `marketing:dispatch` | Queues and sends scheduled and in-flight marketing campaigns |
| `*/15 * * * *` | `bookings:expire` | Expires unanswered requests and unpaid acceptances |
| `*/30 * * * *` | `integrations:sync` | Runs integrations whose auto-sync is due |
| `0 * * * *` | `bookings:autocomplete` | Completes delivered bookings the brand did not action |
| `0 * * * *` | `escrow:release` | Moves matured earnings from locked to withdrawable |
| `15 * * * *` | `privacy:purge-exports` | Deletes personal-data export archives whose download link has expired |
| `10 0 * * *` | `analytics:rollup` | Rebuilds the daily analytics and settlement tables |
| `30 0 * * *` | `creators:refresh` | Recomputes ratings, counts and category tallies |
| `45 0 * * *` | `creators:badges` | Expires lapsed creator badges and re-syncs the KYC-derived ones |
| `55 0 * * *` | `creators:completion` | Re-scores every creator profile's completeness, stalest first |
| `20 1 * * *` | `kyc:expiry` | Clears verification (and the blue tick) on lapsed KYC, warns those approaching expiry at most weekly |
| `40 1 * * *` | `kyc:retention` | Deletes KYC documents and identity details of rejected / abandoned / closed profiles and never-submitted drafts, replaced versions, and orphaned encrypted files |
| `0 2 * * *` | `backup:database` | Nightly dump |
| `0 3 * * *` | `tokens:prune` | Deletes expired tokens, OTPs and rate-limit buckets |
| `0 4 * * *` | `auth:purge-guests` | Deletes expired guest sessions and their guest accounts |
| `0 4 * * 0` | `logs:rotate` | Weekly log rotation |

These are the same schedules **System → Jobs** displays, seeded in `scheduled_jobs`.
Keep the crontab and that table in agreement — an operator debugging a missed job reads
the panel, not your crontab.

Command form:

```
php8.3 /home/USER/domains/DOMAIN/backend/scripts/cron.php JOB
```

Use the versioned binary. Use absolute paths — cron does not inherit your shell.

> **`queue:dispatch` is the one that must not be missed.** Without it the platform
> accepts bookings, records payments and queues every notification — and sends none of
> them. Nothing looks broken from the admin panel.

Jobs are file-locked, so a long run never overlaps the next tick. Each records its
duration and outcome, visible under **System → Jobs**, where any job can also be run
manually.

Verify:

```bash
php8.3 scripts/cron.php --list          # every registered job
php8.3 scripts/cron.php queue:dispatch  # run one now
```

---

## Backups

Nightly via `backup:database`. The operational tool is `scripts/backup.sh`:

```bash
bash scripts/backup.sh                    # database only
bash scripts/backup.sh --files            # + public/uploads
bash scripts/backup.sh --verify           # integrity check
bash scripts/backup.sh --list
bash scripts/backup.sh --prune
```

Design decisions worth knowing:

- `--single-transaction` — a consistent snapshot without locking writers, so it is
  safe against the live site.
- Credentials go through a `600`-mode temporary defaults file, **never the command
  line** — arguments are visible to every user on a shared host via `ps`.
- The dump is written to a `.partial` name, integrity-checked, then renamed. A cron
  that fires mid-write never leaves a half file that looks valid.
- MariaDB compatibility: `--set-gtid-purged` is MySQL-only. The script detects the
  rejection and retries without it rather than failing the nightly backup.

### Off-site copies

Backups on the same server do not survive losing the server. Either enable the
**Cloud backup copies** integration to push each dump to S3, or pull them down on a
schedule:

```bash
rsync -avz user@host:~/domains/DOMAIN/backend/storage/backups/ ./backups/
```

> **Store `APP_ENCRYPTION_KEY` with your backups.** Without it a restored database
> yields KYC records, bank details, 2FA secrets and API keys you cannot decrypt.

### Restore

```bash
php8.3 scripts/deploy_helper.php restore storage/backups/DUMP.sql.gz DATABASE_NAME
```

The database name is required as confirmation, so a restore cannot be run by pressing
up-arrow and enter.

---

## Maintenance mode

Backed by the `general.maintenance_mode` **setting**, read by `MaintenanceMiddleware`
on every API request — not a flag file.

```bash
php8.3 scripts/deploy_helper.php maintenance:on
php8.3 scripts/deploy_helper.php maintenance:off
php8.3 scripts/deploy_helper.php maintenance:status
```

Also toggleable in **Settings → General**, where the message shown to users is edited.

While on: the API returns `503` with `code: "maintenance"`, `/health` and
`/app/config` stay reachable so the apps can display your message, and the admin panel
stays fully usable.

---

## Rollback

**Code** — redeploy the previous commit:

```bash
git checkout <previous-tag>
bash scripts/deploy.sh
```

**Database** — restore the pre-deploy backup:

```bash
bash scripts/deploy.sh --rollback
```

This asks for the database name before overwriting anything.

> Migrations are **forward-only**. There is no `down()`. Rolling back a schema change
> means restoring the backup taken before it — which is exactly why the deploy script
> takes one and refuses to proceed if it fails.

---

## Monitoring

**System → Health** — database, cache, queue depth, disk, cron freshness.
**System → Logs** — application and system logs, filterable.
**Developer → API logs** — every API request with duration, query count and memory.
**Developer → Query monitor** — slow queries collapsed by shape, with occurrence
counts. Threshold under Settings → Security.
**Integrations → Uptime** — connect an external monitor pointed at `/api/v1/health`.

Configure `sentry` under Integrations for exception alerting.

---

## Performance on shared hosting

Already built in: two-tier caching (in-process, then a database table, with APCu when
present), analytics served from roll-up tables rather than raw rows, indexes on every
list-screen filter, and a dashboard that loads its 105 widgets through one batched
AJAX call rather than 105 requests.

If the panel feels slow, look at **Developer → Query monitor** first. It shows the
query shapes that actually cost time, which is almost always more useful than
guessing.

---

## Security checklist before launch

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] SSL installed, HTTPS forced, `SESSION_SECURE=true`
- [ ] `.env` is `chmod 600`
- [ ] Source files return 403/404 over HTTP
- [ ] `CORS_ALLOWED_ORIGINS` lists real origins, no `*`
- [ ] Two-factor enabled on every admin account
- [ ] Each admin holds the narrowest role that lets them work
- [ ] Payment webhook secrets set
- [ ] Firebase credentials JSON outside the web root
- [ ] A backup has been taken **and a restore rehearsed**
- [ ] `APP_ENCRYPTION_KEY` backed up with the database
- [ ] Every job from `cron.php --list` registered and confirmed running
- [ ] `https://<domain>/privacy` and `https://<domain>/account-deletion` load over
      HTTPS. The Google Play listing links to both, and the app's sign-up screen
      links to `/terms` and `/privacy`.
