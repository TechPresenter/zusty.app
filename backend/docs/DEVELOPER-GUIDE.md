# Developer Guide

Conventions, the three registries, and how to extend the panel without breaking it.

Read [STRUCTURE.md](STRUCTURE.md) first for the layout and request flow.

---

## Rules

**No Composer, no npm, no build step.** Classes load through a PSR-4 autoloader in
`app/Core/Autoloader.php`. CSS and JS are hand-written and served as-is. Adding a
dependency means writing it.

**Namespace** — `Zuste\` maps to `app/`. `app/Services/BookingService.php` is
`Zuste\Services\BookingService`. The file name must match the class name exactly;
Linux is case-sensitive and Windows is not, which is exactly how a case mistake reaches
production undetected.

**`declare(strict_types=1);` at the top of every PHP file.** No exceptions.

**Services own business logic.** Controllers validate input, call a service, and choose
a response. If a rule lives in a controller, a cron job cannot reuse it.

**Never interpolate into SQL.** Every value is bound. Identifiers that must be dynamic
go through a whitelist.

---

## Adding an API endpoint

1. **Route** — `routes/api.php`:

```php
$router->get('/creators/{id:\d+}/stats', [CreatorController::class, 'stats'])
       ->middleware('auth');
```

Placeholders are `{name}` or `{name:regex}`. **The regex cannot contain braces** —
`{id:[a-f0-9]{64}}` breaks the parser, because the parser splits on braces. Write
`{id:[a-f0-9]+}` and validate the length in the controller.

2. **Controller** — thin:

```php
/** GET /creators/{id}/stats */
public function stats(Request $request): never
{
    $creatorId = $request->routeParamInt('id');

    Response::success(CreatorService::stats($creatorId));
}
```

Two conventions in that signature:

> **Controller methods return `never`.** `Response::success()`, `Response::paginated()`
> and the admin `$this->view()` / `$this->back()` helpers send the response and
> terminate. Do not `return` them.

> **Route parameters are not method arguments.** The router hands the controller a
> `Request` and nothing else; read parameters with `routeParamInt()` or `routeParam()`.
> A signature of `stats(Request $r, int $id)` will fail.

Response helpers: `success`, `created`, `noContent`, `paginated`, `error`,
`validationError`, `unauthorized`, `forbidden`, `notFound`, `conflict`,
`tooManyRequests`, `serverError`, `serviceUnavailable`, `json`, `html`, `download`,
`redirect`.

3. **Service** — the actual work, in `app/Services`.

4. **Validate** before using anything. Both base controllers expose the same helper:

```php
$data = $this->validate($request, [
    'title'  => 'required|string|max:191',
    'amount' => 'required|numeric|min:0',
    'email'  => 'required|email|unique:users,email',
]);
```

> `validate()` returns **only the keys that were present**. An absent optional field is
> not in the array — so read optionals as `$data['expires_at'] ?? null`, never
> `$data['expires_at']`. This has bitten more than once.

---

## Adding an admin CRUD module

Do **not** write a controller. Declare a resource.

`app/Admin/Resource.php`, in `registry()`:

```php
'announcements' => new self(
    key: 'announcements',
    table: 'announcements',
    singular: 'Announcement',
    plural: 'Announcements',
    icon: 'megaphone',
    viewPermission: 'cms.view',
    managePermission: 'cms.manage',
    columns: [
        ['key' => 'title',      'label' => 'Title',  'type' => 'title',    'sortable' => true],
        ['key' => 'status',     'label' => 'Status', 'type' => 'status'],
        ['key' => 'created_at', 'label' => 'Created','type' => 'datetime', 'sortable' => true],
    ],
    fields: [
        ['name' => 'title',  'label' => 'Title', 'type' => 'text',     'rules' => 'required|string|max:191'],
        ['name' => 'body',   'label' => 'Body',  'type' => 'textarea', 'rules' => 'required|string', 'rows' => 6],
        ['name' => 'status', 'label' => 'Status','type' => 'select',
         'options' => ['draft' => 'Draft', 'published' => 'Published'], 'rules' => 'required|in:draft,published'],
    ],
    filters: [['name' => 'status', 'label' => 'Status',
               'options' => ['draft' => 'Draft', 'published' => 'Published']]],
    searchable: ['title', 'body'],
    sortable: ['id', 'title', 'created_at'],
    defaultSort: 'created_at',
    softDeletes: true,
    slugSource: 'title',
),
```

Then add the table in a migration and a sidebar entry in
`app/Views/admin/layouts/app.php`. List, create, edit, delete, search, filters, sort,
pagination, bulk actions, export, validation, CSRF, permissions and activity logging
all come for free, because one controller and three views serve all twelve resources.

The twelve today: `categories`, `pages`, `blogs`, `banners`, `faqs`, `testimonials`,
`coupons`, `popups`, `redirects`, `feature_flags`, `homepage_sections`, `ai_prompts`.

**Field types** — `text` (the default), `textarea`, `html`, `select`, `switch`,
`number`, `datetime`, `color`, `image`.
**Column types** — `title`, `mono`, `number`, `boolean`, `chip`, `status`, `rating`,
`date`, `datetime`, `thumbnail`; anything else renders as plain escaped text.

An unknown type is not an error — it silently falls through to the default. Use one
from these lists, or add a `case` to `app/Views/admin/resource/form.php` and
`index.php`.

`unique:` rules in a resource automatically exclude the current row on update, so an
edit that does not change the unique column will not fail against itself.

---

## Adding a dashboard widget

`app/Admin/Widget.php`. A KPI card:

```php
self::stat('Pending payouts', $g, 'wallet', 'warning', 'wallet.withdrawals',
    'pendingPayouts', '/admin/withdrawals', 'money'),
