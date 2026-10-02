# Creator Profile Management & Verification — Phase Report

**Date:** 2026-08-06
**Scope:** Creator Profile Management & Verification System, plus the Phase 3
wallet/reporting fix and the Premium Dynamic Content Editor.

---

## 1. Summary

| | |
|---|---|
| Migrations added | 5 (035–039) — 16 new tables, 10 new taxonomies |
| Backend files added | 10 services/controllers, 4 admin views, 2 admin assets |
| Routes added | 60 (466 handlers total, 0 unresolved) |
| Defects found by adversarial verification | 54 |
| Defects fixed and re-proven | 54 |
| Assertions passing | **1110**, 0 failing |
| PHP files linting clean | 249 |
| Flutter | analyzer clean; release web build succeeds |
| Live data | intact — 47 creators, 300 portfolio, 129 badges, 193 wallet txns |

---

## 2. Defects fixed, by severity

### CRITICAL

| ID | Module | Root cause | Fix applied | Verification |
|---|---|---|---|---|
| CP-C1 | Contact verification | `SELECT *` returned the `VARBINARY(16)` `ip_address` column. `json_encode` fails on invalid UTF-8, so **every JSON response became an empty body** once a real client IP was stored. The module's own proof missed it by writing NULL or text IPs rather than the packed `inet_pton` form the application actually stores. | Named-column constants replace `SELECT *` on every response path. | Stores a genuine packed IP and asserts `json_encode` of each endpoint payload is non-empty and valid; recursive UTF-8 scan finds zero raw bytes. The hazard was confirmed still live by re-running the old query shape. |
| CP-C2 | Contact verification | A locked `contact` section did not stop the write — a correct OTP still applied the change. | `assertContactUnlocked()` added to `verify()` and `resend()`. | With the lock on, the correct code is refused 403, `users.email` does not move, the request stays `pending`. Unlocking and replaying the same code completes it, which proves the lock was what refused it. |

### HIGH

| ID | Module | Root cause | Fix applied | Verification |
|---|---|---|---|---|
| CP-H1 | Profile fields | `taxonomyKey()` accepted a raw taxonomy key, then every write method indexed `TERM_SECTIONS[$section]` with the un-mapped string — undefined index, **HTTP 500** on five endpoints. The six failing strings are exactly what the module publishes as `taxonomy_key` on every term it returns. | Resolve through `termSection()` first. An unknown section is 422, never 500. | All six previously-fatal inputs return 422; valid sections still write. |
| CP-H2 | Profile fields | `if (!array_key_exists('booking_id', $data))` skipped the substantive-change loop whenever `booking_id` was present — and the apps PUT whole forms, so it always was. "Nike — platform verified" was displayable from a skincare booking with a different brand. | Loop runs even when `booking_id` is unchanged; organisation validated against the booking's actual brand. | Editing the organisation on a verified entry now withdraws verification. |
| CP-H3 | Completion | Override expiry stored via `date(...)` = **midnight**, compared against `expires_at > NOW()`. A same-day grant was already expired when written. | `expiryInstant()` stores 23:59:59 for a date-only input. | "Until today" now grants access for the rest of the day. |
| CP-H4 | Change approval | `raise()` read the old value from `creators` while applying the new value to a different target row, so the recorded "before" was never true of the row changed. | Old value read from the same row the new value applies to; target ownership validated. | Cross-record write refused; old and new now agree. |
| CP-H5 | Schema 035 / 037 | Two contradictory definitions of "gallery" shipping in one batch: 035 made `collection` the discriminator, 037 split by `media_kind`. The completion score and the profile sections would have scored the same 300 rows differently. | 037's seeded filters rewritten to use `collection`. | Both rules reference only real `portfolio_collection` terms. |
| CP-H6 | Schema 036 | `uq_ucp_verified` bound a verified address to a **soft-deleted** account permanently — deleting your account meant never re-registering with your own email, and no operator could free it. | `deleted_at` folded into the generated `verified_key`, following the pattern migration 030 established for `users`. | A released address is re-claimable. |
| CP-H7 | Schema 039 | Three taxonomies created with `is_system = 1`, which the admin UI refuses to add terms to — while their own descriptions promised "add a row, no release required". | `is_system = 0`. | All three editable from the panel. |
| CP-H8 | Schema 038 | `profile_section.pricing` metadata named `creator_packages`; `price_min`/`price_max`/`currency` live on `creators`, so the review gate missed every pricing edit. | Metadata corrected to `creators`. | Verified in the applied schema. |
| CP-H9 | Schema 035 / 038 | **FK constraint name collision.** 035 names `fk_ccr_creator` on `creator_credentials`; 038 wanted the same name for `creator_change_requests`. InnoDB FK names are unique per *database*, so 038 failed with errno 121. Neither module's reviewer could see it — each tested its own file against a partial clone. Only applying the whole batch in order revealed it. | 038's identifiers renamed `ccr` → `cchr`. | Full 126-table clone, all five migrations applied twice, clean. |

