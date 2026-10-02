# Zusty

**The influencer marketplace where brands book verified creators.** A brand posts a
campaign or books a creator directly and pays up front. The money sits in escrow until
the brand approves the delivered work (or automatically 7 days after delivery, by
default). The platform earns a fee charged to the brand at checkout plus a commission
taken from the creator's payout.

This repository holds the whole platform: the PHP backend (REST API and admin panel)
and the Flutter app that brands and creators use on Android, iOS and the web.

## Repository layout

| Path | What it is |
| --- | --- |
| [`backend/`](backend/) | Core PHP 8 REST API, admin panel, cron jobs and public web pages. No framework, no Composer, no build step. |
| [`mobile/`](mobile/) | Flutter app for brands and creators (Android, iOS, web). |
| [`stitch_ui_screen_replication/`](stitch_ui_screen_replication/) | Reference screens for the UI, from the Stitch mockups. |
| [`Zuste_PRD.md`](Zuste_PRD.md), [`Zuste_SRS.md`](Zuste_SRS.md), [`Zuste_TRD.md`](Zuste_TRD.md), [`Zuste_Workflow.md`](Zuste_Workflow.md), [`Zuste_Complete_Feature_Documentation.docx`](Zuste_Complete_Feature_Documentation.docx) | Product requirements, software requirements specification, technical requirements, business workflows and the complete feature documentation. These are the August 2026 planning documents, written under the earlier "Zuste" name. Where they differ from the code, the code and `backend/docs/` win. |
| [`DESIGN.md`](DESIGN.md) | The original "Vibrant Influence" design system from the Stitch mockups: colour, type, spacing and shape tokens plus component guidance. The app's colours have changed since; the live tokens are in `mobile/lib/core/theme/`. |
| [`AUDIT_REPORT.md`](AUDIT_REPORT.md) | Architecture and feature audit (August 2026). |

## Run it locally

### Backend: API and admin panel

You need:

- PHP 8.2 or newer (8.3 recommended) with `pdo_mysql`, `mbstring`, `openssl`, `json`,
  `curl` and `fileinfo`. The installer still accepts 8.1, but `scripts/preflight.php`
  (the launch check) rejects anything below 8.2. `gd` is optional: it resizes and
  re-encodes uploaded images.
- MariaDB 10.3 or newer (10.6 recommended). MySQL is not supported: the migrations
  use MariaDB-only syntax such as `ALTER TABLE … ADD COLUMN IF NOT EXISTS`.

```bash
cd backend
cp .env.example .env
# In .env set APP_ENV=local, APP_DEBUG=true, SESSION_SECURE=false, APP_URL and DB_*.
# The template ships production values; the test suite fails with them.
# Create an empty utf8mb4 database named as in DB_NAME, then:
php scripts/install.php           # migrations, seed data, and the first Super Admin
php -S localhost:8000 -t public scripts/serve.php
```

| URL | What |
| --- | --- |
| <http://localhost:8000/api/v1/health> | API health check (the API lives under `/api/v1`) |
| <http://localhost:8000/admin> | Admin panel. Sign in with the Super Admin from the installer. |
| <http://localhost:8000/privacy>, `/terms`, `/account-deletion` | Public pages the app and the Play listing link to |

`scripts/serve.php` reproduces the production URL rewriting, so always serve through
it. On Windows, `localhost` can end up listening on IPv6 only. If the test runner, an
Android emulator or another tool can't connect, serve on `127.0.0.1:8000` instead.

Add `--with-demo` to the installer to load the demo dataset (65 sample accounts with
campaigns and bookings). It is refused when `APP_ENV=production`.

The installer generates `JWT_SECRET`, `APP_ENCRYPTION_KEY` and `CRON_SECRET` if you
leave them blank. Back up `APP_ENCRYPTION_KEY` together with the database: it encrypts
KYC documents, bank details, admin two-factor secrets and stored API keys (including
the Firebase service account). If it changes or is lost, all of that becomes
unreadable and every admin with two-factor sign-in is locked out. The full walkthrough,
including Hostinger, is in [backend/docs/INSTALLATION.md](backend/docs/INSTALLATION.md).
Most settings are explained in [ENVIRONMENT.md](backend/docs/ENVIRONMENT.md);
`TRUSTED_PROXIES` and the `CASHFREE_*` keys are described only in the comments in
`.env.example`.

### Mobile app

You need Flutter 3.41.7 or newer (Dart 3.11.5 or newer; `mobile/pubspec.yaml`
requires `sdk: ^3.11.5`).

```bash
cd mobile
flutter pub get
flutter run --dart-define=ZUSTE_API_ORIGIN=http://localhost:8000
```

- **Android emulator:** serve the API on `127.0.0.1:8000`, because adb's reverse
  tunnel reaches your machine over IPv4 only. Then run `adb reverse tcp:8000 tcp:8000`
  so that the emulator's `localhost:8000` reaches it.