```

A panel (chart, list, table, feed):

```php
self::panel('Revenue', $g, 'chart', 'chart', 'primary', 'analytics.view',
    'chartRevenue', self::SIZE_LG, ['chart' => 'area', 'options' => ['days' => 30]]),
```

Then implement the resolver in `WidgetService`:

```php
public function pendingPayouts(): array
{
    return ['value' => Database::scalar(
        "SELECT COUNT(*) FROM `withdrawal_requests` WHERE `status` = 'pending'"
    )];
}
```

Rules that matter:

- **Every widget declares a permission.** Widgets the admin cannot see are never
  resolved and never sent to the browser.
- **Read roll-up tables, not raw rows.** `analytics_daily` exists so 105 widgets can
  load quickly. A widget that scans `bookings` will be the slow one on the dashboard.
- **`refresh`** is seconds; `0` disables polling. Use polling sparingly.
- The dashboard resolves every visible widget in **one batched request**, not one
  request per widget.

Sizes are grid columns out of 12: `SIZE_XS` (2), `SIZE_SM` (3), `SIZE_MD` (4),
`SIZE_LG` (6), `SIZE_XL` (12).

---

## Adding an integration

`app/Admin/Integration.php`:

```php
'brevo' => self::make(
    'Brevo', 'email', 'Brevo',
    'Transactional email and marketing campaigns.',
    'https://developers.brevo.com/',
    [
        self::secret('api_key', 'API key'),
        self::text('sender', 'Sender address', 'The from address on outgoing mail.'),
    ],
    [
        'test' => ['type' => 'http', 'method' => 'GET',
                   'url' => 'https://api.brevo.com/v3/account',
                   'headers' => ['api-key' => '{api_key}']],
        'sync' => true,
    ],
),
```

Three declarations carry the important decisions:

| Key | Meaning |
|---|---|
| `settings_map` | Mirror fields into `settings` rows that an existing service already reads, so connecting from either screen works and the two never disagree |
| `delegate` | A full module already owns this service — link to it instead of duplicating configuration |
| `test` | `http` (a real authenticated request), `internal` (call our own service), or `config` (validate configuration only) |

Field helpers: `text()`, `secret()`, `choice()`, `toggle()`, `textarea()`.

**Do not fabricate a connection test.** If a service has no cheap authenticated
endpoint — AWS S3 and SNS, for example — declare `config` and let the panel say
"configuration valid". A green tick that means nothing is worse than an honest amber
one.

Secrets are encrypted with `Crypto` and never rendered back. A blank secret on save
means "keep the stored value"; clearing requires the explicit clear action. Preserve
that behaviour in anything you add.

---

## Adding a cron job

`app/Services/CronRunner.php` — add to `jobs()`, add a dispatch case, write the method:

```php
private function pruneStaleDrafts(): array
{
    $deleted = Database::execute(
        "DELETE FROM `bookings` WHERE `status` = 'draft' AND `created_at` < :cutoff",
        ['cutoff' => date('Y-m-d H:i:s', strtotime('-30 days'))]
    );

    return ['deleted' => $deleted];
}
```

Then seed a `scheduled_jobs` row so it appears under System → Jobs, and register the
crontab entry.

Jobs are file-locked against overlap and record duration and outcome. **Wrap per-item
work individually** — `integrations:sync` handles each integration in its own
try/catch precisely so one broken integration does not stop the other fifty-three.

---

## Database

### Querying

```php
Database::select($sql, $bindings);      // list of rows
Database::selectOne($sql, $bindings);   // one row or null
Database::scalar($sql, $bindings);      // single value
Database::column($sql, $bindings);      // flat list of the first column
Database::exists($sql, $bindings);      // bool
Database::execute($sql, $bindings);     // affected rows
Database::insert($sql, $bindings);      // last insert id
Database::update(...); Database::delete(...); Database::insertIgnore(...);
Database::lockForUpdate($sql, $bindings);   // SELECT … FOR UPDATE, inside a transaction
```

Transactions nest via savepoints, and deadlocks retry (`$attempts`, default 2):

```php
Database::transaction(static function () use ($brandId, $creatorId, $amount, $key) {
    WalletService::debit($brandId, $amount, ['idempotency_key' => $key . ':debit', …]);
    WalletService::credit($creatorId, $amount, ['idempotency_key' => $key . ':credit', …]);
});
```

Services are static. There is no container and no injection — a service is callable from
a controller, a cron job or a script with the same one-line call.

### Repeated placeholders are allowed

Native prepared statements forbid reusing a named placeholder.
`Database::statement()` rewrites repeats before preparing, so this works:

```php
Database::select(
    'SELECT * FROM `bookings` WHERE `brand_user_id` = :id OR `creator_user_id` = :id',
    ['id' => $userId]
);
```

Fixed once, centrally, rather than at each call site that hit it.

### Dynamic identifiers

Table and column names cannot be bound. Anything dynamic — a sort column, a recycle-bin
table, an import target — resolves through a whitelist:

```php
$column = in_array($requested, $resource->sortable, true) ? $requested : $resource->defaultSort;
$sql    = 'SELECT * FROM ' . Database::quoteIdentifier($resource->table)
        . ' ORDER BY ' . Database::quoteIdentifier($column) . ' ' . $direction;
