# Zuste+ — Influencer Marketplace Platform

> Shoot. Connect. Grow.

Zuste+ connects brands with verified creators. A brand posts a campaign or books a
creator directly, funds sit in escrow until the work is delivered, and the platform
takes a fee on completion.

This repository holds the **backend**: a REST API for the mobile and web clients, and
a full admin panel. Both are written in Core PHP with no framework and no Composer
dependencies, so the whole thing runs on shared hosting.

---

## What is in here

| | |
|---|---|
| **REST API** | 125 routes for the Android, iOS and web clients |
| **Admin panel** | 245 routes across ~40 modules |
| **Database** | 122 tables, 21 migrations, 17 seed files |
| **Access control** | 7 roles, 84 permissions in 25 groups |
| **Settings** | 184 settings in 20 groups |
| **Authentication** | 6 sign-in methods, individually switchable, enforced server-side |
| **Dashboard** | 105 widgets in 12 groups, drag-and-drop layouts, CSV/XLSX/PDF export |
| **Integrations** | 58 third-party services in 16 categories |
| **Scheduled jobs** | 14 cron jobs |
| **Code** | 193 PHP files — 33 services, 37 controllers, 21 core classes, 77 admin views |

> Counts are checked against the running application, not maintained by hand. Re-derive
> them with `php scripts/cron.php --list` and the queries in
> [DATABASE.md](DATABASE.md) rather than trusting this table after a change.

---

## Stack

- **PHP 8.1+** (8.3 in production). No framework, no Composer.
- **MySQL / MariaDB** with PDO, prepared statements and foreign keys.
- **Admin UI** — server-rendered PHP, hand-written CSS, vanilla JavaScript, AJAX.
  No React, Vue, Angular, Bootstrap or any admin template.
- **Auth** — JWT (HS256) with rotating refresh tokens for the API; server sessions
  with CSRF tokens for the panel.
- **Firebase** — optional. Used for social sign-in, push and storage. The platform
  runs fully without it; MySQL is always the source of truth.

Everything third-party is optional and configured at runtime. There is no build step:
deploying is copying files and running one script.

---

## Quick start

```bash
# 1. Configure
cp .env.example .env          # then fill in DB credentials and generate the two keys

# 2. Create the schema, seed reference data, create the first admin
php scripts/install.php

# 3. Serve locally (reproduces the .htaccess rewrite rules)
php -S localhost:8000 -t public scripts/serve.php
```

Then open:

- Admin panel — <http://localhost:8000/admin>
- API health — <http://localhost:8000/api/v1/health>

`install.php` prompts for the first Super Admin. **No default password ships in any
seed file** — an install with a known password would be a live account on every
deployment.

Full walkthrough: [INSTALLATION.md](INSTALLATION.md).

---

## Documentation

| Document | For |
|---|---|
| [INSTALLATION.md](INSTALLATION.md) | Getting it running locally and on Hostinger |
| [ENVIRONMENT.md](ENVIRONMENT.md) | Every `.env` key, what it does, what breaks without it |
| [DATABASE.md](DATABASE.md) | Schema reference, migrations, seeds, money model |
| [API.md](API.md) | REST reference for client developers |
| [STRUCTURE.md](STRUCTURE.md) | Folder layout and how a request flows |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Deploy, cron, backups, rollback |
| [ADMIN-MANUAL.md](ADMIN-MANUAL.md) | Every admin module, for operators |
| [USER-MANUAL.md](USER-MANUAL.md) | Brand and creator journeys, for support staff |
| [DEVELOPER-GUIDE.md](DEVELOPER-GUIDE.md) | Conventions and how to extend safely |

---

## Design system

The admin panel and the apps share one visual language, defined in
[DESIGN.md](../../DESIGN.md) at the repository root.

| Token | Value |
|---|---|
| Primary | `#b80049` |
| Secondary | `#632ce5` |
| Tertiary | `#006a34` |
| Error | `#ba1a1a` |
| Surface | `#fff8f7` |
| Typeface | Plus Jakarta Sans |
| Radii | 4 / 8 / 12 / 16 / 24 px |
| Spacing | 4px baseline |

Currency is INR throughout, formatted with Indian digit grouping (`₹12,34,567`).

---

## Security posture

- Every SQL value is bound. Table and column names in dynamic queries come from
  whitelists, never from user input.
- Output is escaped at render. CSRF tokens on every unsafe verb.
- Passwords are bcrypt (cost 12). API keys, OAuth tokens, KYC documents and bank
  details are encrypted with AES-256-GCM.
- Admin 2FA is TOTP (RFC 6238) with single-use recovery codes.
- An optional IP allow list can restrict the panel; it fails open while empty so it
  cannot lock out the operator who is configuring it.
- Rate limiting on sign-in, OTP and password reset; every sign-in attempt is recorded.
- Sensitive keys are redacted before anything reaches a log.

---

## Licence

Proprietary. All rights reserved.
