# Admin Panel Manual

For operators. Every module in the sidebar, what it does, and the decisions it asks
you to make.

**URL** — `https://yourdomain.com/admin`
**Sign-in** — email and password, plus a 6-digit code if two-factor is enabled on your
account.

Five failed attempts lock an account for 15 minutes. Both numbers are configurable
under Settings → Security.

---

## Reading the panel

The sidebar is grouped by job, not by database table. **You only see the modules your
role permits** — an empty-looking sidebar means a narrow role, not a broken install.

Conventions that hold everywhere:

- **List screens** — search, filters, sort, per-page, bulk actions, and export. Filters
  survive in the URL, so a filtered list is shareable and bookmarkable.
- **Destructive actions** always confirm, and most delete softly into the recycle bin.
- **Every change is logged.** Who, what, when, and from which IP — visible under
  Access → Activity Log.
- **Ctrl/⌘ + K** opens global search from any screen.

---

## Overview

### Dashboard

The landing screen: **105 widgets** across KPI cards, charts, lists, tables, activity
feeds, progress meters and quick actions.

- **Customise** — drag to reorder, resize, hide. The layout saves per admin, so your
  dashboard is yours.
- **Date range** — every widget respects the range picker.
- **Auto-refresh** — live widgets poll; the rest load once.

Widgets you have no permission to see are never sent to the browser, not merely hidden
in CSS.

Numbers come from `analytics_daily` roll-up tables written by the nightly
`analytics:rollup` job, not from scanning raw rows — which is why the dashboard stays
fast as data grows. **If the dashboard looks stale, check that cron job first.**

### Analytics

Deeper than the dashboard: revenue, bookings, users, creators and traffic over time,
with comparison against the previous period. Every chart exports to CSV or Excel.

---

## Marketplace

### Users

Every account, brand and creator. Search by name, email, phone or ID.

Per user you can: view the full profile and activity, verify or unverify, suspend or
reactivate, adjust the wallet (with a mandatory reason, written to the ledger), impersonate
for support, force a password reset, and export.

**Suspension is immediate.** The user's tokens are revoked, so open apps are signed out
on their next request.

**Safety** (on the user page) lists the accounts this user blocked and the accounts
that blocked them, with dates, and links to every report about them. Blocks are made by
users in the app and cannot be made or lifted from the panel. While a block stands the
two accounts cannot message each other or start a booking, an offer, a campaign
invitation or an application with each other, and they drop out of each other's search;
a booking already paid for between them can still be delivered, approved or disputed,
and you resolve its disputes as usual. An account that many people block is worth a
look even before anyone reports it. Erasing an account removes its blocks both ways.

**Erase account erases.** The Danger zone's **Erase account** (`users.delete`) runs the
same erasure as the user deleting their account in the app: personal data removed,
the row left as an anonymous "Deleted user" for the records the law keeps, and the
same confirmation sent to the user. It cannot be undone or restored, so it asks for
the same two things as Privacy requests: type `DELETE`, and write how you confirmed
the request comes from the owner (stored with the deletion record). Without both,
nothing is changed. Anything still in progress (a booking, a dispute, money in the
wallet, a withdrawal or refund) refuses it with the reasons. To stop someone using
Zusty without erasing their data, suspend or ban them instead. For a request the owner
sent by email, Privacy requests → Act on a request (below) also shows what would be
erased and kept, and can give up a small balance.
An erased account's wallet still takes a wallet adjustment from here, which is how
money still owed to or by it is settled; nobody is notified.

**KYC** lives under this module (`/admin/kyc`). Submitted documents are decrypted for
review only when opened, and each decision — approve or reject with a reason — is
recorded against the reviewer.

### Privacy requests

`/admin/privacy-requests`. Users download their data and delete their accounts
themselves, from Settings → Privacy & data in the apps, after confirming it is them.
Someone who cannot use the app writes in instead, and you act for them here. Three
tabs:

- **Account deletions** — when, brand or creator, the reason they chose and anything
  they wrote, how it was confirmed (Password, Email code, SMS code, Google/Apple
  sign-in, **Support request (staff)**, or **Closed before erasure existed**), where
  from (Android, iOS, web, or **Support**), who did it (the account owner or the staff
  member), the clean-up, how many records were kept, and **Gave up ₹X** when the owner
  gave up a balance that could not be paid out. A deletion row has no name or contact
  details, because the erasure removed them. "Why people leave" totals the reasons.
- **Data exports** — who asked, when, the archive size, downloads used, when the link
  expires, and the requesting IP. The status is the link's real state: Ready, Used up,
  Expired, Link revoked (after a password change or "sign out everywhere"), Preparing
  or Failed. "Export links still live" counts only links someone could use right now.
  If someone says "I never asked for this", this is where you check.
- **Act on a request** — find an account by ID or email, see what an erasure would do
  to it (anything in the way, what is erased, what is kept), then **Erase account** or
  **Email a copy of the data**. Below it, **Deleted by staff but not erased** lists
  accounts deleted from the user page before Delete became an erasure: hidden, but
  with their name and contact details still stored. Review each one and erase it
  deliberately.

**Handling a request sent by email** (deletion or a copy of the data):

1. Confirm the request comes from the account's owner. Reply to the email address on
   the account — not to a different address the request came from — and act only on
   an answer from it. Never send account details to any other address.