```

`$direction` is normalised to `ASC` or `DESC`, never taken from input directly.

### Migrations

```
database/migrations/014_your_change.sql
```

Numbered, applied in order, recorded with a SHA-256 checksum.

- **Never renumber or edit an applied migration.** The checksum will not match and the
  installer will tell you the file changed after it was applied.
- **Never reuse a version number.** The ledger is keyed on the three-digit prefix, so a
  duplicate means the second file is silently skipped and its tables never exist. This
  happened — two files were both `011`, six tables were missing, and the error message
  pointed somewhere else entirely. `install.php` now refuses to run when it finds a
  duplicate.
- **Constraint names are global in MySQL, not per table.** Two tables cannot both
  define `fk_user`. Prefix with an abbreviation of the owning table: `fk_wt_wallet`.
- **`COMMENT` comes before `AFTER`** in a column definition. The other order is a
  syntax error MySQL reports unhelpfully.
- Forward-only. There is no `down()`; rolling back means restoring the backup the
  deploy script took.

Seeds must be idempotent — `INSERT … ON DUPLICATE KEY UPDATE`.

---

## Views

```php
<?php
$this->extend('admin.layouts.app');
$this->start('content');
?>
<div class="card">
  <h2 class="h2"><?= $this->e($title) ?></h2>
</div>
<?php $this->stop(); ?>
```

**Escaping is explicit.** `$this->e()` for anything that reaches HTML; `$this->html()`
only for content already sanitised. There is no auto-escaping to fall back on.

Helpers on `$this` inside a view:

| | |
|---|---|
| Escaping | `e()`, `html()`, `json()` |
| Layout | `extend()`, `start()`, `stop()`, `section()`, `partial()` |
| Formatting | `money()`, `moneyShort()`, `number()`, `date()`, `dateTime()`, `timeAgo()`, `compact()`, `initials()` |
| Forms | `csrf()`, `csrfToken()`, `old()`, `error()`, `hasError()` |
| Navigation | `queryUrl()`, `isActive()` |
| Other | `can()`, `icon()` |

### CSS

Use the classes that exist. The design system is in
`public/admin/assets/css/admin.css`; inventing a class name produces an unstyled
element that looks broken only on the screen you did not test.

Real components: `card`, `btn` (`btn--primary`, `btn--secondary`, `btn--ghost`,
`btn--danger`, `btn--outline`, `btn--tonal`, `btn--icon`, `btn--sm`, `btn--lg`,
`btn--block`, `btn--pill`), `table` / `table-wrap`, `field`, `input`, `select`,
`textarea`, `checkbox`, `switch`, `chip` (`chip--success`, `chip--warning`,
`chip--error`, `chip--info`, `chip--neutral`, `chip--primary`), `callout`
(`callout--info`, `callout--success`, `callout--warning`, `callout--error`), `dialog`,
`toast`, `empty`, `skeleton`, `pagination`, `toolbar`, `progress`, `stat`, `donut`,
`chart`, `avatar`.

Layout: `grid` with `grid--2`, `grid--3`, `grid--4`, `grid--sidebar`; `row`,
`row--between`, `row--end`, `row--wrap`; `form-grid`, `form-grid--2`.

Text: `h1`, `h2`, `h3`, `display`, `small`, `tiny`, `muted`, `faint`, `mono`,
`truncate`, `clamp-2`, `text-primary`, `text-success`, `text-warning`, `text-error`.

Colours, spacing and radii come from CSS custom properties. Add a token before adding a
hard-coded hex.

### JavaScript

`window.Admin` exposes exactly six methods:

```js
Admin.toast(message, type, timeout);   // type: 'success' | 'error' | 'warning' | 'info'
Admin.confirm(options);                // returns a Promise<boolean>
Admin.request(url, options);           // fetch + CSRF + JSON + error handling
Admin.submitForm(form, onSuccess);
Admin.lineChart(container, series, options);
Admin.barChart(container, series);
```

Note the argument order: **message first, then type.** Reversing them produces a toast
labelled with your message and a body of "error".

Vanilla JavaScript only. No jQuery, no framework, no bundler.

---

## Security

**CSRF** — every unsafe verb in the panel goes through `CsrfMiddleware`. Forms include
the token via `Csrf::field()`; AJAX picks it up from the `csrf-token` meta tag
automatically inside `Admin.request`.

**Permissions** — declared on the route:

```php
$router->post('/admin/payouts/{id:\d+}/approve', [FinanceController::class, 'approve'])
       ->middleware('permission:wallet.withdrawals');
