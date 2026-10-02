# Database Schema

**122 tables**, InnoDB, `utf8mb4_unicode_ci`, built by **21 migrations** and **17 seed
files**.

Re-derive any count here rather than trusting it after a change:

```sql
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();
SELECT COUNT(*) AS permissions, COUNT(DISTINCT group_name) AS groups FROM permissions;
SELECT COUNT(*) AS settings,    COUNT(DISTINCT group_key)  AS groups FROM settings;
SELECT job_key, schedule_hint FROM scheduled_jobs ORDER BY id;
```

Every table has an integer surrogate primary key, foreign keys with explicit
`ON DELETE` behaviour, and indexes on every column used in a `WHERE`, `JOIN` or
`ORDER BY` on a list screen.

---

## Migrations

Applied in filename order and recorded in the `migrations` table with a SHA-256
checksum, so `install.php` can be re-run safely.

| File | Tables | Covers |
|---|---:|---|
| `001_core_and_rbac.sql` | 16 | Migrations ledger, settings, roles, permissions, admins, sessions, rate limits, activity and system logs, media, email/SMS/push queues |
| `002_users_and_auth.sql` | 9 | Users, refresh tokens, token blacklist, devices, OTP, password resets, KYC documents, referrals, bank accounts |
| `003_categories_and_creators.sql` | 12 | Categories, creators (FULLTEXT), social accounts, packages, portfolio, availability, favourites, follows, search history |
| `004_campaigns_and_bookings.sql` | 9 | Campaigns, bookings, offers, status history, deliverables, invoices, reviews, reports, disputes |
| `005_wallet_and_payments.sql` | 8 | Wallets, wallet transactions, payments, webhooks, refunds, withdrawals, settlements, platform earnings |
| `006_messaging_and_notifications.sql` | 9 | Conversations, participants, messages, receipts, notifications, templates, broadcasts, support tickets |
| `007_cms_and_analytics.sql` | 11 | Pages, blogs, banners, FAQs, testimonials, contact messages, daily analytics, scheduled jobs, backups |
| `008_enterprise_modules.sql` | 27 | Login history, IP allow list, API logs, query log, cache, coupons, marketing campaigns, popups, menus, redirects, approvals, automation, AI prompts, saved filters, revisions, feature flags |
| `009_dashboard_widgets.sql` | 6 | Dashboard layouts, widget preferences, admin notes, tasks, announcements, visit stats |
| `010_security_centre.sql` | 0 | Widens `admins.two_factor_secret` to hold AES-256-GCM ciphertext |
| `011_ai_providers.sql` | 2 | AI providers and per-model pricing |
| `012_integrations.sql` | 3 | Integrations, integration logs, daily usage roll-up |
| `013_kyc_verification.sql` | 6 | KYC profiles, brand/creator detail, events, notes, requirements |
| `014_soft_delete_indexes.sql` | 0 | Indexes `deleted_at` on the 16 tables the recycle bin sweeps |
| `015_automation_runner.sql` | 1 | Keyset cursor on `automation_rules` plus an idempotent run ledger |
| `016_taxonomies.sql` | 2 | Taxonomies and terms — the option lists the apps used to hard-code |

> **Two migrations must never share a version number.** The ledger is keyed on the
> three-digit prefix, so a duplicate means the second file is silently skipped and its
> tables never exist. `install.php` refuses to run when it finds one, naming both files.
>
> This is not hypothetical: it has happened four times. First with two files numbered
> `011` — six tables were quietly missing and the installer's message pointed
> elsewhere, which is what prompted the guard. Then `014_taxonomies.sql`, caught by the
> guard and renumbered to `016`. Then `015_dedupe_homepage_sections.sql` and
> `016_onboarding_slides.sql`, both shadowed by already-applied versions and therefore
> never run at all — the duplicate home-screen rails the first was written to fix were
> still being served. Renumbered to `017` and `018`.
>
> **Seeds are different and safe.** They have no ledger — `install.php` globs and sorts
> them and simply runs each one — so a shared prefix among seeds is harmless. It is only
> migrations, where the prefix is a primary key, that a duplicate silently breaks.

