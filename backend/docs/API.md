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
| `401` | Missing, expired or invalid token |
| `403` | Authenticated but not allowed |
| `404` | No such resource |
| `409` | Conflict — e.g. an illegal booking transition |
| `422` | Validation failed; read `errors` |
| `429` | Rate limited; honour `Retry-After` |
| `503` | Maintenance mode |

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
| `POST` | `/auth/otp/send` · `/auth/otp/verify` | Phone verification and sign-in |
| `POST` | `/auth/forgot-password` · `/auth/reset-password` | Password recovery |
| `GET` | `/auth/me` | Current user |
| `POST` | `/auth/logout` · `/auth/logout-all` | End this session, or every session |
| `POST` | `/auth/change-password` | Requires the current password |
| `GET` | `/auth/sessions` · `DELETE /auth/sessions/{id}` | List and revoke devices |
| `DELETE` | `/auth/account` | Delete the account. Kept for older builds; same erasure and re-authentication as `POST /me/privacy/delete-account` (see [Privacy](#privacy-data-export-and-account-deletion)), without the typed confirmation |

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
| `GET` | `/me/privacy` | | `verification`, `export`, `deletion`: everything the screens show |
| `POST` | `/me/privacy/verification-code` | `purpose` (`data_export` \| `delete_account`), `channel` (`email` \| `sms`) | `destination` (masked), `length`, `expires_in` |
| `POST` | `/me/privacy/exports` | proof | 201 `export`, with its `download_url` |
| `POST` | `/me/privacy/delete-account` | proof, `confirmation` (`DELETE`), `reason_code?`, `reason?` (≤ 500) | `deleted: true`, `retained` counts |
| `GET` | `/privacy/exports/download?token=…` | | The ZIP, as an attachment. No bearer: the token is the credential |

**Proof** re-authenticates on the spot, because a stolen session alone must not be
enough to take a copy of everything or to erase it. It is either `password`, or
`code` with `channel`. A code comes from `verification-code` and always goes to the
account's own email or phone. The client never supplies a destination, and a code for
one purpose never works for the other. `verification.password` and
`verification.channels` say which proofs an account can give. SMS is offered only
when the OTP method and an SMS provider are both on.

**The export** is built while the request waits. It is a ZIP of JSON files (account,
security, creator profile, campaigns, bookings, messages sent, payments, reviews,
verification, activity) plus a README that also says what is left out: password and
token hashes, push tokens, full bank account numbers, KYC document numbers and images,
and other people's messages. The link lives `link_hours` (72), allows
`max_downloads` (5), and a new export may be requested every `cooldown_hours` (24).
These are operator settings. A failed export does not start the cooldown. Each export
also sends a security email with the link, whatever the user's email preferences.

**Deletion** erases rather than hides. Profile, contact details, creator profile,
portfolio and packages, saved items, notifications, devices, sessions, payout methods,
uploaded files (except booking deliverables and chat attachments) and data exports are
removed. The KYC module purges identity documents under its retention rules, and the
Firebase Auth record is deleted. Bookings, payments, invoices, refunds and the wallet
ledger are kept, as tax law requires, against an anonymous "Deleted user" tombstone.
Messages the user sent stay in the other person's conversation. Every session is
revoked, and the email and phone become free for a new sign-up. `deletion.erased` and
`deletion.retained` carry this list for the app to show before the user confirms.

Errors the app should act on:

| Status | `code` | Meaning |
|---|---|---|
| 422 | `reauth_required` | Neither a password nor a code was sent |
| 422 | `invalid_credentials` | Wrong password; also in `errors.password` |
| 422 | `password_not_set` | The account has no password; use a code |
| 422 | `otp_invalid` · `otp_expired` · `otp_not_found` | The code is wrong, old, or was never requested for this purpose |
| 422 | `confirmation_mismatch` | `confirmation` is not `DELETE` |
| 409 | `deletion_blocked` | Something must be settled first. `errors` is keyed by blocker (`open_bookings`, `open_disputes`, `wallet_balance`, `wallet_on_hold`, `pending_withdrawals`, `pending_refunds`); `deletion.blockers` in the overview says how to settle each |
| 429 | `rate_limited` | Export cooldown, or too many attempts; honour `Retry-After` |
| 410 | `export_expired` · `export_download_limit` | The download link has expired or been used up. Browsers get a readable HTML page instead of JSON |

---

## Rate limiting

Applied to authentication, OTP, password reset, uploads and the contact form.

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
- [ ] Store the newest refresh token; never retry with an old one
- [ ] Read `errors` on `422` and attach messages to fields
- [ ] Handle `409` on bookings by re-reading the booking
- [ ] Honour `Retry-After` on `429`
- [ ] Show the operator's message on `code: "maintenance"`
- [ ] Call `/bookings/quote` before creating a booking
- [ ] Send `Accept: application/json` on every request
- [ ] Never hardcode the base URL — read it from build configuration