```

Check in a controller with `$this->authorize($request, 'wallet.withdrawals')` and in a
view with `$this->can('wallet.withdrawals')`. **Do all three** where a screen both
renders an action and executes it — hiding a button is presentation, not authorisation.

**Encryption** — `Crypto::encrypt()` / `Crypto::decrypt()`, AES-256-GCM. Applied to KYC
paths, bank account numbers, 2FA secrets, integration credentials and AI keys. Keyed on
`APP_ENCRYPTION_KEY`, which cannot be rotated in place.

**Passwords** — `Crypto::hashPassword()`, bcrypt cost 12. Never anything else.

**Uploads** — always through `UploadService`. It validates the real MIME type rather
than the filename, enforces size and dimension limits, strips EXIF, and generates a
random storage name. A filename extension is a claim, not evidence.

**Rate limiting** — `->middleware('throttle:5,60,login')` — five attempts per 60
seconds per key.

---

## Logging

```php
Logger::info('Booking completed', ['booking_id' => $id]);
Logger::error('Payment verification failed', ['payment_id' => $id, 'reason' => $why]);
```

`Logger` levels: `debug`, `info`, `notice`, `warning`, `error`, `critical`, plus
`exception()` for a caught throwable.

The admin audit trail is separate. From an admin controller use the base helper, which
attaches the acting admin for you:

```php
$this->log($request, 'booking.refund', 'booking', $bookingId,
    'Refunded ₹' . $amount . ' to the brand.',
    ['before' => $before, 'after' => $after]);
```

Outside a controller, call `ActivityLogger::admin($admin, $action, …)`,
`ActivityLogger::user($user, …)` or `ActivityLogger::log(…)` for system actions. The
signature is `(action, subjectType, subjectId, description, properties)` — the
before/after pair belongs inside `properties`, not as positional arguments.

Call it for **any** operator action that changes data. It is what Access → Activity Log
shows, and it is not editable from the panel.

**Never log a secret.** Not tokens, not keys, not card data, not decrypted KYC. Logs
are read by more people than the database is.

---

## Testing changes

There is no test framework — a deliberate consequence of no Composer. Verify by hand,
and verify the things that actually break:

```bash
php -l app/Services/YourService.php                    # syntax
php scripts/install.php --migrate-only                 # migrations apply cleanly
php scripts/cron.php your:job                          # the job runs
curl -s http://localhost:8000/api/v1/health            # the app still boots
```

Then walk the screen: create, edit, delete, filter, sort, paginate, export, and try it
once with a role that should **not** have access.

Before deploying, `bash scripts/deploy.sh --dry-run` runs the full lint gate over every
PHP file.

---

## Conventions checklist

- [ ] `declare(strict_types=1);`
- [ ] Type hints on every parameter and return
- [ ] Business logic in a service, not a controller or a view
- [ ] Every value bound; every dynamic identifier whitelisted
- [ ] Every input validated; optionals read with `??`
- [ ] Route parameters read via `routeParamInt()`, not method arguments
- [ ] Permission declared on the route **and** checked in the view
- [ ] `$this->e()` on everything that reaches HTML
- [ ] Only CSS classes that exist in `admin.css`
- [ ] `Admin.toast(message, type)` — that order
- [ ] Operator actions written to the activity log
- [ ] No secret in any log line
- [ ] Migration version unique; constraint names prefixed
- [ ] `php -l` clean