### MEDIUM / LOW (selected)

| ID | Module | Defect | Fix |
|---|---|---|---|
| CP-M1 | Engagement | Dashboard totals did not reconcile — channel-less shares counted in `totals()` but were excluded from `channelBreakdown()` by `AND channel IS NOT NULL`. A generic OS share sheet reporting no channel is the common case. | "Unspecified" bucket. |
| CP-M2 | Engagement | A creator could inflate their own engagement without limit, contradicting `CreatorService::recordView`'s existing refusal to count self-views. | Self-generated engagement not counted. |
| CP-M3 | Engagement | `GET /engagement/options` disclosed internal table names to anonymous callers. | Replaced with a `requires_target` boolean. |
| CP-M4 | Engagement | `shareUrl()` mangled an absolute configured path into `https://zustee.com/https://evil.example.com/{handle}` instead of rejecting it. | Rejected; falls back to the default. |
| CP-M5 | Profile fields | `resume_url` published on the unauthenticated profile while address and PIN were correctly withheld — a CV usually carries both. | Gated. |
| CP-M6 | Profile fields | `addWorkingHour` left a phantom `00:00–00:00` slot on a working day; `updateWorkingHour` bypassed the per-day cap and duplicated `sort_order`. | Both fixed. |
| CP-M7 | Schema 035 | `'upload'` is the column default and 40 live rows carry it, but it was not a `social_platform` term — a taxonomy-driven picker would reject its own default. | Term seeded. |
| CP-M8 | Schema 035 | The language backfill silently dropped Urdu, Konkani and Rajasthani (6 creators), making those languages permanently unsearchable through the link table. | Terms seeded before the backfill runs. |

---

## 3. Pre-existing defects found and fixed along the way

Not part of the new work — found because the new work touched the same code.

| Module | Defect | Fix and evidence |
|---|---|---|
| Wallet / reporting | `platform_earnings` was **never reversed on refund**, and all 14 read sites counted already-reversed rows. The dashboard overstated 30-day revenue by ₹1,485 and all-time by ₹172,590. | Migration 033 + `EarningsService`: accrued figures stay immutable for audit, `refunded_ratio` records what came back. 21 assertions, including that a second partial refund does not compound. |
| Social accounts | `PUT /me/creator/social/order` wrote a `sort_order` that **nothing read** — the public profile ordered by follower count, so reordering was invisible. 13 of 20 columns were dropped from the payload, including `following`, `posts` and `last_synced_at`, all named in the brief. | Read fixed and enriched from the taxonomy; verified over HTTP. |
| Discovery | Five validator lists and three read paths hardcoded platform / deliverable / portfolio-kind values, so the six kinds migration 035 added were unusable the day they shipped, and `portfolio()` silently returned the wrong tab with a 200. | Taxonomy-driven via a `taxonomyRule()` helper. 8 assertions. |
| Flutter | The share button composed `https://zuste.com/creator/{handle}` — wrong domain (one `e`) and wrong path segment. **Every link ever shared from the app was a 404.** | Uses the server's share payload; the fallback deliberately shares no URL rather than guessing one. |
| Sitemap | A creator who hid their profile stayed in the sitemap at priority 0.9, inviting crawlers to a page that now 404s. | `is_public` enforced in both sitemap queries, search and the public fetch. Owner can still preview; the admin list deliberately does not inherit the filter. |
| Admin panel | `View::icon()` fails silently. 13 icon names used by the new taxonomies and screens did not exist, drawing blank space or an identical grey circle. | All added. |

---

## 4. Database changes

Migrations 035–039. All additive, all `IF NOT EXISTS`, all re-runnable —
verified by applying the batch **twice** against a full 126-table clone of live
data before production was touched.

- **035** — extended profile fields: `creator_profile_terms`,
  `creator_credentials`, `creator_experience`, `creator_working_hours`,
  `creator_faqs`, plus scalar columns on `creators` (address, PIN, timezone,
  intro video, media kit, resume, profession). New taxonomies: `profession`,
  `skill`, `interest`, `portfolio_collection`.
- **036** — contact verification: `user_contact_points`,
  `contact_change_requests`, `contact_verification_events`.
- **037** — completion and gating: `creator_profile_rules` (33 seeded),
  `creator_profile_completion`, `creator_feature_gates` (4 seeded),
  `creator_feature_overrides`, `creator_handle_history`.
- **038** — change approval: `creator_change_requests`,
  `creator_change_fields`; moderation columns on `creator_portfolio`.
- **039** — visibility and engagement: `creators.is_public`,
  `creator_engagement_events`; `creator_profile_views.source` ENUM → taxonomy.

Backup taken before applying: `storage/backups/pre-035-039-*.sql` (14 MB).

---

## 5. API changes

60 new routes. No existing route changed shape; additions to existing responses
are additive only.