2. Find the account under **Act on a request**.
3. To erase: read what is in the way and settle it first (the same blockers as the
   app). Type `DELETE` and write how you confirmed the request (for example "Ticket
   1234, confirmed by reply from the account email"); the note is stored with the
   deletion record. If the owner has a small balance that cannot be paid out and has
   agreed to give it up, tick the box that names it; otherwise pay it out first. The
   owner gets the usual confirmation.
4. To send a copy: **Email a copy of the data** prepares the same ZIP the app makes and
   emails the download link to the account's own address. The link is never shown in
   the panel, and the account's cooldown applies.

Erasing needs `users.delete`; sending a copy needs `users.edit`. **Users → Erase
account** erases too, with the same typed `DELETE` and note, but cannot give up a
balance, so use this tab for requests. The same actions work from the command line:

```bash
php scripts/privacy_support_request.php delete --user=ID_OR_EMAIL --admin=YOUR_ADMIN_EMAIL \
    --note="how the request was confirmed"          # shows what would happen; changes nothing
php scripts/privacy_support_request.php delete ... --confirm [--forfeit=AMOUNT]
php scripts/privacy_support_request.php export --user=ID_OR_EMAIL --admin=YOUR_ADMIN_EMAIL
```

**Clean-up.** The erasure itself is immediate. Deleting the uploaded files, data
exports, KYC documents and the Firebase sign-in record comes straight after it, and the
`privacy:finish-deletions` job (every 10 minutes) retries anything that failed. The
Clean-up column says **Completed**, **Clean-up pending** (what is left and the next
try) or, after 8 failed tries, **Needs attention** with the last error, such as
Firebase not being configured; fix the cause and the job finishes on its next run. The
"Clean-ups pending" figure counts the unfinished ones.

**Accounts closed before erasure existed.** Before migration 043 the app's "Delete
account" only hid an account. Erase those once, after deploying 048, with
`php scripts/privacy_erase_legacy_deletions.php --dry-run` (lists them) and then
without `--dry-run`. They appear as "Closed before erasure existed".

A deleted account becomes an anonymous **Deleted user** everywhere. Bookings,
payments, invoices and refunds stay, as tax and accounting law requires; tax invoices
and payout statements keep the billing name, address and GSTIN printed on them.
Reviews stay without the name. It cannot be restored, and it is not in the recycle
bin. The export link lifetime, download limit and cooldown are under Settings →
Security, and expired archives are deleted by the `privacy:purge-exports` job (System
→ Jobs).

### Creators

Creator profiles and the approval queue. Status runs
`draft → pending_review → approved / rejected / suspended`.

The sidebar badge counts creators awaiting review. Approving publishes the profile to
search; rejecting requires a reason, which the creator sees.

Also here: featured flags, search boost, category assignment, package and portfolio
review, and per-creator earnings.

Creators manage their own **packages, portfolio and social accounts** in the app
(Profile → For creators). What that means for you:

- **Portfolio moderation.** With the creator setting *Moderate gallery uploads* on
  (the default), every new portfolio item arrives *pending* and stays off the
  public profile until approved under **Profile changes**. The creator sees it as
  *In review*; a rejected item shows them the reason's **label** — never your
  free-text note.
- **Portfolio size.** The creator setting *Maximum portfolio items*
  (`creator.max_portfolio_items`, migration 057, default 100; items in review
  count, 0 = no limit) caps each creator's portfolio, and adding is limited to 30
  uploads per 10 minutes per account. Like the other `creator` settings it has no
  tab under Settings yet; it is a row in the `settings` table.
- **Package prices** must sit between Bookings → minimum and maximum booking
  amount, because a package's price becomes the booking amount. Raising the
  minimum does not touch existing packages, but a package now priced outside the
  bounds cannot be booked (the brand is told why) until its creator raises it.
- **Starting price.** A creator's "Starting at" / "From" price is their cheapest
  live package. With no live package left it is cleared, so the profile and the
  search cards show no price rather than an old one.
- **Social figures are self-reported.** Followers and engagement are what the
  creator typed; the app labels them so on the creator's profile and in search,
  and the date shown is when the creator entered them. A network reporting figures
  holds one account per creator, and the name shown is always the network's (only
  a custom link carries the creator's own name). When a creator changes the handle
  or link of an account you marked verified, the mark is cleared until you check it
  again.
- **Section locks** (a creator's page → *Profile section locks*: pricing,
  portfolio, social) refuse the creator's changes with your reason, and the app
  shows the section as locked.
- **Profile completion** (Marketplace → Profile completion) scores each profile
  against the completion rules. While SMS is off (Settings → SMS), the rules and
  the badge that only a text message can meet — *Add a mobile number*, *Confirm
  your mobile number* and *Mobile verified* — are not scored, not shown to the
  creator and not required by any feature gate, because nobody can add or confirm
  a number without one. Migration 058 also made them optional and took Mobile
  verified off the gates and out of `creator.required_verifications`. Switch SMS on
  and they count again, as optional items (make one mandatory again in the rules
  table if you want it to be). After changing SMS or a rule, run
  `creators:completion` under System → Jobs to refresh the list at once; each
  creator's own breakdown refreshes the next time it is opened, and every profile
  is rescored nightly. The *Email verified* and *Mobile verified* badges are
  re-checked whenever a breakdown is read or rescored, for creators in review as
  well as approved ones (the nightly `creators:badges` sweep covers approved
  creators only), so a confirmed address is never listed as a missing badge. The
  app lists only
  the sections a creator can finish in the app (contact details, social accounts,
  portfolio, packages, KYC and the profile photo), and leaves KYC out while it is
  under review or approved; feature gates are shown here
  but nothing on the platform withholds a feature by them yet.

### Categories

The taxonomy behind search and browsing. Drag to reorder — that order is what the app
shows. A category with creators attached cannot be deleted; move them first.

### App lists

Every option list the apps render — sort orders, platforms, languages, genders, age
bands, experience levels, response windows, campaign types, deliverables, industries,
support topics. Each list holds ordered terms with an optional icon, colour and
metadata.

These used to be hard-coded in the Flutter source, where changing one meant a store
release. Now the apps fetch the whole set in one call and cache it, so an edit here
reaches Android, iOS and Web without a code change.

Renaming a term's **label** is safe; changing its **value** is not — existing rows
still hold the old value. Add a new term and retire the old one instead.

### Bookings

The operational heart. Every booking with its full status history, participants,
deliverables, payment and messages.

Operator actions: force a status change (recorded with your name and reason), release
or refund the held payment, resolve a dispute, and cancel with a fee override.

The sidebar badge counts open disputes. **Decisions on a held payment are
irreversible** — read the deliverables and the message thread before releasing or
refunding.

**"Escrow" is an internal name only.** The code and database still say escrow
(`bookings.escrow_status`, the `escrow_hold` / `escrow_release` ledger categories, the
`escrow:release` job), but the money settles to Zusty's own Cashfree account and no
regulated escrow or nodal arrangement exists, so nothing users or Google read may call
it escrow (decision P-ESCROW, migration 059). The panel shows **Payment held**; in copy
you write (pages, FAQs, banners, notification templates, popups), say *payment
protection* or *held by Zusty*: the brand's payment is held by Zusty, released to the
creator on approval or automatically `booking.auto_complete_days` after delivery, and
reviewed by our team before any money moves if there is a dispute. Never write escrow,
nodal, trust account, guaranteed or 100% safe; the `17_public_pages` suite checks every
active page, FAQ, template, banner, slide, popup and testimonial for those words.

### Reviews

Moderate reviews. Approve, reject, hide or delete; reply as the platform. Rejecting
recomputes the creator's average rating immediately.

---

## Finance

### Payments

Every gateway transaction with its status, method, fees and webhook trail. Where a
webhook failed signature verification, you see that it was recorded and ignored — the
raw payload is retained for investigation.

Refunds are initiated here and reconcile against the wallet ledger.

**Where a refund goes.** Brands pay each booking at checkout and cannot top their
wallet up (Settings → Wallet → Wallet top-up is off), so refunds default to the
**original payment method** — on the booking screen, the payments screen, and when
resolving a dispute ("Refund goes to"). A Cashfree refund shows `processing` until
Cashfree confirms it by webhook; that is normal, not a rejection. Pick **Zusty wallet**
only when there is a reason to; it is credited at once. Two cases go to the wallet
whatever you pick: a booking that was paid from the wallet, and a payment that cannot
be reversed automatically (no refund API for its gateway, or no gateway id on the
row — the refund's notes say so). A refund the
gateway refuses is recorded `failed` on the original method and is never moved to the
wallet automatically: read the reason under Finance → Refunds and re-issue it,
choosing the wallet if the original method cannot take it.

**A refund on its way back is not refundable again.** While a refund is `processing`
(or parked for approval), its amount is taken off what the booking and payments screens
offer, and the server refuses a second refund beyond what is left ("There is nothing
left to refund on this payment"). The Refunded column on Finance → Payments shows the
amount still in progress. If Cashfree later reports the refund failed, the amount
becomes refundable again.

**Resolving a dispute.** The resolution (dispute status, booking status, creator
payout) is saved first, and the brand's refund is sent only after that. The message
says what happened to the refund: credited to the wallet, sent to the original method
and waiting for the gateway, or **failed**, with the gateway's reason. A failed refund
leaves the dispute resolved and the payment still refundable: issue the refund again
from the booking (Admin actions → Issue refund) or Finance → Payments, to the wallet if
the original method keeps refusing. If the booking has moved on since the dispute was
raised (cancelled, say), the resolution is refused before any refund is recorded.

**Small refunds to the wallet.** A brand's wallet only receives refunds, and Wallet →
Minimum withdrawal applies to brands too: a brand holding less can spend it on a booking
the balance covers in full, but cannot withdraw it from the app (the app tells them to
ask support). So prefer the original payment method for a small refund; the refund and
dispute forms show the minimum next to the wallet option. If a brand asks for a small
balance to be paid out, pay it to their bank account or UPI ID and record it with a
wallet adjustment (debit) on the user's page.

If you ran promotions about adding money to the wallet (banners, popups, FAQs),
switch them off: the app no longer offers it.

### Wallets

Balance and locked balance per user, with the full append-only ledger. Locked ("on
hold" in the app) is a creator's earnings during `wallet.withdrawal_hold_days` after a
completed booking, or money reserved against a payout in flight; a booking's own
payment is held on the booking, not in a wallet.

Manual adjustments require a reason and are written as ledger rows like any other
movement. **Nothing in this panel edits a balance directly** — the ledger is the
source of truth, and the balance is derived from it.

### Withdrawals

The payout queue. Review the request, check the bank account (decrypted on view only),
approve or reject with a reason, and mark as paid once the transfer settles.

The sidebar badge counts pending requests. Approving debits the wallet; the money is
committed at that moment.

### Settlements

Platform earnings, commission collected, GST, and what is owed to creators, by period.
Exports for your accountant.

---

## Content

Ten modules sharing one CRUD engine, so they behave identically: **Pages, Blog,
Banners, Home Sections, FAQs, Testimonials, Redirects, Menus, Sitemap, Media**.

- **Pages** — legal and informational pages, with SEO fields and slugs. Six of
  them are also public web pages at the site root: `/about`, `/terms`, `/privacy`,
  `/refund-policy`, `/community-guidelines` and `/contact`. The app's sign-up screen
  links to `/terms` and `/privacy`, and the Google Play listing links to `/privacy`.
  Switching a page off takes it off the web too. `/account-deletion`, also linked
  from the Play listing, is generated from the same "what we delete / what we keep"
  copy as the app's deletion screen.

  The Privacy Policy is what Google compares with the app's Data safety form, so keep
  the two in step: a new kind of data, a new service provider (today Cashfree,
  Firebase and Hostinger) or a new permission needs a sentence in the policy before
  the build that uses it is released. Some pages quote settings as numbers, written in
  when migrations 053 and 059 ran; edit the copy if you change the setting:
  `booking.auto_complete_days` (terms section 6, the refund policy's Payment protection
  section, the about page's "Pay securely" step and the "Is my payment secure?" FAQ;
  the website's landing page and the app read the setting live), the KYC
  `retention_days_*` and
  `purge_superseded_after_days` settings and `BACKUP_RETENTION_DAYS` (privacy policy
  section 5). Copy must stay truthful: no ratings, user counts or guarantees, no phone
  number (support is `support@zusty.app` only), no SMS and no "escrow" (see Bookings).
  Privacy section 8, terms section 17 and the contact page name the **Grievance
  Officer, Appsgain Technologies Private Limited** at `support@zusty.app`: a
  designation, never a person's name, address or phone. Complaints with "Grievance" in
  the subject must be acknowledged within 24 hours and resolved within 15 days (IT
  Rules 2021, rule 3(2)); the same mailbox takes DPDP Act requests (access,
  correction, erasure, withdrawing consent). The policy also says that
  accounts created before sign-up asked for marketing consent had product updates on
  by default, because migration 055 kept that stored choice; if those accounts are
  ever switched off, delete that sentence (the `17_public_pages` suite checks it only
  while such accounts exist).
- **Blog** — posts with categories, cover images, scheduling and view counts.
- **Banners** — app and web placements with date windows and click tracking.
  On the app Home, a banner shows its title and its subtitle on up to two lines
  each, about 35 characters a line on a phone; anything longer ends in "…".
  Keep a subtitle to about 70 characters. The brand banner "Verified creators,
  transparent pricing, and payment protection on every booking." (81
  characters) loses its last word on a phone.
- **Home Sections** — what appears on the app home screen, and in what order.
- **FAQs** — grouped, orderable, with helpful-vote counts from the app.
- **Testimonials** — quotes with an approve step. Publish only quotes a real customer
  gave you and agreed to share: the four seeded ones are samples and ship switched off.
- **Redirects** — old URL → new URL, 301 or 302. Useful after a slug change.
- **Menus** — app drawer and footer navigation, built as a nested tree.
- **Sitemap** — regenerate `sitemap.xml` and ping search engines.
- **Media** — the upload library, foldered, with type and size filters. Files are
  validated by real MIME type rather than filename, and images are resized on upload
  when `gd` or `imagick` is available.

Anything with a slug carries `meta_title`, `meta_description` and `og_image`, which the
sitemap and the public pages both use.

---

## Marketing

- **Campaigns** — email and push campaigns: audience segment, content, schedule, then
  open and click statistics. Sending goes through the queue, so `queue:dispatch` must
  be running. An account is in an audience only if it opted in to product updates and
  offers (marketing is off by default); one with no stored choice is left out. The
  choice is read again before every batch, so someone who switches it off after the
  campaign was queued gets none of the rest: their row ends as failed, "Not sent: the
  account has not opted in to marketing." Campaigns queued before migration 055 are
  held to the same rule. The **Subscribers** audience is the exception — there the
  newsletter subscription is the consent — and any email address that unsubscribed or
  bounced after queueing is skipped ("Not sent: the address unsubscribed…").
- **Subscribers** — the marketing list, with source, status and export.
- **Coupons** — percentage or fixed discounts with usage limits, validity windows,
  minimum booking amount and per-user caps. Redemption history per coupon.
- **Popups** — in-app promotional popups with targeting rules, display frequency and
  impression/click counts.

---

## Engagement

- **Notifications** — send to a segment or an individual across push, email and SMS;
  edit the seeded templates; review delivery history. Templates use placeholders that
  are substituted per recipient. A broadcast is promotional: everyone gets it in the
  app's notification centre, but push and email go only to accounts that opted in to
  product updates and offers. The same goes for any template whose category is
  **promo** or **marketing** (today `referral.rewarded`): in-app for everyone, push,
  email and SMS only for accounts that opted in. File a transactional template under
  another category.
- **Support** — the ticket queue. Assign, reply, change priority and status, and close.
  The badge counts open tickets.
- **Reports** (Abuse reports) — what users report from the app's menus: profiles,
  messages, reviews, bookings, portfolio items and campaign briefs. Each row shows the
  reporter, what was reported with a copy of it as it read when the report was filed
  (a message or a brief may since have changed or gone) and any photo or link, the
  reason, the reporter's note, and the **reported account** with how many reports about
  it are still open and how many people have blocked it. A review's link opens that one
  review on the Reviews screen (a reported review is also marked **Flagged** there;
  **Keep** clears the flag and leaves it published). Messages and briefs have no
  screen of their own in the panel, so their link opens the account that posted them.
  Filter by status, subject, reason, or search the copy, notes and names; "N open
  reports" on a row lists every report about that account. **Investigate**, then
  **Resolve** (say what you did) or **Dismiss** (say why); both notes go to the audit
  trail.

  **Taking content down.** An open report on content has a red button that removes it
  and resolves every open report on the same thing in one step, with your note in the
  audit trail (`report.take_down`):

  | Reported | Button | What happens |
  |---|---|---|
  | Message | **Remove message** | Gone from the chat for both people; the inbox preview stops quoting it. The report keeps its copy |
  | Campaign brief | **Take down brief** | Cancelled and made private, so it leaves every creator's board; creators who applied are told it closed. Bookings already made from it carry on and are handled on their own pages |
  | Review | **Hide review** | Hidden and no longer counted in the rating (Reviews → Publish brings it back) |
  | Portfolio item | **Remove item** | Refused, so it leaves the public profile; it shows as rejected in Creator changes → Items |

  The person who posted it gets an in-app notice that it was removed for breaking the
  community guidelines, with support@zusty.app for an appeal; they are never told who
  reported it. A profile, an account or a booking has no take-down: suspend the account
  from its user page (a suspended brand's briefs leave the board with it), and cancel
  or refund a booking from the booking. Red reasons (child safety,
  self-harm, violence, hate speech, sexual content, fraud, harassment, impersonation)
  come first in any day's work; for child safety, act on the account at once and escalate
  as the law requires. One person can have one open report on the same thing; once
  you close it, they can report it again. The reporter is never told the outcome by
  the panel, and the reported person is never told who reported them. The badge counts
  open reports.
- **Contact Inbox** — submissions from the public contact form. The badge counts unread.

---

## Workflow

- **Approvals** — items requiring a second pair of eyes before they take effect.
  Configure which actions need approval and who can grant it. Each item shows what
  changes, who requested it, and the approval trail.
- **Automation** — trigger → condition → action rules, e.g. "when a booking is
  disputed, notify the finance team and flag the creator." Rules are evaluated in
  order; each records when it last fired.

---

## AI

**Settings → AI** is where AI is switched on for the app:

1. **Provider & API key.** Pick the provider (for example OpenAI), paste its API key,
   choose a model and **Save provider**. A new key is tested straight away; use
   **Test connection** to check again later. The key is stored encrypted and is never
   sent to the app — the app asks the server, and the server calls the provider.
2. **App features & limits.** Turn on **AI features enabled**, then choose which
   features the app offers: the **bio writer** for creators, the **campaign brief
   writer** and **creator matching** for brands. Set **requests per account per day**
   and a **monthly budget** in USD; requests stop for the month once spend reaches it.

The "Live in the app" line on that card shows exactly what installed apps are offering
right now. Apps pick up a change the next time they open or come back to the
foreground.

- **Overview** — usage, cost and activity across providers, with a read-only summary
  of the setup above.
- **Providers** — 7 providers: Anthropic, OpenAI, Google Gemini, Azure OpenAI, Mistral,
  Groq and OpenRouter, served by 5 adapters (the last three share the
  OpenAI-compatible one). Paste an API key, choose a default model, set a monthly spend
  cap, then **Test connection** — which makes a real one-token request rather than
  merely checking the key's shape.

  Keys are encrypted at rest with AES-256-GCM and are never rendered back to the
  browser; the field shows a masked placeholder, and leaving it blank on save keeps the
  stored key.

  **All seven ship disabled with no keys.** Nothing calls out until you configure it.

  Pricing is stored per model, per million tokens, and drives the cost figures. The
  Anthropic and OpenAI catalogues are seeded with published pricing; add models for
  other providers with the pricing from their own documentation.

- **Prompts** — the prompt library used by AI-assisted features, versioned and editable.
  `bio_writer`, `campaign_brief` and `creator_match` drive the app features; edit the
  wording freely. Length and output-format rules the app depends on are added by the
  server, so an edit cannot break what the app receives. Deactivating a prompt hides
  its feature in the app.
- **Usage Logs** — every AI call with provider, model, tokens, cost, latency and
  outcome. Filterable, exportable.

---

## Productivity

- **Global Search** — one box across users, creators, bookings, payments and content.
  Ctrl/⌘ + K from anywhere.
- **Saved Filters** — name a filter combination and pin it to the sidebar. Private to
  you unless shared.
- **Recycle Bin** — soft-deleted rows from every module that supports it. Restore or
  purge permanently. Purging is final. Erased accounts are not in it, and neither is
  anything that belongs to one: an account deleted by its owner or erased by staff has
  no personal data left to restore, and the records the law keeps still point at it.
  So the bin neither lists, counts, restores nor purges (one at a time, with Empty, or
  with the retention purge) the tombstone, the erased creator's profile, the erased
  brand's campaigns, or bookings, reviews, messages, files, packages, portfolio items
  and payout accounts keyed to an erased account.
- **Revisions** — content history with a side-by-side diff and one-click restore.
- **Import CSV** — bulk import with column mapping, a dry-run validation pass that
  reports errors per row before anything is written, and a full result report.

---

## Developer

Diagnostics. Restricted to the `developer.*` permissions, which only **Super Admin** and
**Admin** hold by default.

- **Overview** — PHP version, extensions, database size, cache driver, disk.
- **API Logs** — every API request: endpoint, status, duration, query count, memory,
  user. The place to look when the app reports something the panel says is fine.
  The body of a failed request is kept with secrets (passwords, tokens, codes, card,
  bank, UPI and identity numbers) replaced by `[REDACTED]`, and any phone number or
  email address masked to its last four digits or its first characters and domain,
  under any field name that says what it is (`customer_phone`, `alternateMobile`,
  `billing_email` …). So is the `value` of a contact change or an additional contact
  (`{"channel": "email|phone", "value": …}`), a phone-shaped `value` anywhere, and an
  email address wherever it appears in the body. Field names are always kept.
- **Query Monitor** — slow queries collapsed by normalised shape with occurrence
  counts, so one slow query run 400 times reads as one row with a count of 400 rather
  than 400 rows. Threshold under Settings → Security.
- **Cache** — inspect and clear.
- **API Tester** — issue authenticated requests against your own API from the panel.

  On the local built-in PHP server this times out, because that server handles one
  request at a time and the tester's request is the second. The panel detects it and
  says so. It works normally on real hosting.

---

## Access

- **Admin Users** — create admins, assign a role, grant or revoke individual
  permissions on top of the role, force 2FA, suspend.
- **Roles** — 7 roles by level: **Super Admin (100)**, Admin (90), Manager (70),
  Finance (60), Moderator (50), Content Manager (40), Support (30). 84 permissions in
  25 groups, edited as a matrix.

  Level matters beyond permissions: **an admin can never edit or delete an account at
  an equal or higher level.** A Manager cannot demote an Admin, and no one can delete
  the last Super Admin.

- **Activity Log** — every admin action with before/after values, IP and user agent.
  Filter by admin, module, action or date. This is your audit trail; it is not editable
  from the panel.

---

## Authentication & Login

**Login Methods** (`/admin/login-methods`) — every way into the platform, on one
screen: Email & Password, One-time code (OTP), Google, Apple, Facebook and Guest access.

The apps offer three ways in: email and password, a one-time code by email, and Google.
**Sign in with Apple is switched off** (migration 055); its stored credentials are kept,
and the API refuses Apple tokens while it is off.

**One-time codes** go to the account's email address. The **Firebase & flow** tab
sets which channels may carry a sign-in code (Email, SMS), whether a code alone signs
an existing account in (it never creates one), and the button label the apps show
("Log in with email code"). At least one channel that can deliver must be ticked: a
save with none, or with SMS alone while SMS delivery is off, is refused and changes
nothing (to stop code sign-in, switch "A code alone can sign someone in" off, or the
method itself). If SMS is later switched off while it is the only channel ticked, the
page warns you and the apps hide code sign-in until Email is ticked again. SMS is
offered only while it is ticked there
**and** switched on under Settings → SMS; with SMS unticked there is no phone sign-in
at all, Firebase phone sign-in included. Settings → SMS is the switch that counts: off
there means off, whatever `SMS_ENABLED` says in `.env`, and the SMS queue then sends
nothing (anything waiting is closed as not sent, rather than going out late). While SMS
is off and contact verification codes are on, nobody can add or change a mobile number
in the app (a new number is proved by text): **Email & mobile** shows no mobile change
or "Add another number" and says why, the API refuses such a request with
`sms_unavailable`, and the user's contact page in the panel shows "Mobile changes
allowed" as off. Brands without a number give one at checkout instead.

**Sign-up.** Every new account has an email address: there are no phone-only accounts,
since a number cannot be verified without SMS. **Sign-in identifier** only decides what
the apps' sign-in field asks for (Email, or Email or a number an older account already
has). Accepting the Terms and Privacy Policy is required on every sign-up, Google
included, and is no longer a switch; the app shows "By continuing, you agree to the
Terms and Privacy Policy" above the Google button, and each account records when it
accepted (`users.terms_accepted_at`). **Product updates and offers** are opt-in: an
unticked box at sign-up, off for a Google sign-up, and a switch in the app's
Settings → Notifications.

### Switching a method off is real

A disabled method is **refused by the API**, not merely hidden in the app. A
request using it is answered `403 auth_method_disabled` and recorded in the
sign-in history. That includes Google and Apple: the server inspects which
provider issued the Firebase token, so a disabled provider's tokens are rejected
rather than accepted because they happen to be valid.

Two consequences worth knowing:

- **Existing sessions keep working** until their access token expires (Settings →
  Security → access token TTL, one hour by default). When switching a method off
  you can tick **"Also sign out everyone using it"** to revoke those sessions
  immediately.
- **Password recovery is the one exception.** With recovery off, the endpoint
  still answers `200`, because a `403` would reveal which email addresses exist.

### Credentials

Client IDs, secrets, redirect URIs and the Apple `.p8` are stored encrypted with
AES-256-GCM and **never rendered back** — the field shows "Stored — leave blank
to keep it", and saving with it blank keeps the stored value. Use *Remove stored
value* to clear one, which also switches the method off.

Nothing is duplicated: Google and Apple credentials live in the same records the
Integrations hub already used, SMS gateway settings stay under Settings → SMS,
password policy under Settings → Security, and SMTP under Settings → Email. This
screen edits them in place rather than keeping a second copy that could drift.

### Connection tests

Each test proves what it honestly can:

| Method | What the test actually proves |
|---|---|
| **Apple** | Real proof. Signs a client-secret JWT with your `.p8` and presents it to Apple, which confirms whether the Service ID, Team ID, Key ID and key belong together. No side effect. |
| **Facebook** | Real proof. Requests an app access token, which needs both the app ID and the secret. |
| **Google** | Partial, and says so. Checks the client ID is well-formed, that Google is reachable, and that a Firebase project is set. **Google publishes no way to confirm a client ID without a real sign-in**, so the test does not claim to. |
| **One-time code (OTP)** | Checks every channel a code is offered on can deliver: outgoing mail configured for email codes, an SMS provider and key for SMS codes. Log in with an email code to prove delivery. |
| **Email & Password** | Checks the password policy and that outgoing mail is configured, since recovery depends on it. |

### What reaches the apps, and what cannot

Changes to enable flags, client IDs, redirect URIs, OTP timers, country codes,
button order, labels, terms links and the Firebase client options are published
through `GET /api/v1/app/config` and reach Android, iOS and web **on the next
launch or resume, with no redeploy**.

Some values cannot work that way, and the screen marks them **Build-time**:

- Android package name and signing SHA-1/SHA-256 — the keystore signs the APK
- iOS bundle identifier and the Sign in with Apple entitlement
- `google-services.json` / `GoogleService-Info.plist`
- Authorised JavaScript origins and console-side redirect URIs

These are stored so you can copy them into the Google and Apple consoles, and so
the panel can flag a mismatch. **Editing one here does not change an installed
app** — that needs a new build.

### Guest access

Off by default. When on, someone can browse without an account: they get a real
session, and favourites and follows they build up are kept.

Everything transactional is refused with `guest_upgrade_required`, which the app
uses to raise the sign-up sheet. When they do sign up, the **same account row is
converted in place** — so nothing they did as a guest is lost. Unconverted, idle
guests are soft-deleted by the `auth:purge-guests` job after the retention you set.

### Sign-in activity

The Overview tab shows 24-hour success, failure and refusal counts per method,
plus the ten most recent app sign-ins. The full, filterable, exportable history
is **Security → Login History**, filtered to `actor_type=user` — the same screen
that has always shown admin sign-ins.

---

## Security

- **Overview** — posture at a glance: 2FA coverage, recent failed sign-ins, active
  sessions, allow-list state.
- **Login History** — every attempt, successful or not, with IP, device and location.
- **IP Allow List** — restrict admin sign-in by exact IP, CIDR range or wildcard, IPv4
  and IPv6.

  Two guards exist because this is the easiest way to lock yourself out: **the first
  rule you add must cover your current IP**, and **you cannot disable the last rule
  that covers you.** While the list is empty, every IP is permitted — an empty list
  means "not configured", not "deny all".

- **Sessions** — active admin sessions with device and last activity. Revoke any, or
  all others at once.

Enable two-factor on your own account before anyone else's. Enrolment shows a QR code
generated on the server — the shared secret is never sent to a third-party chart
service — with recovery codes shown once, at enrolment.

---

## Integrations

**58 services in 16 categories**: SEO & Search, Analytics & Tracking, Push
Notifications, Email, WhatsApp, SMS Gateways, Payments, Social Login, Cloud Storage,
Maps & Location, AI Services, Monitoring & Errors, CDN & Performance, Security,
Platform Operations, and Authentication & Login.

> The three entries under **Authentication & Login** — Email & Password, One-time
> code (OTP) and Guest Access — carry no credentials of their own. They exist here for the
> enable flag, status, connection test and activity log, all of which the hub
> already provides. Configure them under
> [Authentication & Login](#authentication--login).

Each integration offers, where the service supports it: enable/disable, API key and
secret management, OAuth, webhook configuration with a copyable callback URL,
**Test connection**, **Sync now**, an auto-sync schedule, a status indicator, error
logs, usage statistics, last-sync time, and a link to the provider's documentation.

Things worth knowing:

- **Secrets are encrypted at rest and never rendered back.** A blank secret field on
  save means "keep what is stored" — it does not clear the value. Use the explicit
  clear action for that.
- **Some integrations mirror into Settings.** SMTP, Razorpay, Stripe, Firebase and
  others write both an encrypted credential blob and the `settings` rows the existing
  services already read, so connecting from either screen works and the two never
  disagree.
- **Some delegate.** Where a full module already owns a service, the integration card
  links to it rather than duplicating the configuration.
- **Test connection means different things by service.** Most make a real authenticated
  request. A few can only validate configuration — AWS S3 and SNS, for instance, are
  reported honestly as "configuration valid" rather than being given a fake green tick.
- **Auto-sync** runs through the `integrations:sync` cron job every 30 minutes. Each
  integration runs independently, so one failure never stops the rest.

---

## System

### Settings

**184 settings in 20 groups.** Fifteen are edited here as tabs — General, Branding,
Bookings, Fees & tax, Wallet, Referrals, Payments, Firebase, Email, SMS, Uploads, App
releases, SEO, Social links, Security. The rest are owned by their own modules: AI
settings live under AI, cache under Developer → Cache, KYC under Users → KYC.

The ones that change behaviour most:

| Setting | Effect |
|---|---|
| General → Maintenance mode | API returns `503`; the panel stays usable |
| General → Support phone | **Empty by default, and should stay empty**: Zusty publishes no phone number. While it is empty the app's Help & Support screen shows no call option; support is `support@zusty.app` |
| Fees & tax → Commission rate | Applied to every new booking quote |
| Fees & tax → GST | Applied on top of the platform fee |
| Bookings → Auto-expire hours | How long a creator has to respond |
| Wallet → Payout hold (days) | How long a creator's earnings stay on hold before the `escrow:release` job ("Release held earnings") makes them withdrawable |
| Bookings → Minimum / maximum booking amount | Also the range every creator package price must fall in |
| Wallet → Minimum withdrawal | Floor on payout requests, for brands (whose wallets hold only refunds) as for creators |
| Wallet → Wallet top-up ("Add money") | **Off by default.** Off: brands pay each booking at checkout, the app shows no "Add money" and `POST /wallet/topup` is refused. Switching it on lets brands preload money, which makes the wallet a prepaid payment instrument under RBI rules — take advice before you do |
| Payments → Allow wallet payment | Lets a brand pay a booking from balance already in the wallet; the checkout offers it only when the balance covers the whole total |
| Security → Max login attempts, lockout minutes | Sign-in lockout |
| Security → Slow query threshold | What Developer → Query Monitor records |
| App releases → Minimum version | Forces an app update |

Secrets in settings are stored encrypted and shown masked.

### System

Operations:

- **Health** — database, cache, queue depth, disk, cron freshness.
- **Jobs** — every cron job with last run, duration, outcome and next due. Run any
  job manually, or disable one. **If notifications are not arriving, check
  `queue:dispatch` here first** — it is the job whose absence is silent.
- **Backups** — take one now, download, restore, and see the retention window.
  Restoring overwrites the current database and asks you to confirm. It then erases
  again every account deleted since the backup was taken, from the record kept in
  `storage/privacy/erasure-ledger.jsonl`, as the privacy policy promises.
  `deploy.sh --rollback` does the same. After any other restore (for example a dump
  imported through the hosting panel), run
  `php scripts/privacy_support_request.php reapply-erasures` straight away. The policy
  also tells users a backup is kept for up to `BACKUP_RETENTION_DAYS` days (14 by
  default): delete any backup file you download, and any off-site copy, within the
  same window.
- **Logs** — application and system logs with level filters, and a clear action.
- **Optimize** — `OPTIMIZE TABLE` across the schema. Safe, occasionally useful after
  large deletions.

### What is deleted automatically

The privacy policy states these periods, and the jobs below keep them:

| Data | Kept for | Job |
|---|---|---|
| Copies of sent email, SMS and push messages | 7 days after sending | `tokens:prune` (daily) |
| Failed or never-sent messages | 30 days | `tokens:prune` |
| Payment gateway callbacks (webhooks), any outcome | 90 days | `tokens:prune` |
| Database backups | `BACKUP_RETENTION_DAYS` (14 by default) | `tokens:prune` and every backup |
| Sign-in history (Security → Login History) | 1 year | `logs:rotate` (weekly) |
| Activity log | 1 year | `logs:rotate` |
| API request logs (Developer → API Logs) | 180 days | `logs:rotate` |
| Debug, info and notice system logs | 60 days | `logs:rotate` |
| Data export archives | Until the link expires or is used up | `privacy:purge-exports` (hourly) |

If one of these jobs stops, the period stops holding: check System → Jobs. A job that
is disabled there does not run, whatever the crontab says.

---

## Daily routine

1. Dashboard — anything anomalous in today's numbers?
2. Badges — pending creators, disputes, withdrawals, tickets, reports, contact messages.
3. System → Jobs — every job green, especially `queue:dispatch`.
4. Security → Login History — unexpected sign-ins or repeated failures.
5. Withdrawals and KYC — the two queues where people are waiting on you.

## Weekly

- Review Activity Log for anything you did not expect.
- Confirm a backup exists **and restore one to a scratch database** — an untested
  backup is a hope, not a backup.
- Integrations → check for services showing errors.
- Access → Admin Users — remove anyone who has left, narrow anyone over-permissioned.

---

## When something looks wrong

| Symptom | Look here first |
|---|---|
| Nothing is being emailed or pushed | System → Jobs, `queue:dispatch` |
| Dashboard numbers are stale | System → Jobs, `analytics:rollup` |
| A payment shows unpaid but the user paid | Payments → the webhook trail |
| Held earnings did not become withdrawable | System → Jobs, `escrow:release` ("Release held earnings"); then the booking's status history |
| The panel is slow | Developer → Query Monitor |
| The app reports an error the panel does not show | Developer → API Logs |
| An integration silently stopped working | Integrations → its error log and last sync |
| Someone changed something unexpectedly | Access → Activity Log |
| A deletion's clean-up says Needs attention | Its last error on Privacy requests → Account deletions; then System → Jobs, `privacy:finish-deletions` |