- **Web:** run on a fixed port, for example `flutter run -d chrome --web-port=8080`,
  and add that exact origin (`http://localhost:8080`) to `CORS_ALLOWED_ORIGINS` in
  `backend/.env`. Origins must match exactly: scheme, host and port, and `localhost`
  and `127.0.0.1` count as different origins.

The app's settings are fixed at build time:

| Define | Default | Production |
| --- | --- | --- |
| `ZUSTE_API_ORIGIN` | `http://localhost:8000` | `https://zusty.app` |
| `ZUSTE_API_VERSION` | `v1` | `v1` |
| `ZUSTE_VERBOSE_NETWORK` | `true` (logs only in debug builds) | `false` |
| `ZUSTE_DEEP_LINK_HOST` | `zuste.app` | `zusty.app` (currently unused: nothing reads it; invite links come from the server's `referral.share_base_url` setting) |

Signed release builds for Google Play are covered in [mobile/README.md](mobile/README.md).

## Tests

Backend end-to-end suite. It needs the server running and a development database:

```bash
cd backend
php tests/run.php                                           # every suite
php tests/run.php privacy public_pages                      # named suites only
php tests/run.php --url=http://127.0.0.1:8001/api/v1 auth   # a server on another address
php tests/run.php --list                                    # the suite names
```

By default it targets `http://127.0.0.1:8000/api/v1`. It registers its own
`it-…@zuste.test` accounts and keeps them between runs so it can reuse them
(registration is rate-limited). Most other rows it creates are deleted when each suite
finishes. That makes it fine for a development database, but never run it against
production: `php scripts/preflight.php` reports leftover `it-` accounts.

Mobile:

```bash
cd mobile
flutter analyze
flutter test
```

Some of the Flutter tests need the API on `http://localhost:8000` with the demo data.
The "live backend" group in `test/qa_fix_contract_test.dart` signs in as a demo
account, so install with `php scripts/install.php --with-demo` and `APP_ENV=local`.

## Scheduled jobs

Background work (the email and push queues, escrow release, booking expiry, backups,
data-export clean-up and more) runs through `php scripts/cron.php <job>`. Register
every job that `php scripts/cron.php --list` prints. On Hostinger, add the jobs in
hPanel as described in [DEPLOYMENT.md](backend/docs/DEPLOYMENT.md).
[backend/deploy/cron/zustee-crontab](backend/deploy/cron/zustee-crontab) is a crontab
template for a VPS, but it does not yet include `privacy:purge-exports` or
`creators:badges`.

## Documentation

| Document | Covers |
| --- | --- |
| [backend/docs/README.md](backend/docs/README.md) | Backend overview: stack, modules, quick start |
| [INSTALLATION.md](backend/docs/INSTALLATION.md) | Local and Hostinger installation |
| [ENVIRONMENT.md](backend/docs/ENVIRONMENT.md) | `.env` settings (`TRUSTED_PROXIES` and `CASHFREE_*` are only in `.env.example`) |
| [DEVELOPER-GUIDE.md](backend/docs/DEVELOPER-GUIDE.md) | Conventions for working on the backend |
| [STRUCTURE.md](backend/docs/STRUCTURE.md) | Folder structure and architecture |
| [DATABASE.md](backend/docs/DATABASE.md) | Database schema |
| [API.md](backend/docs/API.md) | REST API reference for the app |
| [ADMIN-MANUAL.md](backend/docs/ADMIN-MANUAL.md) | Admin panel guide |
| [USER-MANUAL.md](backend/docs/USER-MANUAL.md) | Guide for brands and creators |
| [DEPLOYMENT.md](backend/docs/DEPLOYMENT.md) | Deploying, cron, backups, rollback and the launch checklist |
| [mobile/README.md](mobile/README.md) | App build settings and signed releases |

## What is not in this repository

Never commit these; `.gitignore` keeps them out:

- `backend/.env`, the real configuration with database credentials and keys. Copy
  [`backend/.env.example`](backend/.env.example) to start one.
- `mobile/android/upload-keystore.jks` and `mobile/android/key.properties`, the Google
  Play upload key and its passwords. Keep a backup somewhere safe: every app update
  must be signed with it.
- `backend/storage/` (logs, cache, backups, data exports, KYC files) and user uploads
  under `backend/public/uploads/`. The installer recreates the folders.
- Firebase service-account files (`firebase*.json`, `*service-account*.json`).
- Build outputs and signed release files.

Keep Firebase credentials out of files altogether: load them with
`php scripts/configure_firebase.php path/to/firebase.json`, which stores the service
account key encrypted in the database, then delete the JSON. The app falls back to the
file named by `FIREBASE_CREDENTIALS` only when the database has none.