- `GET /creators/{handle}` — gains `badges[]`. `social_accounts[]` gains
  `platform_label`, `icon`, `color`, `has_metrics`, `following`, `posts`,
  `last_synced_at`, `is_primary`, `sort_order`.
- `GET /me/creator` — gains `locked_sections[]` and a top-level `badges[]`
  carrying the whole catalogue with a `held` flag.
- `PUT /me/creator` — gains `held_fields[]` and `change_request_id`, so a
  creator whose edit is parked for review is told rather than seeing the old
  value redrawn under a success message.

---

## 6. Regression status

```
Linted 249 files, 0 failed
Checked 467 handlers, 0 problems
1110 assertions passing, 0 failing
Flutter: dart analyze clean, release web build succeeds (3.97 MB)
Migrations: 39 of 39 applied, none pending
Live data: 47 creators / 300 portfolio / 129 badges / 193 wallet txns — unchanged
Test residue: none
```

---

## 7. Remaining issues

**Zero Critical. Zero High.**

Low-severity items recorded and accepted:

1. `CreatorEngagementService::topTargets()` carries a docblock paragraph made
   stale by the target-ownership fix. The behaviour is correct; the comment
   contradicts the method one above it and reads as licence to delete the
   validation.
2. Cold-path cost comments in the same file quote figures that are
   state-dependent (measured 6–8 statements; the source says roughly nine).
   Cosmetic, but the original defect was also a wrong number in a comment.
3. `qrRepresentations('')` returns no QR and no error for a separator-only
   list, against its own stated "unknown names are refused" rule.
4. `series()` and `topTargets()` stop reconciling with `totals()` when a target
   is soft-deleted *after* an event was recorded.

All four have since been fixed — see §8. Nothing is outstanding.

---

## 8. Follow-up round

The four low-severity items above were fixed rather than carried.

| ID | Fix | Verification |
|---|---|---|
| CP-L1 | `topTargets()`'s docblock rewritten. It claimed the join made target validation unnecessary — the opposite of what the fix established, and it read as licence to delete `assertTargetBelongs()`. | Comment now states why the join is the second line of defence, not the first. |
| CP-L2 | The cost comment quoted "catalogue() = 5, record() ≈ 9". Measured across three fresh processes on this host: **6 cold, 7 after a taxonomy flush, 2 warm** — never 5 or 9. Comment corrected and stated as a range, because the figure is genuinely state-dependent. | Reproducible measurement, three runs. |
| CP-L3 | `qrRepresentations(",")` returned `[]` — indistinguishable from an explicit `"none"`, so a client with a badly-joined parameter got no QR and no error. Now 422. | `","`, `" , , "` and `",,"` all 422; `""`, `"none"` and `"svg"` unchanged. |
| CP-L4 | `topTargets()` used an `INNER JOIN`, so an item soft-deleted *after* an event was recorded vanished from the breakdown while `totals()` kept counting it. Now a `LEFT JOIN` with an `is_missing` flag and a "Removed item" label. Ownership still enforced in the `ON` clause. | Breakdown sums to the headline total both before and after deletion; the row is flagged and labelled. |

### Also added in this round

- **`GET /me/creator/completion`** — the completion engine had an admin screen
  but no creator-facing endpoint, so the progress bar the brief asks for had
  nothing to read. Returns the per-section breakdown and the feature gates
  together, because a percentage with no list of what is missing is a scold
  rather than a prompt. Verified: 200 for a creator, 401 anonymous, 403 for a
  brand account.
- **Flutter completion card** on the creator's menu — percentage, the two
  highest-weight incomplete sections, and the nearest locked feature with the
  server's own wording. Renders nothing when the profile is complete and no
  feature is locked.
- **Flutter contacts screen** (`/profile/contacts`) — verify, change and add
  alternate email addresses and numbers. The screen enables and disables its
  own controls from the policy the server sends (`otp_verification`,
  `allow_email_change`, `allow_mobile_change`, `max_alternates_per_channel`,
  `contact_locked`), so an operator's change takes effect with no release. The
  pending-change UI states plainly that the current address stays in place
  until the new one is confirmed.
- **Flutter profile-details screen** (`/profile/details`) — the creator can now
  add and remove education, certifications, awards, work history, brand
  collaborations, working hours and FAQs in the app. Previously these rendered
  on the public profile and were fully served by the API, but the only way to
  change them was to call the endpoints directly.

  Verified end to end against the live API: a credential, an experience entry,
  an FAQ and a working-hours window were created through the same endpoints the
  screen calls, confirmed visible on the **anonymous** public profile, then
  deleted — all four tables back to zero afterwards. A locked section was also
  exercised: the write is refused with *"Documents & media kit is locked by an
  administrator: Under review."*, which is the message the screen shows
  verbatim rather than replacing with something generic.

  The add form is deliberately one generic dialog driven by a field list. Four
  near-identical dialogs would have been four places to fix the next time one
  of them needed a change.