---

## Seeds

Idempotent (`ON DUPLICATE KEY UPDATE`), so re-running never creates duplicates.

| File | Contents |
|---|---|
| `001_reference_data.sql` | 7 roles, 56 permissions, the role matrix, 115 settings, 16 categories, 9 scheduled jobs |
| `002_cms_content.sql` | 12 FAQs, 6 legal/informational pages, 4 blog categories, notification templates, 4 testimonials, 3 banners |
| `003_enterprise_defaults.sql` | 17 more permissions, 11 more settings, approval workflows, AI prompts, feature flags, media folders, app-drawer menu, homepage sections |
| `004_ai_providers.sql` | 7 AI providers (all disabled, no keys) and the Anthropic model catalogue with pricing |
| `005_integrations.sql` | `integrations.view` / `integrations.manage`, granted to roles that already hold the equivalent settings permission, plus the `integrations:sync` job row |
| `006_kyc_defaults.sql` | KYC requirements for brands and creators, 12 KYC settings, KYC notification templates, permissions, and the `kyc:expiry` job row |
| `007_taxonomies.sql` | The option lists the apps render — sort orders, platforms, languages, campaign types, deliverables, support topics |

Totals after seeding: **81 permissions in 24 groups**, **142 settings in 19 groups**,
**13 scheduled jobs**.

> Seeds are applied in filename order with **no ledger** — they simply re-run, which is
> why every one of them is idempotent. That is the opposite of migrations, where the
> version prefix is a key and a duplicate silently skips a file. A shared prefix among
> seeds would be harmless; among migrations it is a bug.

**No seed creates an admin account.** `install.php` prompts for the first Super Admin,
so no deployment ships with a known password.

---

## Core domains

### Access control

`roles` → `role_permissions` → `permissions`, plus `admin_permissions` for per-admin
grants and revocations layered on top of the role.

7 roles by level: **Super Admin (100)**, Admin (90), Manager (70), Finance (60),
Moderator (50), Content Manager (40), Support (30). 81 permissions in 24 groups.

Level matters beyond permissions: an admin can never edit or delete an account at an
equal or higher level, so a Manager cannot demote an Admin.

### Users and creators

`users` holds every account. `creators` is a one-to-one extension for accounts that
sell, carrying the profile, pricing band, ratings and a moderation `status`
(`draft` → `pending_review` → `approved` / `rejected` / `suspended`).

Creator search uses a FULLTEXT index over the display name, headline and about text,
with `search_boost` and `profile_score` as ranking inputs.

### Bookings

The heart of the platform. `bookings.status` moves through:

```
pending ─┬─ accepted ── awaiting_payment ── confirmed ── in_progress ── delivered ── completed
         ├─ countered ─→ (accepted / rejected)
         ├─ rejected
         ├─ cancelled
         └─ expired

completed ─┬─ disputed ── refunded
           └─ (final)
```

Every transition writes a `booking_status_history` row recording who changed it, from
what, to what, and why. Nothing mutates `status` without that trail.

Booking codes are human-quotable: `#ZB000042`.

### Money

Two structures, deliberately:

- **`wallet_transactions`** — an append-only ledger. Rows are never updated or
  deleted. Every credit and debit is a row carrying `balance_after`.
- **`wallets`** — one row per user holding `balance` and `locked_balance`, maintained
  as a fast read cache of the ledger.

Rules the code enforces:

1. Every balance change happens inside a transaction with `SELECT … FOR UPDATE` on the
   wallet row, so two concurrent payouts cannot both succeed.
2. Every transaction carries a unique `idempotency_key`. A retried webhook or a
   double-clicked button cannot credit twice.
3. Escrow is `locked_balance`: paying for a booking moves money from the brand's
   available balance into the creator's locked balance. It unlocks on completion, or
   returns on refund. Money is never in two places.

