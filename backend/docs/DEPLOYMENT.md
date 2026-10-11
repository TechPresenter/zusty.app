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

If you upload files instead of pulling, include new directories too, such as
`public/assets/landing/` (the website's logo, icons and share image).

### The website at `/`

The site root serves the landing page (`LandingController`), next to the legal pages
(`/about`, `/terms`, `/privacy`, `/refund-policy`, `/community-guidelines`, `/contact`)
and `/account-deletion`. The links the app shares land here too: `/invite/{code}`
(set **Referral → Invite link base** to `https://<domain>/invite/`) and
`/creators/{handle}` (built on SEO `canonical_base`), both pointing people to the
app. It reads everything it shows from settings: General (app
name, tagline, support email, company name), SEO (title, description,
`canonical_base`, share image, Twitter handle), Social links and App. Its canonical
and share URLs, and the legal pages' canonical links, are built on `APP_URL`; SEO
`canonical_base` replaces it only when it is an `https://` URL on the same host (a
value naming another domain is ignored), and a localhost `APP_URL` falls back to
`https://zusty.app`. Check after a deploy:

```bash
curl -sI https://yourdomain.com/            # 200, text/html
curl -s  https://yourdomain.com/robots.txt  # Sitemap: line names this domain
```

- **Google Play link.** The page links to `app.android_store_url` only while
  **Settings → App → Play listing is live** (`app.android_store_live`, added by migration
  049) is on; until then it shows "Coming soon on Google Play". Turn it on the day the
  listing is public. The URL itself stays the app's update link either way.
- **Sitemap.** `public/robots.txt` and `public/sitemap.xml` ship for `https://zusty.app`.
  **SEO → Sitemap** rewrites both; set **Settings → SEO → canonical_base** to the live
  domain before generating. Migration 049 turns off the blog, category and creator
  sources, because the website does not serve those pages.
- **Mail domain.** zustee.com takes no mail. Migration 050 moves every published
  `@zustee.com` mailbox (CMS pages, FAQs, notification templates, popups, blog posts,
  and settings that hold an address, such as the support email and the mail from
  address) to the same name at `@zusty.app`, and dates the privacy and terms pages
  10 October 2026. It never rewrites URLs, encrypted settings or credentials: set the
  SMTP login (`mail.username` / `MAIL_USERNAME`), `MAIL_FROM_ADDRESS`, SEO
  `canonical_base` and the CORS origins to zusty.app yourself if they still name
  zustee.com.
- **One mailbox, email codes, no SMS.** Migration 052 applies the 2026-10-10 decisions.
  `support@zusty.app` is the only public mailbox: any other `@zusty.app` or `@zustee.com`
  mailbox in CMS pages, FAQs, notification templates, popups, blog posts and plain
  settings becomes `support@zusty.app` (the `mail` group, i.e. the SMTP login and
  sender, is never touched). No company address is published: the contact page's
  "Registered office" line is removed and `general.company_address` is emptied, so
  invoices print the company name (and GSTIN when set) only. Sign-in is email and
  password, Google, or a one-time code sent to the account's **email**
  (`auth.otp_login_enabled` = 1, `auth.otp_channels` = `["email"]`, button label
  `auth.otp_label` = "Log in with email code"); `sms.enabled` stays 0, and with no `sms`
  channel there is no phone sign-in at all, Firebase phone sign-in included. A code
  only signs in an existing account. Email codes need working outgoing mail: check
  **Authentication & Login → One-time code → Test connection** after a deploy.
- **Policy copy for Play review.** Migration 053 makes the public pages say what the app
  does. The **Privacy Policy** is rewritten to cover everything the Play Data safety form
  declares (identifiers, approximate location worked out on the phone, voice search,
  KYC fields with only an Aadhaar hash and last four digits kept, Cashfree checkout and
  payout details, activity and search history, Crashlytics, the install ID and push
  token, opt-in marketing, the named providers Cashfree, Firebase and Hostinger,
  retention periods, export and deletion); its sign-in code goes to the email only and
  deletion erases no "texts". The **Terms** gain per-booking Cashfree payment, escrow
  released on approval or after `booking.auto_complete_days` (the number is printed
  into the terms, the refund policy and the escrow FAQ when the migration runs), the
  wallet (earnings and refunds, nobody pays money into it), reports and blocking and no
  text messages. The **Refund policy** sends refunds to the original payment method, the
  **Community Guidelines** gain honest reviews, harmful content and how moderation works
  (their Reporting section is migration 054's), the **Contact** page loses its phone
  line, and the **About** page no longer says every creator is verified. Each page is
  replaced only while it still holds the seeded wording, so a page you rewrote in the
  panel is left alone: compare it with `database/seeds/002_cms_content.sql` by hand.
  It also rewrites seven seeded FAQs and adds "How do I report or block someone?",
  takes the guarantees out of two home banners and two onboarding slides, switches off
  the four seeded testimonials (sample quotes, never said by a customer) and empties
  `general.support_phone`, so the app shows no call option and nothing publishes a
  phone number. A list item that gives a phone number (a `tel:` link or nine or more
  digits after "Phone", "Telephone", "Call us" or "WhatsApp") is removed from any page;
  an item that only mentions phones, like the privacy policy's optional-phone bullet,
  stays. It also corrects two creator profile-completion hints from migration 037
  ("Verified reach ranks higher in brand search" and "Required before withdrawals" on
  the mobile-number rule), which the code does not back.

  The privacy policy says that marketing is opt-in **and** that accounts created
  before the sign-up opt-in had product updates switched on by default, because
  migration 055 keeps the `"marketing": true` the old sign-up stored for everyone.
  If you later switch those accounts off (re-consent), remove that sentence from the
  policy in the panel. Check after a deploy:

  ```bash
  curl -s https://yourdomain.com/api/v1/app/config | grep -o '"support_phone":"[^"]*"'   # "support_phone":""
  curl -s https://yourdomain.com/privacy | grep -c 'Crashlytics'                         # 1 or more
  curl -s https://yourdomain.com/privacy | grep -c 'switched on by default'              # 1
  ```
- **Reports and blocking.** Migration 054 adds what Google Play's user-generated content
  policy asks for. It creates `user_blocks` (one row per blocker and blocked account,
  removed with either account) and extends `reports`: campaign briefs can be reported,
  the reasons gain hate speech, sexual content, violence, self-harm and child safety, and
  two new columns record the account behind the reported content (`reported_user_id`,
  backfilled for reports already filed) and a copy of what was reported as it read at
  the time (`subject_excerpt`). The Community Guidelines' Reporting section becomes
  "Reporting and blocking" (only while it still holds the seeded wording; seed 002
  carries the same text). The rest is code: `POST /reports` takes every target with a
  reason and an optional note, `GET/POST/DELETE /me/blocks` manage blocks, and a block
  stops messages, booking requests, offers, invitations and applications between the
  two accounts and hides them from each other's search, while a booking already paid
  for can still be completed or disputed. **Abuse reports** links each report to the
  reported account, and the user page lists their blocks both ways. A moderator can
  take a reported message, brief, review or portfolio item down from the queue
  (`POST /admin/reports/{id}/take-down`, permission `reports.handle`; no schema
  change), and a suspended brand's briefs leave the board. A fresh install with demo
  data gets `reported_user_id` on the demo reports from seed 026, since seeds run after
  054's own backfill. Ship the matching app build: older builds can report a user from
  a chat only, and show a blocked chat's refusal as a failed message. Check after a
  deploy (with a signed-in token):

  ```bash
  curl -s -H "Authorization: Bearer $TOKEN" https://yourdomain.com/api/v1/me/blocks | grep -o '"success":true'   # "success":true
  ```
- **Sign-up consent.** Migration 055 applies the Play-review decisions of 2026-10-10.
  It adds `users.terms_accepted_at` (when an account accepted the Terms and Privacy
  Policy at sign-up; accounts made before 055 stay NULL, because that was never
  recorded), sets `auth.identifier_mode` = `email` and `auth.require_terms_accept` = 1
  (no longer a switch in the panel: every sign-up, Google included, needs
  `accept_terms`), and switches **Sign in with Apple** off
  (`integrations.apple_login` = 0; stored credentials stay). Sign-up needs an email
  address. Product updates and offers are opt-in from now on. **No stored preference
  is changed**: accounts that hold `"marketing": true` keep it, since whether each of
  them chose it cannot be known (sign-up used to store true for everyone). What did
  change is the default for an account with **no** stored choice (guests, accounts an
  operator created): it now counts as opted out, so campaigns and promotional
  broadcasts skip it. Campaigns re-read the choice before every batch, so a campaign
  that was already queued when you deploy keeps sending only to accounts that hold
  `"marketing": true`; its other pending rows end as failed ("not opted in to
  marketing"). Templates filed as `promo`/`marketing` (today `referral.rewarded`) now
  push and email only opted-in accounts. Settings → SMS now overrides `SMS_ENABLED` in
  `.env` whenever it holds a value, and while SMS is off `queue:dispatch` closes waiting
  SMS as not sent. An account with no phone (every new sign-up) types a mobile number
  at checkout for Cashfree (`customer_phone` on `POST /payments/booking/{id}`, used for
  that order only); an older app build cannot, and shows the `phone_required` message.
  While SMS is off (and contact codes are on), nobody can add or change a mobile number
  under Email & mobile, since a new number is proved by text: the contacts policy
  reports `allow_mobile_change` and `mobile_codes_available` false and the API answers
  `422 sms_unavailable` (code only, no schema change).
  Ship the matching app build: an older app neither shows the terms notice above Google
  nor sends `accept_terms`, so its first-time Google sign-ups get `422 terms_required`
  (existing accounts sign in as before). Check after a deploy:

  ```bash
  curl -s https://yourdomain.com/api/v1/app/config | grep -o '"key":"apple","enabled":[a-z]*'   # false
  ```
- **Bookings paid directly, no wallet top-up.** Migration 056 applies decision U2 of
  2026-10-10. It adds **Settings → Wallet → Wallet top-up ("Add money")**
  (`wallet.topup_enabled`) switched **off**: brands pay each booking through Cashfree's
  checkout, `POST /wallet/topup` answers `403 topup_disabled`, and `/app/config` →
  `wallet.topup_enabled` = false tells the app to hide every "Add money" button, preset
  and prompt. A re-run keeps whatever an admin has since chosen. It also lists the
  switch under "Wallet enabled", says that the top-up limits only apply while it is on
  and what **Payments → Allow wallet payment** now means, and rewrites the seeded
  "What payment methods are accepted?" FAQ (only while it still holds the seeded text).
  It runs after 053: in the Refund & Cancellation Policy (version bumped) and the
  "Can I cancel a booking?" and "What happens if a creator does not deliver?" FAQs, it
  adds when a refund goes to the wallet instead of the original method (a payment that
  cannot be returned there automatically, or one support agrees with the brand) — each
  only while it still holds 053's wording, so a re-run or an admin's rewrite is left alone.
  No migration is needed for the rest, which is code: refunds go back to the original
  payment method by default (the wallet only for a wallet payment, an admin's choice,
  or a payment no gateway can reverse; a dispute's refund is no longer forced into the
  wallet), the checkout offers the wallet only while its balance covers the whole
  booking, and `GET /payments/methods` lists it only while it holds money. Refunds still
  `processing` at Cashfree count against what can be refunded again; a dispute's refund
  is sent only after the resolution is saved; and a refund confirmed after its booking
  finished no longer sets the booking's escrow back to "held". Top-ups
  already in flight at deploy time still credit the wallet when Cashfree confirms them.
  Make sure **Payments → Cashfree enabled** is on with live credentials before you
  deploy: it is now the only way a brand can pay. Check after a deploy:

  ```bash
  curl -s https://yourdomain.com/api/v1/app/config | grep -o '"topup_enabled":[a-z]*'   # false
  ```
- **Creator tools.** Migration 057 goes with decision U1 of 2026-10-10 (creators
  manage their own packages, portfolio and social accounts in the app). It adds the
  setting `creator.max_portfolio_items` = 100: the most items one creator may keep in
  their portfolio, items still in review included (0 = no limit). A re-run keeps
  whatever value the row already holds. Adding past it answers `422 portfolio_full`,
  and `POST /me/creator/portfolio` now has its own throttle (30 per 10 minutes per
  account). No migration is needed for the rest, which is code: a creator's starting
  price (`creators.price_min` / `price_max`) falls to 0 when no live package is left,
  instead of keeping the last one (the nightly statistics refresh corrects any creator
  still showing an old price); a booking that names a package is checked against the
  booking minimum and maximum at the package's price; a social account's `label` is
  kept only for a custom link; linking a second account on a network that has one is
  refused (edit it instead); and a link-only row stores no follower figures. Ship the
  matching app build: older builds offer an already-linked network again and show the
  refusal as an error. Check after a deploy (with a creator's token):

  ```bash
  curl -s -H "Authorization: Bearer $TOKEN" https://yourdomain.com/api/v1/me/creator/portfolio | grep -o '"max_items":[0-9]*'   # "max_items":100
  ```
- **Profile completion with SMS off; masked request logs.** Migration 058. With SMS
  off nobody can add or confirm a mobile number, yet migration 037 had made *Add a
  mobile number*, *Confirm your mobile number* and the *Mobile verified* badge
  mandatory, so every creator, the review account included, carried a mandatory item
  they could not act on. The migration makes those rules optional (found by what they
  read, not by name), takes `mobile_verified` out of every feature gate's required
  badges (`accept_bookings` and `apply_to_campaigns` keep `email_verified`) and out of
  `creator.required_verifications`, and deletes the stored completion rows that list a
  mobile rule as missing (a cache; each creator's refills on the next read). Run
  `creators:completion` from System → Jobs after the deploy to rescore everyone at
  once, or wait for the nightly run. It also gives `sms.enabled` back its definition
  (boolean, label, description, sort order) where the row had been re-created bare; the
  value is never touched. Finally it masks what the request log already holds: phone
  numbers keep their last four digits behind six stars and email addresses their first
  character and domain, under any key naming a phone, mobile, msisdn, WhatsApp number
  or email, plus `identifier`, `destination` and `recipient`; a phone-shaped value
  under `value`, `new_value`, `old_value` or `contact` (a refused
  `POST /me/contacts/change` or `/me/contacts/alternates` sends
  `{"channel": "email|phone", "value": …}`); and every other email address in a body,
  in a list or inside text (a file name such as `logo@2x.png` is left alone). On a
  large `api_logs` table those steps scan every stored body once each. The code that
  goes with it: while
  SMS is off the completion engine does not score those rules, no gate asks for that
  badge, `GET /me/creator` does not offer it as a badge to earn and
  `GET /me/creator/completion` adds `applicable` per section; switching SMS on brings
  them back as optional items with no data change. The *Email verified* badge is
  re-checked whenever a breakdown is read or rescored, so a creator in review whose
  address is confirmed is no longer told it is missing (the nightly badge sweep covers
  approved creators only). `ApiLogService` now masks by key
  pattern at any depth (it matched only the exact keys `email` and `phone`, so a failed
  `POST /payments/booking/{id}` kept `customer_phone` in the clear), the `value` beside
  a contact channel, a phone-shaped `value` and any email address. Ship the matching
  app build: its completion card lists only what the creator can finish in the app and
  opens that screen (KYC only while it needs the creator's action), and the portfolio
  viewer can report a post. Check after a deploy (with a creator's token):

  ```bash
  curl -s -H "Authorization: Bearer $TOKEN" https://yourdomain.com/api/v1/me/creator/completion | grep -c 'phone_verified\|Mobile verified'   # 0
  ```
- **Payment protection, not escrow; the Grievance Officer.** Migration 059 applies
  decisions P-ESCROW and P-GRIEVANCE of 2026-10-10. Money settles from Cashfree into
  Zusty's own account and no regulated escrow or nodal arrangement is confirmed, so no
  copy users or Google read says "escrow": the brand's payment is *held by Zusty*,
  released on approval or automatically `booking.auto_complete_days` after delivery,
  and reviewed by our team before any money moves if there is a dispute. Internal names
  (`escrow_status`, `escrow_hold` / `escrow_release`, the `escrow:release` job key) are
  unchanged. Edited in place, each only while it still holds the seeded wording (an
  admin's rewrite is left alone), each page's version bumped once:
  - **Privacy** — section 2 says what the code keeps of a payment (Cashfree's
    references, amount, status and method type, with card details, UPI ID and contact
    details removed; a net banking reply can still name the bank), that the wallet also
    holds referral rewards, and that a checkout phone goes to Cashfree with that order
    only while our request log keeps its last four digits (this relies on the
    `ApiLogService` masking that ships in the same deploy); section 5 says "on hold";
    section 8 names the **Grievance Officer, Appsgain Technologies Private Limited** at
    support@zusty.app (24-hour acknowledgement, 15-day resolution, DPDP Act requests).
  - **Terms** sections 5, 6 and 17, the **Refund & Cancellation Policy** (heading,
    subtitle, disputes, meta description), **About** (with the review window printed
    in), **Community Guidelines** and **Contact** (a Complaints item).
  - FAQs "How does Zusty work?", "Is my payment secure?" and "What happens if a creator
    does not deliver?"; templates `payment.success`, `campaign.hired` and
    `booking.disputed`; the first home banner and the third onboarding slide; the
    `escrow:release` job's name ("Release held earnings"); and the two booking-timeline
    notes BookingService wrote before ("Payment captured and held by Zusty.",
    "Completed by an administrator; payment released.").
  - Settings: `app.android_store_url` becomes the `com.zuste.zuste` listing when it is
    empty or names `com.zuste.app`, and `referral.share_base_url` becomes
    `https://zusty.app/invite/` when it is still on zuste.app or zustee.com. `social.*`
    and `seo.twitter_handle` are not touched.
  - The switched-off sample testimonial that opened "Escrow changed everything…" now
    says "Payment protection changed everything…".
  - Stored Cashfree replies (`payments.gateway_response`, `payment_webhooks.payload`):
    a one-time scrub removes the payer's phone from the `app` (wallet), `pay_later` and
    `cardless_emi` objects and the `card` / `emi` / `card_emi` objects, so the privacy
    policy's "card details, UPI ID and contact details taken out" holds for every
    method. `PaymentService` drops the same keys from new replies (same deploy). Each
    statement removes only keys whose parent exists, because MariaDB 10.4's
    `JSON_REMOVE` returns NULL for a path whose parent is missing.

  No migration is needed for the rest, which is code: the landing page, the admin
  labels ("Payment held", "Held for bookings"), the API's dispute message and the
  deletion blockers ("on hold"), and `/app/config` → `booking.auto_complete_days`,
  which the app quotes wherever it explains payment protection. The six landing
  screenshots the page no longer uses were deleted from `public/assets/landing/screens`.
  Ship the matching app build (older builds still say escrow). Check after a deploy:

  ```bash
  curl -s https://yourdomain.com/privacy | grep -c 'Grievance Officer'                    # 1 or more
  curl -s https://yourdomain.com/ https://yourdomain.com/terms | grep -ci 'escrow'         # 0
  curl -s https://yourdomain.com/api/v1/app/config | grep -o '"auto_complete_days":[0-9]*' # e.g. 7
  mysql -e "SELECT COUNT(*) FROM payments WHERE JSON_VALID(gateway_response) AND JSON_CONTAINS_PATH(gateway_response,'one','$.payment_method.app.phone','$.payment_method.emi','$.payment_method.card')" zuste_marketplace   # 0
  ```
- **Copy polish before the Play release.** Migration 060. Every statement changes a
  row only while it holds the exact old wording (an admin's own text is kept), and a
  second run changes nothing:
  - **Home banners.** The app cuts a banner subtitle after two lines (about 33
    characters a line on a 360 dp phone). Banner 1 becomes "Verified creators, clear
    pricing and payment protection." (was 80 characters, lost its last word), banner 2
    "Set your packages, get booked, get paid on approval." (was 70, whole on a 411 dp
    phone but not on a 360 dp one) and banner 3 "Earn a reward once an invited friend
    completes a booking." (was 78).
  - **Popups** shown inline (banner, announcement bar) get two small lines beside the
    call to action. Demo rows 1518 and 1519 become "Creators apply with their rate.
    Pick who to hire." and "Save creators to a list and compare them side by side."
    (1519 also promised saved searches and rate history, which the app does not
    have). Installs without demo data have neither row.
  - **Onboarding slide 3**: "Collaborate and get paid on approval." (was "…get paid
    safely.") with the subtitle "Briefs, offers and chat in one place. Zusty holds the
    payment until the work is approved." (was 105 characters).
  - **Home sections.** "Top Creators For You / Handpicked for your brand" lists
    creators by rating for every audience and becomes "Top-rated creators / Highest
    rated first"; "Trending Now / Most booked this week" lists them by their
    self-reported follower total and becomes "Largest audiences / Ranked by
    self-reported followers". A rename is skipped if a section with the new title and
    the same type already exists (the unique key seed 003 upserts on). The code makes
    the headings true: these rails, and "New on Zusty", are now in the order they
    name alone (`AppContentService::creatorsFor` passes `featured_first = false`;
    before, the featured creators filled them whatever their rating or sign-up date),
    and the top-rated rail leaves out creators with no reviews, so it is hidden until
    someone has been rated. Search and Explore still put featured creators first.
  - **Privacy policy** section 2: the wallet also holds "any corrections our team
    makes to fix an error" (Admin → Users → Wallet adjustment, ledger category
    `adjustment`); "Last updated: 11 October 2026"; version bumped once.
  - **Stored notifications** sent before 059 changed the templates: the sentences "Your
    funds are held safely in escrow." (`payment.success`), "The brand will raise a
    booking so the payment can be held in escrow." (`campaign.hired`) and "The payment
    stays in escrow until our team has reviewed it." (`booking.disputed`) are replaced
    with the templates' current wording. Only `notifications.body` changes (the titles
    never carried them); `email_queue` and `push_queue` are history and are left alone.

  Seeds 002, 003, 009 and demo/026 carry the same copy for fresh installs. The code
  that goes with it: the landing page's lead says brands book "choosing one of a
  creator's fixed-price packages or naming their own budget" (not "at clear, fixed
  prices"), and the password-reset and verification-code emails say "you can ignore
  this email" (no "safely"). Ship the matching app build: the booking form fills in
  the title and budget of the package picked on the profile, the Home fallback
  headings match the sections above, and counts read "1 review", "1 day" and so on.
  Check after a deploy:

  ```bash
  curl -s https://yourdomain.com/privacy | grep -c 'corrections our team makes'                   # 1
  curl -s https://yourdomain.com/ | grep -ci 'fixed prices'                                       # 0
  mysql -e "SELECT COUNT(*) FROM notifications WHERE (type='payment.success' AND body LIKE BINARY '%Your funds are held safely in escrow.%') OR (type='campaign.hired' AND body LIKE BINARY '%so the payment can be held in escrow.%') OR (type='booking.disputed' AND body LIKE BINARY '%The payment stays in escrow until our team has reviewed it.%')" zuste_marketplace   # 0
  mysql -e "SELECT id, CHAR_LENGTH(subtitle) FROM banners WHERE is_active=1 AND CHAR_LENGTH(subtitle) > 65" zuste_marketplace   # no rows
  mysql -e "SELECT title, subtitle FROM homepage_sections WHERE data_source IN ('top_rated','trending')" zuste_marketplace
  ```

---

## Cron jobs

hPanel → **Advanced → Cron Jobs**. Either one entry every minute running
`php scripts/cron.php --due`, which starts each job below on its schedule (as stored
under System → Jobs), or one entry per job. `php scripts/cron.php --crontab` prints
the complete per-job list, one ready-to-paste line per job, with this server's PHP
binary and absolute paths. Every job `php scripts/cron.php --list` reports:

| Schedule | Job | Does |
|---|---|---|
| `* * * * *` | `queue:dispatch` | Sends queued email, SMS and push |
| `*/5 * * * *` | `automation:run` | Evaluates active automation rules and performs their actions |
| `*/5 * * * *` | `marketing:dispatch` | Queues and sends scheduled and in-flight marketing campaigns |
| `*/10 * * * *` | `privacy:finish-deletions` | Retries the clean-up after an account is erased (uploaded files, data exports, KYC documents, the Firebase sign-in record) until it succeeds |
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
| `0 3 * * *` | `tokens:prune` | Deletes expired tokens, OTPs and rate-limit buckets; sent email, SMS and push copies after 7 days and failed or unsent ones after 30; payment gateway callbacks after 90 days; backups past `BACKUP_RETENTION_DAYS` |
| `0 4 * * *` | `auth:purge-guests` | Deletes expired guest sessions and their guest accounts |
| `0 4 * * 0` | `logs:rotate` | Weekly log rotation; deletes sign-in history older than a year and API request logs older than 180 days |

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
php8.3 scripts/cron.php --crontab       # a crontab line for every job, from scheduled_jobs
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

> **Keep off-site copies on the same clock.** The privacy policy tells users a backup
> is kept for up to `BACKUP_RETENTION_DAYS` days (14 by default) and then deleted, and
> `tokens:prune` deletes older dumps on the server every night. Give the S3 bucket a
> lifecycle rule that expires objects after the same number of days, and pull with
> `rsync -avz --delete` so a dump deleted on the server goes from your copy too. If you
> change `BACKUP_RETENTION_DAYS`, update the number in section 5 of the Privacy Policy
> (Content → Pages).

> **Keep `storage/privacy/erasure-ledger.jsonl` with the server.** It records every
> erased account outside the database, and a restore uses it to erase them again
> (below). Copy it along when you move to another server.

### Restore

```bash
php8.3 scripts/deploy_helper.php restore storage/backups/DUMP.sql.gz DATABASE_NAME
```

The database name is required as confirmation, so a restore cannot be run by pressing
up-arrow and enter.

A dump can predate accounts that were erased after it was taken, and the privacy
policy promises those are deleted again straight away. A restore from **System →
Backups** in the admin panel does that by itself, and so does `deploy.sh --rollback`.
After a restore with `deploy_helper.php`, run it yourself:

```bash
php8.3 scripts/privacy_support_request.php reapply-erasures
```

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

This asks for the database name before overwriting anything, then erases again every
account deleted since the backup was taken.

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
- [ ] `GET /api/v1/app/config` shows `auth.firebase.enabled: true` and, for
      Android, a non-empty Google `server_client_id`. Until both are set the app
      hides Google sign-in (the server reports Google, Apple and Facebook as off
      while Firebase is incomplete) and push cannot register.
- [ ] Payments: Cashfree is the only card/UPI gateway the app can complete
      (hosted checkout). Razorpay and Stripe are hidden in the app even if enabled.
