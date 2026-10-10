# REST API Reference

For developers building the Android, iOS and web clients.

**Base URL** — `https://yourdomain.com/api/v1`
**125 routes.** JSON in, JSON out, UTF-8 throughout.

---

## Response envelope

Every response uses the same shape, so a client can branch on `success` before
touching anything else.

**Success**

```json
{ "success": true, "message": "Booking created.", "data": { "id": 42 } }
```

**Failure**

```json
{
  "success": false,
  "message": "The information provided is not valid.",
  "code": "validation_error",
  "errors": { "email": ["Email must be a valid email address."] }
}
```

`errors` appears only for validation failures and is keyed by field name, so a client
can attach each message to its input.

**Paginated**

```json
{
  "success": true,
  "data": {
    "items": [],
    "pagination": { "total": 340, "page": 2, "per_page": 20, "last_page": 17 }
  }
}
```

### Status codes

| Code | Meaning |
|---|---|
| `200` | Success |
| `201` | Created |
| `400` | Malformed request |
| `401` | Missing, expired or invalid token. `code: account_deleted` means the account behind the token has been deleted, from this device or another: end the session for good instead of refreshing |
| `403` | Authenticated but not allowed |
| `404` | No such resource |
| `409` | Conflict — e.g. an illegal booking transition |
| `410` | Gone, e.g. an expired download link |
| `422` | Validation failed; read `errors` |
| `429` | Rate limited; honour `Retry-After` |
| `500` | Server error; `code` says which (e.g. `export_failed`) |
| `503` | Maintenance (`code: maintenance`), or a mail/SMS provider failed (`code: otp_delivery_failed`); read `code` |

---

## Authentication

Bearer JWT (HS256).

```http
Authorization: Bearer <access_token>
```

Access tokens last 1 hour, refresh tokens 30 days (both configurable).

> **Every authentication endpoint sits under `/auth`**, and the self-service ones
> under `/me`. `POST /api/v1/login` is a 404; the path is `POST /api/v1/auth/login`.

### Sign up

```http
POST /api/v1/auth/register
{ "name": "Asha Rao", "email": "asha@example.com", "phone": "+919876543210",
  "password": "…", "account_type": "brand", "accept_terms": true }
```

`account_type` is `brand` or `creator`. `accept_terms` is required while
**Authentication → Sign-up → Require accepting the terms** is on.

### Sign in

```http
POST /api/v1/auth/login
{ "identifier": "asha@example.com", "password": "…" }
```

`identifier` accepts an email or a phone number.

Returns:

```json
{ "success": true, "data": {
    "user": { "id": 1, "name": "Asha Rao", "user_type": "brand" },
    "access_token": "eyJ…", "refresh_token": "…", "expires_in": 3600 } }
```

### Refresh

```http
POST /api/v1/auth/refresh
{ "refresh_token": "…" }
```

> **Refresh tokens rotate.** Every refresh returns a new one and invalidates the old.
> Presenting a spent token revokes the entire family and forces a fresh sign-in — that
> is how a stolen token is detected. Store the newest token and never retry a refresh
> with an old one.

### Other auth endpoints