Amounts are `DECIMAL`, never float. Rounding is explicit at the point of calculation.

### Payments

`payments` records the gateway attempt; `payment_webhooks` stores every callback with
its signature verification result before anything acts on it. An unverified webhook is
recorded and ignored — a webhook is an unauthenticated request until proven otherwise.

`refunds`, `withdrawal_requests`, `settlements` and `platform_earnings` complete the
picture: what came in, what is owed, what was paid out, what the platform kept.

### Messaging

`conversations` → `conversation_participants` → `messages` → `message_receipts`.
Receipts are per participant, so read state is correct in a group thread.

### CMS

`pages`, `blogs`, `blog_categories`, `banners`, `faqs`, `testimonials`,
`homepage_sections`, `menus`/`menu_items`, `redirects`. Most carry SEO columns
(`meta_title`, `meta_description`, `og_image`) consumed by the sitemap generator.

### Analytics

`analytics_daily` and `creator_analytics_daily` are roll-up tables written by the
`analytics:rollup` cron job. Dashboard widgets read the roll-ups, never the raw rows —
which is what keeps 105 widgets loading quickly.

---

## Conventions

**Soft deletes** — tables with `deleted_at` keep rows for the recycle bin. Not every
table has it: `ai_prompts`, `feature_flags` and `redirects` hard delete. Check before
writing a query that assumes `deleted_at IS NULL`.

**Timestamps** — `created_at` and `updated_at` on anything mutable, defaulting to
`CURRENT_TIMESTAMP`. Stored in `APP_TIMEZONE`.

**Encryption at rest** — AES-256-GCM via `Zuste\Core\Crypto`, applied to KYC document
paths, bank account numbers, admin 2FA secrets, integration credentials and AI
provider keys. All keyed on `APP_ENCRYPTION_KEY`; see [ENVIRONMENT.md](ENVIRONMENT.md)
for why that key cannot be rotated in place.

**Enums** — used where the set is fixed and owned by the application. Adding a value
requires a migration, which is the intended friction.

**Foreign keys** — always explicit about deletion. Reference data uses
`ON DELETE RESTRICT`; owned children use `CASCADE`; optional links use `SET NULL`.

> Constraint names are **global** in MySQL and MariaDB, not per table. Two tables
> cannot both define `fk_user`. This bit us once — `fk_aa_admin` was reused across two
> migrations and the second failed with errno 121. Prefix constraint names with an
> abbreviation of the owning table.

---

## Querying safely

Every value is bound. `Zuste\Core\Database` runs with `EMULATE_PREPARES=false`, so
statements are prepared server-side.

One deliberate extension: a named placeholder **may repeat**. Native prepared
statements forbid it, so `Database::statement()` rewrites repeats before preparing.
That means this is valid:

```php
Database::select(
    'SELECT * FROM `bookings` WHERE `brand_user_id` = :id OR `creator_user_id` = :id',
    ['id' => $userId]
);
```

Table and column names can never be bound, so anything dynamic — a sort column, a
recycle-bin table, an import target — is resolved through a whitelist. See
[DEVELOPER-GUIDE.md](DEVELOPER-GUIDE.md).

---

## Backups

`backup:database` runs nightly and writes a gzipped `mysqldump` to
`storage/backups`, pruned after `BACKUP_RETENTION_DAYS`.

`scripts/backup.sh` is the operational tool: `--single-transaction` so it does not
lock the live site, credentials passed via a 600-mode defaults file rather than the
command line (arguments are visible to every user on a shared host via `ps`), and an
integrity check before the file gets its final name.

```bash
bash scripts/backup.sh --files --verify   # dump + uploads, then check
bash scripts/backup.sh --list
bash scripts/backup.sh --prune
```

> **Back up `APP_ENCRYPTION_KEY` with the database.** A restore without the matching
> key gives you rows you cannot decrypt.
