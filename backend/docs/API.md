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
{ "name": "Asha Rao", "email": "asha@example.com", "password": "…",
  "account_type": "brand", "accept_terms": true, "marketing_opt_in": false }
```

`account_type` is `brand` or `creator`. Every sign-up needs:

- `email`. There are no phone-only accounts (SMS is off, so a number could never be
  verified): without one the answer is `422` with `errors.email`. `phone` is still
  accepted as an optional extra.
- `accept_terms: true`, always (Google Play policy); `422` `terms_required` without it.
  The time is stored on the account as `terms_accepted_at`.

`marketing_opt_in` is the unticked "Send me product updates and offers" box. Product
updates and offers are off unless it is `true`; the account can change this later with
`PUT /me/notification-preferences` (`marketing`). With `marketing` off, nothing
promotional goes out by push, email or SMS: admin broadcasts filed as promotional,
notification templates in the `promo`/`marketing` category (their in-app row is still
written) and marketing campaigns, which read the choice again when each batch sends,
so switching it off also stops a campaign already queued.

### Sign in

```http
POST /api/v1/auth/login
{ "identifier": "asha@example.com", "password": "…" }
```

`identifier` is the account's email address. A phone number is still accepted, so an
older account that has one can sign in with it; `GET /app/config` →
`auth.methods[email_password].identifier_mode` (`email`, or `email_or_phone`) says what
the field should ask for.

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
| `POST` | `/auth/firebase` | Exchange a Firebase ID token (Google today; Apple and phone are switched off) for platform tokens. A first sign-in, which creates the account, needs `accept_terms: true` (below) |
| `POST` | `/auth/otp/send` · `/auth/otp/verify` | Codes for `register`, `login`, `verify_phone` and `verify_email` only (below) |
| `POST` | `/auth/forgot-password` · `/auth/reset-password` | Password recovery |
| `GET` | `/auth/me` | Current user, including `has_password` |
| `POST` | `/auth/logout` · `/auth/logout-all` | End this session, or every session |
| `POST` | `/auth/change-password` | Change or set the password, with proof (below). Every session is signed out |
| `GET` | `/auth/sessions` · `DELETE /auth/sessions/{id}` | List and revoke devices |
| `DELETE` | `/auth/account` | Retired. A brand or creator gets `410` `endpoint_retired`; use `POST /me/privacy/delete-account` (see [Privacy](#privacy-data-export-and-account-deletion)). A guest session is still closed here |

**One-time codes.** `POST /auth/otp/send` takes `destination`, `channel` (`email` \|
`sms`) and `purpose`; any other purpose is a `422` on `errors.purpose` (the codes that
confirm privacy actions come from `/me/privacy/verification-code`). A code nothing would
redeem is not sent either: `422` `invalid_purpose` for `register` by email (sign-up takes
no code; `register` is only the guest upgrade's SMS code), `verify_phone` by email and
`verify_email` by SMS. Each network may ask for 20 codes and check 10 in ten minutes,
in separate allowances; each address keeps its own cooldown and ceiling. It answers
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
not an account uses the address. `auth.methods[mobile_otp].login_enabled` is `false`
whenever that list is empty (for example SMS alone stored while SMS delivery is off),
so an app never offers a code sign-in no channel can deliver.

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

**Google sign-up accepts the terms.** A first Google sign-in creates the account, so
`POST /auth/firebase` then needs `accept_terms: true`, as `POST /auth/register` does:
without it the answer is `422` `terms_required` (on `errors.accept_terms`) and no account
is made. The app shows "By continuing, you agree to the Terms and Privacy Policy",
both linked, directly above its Google button and sends `accept_terms` from there. An
existing account signs in whatever it sends, and `is_new_account` says which happened.
A new Google account starts with marketing off.

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
| `GET` | `/app/config` | Feature flags (`features.sms_enabled`: whether a text message can go out at all), minimum app version, public keys, sign-in methods (`auth`), wallet options (`wallet.topup_enabled`, `wallet.wallet_payment`). `app.support_email` is the one public contact; `app.support_phone` is `""` (no phone number is published, so the app shows no call option) |
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
| `GET` | `/testimonials` | Active testimonials only. The seeded sample quotes ship switched off, so the list is empty until an operator publishes real ones |
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
| `GET` | `/wallet` | Balance and locked (escrow) balance; `topup_enabled` |
| `GET` | `/wallet/transactions` | Ledger, paginated |
| `POST` | `/wallet/topup` | Add funds — **off by default**, see below |
| `POST` | `/wallet/withdraw` | Request a payout |
| `GET` | `/wallet/withdrawals` · `POST /wallet/withdrawals/{id}/cancel` | |
| `GET`/`POST` | `/wallet/bank-accounts` · `DELETE /wallet/bank-accounts/{id}` | |
| `GET` | `/payments` · `/payments/methods` | |
| `POST` | `/payments/booking/{id}` | Start payment for a booking |
| `POST` | `/payments/verify` | Verify the gateway callback |

**All amounts are INR, as numbers, not strings, and never in paise.** Format for
display client-side with Indian digit grouping.

### Bookings are paid at checkout; the wallet is not topped up (migration 056)

Brands pay each booking through the gateway's hosted checkout (Cashfree):
`POST /payments/booking/{id}` with `gateway: "cashfree"`. Loading money into the
wallet in advance is switched off (Admin → Settings → Wallet → Wallet top-up), so the
wallet only holds what the platform put there: creator earnings, and refunds that
could not go back to their original method or that an admin sent there.

- `GET /app/config` → `wallet`: `{ "topup_enabled": false, "wallet_payment": true }`.
  While `topup_enabled` is false the app shows no "Add money", top-up preset or prompt.
  `GET /wallet` carries the same `topup_enabled`.
- `POST /wallet/topup` answers **403 `topup_disabled`** while the switch is off,
  whatever the body (the switch is checked before validation). With it on, the
  minimum/maximum (`wallet.min_topup` / `max_topup`) apply as before.
- `GET /payments/methods` lists the `wallet` gateway only while the caller's wallet
  holds a spendable balance (and is not frozen), with that balance in
  `wallet_balance`. Offer it at checkout only when `wallet_balance` covers the
  booking's `total_amount`: a booking is never split across two methods.
  `gateway: "wallet"` with a balance short of the total is refused with
  **422 `insufficient_balance`**. A card gateway's `methods` never lists `wallet`.

### A mobile number for Cashfree, without SMS (migration 055)

Cashfree needs a mobile number on every order. Sign-up is email-only and SMS is off,
so a new account (email or Google) usually has no phone, and none can be verified.
`POST /payments/booking/{id}` therefore takes an optional **`customer_phone`**:

- The account's own `users.phone` always wins. `customer_phone` is used only when the
  account has none, for that one Cashfree order. It is **never written to the
  account** and never counts as verified (it cannot be used to sign in).
- Accepted forms: `9876543210`, `09876543210`, `+91 98765 43210` (an Indian mobile,
  first digit 6–9). It is sent to Cashfree as the ten digits.
- With no phone on the account and no `customer_phone`: **422 `phone_required`**, with
  `errors.customer_phone`. Nothing is written; no payment row is created. The app
  answers by asking for the number in a sheet and repeating the request with it.
- A `customer_phone` that is not an Indian mobile: **422 `phone_invalid`**, with
  `errors.customer_phone`.
- Other gateways and `gateway: "wallet"` ignore the field.

### Refunds go back to the original payment method

A refund (cancellation, admin refund, dispute) goes back to the method the booking
was paid with: for Cashfree it is sent to Cashfree, recorded `processing`, and
completed by the `REFUND_STATUS_WEBHOOK`. It goes to the wallet only when the
booking was paid from the wallet, when an admin picks the wallet, or when the payment
cannot be reversed automatically (a gateway with no refund API, or a payment row
without the gateway id the refund needs) — the refund's `notes` then say so. A gateway
that refuses a refund leaves it `failed` on the original method for finance to
re-issue; it is never moved to the wallet automatically. The brand's
`payment.refunded` notification names where the money went.

What is still refundable on a payment is its amount, less `amount_refunded`, less every
refund on it that is still `pending` (recorded, or parked for approval) or `processing`
(accepted by the gateway, not yet confirmed). A Cashfree refund stays `processing` for
days, and counting it only once it completed let a second refund — to the wallet, which
is instant — pay the same money back twice. A refund beyond that balance is cut to it;
with nothing left it is refused with **422** "There is nothing left to refund on this
payment." A refund the gateway reports `failed` frees its amount again.

A dispute's refund is recorded with the resolution and sent to the gateway only once
the resolution is saved, so a resolution that is refused (the booking has moved on) never
leaves a refund at the gateway without a record.

A brand's wallet only receives refunds, and `wallet.min_withdrawal` (`GET /wallet` →
`limits.min_withdrawal`) applies to brands as to creators: a refunded balance below it
can pay for a booking it covers, but cannot be withdrawn from the app. The app says so
on the wallet screen ("ask support to pay out a smaller balance") instead of promising
a withdrawal, and the admin refund and dispute forms warn before the wallet is chosen.

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

Each inbox row and each `GET /conversations/{id}/messages` carries `can_message`. It is
`false` while a block stands between the two people in the thread: the history stays
readable, and `POST /conversations/{id}/messages` answers `403` with `code: "blocked"`.
Show a notice in place of the composer. A thread with someone the caller blocked is
left out of the caller's inbox; it still opens from its booking
(`POST /conversations/start {booking_id}`).

`GET /conversations/{id}/messages` also carries `conversation`: the thread as its inbox
row reads, so a chat opened from a booking, or one the user just blocked from, keeps
its header and menu. Its `participant.is_blocked` is `true` when the caller blocked the
other person (offer Unblock); the inbox never has such a row. Across a block either
way `participant.is_online` is always `false`, so nobody can watch for someone who
blocked them coming online.

---

## Reports and blocking

Google Play asks an app with user content to let people report it and block the
people behind it (migration 054).

### `POST /reports`

```json
{ "reportable_type": "message", "reportable_id": 812, "reason": "abuse",
  "note": "Keeps messaging after I said no" }