All under `/api/v1/auth`.

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/auth/firebase` | Exchange a Firebase ID token (Google, Apple, phone) for platform tokens |
| `POST` | `/auth/otp/send` · `/auth/otp/verify` | Codes for `register`, `login`, `verify_phone` and `verify_email` only (below) |
| `POST` | `/auth/forgot-password` · `/auth/reset-password` | Password recovery |
| `GET` | `/auth/me` | Current user, including `has_password` |
| `POST` | `/auth/logout` · `/auth/logout-all` | End this session, or every session |
| `POST` | `/auth/change-password` | Change or set the password, with proof (below). Every session is signed out |
| `GET` | `/auth/sessions` · `DELETE /auth/sessions/{id}` | List and revoke devices |
| `DELETE` | `/auth/account` | Retired. A brand or creator gets `410` `endpoint_retired`; use `POST /me/privacy/delete-account` (see [Privacy](#privacy-data-export-and-account-deletion)). A guest session is still closed here |

**One-time codes.** `POST /auth/otp/send` takes `destination`, `channel` (`email` \|
`sms`) and `purpose`; any other purpose is a `422` on `errors.purpose` (the codes that
confirm privacy actions come from `/me/privacy/verification-code`). It answers
`{expires_in, length, channel, resend_in}`; wait `resend_in` seconds before offering
"Send again". Refusals: `429` `otp_cooldown` (a code for this address and purpose was
sent moments ago) and `429` `otp_send_limit` (too many in the window), both with
`Retry-After`; `422` `channel_not_allowed` (that channel is off for sign-in or
sign-up, or it is `sms` while SMS delivery is switched off), `422`
`phone_country_not_supported`, `422` `invalid_phone`; `503` `otp_delivery_failed`
when the mail or SMS provider failed — nothing was sent and an earlier code still
works. Which channels sign-in codes may use is `auth.methods[mobile_otp].channels` in
`GET /app/config`; today that is `["email"]` (the code goes to the account's email
address, and the destination is that address). The send answers the same whether or
not an account uses the address.

**Signing in with a code.** `POST /auth/otp/verify` with `purpose: login` and the same
`destination` answers `200 {user, tokens}` like `/auth/login`. It is `403`
`auth_method_disabled` while OTP sign-in is switched off (or for a phone number while
SMS codes are not offered), `404` `account_not_found` for an email address or number
with no account (it does not create one; sign up with email and password or Google),
and `429` `rate_limited` after too many wrong attempts.
The other purposes answer `{verified: true}`. For any purpose, a code that is wrong,
old, was never sent or was already used is `422` `otp_invalid`, `otp_expired`,
`otp_not_found` or `otp_used`, and too many wrong tries of one code is `429`
`otp_attempts_exceeded`.

**Google, Apple or phone sign-in for an existing account.** When the sign-in sent to
`POST /auth/firebase` matches an existing account by its email address or mobile
number, the answer is `409` `account_link_conflict` if that account is already linked
to a different sign-in, and `409` `account_link_held` if that email or number changed
in the last 72 hours. Either way, sign in with the password instead.

**Changing or setting the password.** Send `password` and `password_confirmation`.
An account that has a password also sends `current_password` (`422`
`invalid_current_password` when wrong). An account without one (made with Google,
Apple or a phone number; `has_password: false`) proves it is its owner instead: a code
from `POST /me/privacy/verification-code` with `purpose: set_password`, sent as
`code` and `channel`, or a fresh Google/Apple sign-in sent as `id_token`. A password
is never accepted as that proof. Without one the answer is `422` `reauth_required`,
or `422` `reauth_unavailable` when the account has no way to prove it; the other
proof errors are listed under [Privacy](#privacy-data-export-and-account-deletion).
Either way a security notice is emailed to the account's address, when it has one.

### A sign-in method can be switched off

Every method is individually enabled by an operator under
**Authentication & Login**. When one is off, the API **refuses it** — it is not
merely hidden in the app:

```json
HTTP/1.1 403 Forbidden
{ "success": false, "code": "auth_method_disabled",
  "message": "Google Sign-In is currently unavailable." }
```

This applies to `/auth/login`, `/auth/register`, `/auth/firebase` (per originating
provider), `/auth/otp/send`, `/auth/otp/verify` and `/auth/reset-password`.
Read `GET /api/v1/app/config` → `auth.methods` and render only the enabled ones,
in the `order` the server gives.

`POST /auth/forgot-password` is the one exception: it keeps answering `200`
whether or not recovery is enabled, because a `403` there would reveal which
addresses exist.

---

## Public endpoints

No token required.

| Method | Path | Notes |
|---|---|---|
| `GET` | `/health` | Liveness check. Stays up during maintenance mode. |
| `GET` | `/app/config` | Feature flags, minimum app version, public keys |
| `GET` | `/home` | Home feed: categories, featured and trending creators, banners |
| `GET` | `/explore` | Discovery feed |
| `GET` | `/categories` | All active categories |
| `GET` | `/creators` | Search and filter (below) |
| `GET` | `/creators/{handle}` | Full public profile |
| `GET` | `/creators/{id}/portfolio` · `/reviews` · `/availability` | Profile sections |
| `GET` | `/search/suggestions` | Typeahead |
| `GET` | `/pages` · `/pages/{slug}` | Legal and informational pages |
| `GET` | `/faqs` · `POST /faqs/{id}/helpful` | FAQs and voting |
| `GET` | `/blogs` · `/blogs/{slug}` | Blog |
| `GET` | `/testimonials` | |
| `POST` | `/contact` | Contact form (rate limited) |

### Creator search

```http
GET /api/v1/creators?category=fashion&min_price=5000&max_price=50000
    &city=Mumbai&rating=4&verified=1&sort=rating&page=1&per_page=20
