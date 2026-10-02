# Folder Structure & Architecture

---

## Layout

```
backend/
├── app/
│   ├── Admin/            Declarative registries (Resource, Widget, Integration)
│   ├── Controllers/
│   │   ├── Admin/        Admin panel controllers + a shared base
│   │   └── Api/          REST API controllers + a shared base
│   ├── Core/             21 framework classes — the whole framework
│   ├── Helpers/          Small pure functions (Csrf, Http, Money, Str)
│   ├── Middleware/       7 request filters
│   ├── Repositories/     BaseRepository — shared query plumbing
│   ├── Services/         33 services — all business logic
│   └── Views/
│       ├── admin/        77 admin templates
│       └── emails/       Email templates
│
├── config/               app.php, database.php … arrays only, no logic
├── database/
│   ├── migrations/       16 SQL files, applied in order
│   └── seeds/            7 SQL files, idempotent
│
├── docs/                 This documentation
│
├── public/               ← THE ONLY WEB-ACCESSIBLE DIRECTORY
│   ├── index.php         API front controller
│   ├── admin/index.php   Admin front controller
│   ├── admin/assets/     CSS and JS (hand-written, no build step)
│   ├── uploads/          User uploads
│   └── .htaccess         Rewrites + blocks everything above
│
├── routes/               api.php (121), admin.php (239)
├── scripts/              install, cron, serve, deploy, backup, deploy_helper
├── storage/              logs, cache, backups, tmp — never web-accessible
└── .env                  Secrets. chmod 600. Never committed.
```

The rule that matters: **only `public/` is reachable over HTTP.** Everything else sits
above the web root, and `.htaccess` blocks direct access as a second line of defence.
Verify after any deploy:

```bash
curl -I https://yourdomain.com/app/Core/Database.php   # must be 403 or 404
```

---

## How a request flows

```
public/index.php  or  public/admin/index.php
   │
   ├─ Autoloader          PSR-4, no Composer, optional class map
   ├─ App::boot()         env → config → error handler → database
   │
   ├─ Request::capture()  superglobals wrapped in one immutable object
   ├─ Router::dispatch()  match verb + path, extract params
   │
   ├─ Middleware          auth → csrf → permission → throttle
   │
   ├─ Controller          validate input, call services, pick a view
   │     └─ Service       the actual work; the only place business rules live
   │           └─ Database
   │
   └─ Response / View     JSON for the API, HTML for the panel
```

Errors anywhere land in `App::renderException()`, which returns JSON for API routes
and an error page for admin routes, logging the full trace either way and showing it
only when `APP_DEBUG` is on.

---

## The layers

### `app/Core` — the framework

21 classes, everything a framework would give you, written for this project:

| Class | Responsibility |
|---|---|
| `App` | Boot, middleware registration, exception rendering |
| `Autoloader` | PSR-4 with an optional dumped class map |
| `Router` | Verb + path matching, `{param:regex}` placeholders, groups, middleware |
| `Request` / `Response` | Input and output |
| `Database` | PDO wrapper, transactions with savepoints, deadlock retry, slow-query recording |
| `Validator` | 40+ rules |
| `View` | Template rendering, layouts, sections, escaping helpers |
| `Session` / `Config` / `Env` / `Cache` / `Logger` | Infrastructure |
| `Crypto` | AES-256-GCM, bcrypt, token hashing |
| `Jwt` / `Totp` / `QrCode` | Auth primitives |
| `RateLimiter` | |
| `HttpException` / `ValidationException` / `TokenException` | Typed failures |

Two are worth knowing about because they are unusual:

- **`Totp` + `QrCode`** implement RFC 6238 and a full QR encoder (ISO/IEC 18004,
  Reed–Solomon over GF(256)) from scratch. That is deliberate: sending the shared
  secret to a third-party chart API to render the QR would hand the second factor to
  that service.
- **`Database::statement()`** rewrites repeated named placeholders before preparing,
  so `WHERE a = :id OR b = :id` works despite `EMULATE_PREPARES=false`. Fixed once,
  centrally, rather than at each of the twelve call sites that hit it.

### `app/Services` — the business logic

All of it. Controllers do not contain rules; they validate, delegate and respond. A
service can be called from a controller, a cron job or a script and behave identically.