```

| Field | Values |
|---|---|
| `reportable_type` | `user`, `creator` (a creator profile), `message`, `review`, `booking`, `portfolio`, `campaign` (`brief` is accepted for `campaign`) |
| `reason` | `spam`, `fraud`, `payment_issue`, `abuse`, `hate_speech`, `sexual_content`, `violence`, `self_harm`, `child_safety`, `impersonation`, `inappropriate`, `copyright`, `other` |
| `note` | Optional, up to 2,000 characters. `description` is accepted for older builds |

`201` with `data.report` (`id`, `reportable_type`, `reportable_id`, `reason`, `status`,
`created_at`) and the message to show.

- Only something the reporter can see can be reported: a message by a participant in
  its conversation, a booking by one of its two parties, a hidden review by the person
  it is about, a draft or closed brief by the creators it was sent to, a creator
  profile or portfolio item only while it is approved and public (and live, for an
  item) and its owner has not blocked the reporter. Anything else answers `404`,
  exactly like an id that does not exist.
- A reported review is also flagged (`reviews.is_flagged`), so it shows under the
  admin panel's Reviews → Flagged.
- Reporting yourself or something you posted is `422`, as is a platform message in a
  booking thread.
- One open report per reporter and target: a second while the first is still open or
  under investigation is `409` with `code: "already_reported"` and a message worth
  showing as a confirmation. Once the first is resolved or dismissed, the same thing
  can be reported again.
- Throttled to 10 reports an hour per account.

The server records the account behind the content and a copy of what was reported as
it read at the time (a message or a brief can change before a moderator looks), with
any photo or link as evidence. Neither is ever returned to the reporter.

What a moderator can do about it (admin panel, Abuse reports): take a reported message
out of the thread for both people, cancel a brief and take it off the board, hide a
review, or refuse a portfolio item so it leaves the profile. The person who posted it
gets an in-app notice that it was removed, never who reported it. An account is
suspended from its user page; a suspended brand's briefs leave the board and answer
`404` on `GET /campaigns/{id}` and `POST /campaigns/{id}/apply`.

### Blocking

| Method | Path | Notes |
|---|---|---|
| `GET` | `/me/blocks` | Paginated (`per_page` up to 200), newest first: `user_id`, `name`, `avatar_url`, `account_type`, `handle` (creators), `blocked_at`. An account can block up to 1,000, so read every page (`meta.pagination.has_more`) |
| `POST` | `/me/blocks` | `{ "user_id": 42 }`. `201` with `data.block`; `200` if already blocked. Not yourself (`422`), not a guest or a deleted account (`404`) |
| `DELETE` | `/me/blocks/{userId}` | Idempotent `200` |

Both writes share a throttle of 60 an hour. A block is one person's decision but works
both ways. While it stands, anything new between the two is refused with `403` and
`code: "blocked"`:

- messages in any conversation they share, and starting a direct conversation;
- `POST /bookings` (a request), accepting a pending request, counter offers and
  accepting one;
- campaign invitations, applications, and shortlisting or hiring (declining is
  allowed);
- following and saving the creator. Blocking removes existing follows and saved
  creators both ways; they do not come back on an unblock.

It also hides: neither side appears in the other's creator search, suggestions, home
and explore rails or AI matches, and a brief from a brand with a block between it and
a creator is not on that creator's board. A creator who blocked the viewer answers
`404` on `GET /creators/{handle}`; one the viewer blocked opens with `is_blocked: true`
so the app can offer to unblock. Reviews by someone the viewer has a block with are
left out of a creator's reviews for that viewer (not for the creator themselves, and
the rating still counts them). Notifications one causes the other are not sent.

The escrow rule: a block never strands money. A booking that already exists can still
be paid once accepted, delivered, sent back for changes, approved, disputed, rejected,
cancelled and reviewed, and the platform's booking notices still reach both sides.
The error message tells the person who made the block how to undo it; the other side
is told only that the account is not accepting messages or requests from them.

---

## Profile and creator self-service

| Method | Path | Notes |
|---|---|---|
| `PUT` | `/notification-preferences` | Per-channel toggles: `push`, `email`, `sms`, `marketing`. Unsent keys keep their value; a key never set reads as its default, and `marketing` (product updates and offers) defaults to off. Offer `sms` only while `/app/config` → `features.sms_enabled` is true |
| `POST` | `/devices` | Register an FCM token |
| `POST` | `/upload` | Multipart. Validated by real MIME type, not filename |
| `GET` | `/favorites` · `/referrals` · `/reviews` | |
| `POST` | `/reviews/{id}/reply` | Reply to a review you received |
| `POST` | `/reports` | Report a profile, message, review, booking, portfolio item or campaign brief. See [Reports and blocking](#reports-and-blocking) |
| `GET` | `/me/blocks` | The accounts you blocked |
| `POST` | `/me/blocks` | Block an account: `{ "user_id": 42 }` |
| `DELETE` | `/me/blocks/{userId}` | Unblock |
| `POST` | `/creators/{id}/favorite` · `/follow` | Toggles. Saving or following across a block is refused (`403 blocked`) |
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

### Creator self-management

Creator accounts only (`403` for anyone else). Every write is refused with `403`
and the administrator's reason while that section is locked (`pricing`,
`portfolio`, `social`); each list says `locked` so the app can say so first. A
row that is not the caller's answers `404`, never someone else's data.

| Method | Path | Notes |
|---|---|---|
| `GET`/`PUT` | `/creator` | Own creator profile |
| `GET` | `/creator/analytics` | Views, conversion, earnings |
| `GET` | `/creator/packages` | `packages` (hidden ones too, each with `is_active`), `locked`, `limits {min_price, max_price, max_includes, include_max_length}` |
| `POST` | `/creator/packages` | `title` (3–150), `description?` (≤ 1000), `platform` (`platform` taxonomy), `deliverable_type` (`deliverable_type` taxonomy), `quantity?` (1–100, default 1), `price` (within `booking.min_booking_amount`…`max_booking_amount`), `turnaround_days?` (1–180, default 7), `revisions?` (0–10, default 1), `includes?` (≤ 10 lines of ≤ 120 characters). `201 {package}` |
| `PUT` | `/creator/packages/{id}` | Any of the above, plus `is_active`. An explicit `null` description or `[]` includes clears it |
| `DELETE` | `/creator/packages/{id}` | Soft delete; bookings keep their package |
| `GET` | `/creator/portfolio` | Paginated (`per_page` ≤ 60), newest first, every review state: `moderation_status` (`pending` \| `approved` \| `rejected`), `reason_label` when rejected, `is_active`. `meta.locked`, `meta.max_items` (`creator.max_portfolio_items`, 0 = no ceiling) |
| `POST` | `/creator/portfolio` | Multipart `media` (an image, or a video for `reel`/`video`) **or** `media_url` (http(s)); `media_kind` (`portfolio_kind` taxonomy), `title?` (≤ 191), `caption?` (≤ 1000), `external_url?` (http(s)), `thumbnail_url?`. An uploaded image's `aspect_ratio` is filled in. `201 {item}`, `pending` while `creator.moderate_gallery_uploads` is on. Throttled to 30 per 10 minutes (bucket `portfolio`); at the ceiling `422 portfolio_full` |
| `DELETE` | `/creator/portfolio/{id}` | |
| `GET` | `/creator/social` | `social_accounts` in display order (with `platform_label`, `has_metrics`, `is_custom`), `locked`, `max_accounts` (0 = no ceiling) |
| `PUT` | `/creator/social` | Link or, with `id`, edit: `platform` (`social_platform` taxonomy, not `upload`), `handle` (≤ 120, `@` stripped), `profile_url` (http(s)), `label` (required for a custom-link term, ignored for any other network), `followers?`, `engagement_rate?` (0–100; stored as 0 on a link-only network). One account per network that reports figures: linking one already linked, without `id`, is `422 errors.platform` ("edit it instead"); the same handle twice is `422 errors.handle`; an edit cannot change the network (`422 errors.platform`); a changed handle or link clears `is_verified` |
| `DELETE` | `/creator/social/{id}` | Answers with the remaining list |
| `PUT` | `/creator/social/order` | `ids` in display order; ids that are not the caller's are skipped |
| `PUT` | `/creator/availability` | |

`followers` and `engagement_rate` are what the creator enters; nothing fetches
them from the networks, and `last_synced_at` is when the creator last saved
them, not a sync. Every social write answers with the whole
`social_accounts` list.

The creator's `price_min` / `price_max` (the profile's "Starting at" and the
search cards' "From") are recomputed from live packages after every package
write, and fall to `0` when no live package is left. A booking that names a
`package_id` is checked against `booking.min_booking_amount` /
`max_booking_amount` at the package's price, not the `amount` sent.

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

A new mobile number is proved by a code sent by text message. While SMS is off and
proof is on (`creator.otp_verification_enabled`), `GET /me/contacts` → `policy` reports
`allow_mobile_change: false` and `mobile_codes_available: false`, and the app offers no
mobile change and no additional number; a phone change (`POST /me/contacts/change`),
an additional number or a re-verification of one is refused with `422`
`sms_unavailable` before anything is opened. Email changes are unaffected. A brand
without a number gives one at checkout instead (`customer_phone`, above).

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
- [ ] On `403` with `code: "blocked"`, show the server's message and close the action; read `can_message` before showing a composer
- [ ] Store the newest refresh token; never retry with an old one
- [ ] Read `errors` on `422` and attach messages to fields
- [ ] Handle `409` on bookings by re-reading the booking
- [ ] Honour `Retry-After` on `429`
- [ ] Show the operator's message on `code: "maintenance"`
- [ ] Call `/bookings/quote` before creating a booking
- [ ] Send `Accept: application/json` on every request
- [ ] Never hardcode the base URL — read it from build configuration