```

| Parameter | Values |
|---|---|
| `q` | Free text over name, handle, headline, about |
| `category` | Category slug |
| `min_price` / `max_price` | INR |
| `city` / `state` | |
| `rating` | Minimum average |
| `verified` | `1` for verified only |
| `availability` | `available`, `busy` |
| `sort` | `relevance`, `rating`, `price_low`, `price_high`, `popular`, `newest` |

---

## Bookings

The core flow. All endpoints require authentication.

| Method | Path | Who | Notes |
|---|---|---|---|
| `GET` | `/bookings` | both | Filter by `status`; paginated |
| `GET` | `/bookings/quote` | brand | **Call before creating.** Returns the fee, GST and total |
| `POST` | `/bookings` | brand | Create a request |
| `GET` | `/bookings/{id}` | both | Full detail with status history |
| `POST` | `/bookings/{id}/accept` · `/reject` | creator | Respond |
| `POST` | `/bookings/{id}/counter-offer` | creator | Propose different terms |
| `POST` | `/bookings/{id}/accept-offer` | brand | Accept a counter |
| `POST` | `/bookings/{id}/deliver` | creator | Submit deliverables |
| `POST` | `/bookings/{id}/complete` | brand | Approve — **releases escrow** |
| `GET` | `/bookings/{id}/cancellation-preview` | both | What a cancellation would cost |
| `POST` | `/bookings/{id}/cancel` | both | Cancel |
| `GET` | `/bookings/{id}/invoice` | both | Invoice |
| `POST` | `/bookings/{id}/review` | both | Review after completion |

### Quote first

```http
GET /api/v1/bookings/quote?creator_id=12&package_id=3&deliverables=2
```

```json
{ "success": true, "data": {
    "subtotal": 25000, "platform_fee": 2500, "gst": 450, "total": 27950,
    "creator_receives": 22750, "currency": "INR" } }