33 services: `ActivityLogger`, `AiProviderService`, `ApiLogService`, `ApprovalService`,
`AuthService`, `AutomationService`, `BackupService`, `BookingService`,
`CampaignService`, `CreatorService`, `CronRunner`, `DocumentVault`, `ExportService`,
`FirebaseService`, `ImportService`, `IntegrationService`, `InvoiceService`,
`KycService`, `MailService`, `MessageService`, `NotificationService`, `PaymentService`,
`PermissionService`, `ReferralService`, `RevisionService`, `SearchService`,
`SecurityService`, `SitemapService`, `SmsService`, `TaxonomyService`, `UploadService`,
`WalletService`, `WidgetService`.

Every one of them is **static**. There is no container and no injection — a service is
callable from a controller, a cron job or a script with the same one-line call.

### `app/Admin` — the registries

Three declarative catalogues that let one tested renderer serve many screens:

| Registry | Declares | Result |
|---|---|---|
| `Resource` | 12 CRUD resources — table, columns, fields, rules, filters | One controller and three views serve all 12 |
| `Widget` | 105 dashboard widgets — resolver, size, permission, link | One dashboard renders all 105 |
| `Integration` | 54 third-party services — auth, fields, test strategy | One hub and one detail screen serve all 54 |

This is the main architectural decision in the panel. A new CRUD module is a
declaration, not a new controller — and a fix to the renderer lands everywhere at once.

### `app/Middleware`

`AuthMiddleware` (API JWT), `AdminAuthMiddleware` (panel session), `CsrfMiddleware`,
`ThrottleMiddleware`, `MaintenanceMiddleware`, `CronMiddleware` (the HTTP cron
fallback), `KycMiddleware` (gates endpoints that require an approved KYC).

Permissions are not a separate class — `permission:slug` is handled inside
`AdminAuthMiddleware`, which already has the admin loaded.

Declared per route:

```php
$router->post('/bookings/{id:\d+}/refund', [BookingController::class, 'refund'])
       ->middleware('permission:payments.refund');
```

### `app/Views`

Plain PHP templates with a layout and named sections. No template engine.

```php
$this->extend('admin.layouts.app');
$this->start('content');
// markup
$this->stop();
```

Escaping is explicit: `$this->e()` for text, `$this->html()` only for content already
sanitised. Helpers cover money, dates, numbers and icons so formatting is consistent.

---

## Front-end assets

`public/admin/assets/` — hand-written CSS and vanilla JavaScript. No bundler, no
package manager, no build step. Editing a file changes the panel on the next reload.

| File | Contents |
|---|---|
| `css/admin.css` | The full design system — tokens, components, dark mode, print |
| `css/widgets.css` | Dashboard KPI cards and widget grid |
| `js/admin.js` | Toasts, confirm dialogs, AJAX with CSRF, form validation, theme toggle, charts |
| `js/widgets.js` | Animated counters, 13 widget renderers, drag-and-drop, auto-refresh |

Icons are inline SVG generated by `View::icon()`, so there is no icon font or sprite
request.

---

## Scripts

| Script | Purpose |
|---|---|
| `install.php` | Migrations, seeds, first admin. Idempotent. |
| `cron.php` | Job runner. `--list`, `--all`, or one job. File-locked against overlap. |
| `serve.php` | Local dev server that reproduces `.htaccess` |
| `deploy.sh` | Preflight, lint gate, backup, migrate, permissions, verify, rollback |
| `backup.sh` | `mysqldump` + uploads, with integrity checking |
| `deploy_helper.php` | The parts of deployment that need the app booted |

---

## Design principles

**Services own the rules.** If logic appears in a controller or a view, it is in the
wrong place — a cron job cannot reuse it there.

**Declarative over repetitive.** Three registries replace what would otherwise be ~70
near-identical controllers.

**Fix centrally.** The repeated-placeholder bug was fixed in `Database`, not at twelve
call sites, so it cannot recur.

**Fail closed on money, open on lockout.** Wallet operations lock rows and demand
idempotency keys. The admin IP allow list permits everything while empty, because
locking the operator out of their own panel is worse than the protection is worth.

**No build step.** Deploying is copying files and running one script — which is what
makes shared hosting viable.