```

Show these numbers before the brand commits. The same calculation runs server-side on
create, so a client that skips the quote still cannot pay the wrong amount.

### Transitions are enforced

Only the transitions in the state machine are accepted; anything else returns `409`
with an explanation. Never assume a booking is in the state you last saw — re-read it
after any action.

---

## Wallet and payments

| Method | Path | Notes |
|---|---|---|
| `GET` | `/wallet` | Balance and locked (escrow) balance |
| `GET` | `/wallet/transactions` | Ledger, paginated |
| `POST` | `/wallet/topup` | Add funds |
| `POST` | `/wallet/withdraw` | Request a payout |
| `GET` | `/wallet/withdrawals` · `POST /wallet/withdrawals/{id}/cancel` | |
| `GET`/`POST` | `/wallet/bank-accounts` · `DELETE /wallet/bank-accounts/{id}` | |
| `GET` | `/payments` · `/payments/methods` | |
| `POST` | `/payments/booking/{id}` | Start payment for a booking |
| `POST` | `/payments/verify` | Verify the gateway callback |

**All amounts are INR, as numbers, not strings, and never in paise.** Format for
display client-side with Indian digit grouping.

Payment webhooks (`/webhooks/razorpay`, `/webhooks/stripe`) are called by the gateway,
never by a client. Signatures are verified before anything is acted on.

---

## Messaging, notifications, support

| Method | Path |
|---|---|
| `GET` | `/conversations` |
| `POST` | `/conversations/start` |
| `GET`/`POST` | `/conversations/{id}/messages` |
| `POST` | `/conversations/{id}/read` · `/typing` · `/mute` |
| `GET` | `/conversations/{id}/typing` |
| `GET` | `/notifications` |
| `POST` | `/notifications/read-all` · `/notifications/{id}/read` |
| `DELETE` | `/notifications/{id}` |
| `GET`/`POST` | `/support/tickets` |
| `GET` | `/support/tickets/{id}` · `POST /support/tickets/{id}/reply` |

There is no websocket. Poll `/conversations/{id}/messages` while a thread is open —
every 3–5 seconds is reasonable — and back off when the app is backgrounded.

---

## Profile and creator self-service

| Method | Path | Notes |
|---|---|---|
| `PUT` | `/notification-preferences` | Per-channel toggles |
| `POST` | `/devices` | Register an FCM token |
| `POST` | `/upload` | Multipart. Validated by real MIME type, not filename |
| `GET` | `/favorites` · `/referrals` · `/reviews` | |
| `POST` | `/reviews/{id}/reply` | Reply to a review you received |
| `POST` | `/reports` | Report a user, booking, review or message |
| `POST` | `/creators/{id}/favorite` · `/follow` | Toggles |
| `DELETE` | `/search/history` | |

### KYC

| Method | Path | Notes |
|---|---|---|
| `GET` | `/kyc/requirements` | Public. What each account type must supply |
| `GET` | `/kyc` | Current status and progress |
| `GET`/`PUT`/`POST` | `/kyc/details` | Brand or creator detail, by account type |
| `POST` | `/kyc/documents` | Upload a document. Optional `replace_document_id` uploads a new version of that document (the old one stops being current) |
| `DELETE` | `/kyc/documents/{id}` | Remove one before submission |
| `GET` | `/kyc/documents/{id}/download` | Own documents only, `?token=` from the overview |
| `GET` | `/kyc/documents/{id}/preview` | Same token rules, served `inline`, `no-store`, `nosniff` — the overview's `preview_url` |
| `POST` | `/kyc/submit` | Submit for review. Body `{"consent": true}` is required |
| `GET` | `/kyc/timeline` | Review history |

Documents are encrypted at rest and decrypted only for the owner and a reviewing
operator. `GET /kyc/legacy` still reads the earlier single-step flow;
`POST /kyc/legacy` answers `410 {"code":"gone"}`, and `POST /upload` refuses
`folder=kyc`.

Identity rules (migration 044). Creators: `aadhaar_number` (12 digits, Verhoeff
checked, only the last four are kept — returned as `aadhaar_masked`
`"XXXX XXXX 1234"`) and a personal `pan_number` (4th character `P`). Brands:
`company_type`, `gst_number` (mod-36 checksum, state code, must contain the
company PAN), `cin_number` (required for `private_limited`, `public_limited`,
`opc`; `details.cin_required` says which). Errors are field-keyed
(`errors.aadhaar_number` …). A masked value sent back is ignored. An identifier
already on another account is refused without saying whose.

`GET /kyc` adds `profile.status_label` (Draft / Pending / Under review / Action
needed / Approved / Rejected / Suspended / Expired), `resubmit_slots`,
`badge_status` (`none` | `granted` | `revoked`), `badge_granted_at`,
`has_blue_tick`, `consent_at`, `consent_version_required`, `consent_text`,
`can_submit`, `editable`; per requirement `review_status`
(`missing` | `pending` | `approved` | `rejected`), `rejection_reason`,
`resubmit_requested`; per document `status`, `rejection_reason`, `is_current`,
`preview_url`. The blue tick is granted by an administrator after KYC approval
and is never implied by approval itself.
| `GET`/`PUT` | `/creator` | Own creator profile |
| `GET` | `/creator/analytics` | Views, conversion, earnings |
| `POST`/`PUT`/`DELETE` | `/creator/packages`, `/creator/portfolio` | |
| `PUT` | `/creator/social` · `/creator/availability` | |

---

## AI writing and matching

Switched on in Admin → Settings → AI. `GET /app/config` carries an `ai` block telling
the app what to offer — `{enabled, bio_writer, campaign_brief, creator_match,
daily_limit}` — and never a key or a provider. All three endpoints need a signed-in,
non-guest account.

| Method | Path | Who | Body | Returns |
|---|---|---|---|---|
| `POST` | `/ai/bio` | creator | `notes?`, `max_chars?` (80–1000, default 300) | `text` |
| `POST` | `/ai/campaign-brief` | brand | `idea`, plus optional `title`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `category_id` | `text` (plain text, ≤ 5000 chars) |
| `POST` | `/ai/creator-match` | brand | `brief`, plus optional `category_id`, `platform`, `city`, `budget_max` | `matches`: creator cards with `match_score` (0–100) and `match_reason`, best first, at most 5 |

Every response includes `remaining_today` (null when there is no daily limit). Errors
the app should act on:

| Status | `code` | Meaning |
|---|---|---|
| 403 | `ai_disabled` | The feature or AI as a whole is switched off — refresh `/app/config` |
| 403 | `ai_unavailable` | No provider with a working key |
| 403 | `ai_budget_reached` | The monthly budget is spent |
| 429 | `rate_limited` | The account's daily allowance is used up; honour `Retry-After` |
| 502 | `ai_failed` | The provider failed; safe to retry |

Drafts are suggestions: nothing is saved until the user saves the form they land in.

---

## Privacy: data export and account deletion

"Download my data" and "Delete my account", for brands and creators. All under
`/api/v1/me/privacy`, signed in, not for guests.

| Method | Path | Body | Returns |
|---|---|---|---|
| `GET` | `/me/privacy` | | `verification`, `export`, `deletion`: everything the screens show. Never a download link |
| `POST` | `/me/privacy/verification-code` | `purpose` (`data_export` \| `delete_account` \| `set_password` \| `confirm_contact_change`), `channel` (`email` \| `sms`) | `destination` (masked), `length`, `expires_in`, `resend_in` |
| `POST` | `/me/privacy/exports` | proof | 201 `export`, with a fresh `download_url` (shown only in this response) and `security_notice` |
| `POST` | `/me/privacy/exports/{id}/link` | proof | `export` with a fresh `download_url`. Every earlier link to that copy stops working |
| `POST` | `/me/privacy/delete-account` | proof, `confirmation` (`DELETE`), `reason_code?`, `reason?` (≤ 500), `forfeit_balance?` | `deleted: true`, `retained` counts, `notice` |
| `GET` | `/privacy/exports/download?token=…` | | An HTML page with the archive's size, expiry and downloads left, and a **Download my data** button. No bearer: the token is the credential. Opening it never uses a download |
| `POST` | `/privacy/exports/download` | form field `token` | The ZIP, as an attachment. Counts one download |

**Proof** re-authenticates on the spot, because a stolen session alone must not be
enough to take a copy of everything, to erase it, to set a password or to change the
email or phone on the account. It is `password`, or `code` with `channel`, or
`id_token` — a Firebase ID token from a Google, Apple or phone sign-in completed in the last
five minutes, for the same Firebase account the Zusty account is linked to. When
several are sent, a code wins over an ID token and an ID token over a password. A code
comes from `verification-code` and always goes to the account's own email or phone:
the client never supplies a destination, and a code for one purpose never works for
another. `verification` says which proofs an account can give: `password` (true only
for a password the owner set with proof), `has_password`, `channels` (masked),
`providers` (`google.com`, `apple.com`, `phone`) and `unavailable` — a channel that cannot be
used now, with `reason` `sms_unavailable` or `contact_recently_changed` and, for the
latter, `available_at`. An email address or phone number changed in the last 72 hours
(`security.privacy_contact_hold_hours`) cannot receive these codes. SMS is offered only
when the OTP method and an SMS provider are both on. A proof that fails is never a
`401`, so it never ends the session.

**Contact details.** `POST /me/contacts/change` (`channel`, `value`) always needs the
same proof, sent as `password`, or `code` with `code_channel` (because `channel` names
the contact being changed), or `id_token`; codes use `purpose: confirm_contact_change`.
An account that cannot give any proof right now — no proven password, no code channel
(a phone-only account while SMS is off, or its only channel inside the 72-hour hold)
and no usable Google or Apple sign-in — is refused with `409`
`contact_change_unverifiable`, whose message names the support address to write to
(and, for a hold, when it ends); the change is never applied unproven. When a change
is applied the old address is told. `PUT /me` no longer changes the email or phone: a new value there is `422`
`contact_change_required` (on `errors.email` or `errors.phone`); sending the stored
values back unchanged is still accepted.

**The export** is built while the request waits. It is a ZIP of JSON files (account,
security — devices, sessions and sign-in history — creator profile, campaigns,
bookings, messages sent, payments, reviews, verification, activity) plus a README that
also says what is left out: password and token hashes, push tokens, full bank account
numbers, KYC document images and full document numbers, and other people's messages.
A section cut short at its row limit is listed in the README under "Shortened
sections". The 201 response carries the only copy of the download link; afterwards
`export.latest.download_url` is always null and `can_download` says whether a copy is
still there. `POST /me/privacy/exports/{id}/link` with proof issues a new link and
kills the old ones, without extending the expiry or resetting the download count;
it answers `404` `not_found` for someone else's export and `410`
`export_unavailable` for one that has expired or been used up, both before any proof
is checked. Changing or resetting the password, signing out everywhere or revoking a
session also kills the link; the copy stays until it expires, and its owner can ask for
a new link. The link opens a landing page and the download is the POST behind its
button, so link scanners and previews never use up a download. By default the link
lives `link_hours` (72), allows `max_downloads` (5), and a new export may be requested
every `cooldown_hours` (24); these are operator settings, read from the overview. A
failed export does not start the cooldown. Each export sends a security notice, with
no link in it, whatever the user's email preferences: `security_notice` says where it
went (`channel` `email`, or `sms` for an account with only a phone number) or is null
when it could not be sent anywhere. When support makes an export on a confirmed request
from someone who cannot use the app (Admin → Privacy requests), the email carrying the
link goes to the account's own address, and the link is never shown to staff. The
archive's list of earlier exports says how each was confirmed (`verified_via`):
`password`, `email_code`, `sms_code`, `firebase` (a Google, Apple or phone sign-in) or
`operator` (support, on a confirmed request).

**Deletion** erases rather than hides. Profile and every email address and phone
number on the account, creator profile, portfolio and packages, saved items, profile
views, notifications and our copies of the messages sent to the user, devices,
sessions, payout methods, uploaded files (except booking deliverables and chat
attachments), data exports, identity documents and the Firebase Auth record are
removed. The database erasure commits at once; deleting the files, the exports, the
KYC documents and the Firebase record follows it and, if any of them fails, the
`privacy:finish-deletions` job retries it until it succeeds. Kept, as the law
requires: bookings, payments, invoices, refunds, payouts and the wallet ledger,
against an anonymous "Deleted user" tombstone — tax invoices and payout statements
stay exactly as issued, with the billing name, address and GSTIN printed on them;
messages the user sent, in the other person's conversation; reviews they wrote,
without their name; support tickets, contact-form messages and reports, without their
name or contact details; security logs, without their email or phone, for up to a
year; a minimal identity verification record; a deletion record; and database backups
for up to `backup.retention_days`, after which they are deleted (a restore erases the
account again). `deletion.erased` and `deletion.retained` carry this list for the app
to show before the user confirms. Every session is revoked, the email and phone become
free for a new sign-up, and any later request with the account's token gets `401`
`account_deleted`.

`deletion.blockers` lists what must be settled first, each with `code`, `title`,
`message` and `action` (`bookings`, `wallet`, `kyc` or `support`): `open_bookings`,
`open_disputes`, `wallet_on_hold`, `wallet_balance`, `pending_withdrawals`,
`pending_refunds` and `pending_topup` (a payment into the wallet still being
confirmed). `can_delete` is true when there are none. A wallet balance that cannot be
paid out (below the minimum withdrawal, or waiting for KYC) and is no more than the
operator's limit is not a blocker: `deletion.forfeit` then holds `{amount, currency,
label, reason, message}`, and the user may give it up by sending `forfeit_balance`
equal to `amount`. The response's `notice` says where the confirmation went, as
`security_notice` does for an export.

Errors the app should act on:

| Status | `code` | Meaning |
|---|---|---|
| 422 | `reauth_required` | No proof was sent |
| 422 | `reauth_unavailable` | The account has no proof it can give for this (setting a password) |
| 422 | `invalid_credentials` | Wrong password; also in `errors.password` |
| 422 | `password_not_set` · `password_unproven` | The account has no password, or one that was set without proof; use a code. Also in `errors.password` |
| 422 | `otp_invalid` · `otp_expired` · `otp_not_found` · `otp_used` | The code is wrong, old, was never requested for this purpose, or was already used |
| 429 | `otp_attempts_exceeded` | Too many wrong tries of one code; honour `Retry-After` |
| 429 | `reauth_locked` | Too many failed confirmations for this account in the window; honour `Retry-After`. Code requests are refused too |
| 422 | `no_email` · `no_phone` · `sms_unavailable` | That code channel cannot be used for this account; offer one listed in `verification.channels` |
| 422 | `contact_recently_changed` | That email or number changed in the last 72 hours; use another proof |
| 422 | `invalid_firebase_token` · `reauth_mismatch` · `reauth_stale` · `reauth_reused` · `reauth_method_unavailable` | The `id_token` is not valid, belongs to another Firebase account, is from a sign-in more than five minutes old, was already used, or its sign-in method is switched off |
| 503 | `provider_unavailable` | Firebase could not be reached to check an `id_token`; retry |
| 503 | `otp_delivery_failed` | The code could not be sent; not maintenance. An earlier code still works. Let the user retry or use the password |
| 429 | `otp_cooldown` · `otp_send_limit` | A code was sent moments ago, or too many in the window; honour `Retry-After` |
| 422 | `confirmation_mismatch` | `confirmation` is not `DELETE` |
| 409 | `deletion_blocked` | Something must be settled first. `errors` is keyed by blocker code; `deletion.blockers` in the overview says how to settle each |
| 409 | `forfeit_required` | A balance has to be given up explicitly: send `forfeit_balance` equal to `deletion.forfeit.amount`. If the amount changed, reload the overview. Also in `errors.wallet_balance` |
| 409 | `wallet_closed` | The account was deleted, so its wallet takes no more money |
| 429 | `export_cooldown` | A new export is allowed after `next_available_at`; honour `Retry-After`. Checked before the proof, so no code is spent |
| 410 | `export_unavailable` | `…/link`: that copy has expired or been used up; request a new export |
| 500 | `export_failed` | The archive could not be built; safe to retry, and a failed export does not start the cooldown |
| 404 | `not_found` | Unknown link, a deleted account's link, or one already cleaned up |
| 410 | `export_expired` · `export_download_limit` | The link has expired or been used up. The landing page (`GET`) keeps answering `410` until the hourly `privacy:purge-exports` job deletes the archive and forgets the token; a download (`POST`) that gets a `410` does that clean-up on the spot. Either way the same link answers `404` afterwards |
| 429 | `rate_limited` | Too many download attempts from one network (30 an hour per address, for the landing page and the download each), or another route limit; honour `Retry-After` |

Both download routes answer a browser (an `Accept` header containing `text/html`) with a
readable HTML page for these errors, and any other client with the JSON envelope.

---

## Rate limiting

Applied to authentication, OTP, password reset, uploads, the contact form, the privacy
actions and the export download page.

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 45
```

Honour `Retry-After`. Do not retry in a tight loop — repeated attempts extend the
window rather than shortening it.

---

## Maintenance mode

When an operator enables maintenance, every endpoint returns `503`:

```json
{ "success": false, "code": "maintenance",
  "message": "We are performing scheduled maintenance. Please try again shortly." }
```

`/health`, `/settings` and `/app/config` stay reachable, so the app can show the
operator's message instead of a generic network error. Treat `code: "maintenance"` as
a distinct state in the client.

---

## Client checklist

- [ ] Handle `401` by refreshing once, then signing out if the refresh fails
- [ ] On `401` with `code: "account_deleted"`, end the session and clear the device without refreshing
- [ ] Read `code` on `503`: only `maintenance` is maintenance; `otp_delivery_failed` is retryable
- [ ] Store the newest refresh token; never retry with an old one
- [ ] Read `errors` on `422` and attach messages to fields
- [ ] Handle `409` on bookings by re-reading the booking
- [ ] Honour `Retry-After` on `429`
- [ ] Show the operator's message on `code: "maintenance"`
- [ ] Call `/bookings/quote` before creating a booking
- [ ] Send `Accept: application/json` on every request
- [ ] Never hardcode the base URL — read it from build configuration
