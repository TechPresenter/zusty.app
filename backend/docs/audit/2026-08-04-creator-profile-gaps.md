# Creator Profile Management & Verification — gap analysis

Four lenses (schema, creator API, admin, app sync), each verdict then put to an
adversarial verifier whose job was to refute MISSING and PARTIAL claims — a wrong
MISSING is the expensive error here, because it causes a feature to be built twice.
**20 of 140 verdicts were corrected on verification.**

**BUILT 34 · PARTIAL 66 · MISSING 40**

**schema** — DATA-MODEL VERDICT: the Creator module has solid storage for the ~12 fields that shipped with the original marketplace schema and essentially nothing for the profile depth the spec demands. Of 37 requirements audited: 13 BUILT, 8 PARTIAL, 16 MISSING.

WHAT EXISTS (BUILT): display name, handle, headline, about, cover photo (creators.cover_image_url), profile photo (users.avatar_url), languages (JSON + admin taxonomy), gender, city/state/country, primary category + creator_categories, availability dates, pricing packages, reviews/ratings. All in D:/zustee.com/backend/database/migrations/003_categories_and_creators.sql, written through app/Controllers/Api/CreatorController.php.

WHAT HAS NO STORAGE AT ALL (16 MISSING, each searched under 3-5 alternate names against information_schema and SHOW TABLES): media kit, profession, skills, interests, experience (option list exists as taxonomy id=6 but no column to hold the answer), awards, work history, previous brand collaborations, service areas, custom services, per-creator FAQs (the faqs table is site-wide, no creator_id), per-creator testimonials (same — no creator_id), alternate email/mobile, profile section locks (whole-DB %lock% scan hit only admins.locked_until, conversations.is_locked, wallets.locked_balance), badge history, and the entire creator/profile settings group.

THE FOUR STRUCTURAL BLOCKERS:
1. creator_social_accounts.platform is ENUM('instagram','youtube','tiktok','facebook','twitter','linkedin','snapchat','pinterest','threads') — 9 members, migration 003:114. It CANNOT store 7 of the spec's 16: moj, josh, sharechat, website, portfolio link, blog, custom links. Under STRICT_TRANS_TABLES each is a 1265 hard error. There is no sort/reorder column (none of the 18 columns is a sort key), no DELETE route, and unique key uq_csa_creator_platform caps it at one row per platform so multiple custom links are impossible regardless.
2. There is NO badge table anywhere. Eleven badges are demanded; what exists is scattered booleans plus a hardcoded 4-entry PHP array at app/Services/KycService.php:980-1007. 'Top rated' and 'trusted' have no storage; per-badge verified_by, expiry, status and history do not exist.
3. 'creator' IS a registered approval entity (approval_workflows row id=3 is live, ApprovalService.php:35/53/793) — do NOT rebuild that — but it approves the record's STATUS, not a diff. No pending-changes table exists, RevisionService::ENTITIES (RevisionService.php:44-93) excludes creators, and CreatorController.php:419-423 lets an already-approved creator edit display_name/about/headline/handle/pricing straight to production with no review.
4. No 'creator' or 'profile' settings group among the 20 that exist. Completion weights are hardcoded at CreatorService.php:586-611; the username change limit has no counter; badge and featured rules are PHP literals.

HARDCODED VIOLATIONS TO FIX (the spec's central demand): social platform list CreatorController.php:648; package platform + deliverable_type CreatorController.php:463-464 and 510-511; gender CreatorController.php:372 (and the taxonomy is missing 'prefer_not_to_say', so the two disagree); OTP purposes AuthController.php:254/285; badge definitions KycService.php:980-1007; profile-score weights CreatorService.php:586-611; the 5-category cap CreatorController.php:434; upload folder allow-list AccountController.php:191.

DEAD COLUMNS (storage with no runtime writer — PARTIAL, not BUILT): creators.featured_until, creators.meta_title/meta_description, creator_availability.slots and .note (855 slot rows came from database/seeds/demo/021_demo_creator_depth.sql, not the app), creator_availability.booked_count (read to compute is_bookable, never incremented, so max_bookings is unenforceable), creator_social_accounts.is_primary/is_verified/audience_stats/avg_*, creator_portfolio.sort_order/is_featured/views_count/likes_count/comments_count, creator_packages.sort_order. Nothing anywhere can reorder a portfolio item, a package or a social link.

**creator-api** — CREATOR-FACING API AUDIT — can a creator actually edit each section?\n\nTHE ALLOWLIST IS THE WHOLE STORY. PUT /me/creator (routes/api.php:152 -> D:/zustee.com/backend/app/Controllers/Api/CreatorController.php:363-378) validates exactly 14 fields:\n  display_name, handle, headline, about, primary_category_id, city, state, country,\n  gender, date_of_birth, languages, availability_status, cover_image_url, category_ids\nThat is the entire self-service surface for the profile row. Every spec section not represented by one of those 14 keys is unwritable regardless of what columns exist.\n\nENDPOINT ROLL-CALL (live-probed against http://localhost:8000):\n  profile photo upload      -> POST /me/creator (405), but POST /me multipart `avatar` works (AccountController.php:101)\n  cover photo upload        -> ABSENT (POST /me/creator/cover 404). URL-only field; two-step via POST /me/upload\n  handle change             -> PUT /me/creator (built). LIMIT/COOLDOWN: absent entirely\n  gallery create/delete     -> POST + DELETE /me/creator/portfolio (built)\n  gallery update/reorder    -> ABSENT (PUT /me/creator/portfolio/{id} -> 405)\n  media kit upload/download -> only as KYC vault slot 'media_kit' (private, owner-only)\n  skills/interests/awards/work history/brand collaborations -> ABSENT (no table, no column)\n  experience                -> taxonomy exists, storage does not (orphaned picker)\n  sub-categories            -> BUILT via category_ids -> creator_categories\n  working hours             -> creator_availability.slots is read but has NO writer\n  service areas             -> ABSENT\n  custom services / FAQs    -> ABSENT (packages exist but their platform/deliverable enums are PHP literals)\n  social add/edit           -> PUT /me/creator/social (built)\n  social REMOVE             -> ABSENT (DELETE -> 405; no DELETE on creator_social_accounts anywhere in the codebase)\n  social verify             -> is_verified column, no writer\n  social REORDER            -> no sort_order column at all; ordering hardcoded to followers DESC\n  preview public profile    -> BUILT via GET /creators/{handle} + owner bypass (CreatorService.php:237-240)\n  share profile / QR        -> ABSENT (QR encoder exists at app/Core/QrCode.php, wired only to admin 2FA)\n  alternate email/mobile    -> ABSENT on the creator side (only kyc_brand_details has alt_contact_*)\n\nMOST EXPENSIVE DEFECT FOUND: email/phone OTP verification is a silent no-op. /auth/otp/send and /auth/otp/verify (routes/api.php:73-74) carry no auth or auth.optional middleware, so Request::userId() is 0, AuthService::sendOtp stores user_id NULL (AuthService.php:915-923), and AuthController.php:295-303 therefore never writes email_verified_at / phone_verified_at. Live DB confirms every otp_verifications row has user_id NULL. This also silently disables the `verified` middleware on wallet topup/withdraw. One-line fix.\n\nHARDCODED (the spec's central demand):\n  - computeProfileScore weights: 10/10/15/5/10 + min(15,n*8) + min(20,n*10) + min(15,n*3), all PHP literals at CreatorService.php:587-609. No setting, no admin screen. Ignores photo, cover, languages, KYC and verification entirely.\n  - Sub-category cap of 5: CreatorController.php:434\n  - Availability batch cap of 90 dates: CreatorController.php:698\n  - Package platform/deliverable_type enums: CreatorController.php:463-464, :507-508 (duplicated in the update rules) while `platform` and `deliverable_type` taxonomies exist and are served to the app — guaranteed drift.\n  - Social ordering: 'ORDER BY followers DESC' at CreatorService.php:254 and CreatorController.php:680\n  - creators.response_time_hours: pinned at the schema default 48 for every creator; it is a live search facet (CreatorService.php:142) with no writer.\n\nCOLUMNS WITH NO WRITER (read paths exist, so they render as zeros/empties): creator_portfolio.sort_order, creator_portfolio.is_featured, creator_social_accounts.is_verified / is_primary / avg_views / following / posts / avg_likes / avg_comments / audience_stats, creator_availability.slots + note, creators.response_time_hours, creators.meta_title, creators.meta_description.\n\nNO COMPLETION-BASED GATING EXISTS. profile_score is read only for display (CreatorController.php:353, :782 and the admin list). No middleware consults it; feature_flags gate on audience/rollout/app-version only. The single completeness setting (kyc.auto_approve_min_completeness=100, KycService.php:530) is KYC-document completeness driving auto-approval, not a feature unlock.\n\nONE FINDING OUTSIDE THE LENS BUT WORTH THE PARENT'S ATTENTION: the entire creator self-management API is unconsumed by the Flutter app. In D:/zustee.com/mobile, only ApiEndpoints.creatorSetAvailability is referenced (creator_repository.dart:154); myCreatorProfile, creatorAnalytics, creatorPackages, creatorPortfolioItems and creatorSocial appear in api_endpoints.dart but are called from nowhere. lib/features/profile/edit_profile_screen.dart edits the `users` account (PUT /me) only. There is no creator profile editing UI at all — so even the parts that ARE built are unreachable from the app.\n\nAlso noted: CreatorController.php:419-424 computes $needsReview from substantive edits but only ever writes status='pending_review' when the row was already 'draft'. An approved creator can rewrite display_name/about/headline with no re-review, contradicting the comment directly above it.

**admin** — The admin creator module is a READ + MODERATE surface, not a management surface. What genuinely exists: a filterable/exportable creator list (UserController.php:302-377), a rich read-only detail page (UserController.php:379-443 + Views/admin/creators/show.php), approve/reject/suspend/reinstate with mandatory reasons and notifications (UserController.php:445-496), and verified/featured/commission-override toggles (UserController.php:498-536). All five permission slugs those routes use exist and are available — there is NO route guarded by a nonexistent slug. Documents are reviewable through the separate KYC module.\n\nWhat is genuinely absent, verified under multiple names: field-level editing of a creator (no admin CreatorController, no Resource.php entry, no edit route — creators.edit exists as a permission but migration 025 flags it is_available=0 precisely because it gates nothing); section locks (no column, no table, no enforcement — searched locked_fields/field_lock/section_lock/is_locked/profile_lock); a badge system (no badges table under any of three names; KycService::badgesFor hardcodes four badges derived from KYC state); per-change approval (no change-request table under five candidate names; ApprovalService's 'creator' type approves the whole profile); profile history and version compare (RevisionService.php:44-93 lists 12 entity types and creators is not one; 0 rows live); portfolio and gallery moderation (creator_portfolio has is_active but no admin writer — MediaController covers only the CMS `media` table); and every admin settings lever the spec asks for — mandatory fields, handle-change limit, completion threshold, badge rules — none of which have a settings group (SettingsController.php:32-48 is a hardcoded 15-tab const).\n\nThings that exist under a DIFFERENT name and should not be rebuilt: ban lives on the user account, not the creator profile (UserController.php:164-200, users.status enum includes 'banned'); restore exists twice — 'reinstate' for suspended profiles (UserController.php:474) and a recycle bin that already registers creators, creator_packages and creator_portfolio (ProductivityController.php:111-162); a multi-step creator approval workflow is fully wired in ApprovalService (:34-60, :793-807) with a seeded workflow; the whole revision diff/restore UI works and needs only a const entry plus a record() call; search_boost and is_featured are already settable by admin-configurable automation rules (AutomationService.php:117,133).\n\nFive concrete defects found: (1) badges are sticky-on — show.php:272-284 posts bare checkboxes and UserController.php:506-515 gates on Request::has(), so unchecking Verified/Featured silently does nothing (Request.php:185-190, no JS shim in admin.js); (2) creator-side profile edits are completely unaudited — CreatorController imports ActivityLogger at line 13 and never calls it across eight write methods, confirmed by 0 user-actor creator rows live; (3) an approved creator can rewrite display_name/about/headline with no re-review because CreatorController.php:422 only applies $needsReview when status is already 'draft'; (4) the recycle-bin 'creators' entry is unreachable — nothing anywhere writes creators.deleted_at; (5) the one-click Approve bypasses the configured approval workflow entirely. Hardcoding remains pervasive in the rules layer: completeness weights (CreatorService.php:587-609), badge catalogue (KycService.php:989-1004), moderation vocabulary (UserController.php:451,470-475), status enum and its duplicated tone maps in both views.

**app-sync** — SCOPE NOTE: a concurrent agent applied database/migrations/029_creator_profile_system.sql at 2026-08-04 15:43 while I was auditing. It added the `social_platform` (16 terms) and `verification_badge` (11 terms) taxonomies, the creator_badges / creator_badge_events / creator_profile_locks tables, and widened creator_social_accounts.platform from ENUM to VARCHAR(40). All three new tables are empty and grep of app/ and routes/ finds ZERO PHP references to any of them — storage only, no read or write path.

THE APPS. The creator side of the Flutter app is effectively read-only. features/creator/ contains three files, all viewers: creator_profile_screen.dart (public detail), creator_gallery_screen.dart, widgets/media_viewer.dart. The single thing a creator can write about themselves is their booking calendar (menu_screen.dart:163 -> availability_calendar_screen -> calendar_provider.dart:75 -> PUT /me/creator/availability). features/profile/edit_profile_screen.dart:94-104 writes name/company/email/phone/city/bio/avatar to PUT /me — those land on `users`, not `creators`, so the bio a creator edits in the app is NOT the bio (creators.about) shown on their public profile. Nine of the eleven registered /me/creator routes are unreachable from any client: myCreatorProfile, creatorAnalytics, creatorPackages(+id), creatorPortfolioItems(+id), creatorSocial are declared in api_endpoints.dart:74-81 and referenced nowhere else in the Dart tree. Conversely there are no orphan calls — every endpoint the app actually invokes is registered.

ANTI-HARDCODING. The taxonomy system is real and well used on the brand/filter side (24 keys; filter_sheet.dart drives sort, platform, engagement, rating, language, gender, age_group, experience, response_time from it). It is not used on the creator-profile side. Live hardcodes: models/creator.dart:305-315 (8-way platform label switch) and creator_profile_screen.dart:520-527 (platform icon switch) despite social_platform terms carrying icon and color_hex; models/creator.dart:7-24 (Availability enum + labels, with no taxonomy behind it at all); creator_repository.dart:168-174 (response-time ranking, duplicating TaxonomyTerm.rank); campaign_form_screen.dart:442-444 and models/campaign.dart:109-111,238-243 (gender and platform literals); creator_profile_screen.dart:77 (share URL zuste.com hardcoded). Server-side the option sets are hardcoded a second and third time: CreatorController.php:372,375,463,464,567,648 and KycController.php:346,364 are literal `in:` lists, and creators.gender / creators.availability_status / kyc_creator_details.id_proof_type are still ENUMs. That produces a reproducible defect today — the id_proof_type taxonomy contains `pan`, the app renders it (edit_profile_screen.dart:472, kyc_details_screen.dart:490), and the API 422s it.

BADGES. Dart has no creator badge model at all: Creator carries only isVerified (models/creator.dart:88/142). KycBadge (models/kyc.dart:143) exists but is fed solely by GET /me/kyc, the owner's own view, and the badge catalogue itself is four hardcoded PHP literals at KycService.php:986-1006. CreatorService::profile returns no badges, so a brand never sees one.

ENTIRELY ABSENT, verified under multiple names: professions/skills/niches (no taxonomy among the 24, no column matching profession|occupation|job_title|skill|expertise|niche in any of the 123 tables, no Dart reference); creator contact details and per-field visibility settings (no visibility|privacy|is_public|show_* column on creators); an admin CRUD surface for creator profile fields (Resource.php declares 13 resources, creators is not one; routes/admin.php:121-124 allows only moderate + is_verified/is_featured/commission_rate). Note also that age_group and experience_level are filterable in the app but have NO storage column on `creators`, so those two filters can never match.

THE TEN AUDIT ITEMS. Storage AND writer: profile views (creator_profile_views, CreatorController.php:96 -> CreatorService::recordView:471 — but `source` is always 'direct' because the app sends no source param), login history (login_history, SecurityService.php:66 — admin-only, no API route), admin actions (activity_logs via ActivityLogger::admin:59, incl. creator.approve and creator.flags_updated), verification history for KYC only (kyc_events, 84 rows, GET /me/kyc/timeline). Storage but no effective writer: device history (user_devices + AuthService::registerDevice:1268, but the app never sends a device_id and ApiClient.setDeviceHeaders at api_client.dart:116 has zero callers, so registerDevice returns early every time). Storage but wrong subject: profile edit history (activity_logs has 237 'profile.updated' rows from AccountController.php:123, but CreatorController::updateProfile logs nothing — ActivityLogger is imported at CreatorController.php:13 and never called). Genuinely untracked, no storage and no writer anywhere: profile shares, portfolio downloads, social link clicks, and per-badge verification history. Public profile visits are half-covered — anonymous in-app views are recorded, but the shared URL is not served by this backend so external visits produce nothing. Finally: there is no `audit_logs` and no `user_sessions` table; the equivalents are activity_logs and refresh_tokens respectively — do not create duplicates.


## MISSING (40)

### Admin can edit any creator information

- **Lens:** admin · **Effort:** large
- **Evidence:** routes/admin.php:120-124 is the complete creator route block — get /creators, get /creators/{id}, post /creators/{id}/moderate, post /creators/{id}/flags. Nothing else. Names/places searched: a CreatorController in app/Controllers/Admin/ (the directory has 26 controllers, none named Creator*); a 'creators' key in app/Admin/Resource.php::registry() (keys at lines 89-103 are categories, pages, blogs, banners, faqs, testimonials, onboarding-slides, coupons, popups, redirects, flags, sections, ai-prompts — no creators); routes matching /creators/{id}/edit, /update, /save; the generic resource route regex at routes/admin.php:51 ($resources = 'categories|pages|blogs|banners|faqs|testimonials'…). The only writes to the `creators` table in the whole admin are UserController.php:477 (status/rejection_reason/approved_by/approved_at) and UserController.php:526 (is_verified/verified_at/is_featured/commission_rate).
- **Gap:** There is no admin path to change display_name, handle, headline, about, category, city, pricing, languages, availability, or SEO fields. A permission slug 'creators.edit' exists in the permissions table (database/seeds/001_reference_data.sql:44) but is checked nowhere and was explicitly flagged dead by database/migrations/025_permission_availability.sql:26-33, which sets is_available=0 (confirmed live: creators.edit available=0).

### Admin can approve / reject INDIVIDUAL CHANGES to a profile (field-level change review)

- **Lens:** admin · **Effort:** large
- **Evidence:** Searched SHOW TABLES (117 tables) for a change-request store under the names creator_change_requests, profile_changes, pending_changes, creator_edits, creator_revisions, profile_change_requests — none exist. ApprovalService supports entity_type 'creator' (app/Services/ApprovalService.php:34-42 and 52-60) and its outcome handler at ApprovalService.php:793-807 does `UPDATE creators SET status=…` — i.e. it approves the WHOLE profile, never a field. The only way to raise one is the manual form at WorkflowController::raise (app/Controllers/Admin/WorkflowController.php:379-440, route routes/admin.php:332); live DB has 1 creator workflow and 0 creator approval_requests.
- **Gap:** No pending-value storage, no per-field accept/reject. Worse, the creator API silently bypasses re-review entirely: app/Controllers/Api/CreatorController.php:419-424 computes $needsReview from display_name/about/headline but only applies it `if ($needsReview && $existing['status'] === 'draft')` — an already-approved creator can rewrite their name, bio and headline and the profile stays 'approved' with no admin ever seeing it.

### Admin can view a creator's profile history

- **Lens:** admin · **Effort:** medium
- **Evidence:** No history storage for creator profile state. Searched SHOW TABLES for creator_history, profile_history, creator_audit, creator_status_history (booking_status_history and kyc_events exist for those modules; there is no creator equivalent). `revisions` exists but excludes creators (see the RevisionService finding). creator_profile_views exists but is view-count analytics and is queried by zero admin controllers.
- **Gap:** An admin cannot see who changed what on a profile or when it moved between states. The only trace is the generic activity log, which records admin moderation actions but not the fields.

### Admin can compare previous versions of a creator profile

- **Lens:** admin · **Effort:** medium
- **Evidence:** The diff machinery is real but creators are not wired to it. app/Services/RevisionService.php:44-93 ENTITIES lists exactly 12 types: pages, blogs, categories, banners, faqs, testimonials, coupons, popups, redirects, flags, sections, ai-prompts. RevisionService::record() hard-rejects unknown types at :153-160, and the compare view (app/Controllers/Admin/ProductivityController.php:930-960, routes/admin.php:399-401) resolves the entity through RevisionService::entity(). Live DB: SELECT COUNT(*) FROM revisions WHERE entity_type LIKE 'creator%' = 0.
- **Gap:** Adding 'creators' to ENTITIES gets the diff/restore UI for free, but only if a writer calls RevisionService::record() before each update — today no creator write path (admin or API) calls it at all.

### RevisionService covers the creators table

- **Lens:** admin · **Effort:** medium
- **Evidence:** app/Services/RevisionService.php:44-93 — 'creators' is absent from the ENTITIES const (the only place a type becomes a table name, per the class docblock at :18-21). Confirmed live: 0 revision rows for any creator entity type.
- **Gap:** Nothing at all is covered. RevisionService::diff (:341-381), previous (:256-265), restore (:494-560) and the whole /admin/revisions UI would work for creators the moment the entity is registered AND a record() call is added to the write paths — the service itself needs no change beyond the const entry.

### Admin can moderate a creator's gallery (cover image / uploaded media)

- **Lens:** admin · **Effort:** medium
- **Evidence:** No route touches creator media. MediaController (routes/admin.php:198-208, permission cms.media) operates on the `media` table only — the CMS library, not creator uploads. Grepped app/Controllers/Admin/ for creator_portfolio|creator_social: the only hits are ProductivityController.php:155-162 (recycle-bin registry) and UserController.php:416-427 (read-only SELECTs for the detail page). creators.cover_image_url has no admin writer and is not even rendered (0 hits in creators/show.php); it is set unvalidated-for-moderation from app/Controllers/Api/CreatorController.php:376/410.
- **Gap:** An admin cannot take down or replace a cover image or any creator-uploaded asset.

### Admin can moderate a creator's portfolio

- **Lens:** admin · **Effort:** medium
- **Evidence:** creator_portfolio has is_active and is_featured columns (live SHOW COLUMNS) but no admin writer: the table is written only by app/Controllers/Api/CreatorController.php:597 (insert) and :636 (creator's own soft-delete). The admin detail page renders the grid read-only at app/Views/admin/creators/show.php:156-181 — no per-item action, no form, no route. Searched for /admin/creators/{id}/portfolio, /admin/portfolio, a 'portfolio' key in app/Admin/Resource.php, and a portfolio branch in MediaController — none exist.
- **Gap:** An admin can see an offending portfolio item and has no way to hide, reject or delete it. The one adjacent capability is the recycle bin (app/Controllers/Admin/ProductivityController.php:159-162), which can RESTORE or PURGE items the creator already deleted — the inverse of moderation.

### Creator profile edits are logged from the CREATOR side

- **Lens:** admin · **Effort:** small
- **Evidence:** grep 'ActivityLogger::' across app/Controllers/Api/ returns exactly two hits — AccountController.php:123 (profile.updated on the `users` row) and AuthController.php:243 (password.changed). app/Controllers/Api/CreatorController.php imports ActivityLogger at line 13 but never calls it: updateProfile (:359-453), createPackage (:456), updatePackage (:497), deletePackage (:548), addPortfolioItem (:562), deletePortfolioItem (:622), upsertSocialAccount (:643), setAvailability (:688) all write with no audit call. Live DB: 0 activity_logs rows with actor_type='user' for any creator.* action.
- **Gap:** A creator can rename themselves, rewrite their bio, swap social handles, change all pricing and delete portfolio items with no record whatsoever. This is the single largest audit hole in the module.

### Bulk moderation of creators from the list screen

- **Lens:** admin · **Effort:** small
- **Evidence:** app/Views/admin/creators/index.php:80-166 renders a plain table — no row checkboxes, no bulk toolbar, no bulk form (the toolbar partial call at :56-70 passes only filters/search/export/selects). No /admin/creators/bulk route exists in routes/admin.php:120-124. By contrast KYC has one: routes/admin.php:136 POST /kyc/bulk.
- **Gap:** Approving a queue of pending creators requires opening each profile individually. The list's only action is a 'Review' link (index.php:157-161).

### The public creator profile payload carries badges

- **Lens:** app-sync · **Effort:** small
- **Evidence:** CreatorService::profile() (app/Services/CreatorService.php) assembles categories, social_accounts, packages, portfolio_counts, recent_portfolio, reviews, rating_breakdown, is_favorite/is_following/is_owner, languages, price_formatted, is_online — and nothing badge-shaped. KycService::badgesFor is called from exactly one place, KycService.php:945, which serves GET /me/kyc (the owner's own view).
- **Gap:** A brand browsing creators can never see 'Identity Verified' or 'Top rated'; only the raw creators.is_verified flag reaches them.

### AUDIT 6/10 — Profile shares

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** No storage: information_schema scan of all 123 tables for a column matching 'share' returns only dashboard_layouts.is_shared and saved_filters.is_shared. No table named creator_profile_shares / profile_shares / shares / share_events. No route: routes/api.php has no /share path. No writer. The app's share button (features/creator/creator_profile_screen.dart:71-79) calls SharePlus.instance.share and fires no analytics call — and the URL it shares is hardcoded, 'https://zuste.com/creator/${creator.handle}', rather than coming from /app/config.
- **Gap:** Genuinely untracked. creator_profile_views.source='share' is inbound attribution for a click on someone else's share, not a record that a share happened.

### AUDIT 7/10 — Portfolio downloads

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** No storage (no column matching 'download' anywhere; no portfolio_downloads / media_downloads / download_events table). No route: the only /download in routes/api.php is :141 GET /me/kyc/documents/{id}/download (KYC vault, unrelated). No writer in CreatorService or CreatorController. creator_portfolio has views_count/likes_count/comments_count but no downloads_count. App side: features/creator/widgets/media_viewer.dart offers no download action.
- **Gap:** Genuinely untracked, and there is no download feature to track.

### AUDIT 9/10 — Social link clicks

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** No storage: the only 'click' columns in the schema are banners.clicks, popups.clicks, campaign_recipients.clicked_at and marketing_campaigns.clicked_count — all marketing, none creator. No creator_link_clicks / social_clicks / outbound_clicks table. No route in routes/api.php. App: _SocialRow (features/creator/creator_profile_screen.dart:515-570) renders the platform, handle and follower count with no tap handler at all — the profile_url is displayed but not even openable.
- **Gap:** Genuinely untracked, and the link is not clickable to begin with.

### An `audit_logs` table and a `user_sessions` table (named in the brief)

- **Lens:** app-sync · **Effort:** trivial
- **Evidence:** Enumerated all 123 tables in zuste_marketplace. There is no `audit_logs` — the equivalents are activity_logs (1,878, business/admin actions), system_logs (2,859, level/channel/message), api_logs (27,988, HTTP traffic). There is no `user_sessions` — user sessions are refresh_tokens (393 rows: device_id, device_name, platform, ip, user_agent, expires_at, revoked_at, last_used_at); admin_sessions (34) is the admin panel's own. Names tried: audit_logs, audit_trail, audits, admin_logs, user_sessions, sessions, user_session.
- **Gap:** Nothing is missing functionally — both concepts exist under different names. Flagged so the gap is not 'fixed' by creating duplicate tables.

### Gallery CRUD — update an existing item

- **Lens:** creator-api · **Effort:** small
- **Evidence:** No PUT/PATCH route for a portfolio item in routes/api.php (only POST at :157 and DELETE at :158). Live probe: PUT /api/v1/me/creator/portfolio/1 -> 405 (path matched, verb absent). No updatePortfolioItem method in CreatorController.php.
- **Gap:** creator_portfolio.title, caption, external_url, aspect_ratio and duration_secs are writable only at insert. A typo in a caption can only be fixed by deleting and re-uploading the media.

### Skills

- **Lens:** creator-api · **Effort:** medium
- **Evidence:** No table (full SHOW TABLES taken — no creator_skills), no column on creators, no field in the PUT /creator allowlist. Names searched across app/, database/migrations/, routes/: skills, creator_skills, expertise, niche — 0 hits. Live probe: POST /api/v1/me/creator/skills -> 404.
- **Gap:** No storage and no endpoint.

### Interests

- **Lens:** creator-api · **Effort:** medium
- **Evidence:** Names searched: interests, creator_interests, topics, niche — 0 hits in app/, database/migrations/, routes/. No table in SHOW TABLES, no column on creators.
- **Gap:** No storage and no endpoint. The nearest thing is creator_categories, which is category taxonomy, not interests.

### Experience

- **Lens:** creator-api · **Effort:** small
- **Evidence:** Taxonomy 'experience_level' exists with 4 terms (database/seeds/007_taxonomies.sql:99-104, live in the taxonomies table) and is served to the app, but `creators` has no experience column (full SHOW COLUMNS taken), the PUT /creator allowlist has no such key, and CreatorService::search (CreatorService.php:60-211) accepts no experience filter. Names searched: experience, experience_level, years_experience, seniority.
- **Gap:** An orphaned taxonomy: the app can render an 'Experience level' picker whose value has nowhere to be stored and nothing to filter on.

### Awards

- **Lens:** creator-api · **Effort:** medium
- **Evidence:** Names searched across app/, database/migrations/, routes/: awards, achievements, recognition — 0 hits (the only 'awards' string is prose in database/seeds/002_cms_content.sql:28). No table, no column.
- **Gap:** No storage and no endpoint.

### Work history

- **Lens:** creator-api · **Effort:** medium
- **Evidence:** Names searched: work_history, employment, past_work, previous_work, resume — 0 hits except kyc_requirements slot 'resume' (id=21), which is an optional private KYC document, not structured work history.
- **Gap:** No storage and no endpoint.

### Brand collaborations (a listable set of past brands)

- **Lens:** creator-api · **Effort:** medium
- **Evidence:** Only the derived scalar creators.collabs_count exists, recomputed from completed bookings at CreatorService.php:530-533. Names searched: brand_collab, collaborations, past_brands, worked_with — no table, no column holding brand identities for the profile.
- **Gap:** A creator cannot list the brands they have worked with (especially off-platform ones). Nothing renders logos or named collaborations; only an integer count.

### Service areas

- **Lens:** creator-api · **Effort:** medium
- **Evidence:** creators has only single-value city/state/country. No multi-area table in SHOW TABLES. Names searched across app/, database/migrations/, routes/: service_area, serviceable, coverage, travel_radius, pincode/pin_code (only hits are kyc_*_details.pin_code, a KYC address field). CreatorService::search filters on a single exact 'c.city = :city' (CreatorService.php:93-96).
- **Gap:** No storage and no endpoint. A creator who serves three cities can be discovered in exactly one.

### FAQs (creator-authored, on the profile)

- **Lens:** creator-api · **Effort:** small
- **Evidence:** The `faqs` table is global CMS only — columns are id, category, question, answer, audience, helpful_yes, helpful_no, is_active, sort_order (live SHOW COLUMNS); there is no creator_id. It is served by the public GET /faqs (routes/api.php:109) and edited only in admin (app/Admin/Resource.php:380-419). Names searched: creator_faqs, faq_ + creator. Live probe: GET /api/v1/me/creator/faqs -> 404.
- **Gap:** No per-creator FAQ storage and no creator-facing endpoint.

### Social accounts — REMOVE

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** No DELETE route in routes/api.php (the only social route is PUT at :159). Live probe: DELETE /api/v1/me/creator/social -> 405 (path exists, verb does not). grep for `creator_social_accounts` across app/ returns only SELECTs plus the one INSERT at CreatorController.php:656 — there is no DELETE statement in the codebase, not even in admin (app/Controllers/Admin/UserController.php:416 only reads).
- **Gap:** A linked social account can never be unlinked by anyone. It also permanently inflates creators.followers_total, which is recomputed as SUM(creator_social_accounts.followers) at CreatorService.php:523-525 and drives the 'popular' sort and the min/max follower filters.

### Contact verification — alternate email, alternate mobile

- **Lens:** creator-api · **Effort:** small
- **Evidence:** users has no such columns (full SHOW COLUMNS taken: email, phone_country_code, phone only). kyc_creator_details has none either. The only alt_contact_name/alt_contact_email/alt_contact_phone columns in the schema are on kyc_brand_details (database/migrations/013_kyc_verification.sql:120-122) — brand-side, and read only by the admin view app/Views/admin/kyc/show.php:143-145. Names searched: alternate, alt_email, alt_phone, secondary_email, secondary_phone.
- **Gap:** No storage and no endpoint on the creator side.

### Share profile

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** No endpoint and no share field in any payload: CreatorService::profile (CreatorService.php:218-327) returns c.* plus joins and adds no share_url; CreatorService::presentCard (CreatorService.php:645-674) has no share key. Names searched across app/ and routes/: share_url, /share, shareLink, deep_link, deeplink, og_image — the only hits are ReferralService.php:178 (referral code link) and an unrelated admin saved-filter share route (routes/admin.php:390). creator_profile_views already accepts source='share' (CreatorService.php:497) but nothing ever mints such a link.
- **Gap:** No canonical share URL is produced server-side; the client would have to hardcode the web origin and handle format itself.

### Download profile QR code

- **Lens:** creator-api · **Effort:** small
- **Evidence:** No route — live probe GET /api/v1/me/creator/qr -> 404, and routes/api.php:151-160 contains no QR entry. Names searched: qr_code, qrcode, qr. A working in-house encoder DOES exist — app/Core/QrCode.php (ISO/IEC 18004 byte mode) and Zuste\Core\Totp::qrCodeSvg (app/Core/Totp.php:97) — but it is wired only to admin 2FA enrolment (app/Services/SecurityService.php:272).
- **Gap:** No creator-facing QR endpoint. Because the encoder already exists and is dependency-free, this is a thin wrapper, not a build.

### Media kit

- **Lens:** schema · **Effort:** medium
- **Evidence:** Searched information_schema.COLUMNS across the whole schema for %media_kit%, %mediakit%, %press_kit%, %rate_card%, %ratecard%, %deck% — zero hits. SHOW TABLES has no creator_media_kits / media_kits / press_kits. Grepped 'media kit', 'media_kit', 'rate card' across D:/zustee.com/backend/app and /database — nothing.
- **Gap:** Nowhere to store a downloadable media-kit asset, its version, or its visibility. The nearest thing is kyc_creator_details.portfolio_url VARCHAR(255) — a single URL captured during KYC that the public profile never reads.

### Profession

- **Lens:** schema · **Effort:** small
- **Evidence:** Searched information_schema.COLUMNS for %profession%, %occupation%, %job_title%, %designation%, %role% on creators — zero hits. SHOW FULL COLUMNS FROM creators confirms no such column. Grepped 'profession' and 'occupation' across app/ and database/ — nothing.
- **Gap:** The closest surrogates are creators.headline (free text, 150 chars, no option list) and creators.primary_category_id (a marketplace category, not a profession). A structured, admin-curated profession field does not exist.

### Skills

- **Lens:** schema · **Effort:** medium
- **Evidence:** Searched information_schema.COLUMNS for %skill%, %expertise%, %speciality%, %specialty%, %tag% — zero hits on any creator table. SHOW TABLES has no creator_skills, skills, or creator_tags. No taxonomies row with key='skill' (all 22 taxonomy keys listed: creator_sort, platform, language, gender, age_group, experience_level, response_time, rating_filter, engagement_filter, campaign_type, deliverable_type, portfolio_kind, industry, company_type, company_size, support_topic, faq_category, ticket_category, topup_preset, booking_status, id_proof_type, city).
- **Gap:** No storage and no option vocabulary. Needs a taxonomy key plus a creator_skills link table (or a JSON column) and a write path.

### Interests

- **Lens:** schema · **Effort:** medium
- **Evidence:** Searched information_schema.COLUMNS for %interest%, %niche%, %topic% — zero hits. SHOW TABLES has no creator_interests. No taxonomy key='interest' or 'niche' among the 22 seeded keys.
- **Gap:** No storage and no option vocabulary. Same shape as skills.

### Experience

- **Lens:** schema · **Effort:** small
- **Evidence:** The OPTION LIST exists — taxonomies id=6, key='experience_level', 4 terms (new / intermediate / experienced / expert) — but there is NO column to store the chosen value. SHOW FULL COLUMNS FROM creators has no experience, experience_level, years_active, started_at or since column; searched %experience%, %years%, %since%, %started%. CreatorService::search (app/Services/CreatorService.php:60-215) has no experience clause and CreatorController::index (CreatorController.php:49-70) never passes one.
- **Gap:** A dangling taxonomy: the app can render an 'Experience' filter chip from taxonomies but nothing can be selected against and nothing can be saved. Needs creators.experience_level plus validation, write path and a search clause.

### Awards and achievements

- **Lens:** schema · **Effort:** medium
- **Evidence:** Searched information_schema.COLUMNS for %award%, %achievement%, %recognition%, %certificat%, %honor% — zero hits. SHOW TABLES has no creator_awards, awards, creator_achievements or creator_certifications.
- **Gap:** No storage at all. Needs a repeating child table (title, issuer, year, evidence URL, sort_order) plus CRUD.

### Work history

- **Lens:** schema · **Effort:** medium
- **Evidence:** Searched information_schema.COLUMNS for %work_history%, %employment%, %career%, %resume%, %company% on creator tables — only users.company_name (brand-side) and kyc_brand_details columns hit. SHOW TABLES has no creator_work_history, creator_experience or creator_employment.
- **Gap:** No storage at all. Needs a repeating child table (role, organisation, from/to dates, is_current, description, sort_order) plus CRUD.

### Previous brand collaborations

- **Lens:** schema · **Effort:** medium
- **Evidence:** Searched information_schema.COLUMNS for %brand_collab%, %collaboration%, %client% and SHOW TABLES for creator_brands, creator_clients, creator_collaborations — zero hits. The only thing that exists is creators.collabs_count, a derived integer recomputed as COUNT(*) of completed bookings at app/Services/CreatorService.php:529-533.
- **Gap:** A COUNT is not a list. On-platform history is implicit in `bookings` but is never surfaced as a brand showcase, and there is no way at all to record an OFF-platform collaboration (brand name, logo, campaign, date, proof link) — which is what a new creator's profile most needs.

### Service areas

- **Lens:** schema · **Effort:** medium
- **Evidence:** Searched information_schema.COLUMNS for %service_area%, %coverage%, %serviceable%, %radius%, %pincode%; SHOW TABLES for creator_service_areas, creator_locations, creator_cities — zero hits. creators has exactly one location (city/state/country at 003:49-51) and CreatorService::search filters on a single equality `c.city = :city` (app/Services/CreatorService.php:92-95).
- **Gap:** A creator can be found in exactly one city. There is no multi-city, radius, remote/on-site or travel-willingness storage. The taxonomy key='city' (taxonomies id 42) is the app header's location picker, not a per-creator multi-select.

### Custom services

- **Lens:** schema · **Effort:** medium
- **Evidence:** SHOW TABLES has no creator_services or custom_services; searched %custom_service%, %service% columns. The only pricing store is creator_packages, whose deliverable_type is a 10-value ENUM and platform a 7-value ENUM (003_categories_and_creators.sql:144-145).
- **Gap:** A free-form service ('brand strategy call', 'event appearance', 'photo licence') cannot be expressed — every package must be pinned to one of ten fixed deliverable types on one of seven fixed platforms. Under STRICT_TRANS_TABLES anything else is a 1265 hard error, not a silent truncation. Needs either an 'other' + custom_label pair or a separate services table.

### FAQs (per creator)

- **Lens:** schema · **Effort:** medium
- **Evidence:** The `faqs` table exists but is SITE-WIDE: SHOW FULL COLUMNS FROM faqs shows no creator_id and no owner column at all. Its category ENUM('general','booking','payment','wallet','creator','brand','account','security') uses 'creator' to mean 'for creators', not 'belongs to a creator', and its audience ENUM is ('all','brands','creators'). It is admin-only CRUD via D:/zustee.com/backend/app/Admin/Resource.php:403-410 and served globally by ContentController. Searched SHOW TABLES for creator_faqs and creator_questions — absent.
- **Gap:** No per-creator FAQ storage. Needs a creator_faqs table (creator_id, question, answer, sort_order, is_active) plus creator CRUD and admin moderation.

### Testimonials (per creator)

- **Lens:** schema · **Effort:** medium
- **Evidence:** The `testimonials` table is SITE-WIDE marketing copy: columns are author_name / author_role / company / avatar_url / content / rating / source ENUM('manual','review') / review_id / is_featured — no creator_id (SHOW FULL COLUMNS FROM testimonials). Admin CRUD at D:/zustee.com/backend/app/Admin/Resource.php:421-425; served to the marketing screen by app/Controllers/Api/ContentController.php:290-296 (`WHERE is_active = 1`, no creator scope). Searched SHOW TABLES for creator_testimonials — absent.
- **Gap:** A creator cannot collect or display their own testimonials. The `review_id` column hints the table was meant to promote platform reviews to the homepage, which is the opposite direction.

### CONTACT — alternate email and alternate mobile

- **Lens:** schema · **Effort:** small
- **Evidence:** Full information_schema.COLUMNS scan for %alternate%, %alt_%, %secondary% across every table returned exactly four hits: kyc_brand_details.alt_contact_name / alt_contact_email / alt_contact_phone (BRAND KYC only, never read for creators) and media.alt_text. SHOW FULL COLUMNS FROM creators shows no contact columns at all — no email, no phone, no whatsapp. users carries exactly one email and one phone (+ phone_country_code).
- **Gap:** A creator cannot publish a business email separate from their login email, or a WhatsApp/business number separate from their OTP number. Needs alternate_email / alternate_phone (+ their own verified_at) on creators, plus a decision on whether they are publicly visible.

## PARTIAL (66)

### Admin can view a creator's full profile

- **Lens:** admin · **Effort:** small
- **Evidence:** D:/zustee.com/backend/app/Controllers/Admin/UserController.php:379-443 (creatorShow) renders D:/zustee.com/backend/app/Views/admin/creators/show.php:1-344 with header, about, categories, 4 stat tiles, socials, packages, portfolio grid, reviews, bookings. Route D:/zustee.com/backend/routes/admin.php:122.
- **Gap:** Roughly a third of the stored `creators` columns are never displayed. Verified by grepping the view: languages(0 hits — decoded at UserController.php:403 then never used), gender(0), date_of_birth(0), state(0 — the 2 'state' hits are the word 'reinstate'), country(0), price_min/price_max/currency(0), availability_status(0), profile_score(0 — shown on the list at creators/index.php:145 but NOT on the detail page), search_boost(0), meta_title/meta_description(0), cover_image_url(0), featured_until(0), response_time_hours(0), posts_count/platform_followers(0), verified_at(0). u.kyc_status is SELECTed at UserController.php:388 and never rendered. creator_availability and creator_analytics_daily are not queried at all by any admin controller (grep of app/Controllers/Admin/ for creator_availability|creator_analytics_daily|creator_profile_views returns 0 hits).

### Admin can lock individual sections (username, social, pricing, categories, bio, portfolio, contact, verification)

- **Lens:** admin · **Effort:** large · **corrected from MISSING**
- **Evidence:** SHOW COLUMNS FROM creators (42 columns, run live) has no lock column of any kind. Grepped app/ and database/ for locked_fields|field_lock|section_lock|is_locked|profile_lock — the only hits are conversations.is_locked (database/migrations/006_messaging_and_notifications.sql:30, app/Services/MessageService.php:136), which is chat, not profiles. SHOW TABLES has no creator_field_locks / creator_locks / profile_locks table.
- **Gap:** No storage, no admin control, and no enforcement point — app/Controllers/Api/CreatorController.php:359-453 (updateProfile), :643 (upsertSocialAccount), :456/:497 (packages), :562/:622 (portfolio) accept every field unconditionally for any creator who owns the row.
- **Why corrected:** The storage EXISTS and is applied to the live database. D:/zustee.com/backend/database/migrations/029_creator_profile_system.sql:236-256 creates `creator_profile_locks` (id, creator_id, section VARCHAR(40), locked_by, reason, locked_at, UNIQUE KEY uq_creator_section_lock(creator_id, section)) — presence of the row IS the lock, unlocking deletes. I confirmed it live: SHOW TABLES returns 126 tables and `creator_profile_locks` is among them (the claim's '42 columns on creators, no lock column' search was correct but looked only at the creators table; the design deliberately uses a side table, and the claim's own search list omitted `creator_profile_locks`). Live row count is 0 and grep of app/, routes/, public/ for creator_profile_locks returns zero hits, so there is no writer, no admin UI and no enforcement — hence PARTIAL, not MISSING. A builder must NOT create a new lock table.

### Admin can assign badges to a creator

- **Lens:** admin · **Effort:** large
- **Evidence:** Two hardcoded boolean flags only: app/Views/admin/creators/show.php:272-284 posts is_verified / is_featured to UserController::toggleCreatorFlags (app/Controllers/Admin/UserController.php:498-536), which writes creators.is_verified/verified_at (506-510) and creators.is_featured (512-515).
- **Gap:** There is no badge system. SHOW TABLES has no badges / creator_badges / user_badges table (searched all three names). The only named-badge code is app/Services/KycService.php:980-1006 badgesFor(), which DERIVES four hardcoded badges (identity_verified, business_verified, verified_creator/verified_brand, premium_verified — labels and icons literal in PHP) from KYC state; an admin cannot mint, name, or hand-assign any badge.

### Admin can remove badges from a creator

- **Lens:** admin · **Effort:** trivial
- **Evidence:** app/Controllers/Admin/UserController.php:506-515 gates both flags on $request->has(...). app/Core/Request.php:185-190 defines has() as array_key_exists on body/query/routeParams.
- **Gap:** REAL DEFECT — badges are sticky-on. app/Views/admin/creators/show.php:272-284 renders bare checkboxes with no companion hidden 0-value input (grep for type="hidden" in that file returns only the moderate-action/reason/csrf fields at lines 225, 249, 321-322), and public/admin/assets/js/admin.js has no checkbox-serialisation shim (grep for 'checkbox' returns 0 relevant hits). An unchecked checkbox is not posted, so has('is_verified') is false, $updates never contains is_verified=0, and the Verified/Featured badge can be granted but never revoked through the admin UI.

### Admin can ban a creator

- **Lens:** admin · **Effort:** small
- **Evidence:** Ban exists only at ACCOUNT level: app/Controllers/Admin/UserController.php:164-200 updateStatus accepts in:active,suspended,banned,pending on the `users` table and revokes all tokens at line 186-188 (route routes/admin.php:116, permission users.edit). The `creators`.`status` enum is draft/pending_review/approved/rejected/suspended (live SHOW COLUMNS) — no 'banned' value.
- **Gap:** No creator-profile-level ban distinct from suspend, and the user-level ban is on a different screen (/admin/users/{id}) with no link or indicator from the creator page — app/Views/admin/creators/show.php:67 links to /admin/users/{id} only as the email hyperlink, and does not surface u.status even though UserController.php:388 selects it as user_status.

### Admin can restore a creator

- **Lens:** admin · **Effort:** small
- **Evidence:** Two half-paths. (1) 'reinstate' action: app/Controllers/Admin/UserController.php:451 + :474 maps reinstate→approved; UI at app/Views/admin/creators/show.php:246-254, only rendered when status==='suspended'. Live DB has 1 creator.reinstate log row. (2) Recycle bin: app/Controllers/Admin/ProductivityController.php:111-114 registers 'creators' as RECYCLABLE (also creator_packages at :155-158 and creator_portfolio at :159-162), restore route routes/admin.php:395.
- **Gap:** The recycle-bin path is unreachable for creators: NOTHING writes creators.deleted_at anywhere in app/ (grep for deleted_at against the creators table returns only COUNT(*) reads at BaseAdminController.php:55, DashboardController.php:198-200). UserController::destroy (:265-296) soft-deletes the `users` row only. Live DB: 0 soft-deleted creators. Also, a REJECTED creator cannot be reinstated — the reinstate button is guarded on status==='suspended' at show.php:246.

### Admin can view change logs for a creator

- **Lens:** admin · **Effort:** small
- **Evidence:** Admin moderation IS audited: app/Controllers/Admin/UserController.php:493 ('creator.'.$action) and :533 ('creator.flags_updated') call BaseAdminController::log (app/Controllers/Admin/BaseAdminController.php:139-141 → ActivityLogger::admin). Live DB confirms 10 rows with subject_type='creator' across creator.approve/reject/suspend/reinstate/flags_updated. Viewer: app/Controllers/Admin/AccessController.php:430-471 (route routes/admin.php:222, permission logs.activity) with a subject_type filter at AccessController.php:437.
- **Gap:** Two gaps. (a) No per-creator view — app/Views/admin/creators/show.php contains no link to /admin/logs?subject_type=creator&subject_id=N; an operator must leave the profile and hand-filter. (b) The log records the ACTION but not the field diff for edits, because no admin edit path exists to diff.

### Creator profile edits are logged from the ADMIN side

- **Lens:** admin · **Effort:** small
- **Evidence:** app/Controllers/Admin/UserController.php:493 and :533 log every moderation and flag change through BaseAdminController::log (BaseAdminController.php:139-141), including a properties payload ($updates at :533, reason at :493).
- **Gap:** Only moderation and flags are loggable, because those are the only admin writes that exist. Field-level profile edits are unlogged for the simple reason that they are unimplemented. ActivityLogger::diff() (app/Services/ActivityLogger.php:107-125) exists and is unused by any creator path.

### Every admin creator action has a permission slug, and that slug exists in the permissions table

- **Lens:** admin · **Effort:** trivial
- **Evidence:** All four slugs used by routes/admin.php:121-124 (creators.view ×2, creators.approve, creators.verify) plus the in-controller creators.feature (UserController.php:513) exist and are is_available=1 in the live permissions table (verified by query). Seeded at database/seeds/001_reference_data.sql:43-48. No route is guarded by a slug that does not exist — that specific defect is NOT present.
- **Gap:** The inverse defect is present: creators.edit and creators.delete exist as rows (seeds 001_reference_data.sql:44 and :48) and are granted to roles (001_reference_data.sql:125), but gate nothing. database/migrations/025_permission_availability.sql:26-33 sets is_available=0 for both to hide them from the role editor (live: creators.edit=0, creators.delete=0). Also note the granularity gap: creators.verify alone unlocks the commission-rate override (UserController.php:517-520) — a pricing/finance change gated by a 'verify badge' slug.

### Operator can configure MANDATORY PROFILE FIELDS from an admin settings screen

- **Lens:** admin · **Effort:** large · **corrected from MISSING**
- **Evidence:** app/Controllers/Admin/SettingsController.php:32-48 is a hardcoded const GROUPS of 15 tabs (general, branding, booking, commission, wallet, referral, payment, firebase, mail, sms, upload, app, seo, social, security) — no creator/profile group, and index() forces an unknown group back to 'general' at :61-63. Live settings query for keys or groups matching creator|profile|handle|username|badge|complet|verif returned only: app.creator_signup_enabled, commission.creator_commission_percent, kyc.auto_approve_min_completeness, kyc.badges_enabled, kyc.creator_mandatory, plus unrelated mail.username and seo.twitter_handle.
- **Gap:** Which creator fields are required is fixed in PHP validators (app/Controllers/Api/CreatorController.php:363-378 — every field is 'nullable'). The nearest analogue, kyc_requirements (kind/slot/label/is_required/is_active, editable at routes/admin.php:133-134), governs KYC DOCUMENT slots, not profile fields.
- **Why corrected:** The setting EXISTS in the live settings table. database/seeds/031_creator_profile_settings.sql:47-53 seeds ('creator','mandatory_fields','display_name,handle,headline,about,primary_category_id,city,languages','string', is_public=1); I confirmed live: SELECT ... WHERE group_key='creator' returns 27 rows including mandatory_fields, locked_fields, required_verifications. The claim's live scan for 'creator|profile|...' missed the whole group. The values are already served to clients generically by GET /settings (app/Controllers/Api/ContentController.php:495-518 selects every is_public=1 row and buckets it by group_key). Real gap: no admin editor — app/Controllers/Admin/SettingsController.php:32-48 GROUPS has no 'creator' key, index() forces unknown groups to 'general' at :61-63 and update() hard-rejects them at :113-118 ('Unknown settings group.'), so the rows are unreachable from the admin UI; and no PHP reads the keys (grep for mandatory_fields across app/ and mobile/lib = 0 hits). Add a GROUPS entry plus a reader, do not invent new keys.

### Operator can configure a USERNAME/HANDLE CHANGE LIMIT

- **Lens:** admin · **Effort:** medium · **corrected from MISSING**
- **Evidence:** No setting (live settings scan above found none matching handle|username for the creator domain), no counter column on `creators` (42 columns, none like handle_changed_at / handle_change_count), and no throttle in code: app/Controllers/Api/CreatorController.php:387-396 checks only uniqueness before allowing a handle change, and CreatorService::uniqueHandle (app/Services/CreatorService.php:619-637) only generates a collision-free slug.
- **Gap:** A creator can change their public handle an unlimited number of times, instantly, breaking every shared profile link and the sitemap entry (app/Services/SitemapService.php:503 keys creator URLs on c.handle). No admin lever exists.
- **Why corrected:** Three settings exist live and were seeded for exactly this: database/seeds/031_creator_profile_settings.sql:75-89 — creator.username_change_limit='3', creator.username_change_cooldown_days='30', creator.username_min_length='3' (all is_public=1). Confirmed present in the live settings table. The claim's 'live settings scan found none matching handle|username' is wrong because the scan missed the creator group entirely. Still PARTIAL, not BUILT: there is no counter column on creators (I ran SHOW COLUMNS live — 41 columns, no handle_change_count/handle_changed_at) and no throttle in app/Controllers/Api/CreatorController.php:387-396, and no admin tab exposes the keys.

### Operator can configure a REQUIRED COMPLETION PERCENTAGE before a profile goes live

- **Lens:** admin · **Effort:** medium · **corrected from MISSING**
- **Evidence:** No setting exists (live scan). profile_score is computed by app/Services/CreatorService.php:577-616 and is never compared against a threshold anywhere — grep shows it is only written (:613) and displayed (app/Views/admin/creators/index.php:145-148). The one completeness threshold in the platform, kyc.auto_approve_min_completeness=100, belongs to KycService and scores KYC documents, not the profile.
- **Gap:** Nothing blocks a 10%-complete profile from being submitted or approved.
- **Why corrected:** database/seeds/031_creator_profile_settings.sql:23-45 seeds creator.required_completion_percent='60' (integer, is_public=1), creator.completion_weights (json: {basic:15,contact:10,social:15,portfolio:15,kyc:15,bio:5,gallery:5,pricing:10,categories:5,verification:5}) and creator.feature_unlock_thresholds (json: {accept_bookings:60,appear_in_search:70,apply_to_campaigns:75,featured_eligible:90}). All three are live in the settings table and already reach clients via GET /settings (app/Controllers/Api/ContentController.php:495-518). The claim asserted 'No setting exists (live scan)' — that is the stale-scan error. It remains PARTIAL because nothing reads them: app/Services/CreatorService.php:587-616 still computes profile_score from PHP literals and never compares against the threshold, and SettingsController.php:32-48 has no creator tab. Note the schema conflict a builder must resolve: the DB weights are section-based (basic/contact/social/portfolio/kyc/bio/gallery/pricing/categories/verification) while CreatorService.php:587-609 uses a different, field-based set.

### Operator can configure BADGE RULES

- **Lens:** admin · **Effort:** large · **corrected from MISSING**
- **Evidence:** The only badge logic is app/Services/KycService.php:980-1006, where the four badge codes, labels and Material icon names are PHP literals and the trigger conditions are hardcoded field checks. The single operator lever is the on/off boolean kyc.badges_enabled read at KycService.php:982. creators.is_verified / is_featured are raw booleans with no rule engine.
- **Gap:** An operator cannot create a badge, rename one, change its icon, or define what earns it.
- **Why corrected:** A full badge catalogue exists as an admin-managed taxonomy, not as PHP. database/migrations/029_creator_profile_system.sql:120-176 inserts taxonomy `verification_badge` and 11 terms — email_verified, mobile_verified, identity_verified, kyc_verified, creator_verified, premium_creator, featured_creator, business_verified, social_verified, top_rated, trusted_creator — each with label, description, icon, color_hex and a metadata JSON carrying the rule ({"auto":true,"source":"creators.rating_avg","expires_days":90} etc.). I confirmed live: 11 terms under key 'verification_badge' (and 16 under 'social_platform'). Those terms are editable today through the existing admin taxonomy module (app/Controllers/Admin/TaxonomyController.php), so an operator CAN already rename a badge, change its icon and edit its rule metadata. Numeric thresholds are settings too: seeds/031:229-247 creator.top_rated_min_rating=4.50, top_rated_min_reviews=10, trusted_min_collabs=25, featured_max_slots=12, featured_duration_days=30. PARTIAL because no engine reads any of it — grep of app/ for verification_badge returns 0 hits and KycService.php:980-1006 still hardcodes its four badges.

### Nothing about a creator profile is HARDCODED (spec's central demand)

- **Lens:** admin · **Effort:** large
- **Evidence:** Hardcoded sites found, each with file:line — (1) profile-completeness weights: app/Services/CreatorService.php:587-609 (display_name 10, headline 10, about≥80 chars 15, city 5, category 10, socials min(15, n*8), packages min(20, n*10), portfolio min(15, n*3)); (2) badge catalogue: app/Services/KycService.php:989-1004; (3) the moderation action vocabulary: app/Controllers/Admin/UserController.php:451 'in:approve,reject,suspend,reinstate' and the status map at :470-475; (4) the creators.status enum itself (5 values, live SHOW COLUMNS) with tone maps duplicated in two views — app/Views/admin/creators/index.php:19-26 and app/Views/admin/creators/show.php:22-24; (5) admin settings tabs: app/Controllers/Admin/SettingsController.php:32-48; (6) revision-eligible entities: app/Services/RevisionService.php:44-93; (7) recycle-bin entities: app/Controllers/Admin/ProductivityController.php:106-171; (8) approval entity types: app/Services/ApprovalService.php:34-60; (9) sortable creator columns: app/Controllers/Admin/UserController.php:24.
- **Gap:** Categories, cities and most option lists ARE database-driven (categories table, taxonomies), so the taxonomy work landed — but every rule that GOVERNS a creator profile (what counts as complete, what earns a badge, what states exist, what an admin may do) is still literal PHP.

### Approval workflow (multi-step sign-off) applies to creator approval

- **Lens:** admin · **Effort:** medium
- **Evidence:** app/Services/ApprovalService.php:34-42 registers 'creator' => 'Creator profile'; source table wired at :52-60; the approved/rejected outcome writes creators.status/approved_by/approved_at at ApprovalService.php:793-807, correctly guarded by `AND status IN ('pending_review','draft')`. A workflow row is seeded (database/seeds/003_enterprise_defaults.sql:107 — one 'Moderation' step, role 5, permission creators.approve) and live DB confirms 1 creator workflow exists.
- **Gap:** Nothing routes creators INTO it. UserController::moderateCreator (UserController.php:445-496) writes the status directly and never calls ApprovalService::open — so the one-click Approve button bypasses the configured sign-off entirely. The only entry points are the manual raise form (WorkflowController.php:379-440, requires an admin to type the creator id) and an automation rule; live DB has 0 creator approval_requests.

### A creator can edit their creator profile (display_name, handle, headline, about, cover image, city/state/country, gender, DOB, languages, categories, availability_status) from the app

- **Lens:** app-sync · **Effort:** large · **corrected from MISSING**
- **Evidence:** Server side exists: routes/api.php:152 PUT /me/creator -> CreatorController::updateProfile (app/Controllers/Api/CreatorController.php:359-453). App side: D:/zustee.com/mobile/lib/features/creator/ contains only creator_profile_screen.dart (public read-only view), creator_gallery_screen.dart, widgets/media_viewer.dart. ApiEndpoints.myCreatorProfile (core/network/api_endpoints.dart:74) has exactly one occurrence in the whole Dart tree — its own declaration. Searched features/creator/**, features/profile/** (business_profile_screen, edit_profile_screen, menu_screen), features/onboarding/**, features/dashboard/** (brand only), router/app_router.dart. Names tried: creator_edit, edit_creator, creator_profile_edit, creator_setup, my_creator, creator_dashboard, creator_onboarding.
- **Gap:** No screen, no repository method, no provider, no route. CreatorRepository (data/repositories/creator_repository.dart) has no updateProfile at all — it only reads public profiles and writes availability. Every creators-table field is therefore write-only-by-seed today.
- **Why corrected:** The claim contradicts its own evidence and the audit's own grading rule. A complete server writer exists and covers every single field the requirement names: routes/api.php:152 PUT /me/creator -> CreatorController::updateProfile, whose validator (app/Controllers/Api/CreatorController.php:363-378) accepts display_name, handle, headline, about, primary_category_id, city, state, country, gender, date_of_birth, languages, availability_status, cover_image_url and category_ids; it does handle-collision checking (:386-397), writes creators + creator_categories in one transaction (:426-444), auto-transitions draft -> pending_review (:421-424) and recomputes profile_score (:446). The identical situation (server route built, no Dart consumer) is graded PARTIAL in five other claims in this same set — packages, portfolio, social, analytics, profile score. Grading it MISSING here invites someone to rebuild PUT /me/creator and its validator from scratch. The only genuinely absent pieces are Dart-side: no screen, no route, and no CreatorRepository.updateProfile (lib/data/repositories/creator_repository.dart has 11 methods, the only writer being setAvailability at :146-154, which writes the calendar, not availability_status).

### Identity fields a creator can actually write from the app today

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** features/profile/edit_profile_screen.dart:94-104 is the only profile write path: it PUTs name, company_name, email, phone, city, bio, avatar_url to /me (AccountController::updateProfile) plus notification prefs. Those columns live on `users`, not `creators`.
- **Gap:** creators.display_name, headline, about, cover_image_url, handle, languages, gender, date_of_birth, primary_category_id, availability_status have no app writer. A creator editing 'bio' in the app writes users.bio, which CreatorService::profile never returns — the public profile reads creators.about. The two are silently disconnected.

### Creator manages pricing packages from the app

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** Server complete: routes/api.php:154-156 POST/PUT/DELETE /me/creator/packages, CreatorController::createPackage:456, updatePackage:497, deletePackage:548; creator_packages has 200 rows. App: ApiEndpoints.creatorPackages (api_endpoints.dart:76) and creatorPackage(id) (:80) are declared and never referenced — CreatorPackage in models/creator.dart:319-367 is read-only (fromJson only, no toJson).
- **Gap:** No Dart write model, repository method, screen or route. Three registered endpoints unreachable from any client.

### Creator uploads and removes portfolio media from the app

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** Server: routes/api.php:157-158 POST /me/creator/portfolio, DELETE /me/creator/portfolio/{id}; CreatorController::addPortfolioItem:562 (accepts multipart or URL), deletePortfolioItem:622. App: ApiEndpoints.creatorPortfolioItems (:77) and creatorPortfolioItem(id) (:81) never referenced. features/creator/creator_gallery_screen.dart is read-only.
- **Gap:** No upload UI, no delete UI. Also no server route to EDIT an existing item (no PUT/PATCH /me/creator/portfolio/{id}), and no way to set is_featured or sort_order that the SELECT in CreatorService::profile orders by.

### Creator links / unlinks social accounts from the app

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** Server: routes/api.php:159 PUT /me/creator/social -> CreatorController::upsertSocialAccount:643-685 (upsert into creator_social_accounts, 118 rows). App: ApiEndpoints.creatorSocial (:78) never referenced; models/creator.dart:274-316 SocialAccount is read-only.
- **Gap:** No app screen. Server has no DELETE for a social account, so a wrongly-linked handle can never be removed by the creator. upsertSocialAccount also does not accept avg_views or is_verified, so those columns are only ever writable by seed/admin SQL.

### Creator sees their own analytics (views, requests, earnings, followers, rating, profile score)

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** Server complete and correct: routes/api.php:153 GET /me/creator/analytics -> CreatorController::analytics:727-786, reading creator_analytics_daily (1,862 rows) plus a lifetime-earnings roll-up from bookings. App: ApiEndpoints.creatorAnalytics (:75) never referenced; no Dart model for the payload.
- **Gap:** A fully built endpoint with zero consumers. mobile/lib/features has no creator dashboard — features/dashboard/ contains only brand_dashboard_screen.dart.

### Profile completeness score shown to the creator so they know what to fix

- **Lens:** app-sync · **Effort:** small
- **Evidence:** CreatorController::myProfile:353 sets $creator['profile_score'] = CreatorService::computeProfileScore($creatorId); creators.profile_score column exists. models/creator.dart has no profileScore field (checked all 30 constructor params, lines 30-62) and GET /me/creator is never called.
- **Gap:** Stored and computed server-side, never delivered to any client.

### Verification badges are stored per creator with award date, awarding admin, expiry and history

- **Lens:** app-sync · **Effort:** large
- **Evidence:** Storage created 2026-08-04 15:43 by database/migrations/029_creator_profile_system.sql: taxonomy `verification_badge` (11 terms), tables creator_badges (lines 170-196) and creator_badge_events (lines 204-222). Verified live: both exist, both 0 rows. grep of app/ and routes/ for 'creator_badges|creator_badge_events|verification_badge' returns NOTHING.
- **Gap:** Schema only. No service, no controller, no route, no admin screen, no award/revoke/expire writer, no reader. Per the storage-plus-path rule this is PARTIAL, not BUILT — the tables are inert.

### Badge catalogue (labels, icons, rules) is admin-managed, not hardcoded

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** HARDCODED: app/Services/KycService.php:986-1006 builds the live badge list in PHP — four fixed codes with fixed labels and icons ('identity_verified'/'Identity Verified'/'verified_user', 'business_verified', 'verified_creator'|'verified_brand', 'premium_verified'). Only a global on/off is admin-controlled (Config::settingBool('kyc','badges_enabled') at KycService.php:982). The new admin-managed catalogue (verification_badge taxonomy, 11 terms) is not read by badgesFor() or by anything else.
- **Gap:** Renaming 'Identity Verified' or adding a badge still requires a code change and release. Two competing catalogues now exist (PHP literals + taxonomy) with no reconciliation.

### Dart models expose verification badges

- **Lens:** app-sync · **Effort:** medium · **corrected from MISSING**
- **Evidence:** models/creator.dart carries only `isVerified` (declared :88, parsed :142 from is_verified) and `isFeatured`. Grepped the whole mobile/lib for badge|verified|is_verified|isVerified: the only badge *model* is KycBadge (models/kyc.dart:143-152, list at :108/:126), rendered solely on the creator's own KYC screen (features/kyc/kyc_status_screen.dart:425-435). Names tried: badges, badge, verifications, credentials, trustBadges, verificationBadges on Creator/CreatorProfile.
- **Gap:** Creator and CreatorProfile have no badges field, so no badge can ever appear on a public profile, a search card, a chat header or a comparison row. The shared VerifiedBadge widget is driven by the single is_verified boolean (creator_profile_screen.dart:398-400, chat_screen.dart:232-234).
- **Why corrected:** The claim's own evidence names a working Dart badge model, then grades the requirement MISSING. mobile/lib/models/kyc.dart:142-156 defines class KycBadge {code, label, icon} with a fromJson; KycProfile.fromJson parses a list of them via Json.list(json['badges'], KycBadge.fromJson) at lib/models/kyc.dart:127; and lib/features/kyc/kyc_status_screen.dart:425-436 renders them as StatusChip widgets driven entirely by the server-supplied label. So the model, the list parser and the renderer already exist and are live — the remaining work is adding a `badges` field to Creator/CreatorProfile in lib/models/creator.dart (verified: grep for badges|profileScore|profile_score in that file returns nothing) and reusing KycBadge, not designing a badge model. Marked MISSING, this reads as a from-scratch modelling job.

### Nothing about a creator profile is hardcoded in the app — social platforms

- **Lens:** app-sync · **Effort:** small
- **Evidence:** HARDCODED in two places: mobile/lib/models/creator.dart:305-315 SocialAccount.platformLabel is an 8-way switch (instagram/youtube/facebook/twitter|x/linkedin/snapchat/pinterest/tiktok); mobile/lib/features/creator/creator_profile_screen.dart:520-527 _SocialRow._icon is a 5-way platform-to-IconData switch. Admin source EXISTS: taxonomy `platform` (9 terms) and, since migration 029, `social_platform` (16 terms carrying icon, color_hex and a metadata url template). Tax.platform (models/taxonomy.dart:189) is already wired into filter_sheet.dart:148, campaign_form_screen.dart:283 and new_booking_screen.dart:324 — but never into the social-account renderer.
- **Gap:** An operator adding Moj, Josh, ShareChat or a custom website link gets the raw slug as its label and a generic globe icon. There is no Tax key for social_platform at all (models/taxonomy.dart:187-210 lists 22 keys; social_platform and verification_badge are absent).

### Nothing hardcoded — availability status

- **Lens:** app-sync · **Effort:** small
- **Evidence:** HARDCODED: mobile/lib/models/creator.dart:7-24, enum Availability with a fromString that hardcodes busy|limited|unavailable|paused|away and a `label` getter with three English strings. Consumed at creator_profile_screen.dart:440-447 (status chip + tone) and :103 (Book Now disabled). Server column creators.availability_status is ENUM('available','busy','unavailable'); CreatorController.php:375 validates 'nullable|in:available,busy,unavailable'.
- **Gap:** No `availability_status` taxonomy exists — I listed all 24 taxonomy keys live and it is not among them (tried availability, availability_status, creator_availability, creator_status). Label, tone and the set of states are all compile-time in Dart and hardcoded again in PHP and again in the ENUM: four places to change.

### Nothing hardcoded — gender

- **Lens:** app-sync · **Effort:** small
- **Evidence:** Admin source exists and IS used for filtering: Tax.gender -> filter_sheet.dart:217, kyc_details_screen.dart:461. But HARDCODED duplicates remain: campaign_form_screen.dart:442-444 literal {'female':'Female','male':'Male','non_binary':'Non-binary'} and models/campaign.dart:109-111. Server-side the value set is hardcoded three times over: CreatorController.php:372 'in:male,female,non_binary,prefer_not_to_say', KycController.php:346 same list, and the ENUMs creators.gender and kyc_creator_details.gender.
- **Gap:** The `gender` taxonomy holds 3 terms; the PHP validators and ENUMs accept 4. Adding a term in the admin panel yields a 422 from the API and, if it got past that, a STRICT_TRANS_TABLES insert error.

### Nothing hardcoded — languages, age group, experience, response time

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** Taxonomies exist and are populated: language (10), age_group (4), experience_level (4), response_time (4). All four are consumed — but only on the brand-facing filter sheet: filter_sheet.dart:207 (language), :227 (ageGroup), :237 (experience), :247 (responseTime). One hardcoded duplicate remains: data/repositories/creator_repository.dart:168-174 _responseRank hardcodes within_1h/6h/24h/48h ordering, duplicating TaxonomyTerm.rank() (models/taxonomy.dart:62-66) which reads metadata.rank precisely so this comparison survives an admin reorder.
- **Gap:** No creator-side writer for any of them, because no creator edit screen exists. creators.languages (JSON) is only writable via PUT /me/creator, which no client calls. age_group and experience_level are filterable but have NO storage column on `creators` at all — searched creators for age_group/age_band/experience/experience_level/years_experience: absent. Those two filters can therefore never match anything real.

### Nothing hardcoded — professions / skills / niches

- **Lens:** app-sync · **Effort:** large · **corrected from MISSING**
- **Evidence:** No taxonomy: I enumerated all 24 live taxonomy keys — profession, professions, skill, skills, expertise, niche, content_niche, specialization are none of them. No column: information_schema scan of every column in zuste_marketplace for 'profession|occupation|job_title|skill|expertise|special|niche' returned zero creator-domain hits. No PHP/SQL source: grep of app/, routes/, database/ for those words returns only prose in seed content and a comment in 013_kyc_verification.sql:155. No Dart: grep of mobile/lib for profession|skill|niche returns nothing.
- **Gap:** The concept does not exist anywhere in the stack. `categories` (16 rows) + creator_categories (102 rows, max 5 per creator, CreatorController.php:434) is the only classification a creator has.
- **Why corrected:** Correct for profession and skill — I re-enumerated all 24 live taxonomy keys (age_group, booking_status, campaign_type, city, company_size, company_type, creator_sort, deliverable_type, engagement_filter, experience_level, faq_category, gender, id_proof_type, industry, language, platform, portfolio_kind, rating_filter, response_time, social_platform, support_topic, ticket_category, topup_preset, verification_badge) and re-ran the information_schema scan for profession|occupation|job_title|skill|expertise|special|niche|tag: the only hit anywhere is blogs.tags. But the NICHE axis is not absent, it is unwired. `categories` is a self-referential tree: categories.parent_id INT UNSIGNED exists, and the public API already selects and returns it — CreatorController.php:259 'SELECT cat.id, cat.parent_id, cat.name, ...' in GET /categories. A creator already writes up to 5 of them (category_ids in PUT /me/creator, CreatorController.php:429-441 into creator_categories, 102 rows live). What is missing is only the ends: all 16 category rows have parent_id NULL, Admin\Resource::categories() (app/Admin/Resource.php, fields list) exposes no parent_id field so an operator cannot create a child, and mobile/lib/models/category.dart:30-39 does not parse parent_id. Flagging this MISSING risks a parallel `niches` taxonomy plus a creator_niches join table being built next to the hierarchy that already ships.

### Nothing hardcoded — creator contact details and per-field visibility settings

- **Lens:** app-sync · **Effort:** large · **corrected from MISSING**
- **Evidence:** No storage on `creators` (I read all 40 columns: no email, phone, website, whatsapp, contact_*, or any visibility/privacy/is_public flag). information_schema scan for 'visib|privacy|website|whatsapp|contact_' across all 123 tables returns only kyc_brand_details.contact_* (brand KYC), users.website, dashboard_layouts.is_shared, saved_filters.is_shared. No route, no service, no Dart field. Names tried: visibility, is_public, show_email, show_phone, privacy_settings, profile_visibility, contact_visibility, hide_*.
- **Gap:** There is no way for a creator to publish a contact detail, and no way for anyone to hide any profile field. `creators.status` (draft/pending_review/approved/...) is all-or-nothing publication.
- **Why corrected:** The visibility half is correctly MISSING — I re-ran the information_schema scan for visib|privac|is_public|show_|hide_ across all 123 tables and found only dashboard_layouts.is_shared and saved_filters.is_shared. But the contact-link half has storage AND a live write route, so the pair is PARTIAL. Migration 029 (database/migrations/029_creator_profile_system.sql:85-90) widened creator_social_accounts.platform to VARCHAR(40) and added a `label` VARCHAR(80) column expressly so a creator can publish an arbitrary link — the file header says so ('an ENUM can never express arbitrary'). The social_platform taxonomy it seeds carries exactly the contact-link terms: verified live, its 16 values are instagram, youtube, facebook, linkedin, twitter, snapchat, pinterest, threads, tiktok, moj, josh, sharechat, website, portfolio, blog, other. The writer is routes/api.php:159 PUT /me/creator/social -> upsertSocialAccount (CreatorController.php:643-685), which persists profile_url VARCHAR(500). It is blocked today only by the hardcoded validator at CreatorController.php:648 ('required|in:instagram,...,threads'), which is already flagged separately. Calling this MISSING risks a new creator_links or creator_contacts table being built alongside the vehicle migration 029 just provisioned for it.

### Category icons and colours are admin-controlled end to end

- **Lens:** app-sync · **Effort:** small
- **Evidence:** Admin-controlled halves are wired: categories.icon and categories.color_hex are read into models/category.dart:34-35 and the colour is applied dynamically (Category.color, :41-48). But models/category.dart:52-68 maps the admin's icon NAME onto one of only 14 bundled Material icons, with `_ => Icons.category_rounded`.
- **Gap:** An admin picking any of the other ~3,000 Material Symbols names silently gets the generic fallback. Same pattern at models/menu.dart:78-96. This is an accepted Flutter constraint (icon fonts must be bundled) but the fallback is silent, so the admin gets no signal that their choice did nothing.

### Server accepts every admin-managed option value (no hardcoded validator lists)

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** HARDCODED `in:` lists that shadow the taxonomies: CreatorController.php:372 gender, :375 availability_status, :463 platform ('in:instagram,youtube,tiktok,facebook,twitter,linkedin,multi'), :464 deliverable_type, :567 media_kind, :648 platform (a DIFFERENT 9-value list). KycController.php:346 gender, :364 id_proof_type.
- **Gap:** Concrete divergences today: the `platform` taxonomy contains snapchat and pinterest, which createPackage (:463) rejects with 422 while upsertSocialAccount (:648) accepts; the new social_platform taxonomy's 16 terms (moj, josh, sharechat, website, portfolio, blog, other) are rejected by both. Every taxonomy addition is a two-file code change plus a release — the exact thing the taxonomy system was built to remove.

### Database columns do not hardcode admin-managed option sets (ENUM under STRICT_TRANS_TABLES)

- **Lens:** app-sync · **Effort:** small
- **Evidence:** Fixed by migration 029: creator_social_accounts.platform is now VARCHAR(40) (029_creator_profile_system.sql:85-90). Still ENUM: creators.gender, creators.availability_status, creators.status, kyc_creator_details.gender, kyc_creator_details.id_proof_type ENUM('aadhaar','passport','driving_license','voter_id').
- **Gap:** LIVE REPRODUCIBLE DEFECT: the `id_proof_type` taxonomy has 5 terms including `pan`; the ENUM has 4 and KycController.php:364 validates 'in:aadhaar,passport,driving_license,voter_id'. The app renders the taxonomy list verbatim at features/profile/edit_profile_screen.dart:472 and features/kyc/kyc_details_screen.dart:490, so any user selecting 'PAN card' gets a 422. Admin-editable list, non-editable consumer.

### Admin can edit a creator's profile fields

- **Lens:** app-sync · **Effort:** large · **corrected from MISSING**
- **Evidence:** routes/admin.php:121-124 is the entire creator surface: GET /creators (list), GET /creators/{id} (view), POST /creators/{id}/moderate, POST /creators/{id}/flags. moderateCreator (app/Controllers/Admin/UserController.php) writes only status/rejection_reason/approved_by/approved_at; toggleCreatorFlags writes only is_verified/verified_at, is_featured, commission_rate. app/Admin/Resource.php declares 13 CRUD resources (categories, pages, blogs, banners, faqs, testimonials, onboarding_slides, coupons, popups, redirects, feature_flags, homepage_sections, ai_prompts) — `creators` is not one of them. Names tried: creators, creator, creator_profiles in Resource.php and routes/admin.php.
- **Gap:** An operator cannot correct a creator's handle, headline, about, languages, city, price band, packages, portfolio or social links. Combined with the missing app editor, most of the creators table has no writer in production at all.
- **Why corrected:** An admin has a live, permissioned writer into the `creators` table today through a real UI. app/Views/admin/creators/show.php:223-231 and :247-253 post to /admin/creators/{id}/moderate (UserController::moderateCreator writes status, rejection_reason, approved_by, approved_at at UserController.php:477-482), and :269-295 posts to /admin/creators/{id}/flags (toggleCreatorFlags writes is_verified, verified_at, is_featured, commission_rate at UserController.php:505-526). That is 8 of the 42 creators columns editable by an operator right now, plus a full read-only detail view of every remaining field, categories, socials, packages, portfolio, bookings and reviews (UserController::creatorShow, :380-443). Two generic systems also already reach creators: Admin\Resource.php provides the declarative CRUD engine the missing fields would slot into, and ApprovalService::ENTITY_TYPES (app/Services/ApprovalService.php:34-42) already registers 'creator' => 'Creator profile' with ENTITY_SOURCES mapping it to ['creators','display_name',true] (:53), with a complete admin approval UI in WorkflowController. The gap is the identity/content columns (handle, headline, about, languages, city, price band) having no admin field — an extension of an existing screen or one new Resource entry, not a new module.

### Server creator endpoints the app never calls

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** Seven declared-but-unreferenced creator endpoints (each appears exactly once in the Dart tree — its own declaration): api_endpoints.dart:74 myCreatorProfile, :75 creatorAnalytics, :76 creatorPackages, :77 creatorPortfolioItems, :78 creatorSocial, :80 creatorPackage(id), :81 creatorPortfolioItem(id). Server counterparts: routes/api.php:151-159. Beyond creator scope, also unreferenced: sessions/session(id) (:33/:35, server api.php:83-84), devices (:56, server :127), logoutAll (:31), myReviews (:71), searchHistory (:43, server :172), kycDocumentDownload (:68), taxonomy(key) (:16, server :56), health, settings, pages, blogs/blog, testimonials, contact, reports, replyToReview, campaignRecipients, cancellationPreview.
- **Gap:** Nine of the eleven registered /me/creator routes are dead from the client's point of view. Only PUT /me/creator/availability is live.

### Gaps in the server's own creator API surface

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** routes/api.php:151-160 registers 10 creator-self routes. Missing verbs the app's own endpoint helpers imply or a real editor needs: no PUT/PATCH /me/creator/portfolio/{id} (only POST and DELETE at :157-158, yet ApiEndpoints.creatorPortfolioItem(id) at api_endpoints.dart:81 is generic); no DELETE /me/creator/social/{platform}; no endpoint to set price_min/price_max/currency (updateProfile's validator at CreatorController.php:363-378 omits them entirely, so the price band shown on every card and in every filter has no writer).
- **Gap:** creators.price_min / price_max / currency are read by CreatorService::profile and by the search filters (creator_repository.dart:265-270) but no route can write them.

### AUDIT 1/10 — Profile edit history

- **Lens:** app-sync · **Effort:** small
- **Evidence:** Storage: activity_logs (1,878 rows, actor_type/actor_id/action/subject_type/subject_id/properties/ip/user_agent). Writer for USER-level edits: app/Controllers/Api/AccountController.php:123 ActivityLogger::user($existing,'profile.updated','user',$userId,...) — 237 such rows live. ActivityLogger::diff (app/Services/ActivityLogger.php:107) exists for before/after capture.
- **Gap:** CREATOR profile edits are untracked. CreatorController::updateProfile (:359-453) makes no logging call — ActivityLogger is imported at CreatorController.php:13 and never used anywhere in the file. Same for createPackage/updatePackage/deletePackage/addPortfolioItem/deletePortfolioItem/upsertSocialAccount/setAvailability. No creator_profile_history / profile_revisions table exists (the `revisions` table, 0 rows, is CMS content revisions).

### AUDIT 2/10 — Verification history

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** KYC half BUILT: kyc_events (84 rows) with actor_type/action/from_status/to_status/note/metadata/ip, written throughout KycService and surfaced by GET /me/kyc/timeline (routes/api.php:144), consumed by the app (ApiEndpoints.kycTimeline is referenced). Creator half not: flipping creators.is_verified in UserController::toggleCreatorFlags leaves only a generic admin row ($this->log(...,'creator.flags_updated',...)); creators.verified_at is set but there is no de-verification history and no per-badge trail. creator_badge_events exists (migration 029:204-222) with 0 rows and no writer.
- **Gap:** No history for the creator-level verified flag itself, and none per badge.

### AUDIT 4/10 — Device history

- **Lens:** app-sync · **Effort:** small
- **Evidence:** Storage: user_devices (94 rows: device_id, fcm_token, platform, device_model, os_version, app_version, locale, last_active_at). Server writer: AuthService::registerDevice (app/Services/AuthService.php:1268-1298), called on register/login/firebase/otp (AuthController.php:43,68,95,152) and by POST /me/devices (routes/api.php:127).
- **Gap:** The Flutter app never supplies a device id, so no app-originated row can ever be written: registerDevice returns early when device_id is empty (AuthService.php:1270-1274); deviceContext reads header x-device-id or a device_id body field; data/repositories/auth_repository.dart login (:31-42), register (:45-74), firebase (:77-90) and otp (:92-107) bodies contain none; and ApiClient.setDeviceHeaders (mobile/lib/core/network/api_client.dart:116-119), which would set X-Device-Id, has zero callers in the entire Dart tree. ApiEndpoints.devices (:56) is also never called. The 94 rows are seed data.

### AUDIT 8/10 — Public profile visits (anonymous / web)

- **Lens:** app-sync · **Effort:** medium
- **Evidence:** Same storage and writer as profile views: GET /creators/{handle} sits in the auth.optional group (routes/api.php:91), so anonymous visits do reach CreatorService::recordView with viewer_id NULL. visit_stats (4,410 rows) additionally aggregates by device/browser/os/country/city/language/platform/source.
- **Gap:** There is no separate public/web profile surface to track — the shared URL zuste.com/creator/{handle} is not served by this backend (no such route in routes/api.php or routes/admin.php), so an outside visitor who follows a shared link produces no row at all. Only in-app visits are counted.

### A creator's own session and login history is visible in the app

- **Lens:** app-sync · **Effort:** small
- **Evidence:** Server built: routes/api.php:83 GET /auth/sessions -> AuthController::sessions (selects id, device_name, platform, ip_address, created_at, last_used_at, expires_at from refresh_tokens) and :84 DELETE /auth/sessions/{id}. App declares ApiEndpoints.sessions (:33) and session(id) (:35) and calls neither; ApiEndpoints.logoutAll (:31) likewise unused.
- **Gap:** No 'Active sessions' or 'Where you're signed in' screen exists — features/profile/menu_screen.dart has no such entry. login_history is not exposed by any API route at all.

### Cover photo upload

- **Lens:** creator-api · **Effort:** small
- **Evidence:** Storage: creators.cover_image_url. Write path is URL-only: CreatorController.php:376 'cover_image_url' => 'nullable|url|max:500', applied at CreatorController.php:410. No file branch (contrast AccountController.php:101 for avatar).
- **Gap:** There is no cover upload endpoint. PUT /me/creator is the only writer and PHP never populates $_FILES on PUT, and there is no POST /me/creator (live probe: POST /api/v1/me/creator -> 405; POST /api/v1/me/creator/cover -> 404). A creator must do two calls: POST /me/upload then PUT /me/creator with the returned URL — and the folder allowlist at AccountController.php:191 has no 'covers' entry, so the file silently lands in 'general'.

### Handle change LIMIT or cooldown enforced

- **Lens:** creator-api · **Effort:** small · **corrected from MISSING**
- **Evidence:** Searched app/, routes/, database/migrations/ for: handle_change, handle_changed_at, handle_updated_at, username_change, cooldown — zero hits except an unrelated auth.otp_resend_seconds. `creators` has no handle-history or last-changed column (full SHOW COLUMNS taken). settings has no matching key (only seo.twitter_handle).
- **Gap:** A creator can rename unlimited times, instantly. No history table, no cooldown, no rate limit beyond the generic route throttle, and no row written to the existing `redirects` table — so every previously shared /creators/{old-handle} link 404s and the freed handle can immediately be claimed by someone else (impersonation).
- **Why corrected:** The claim's central evidence — "settings has no matching key (only seo.twitter_handle)" — is false. D:/zustee.com/backend/database/seeds/031_creator_profile_settings.sql:64 seeds creator.username_change_limit='3' and :69 seeds creator.username_change_cooldown_days='30' (plus username_min_length='3' at :74). All three are LIVE in the settings table (27 rows in group_key='creator') with is_public=1, and are already served over the wire: GET /api/v1/settings (app/Controllers/Api/ContentController.php:497-518, `WHERE is_public = 1`) returns them — verified with a live probe, which came back {"username_change_limit":3,"username_change_cooldown_days":30,"username_min_length":3,...}. So the configurable policy already exists and is already reaching the app; only the enforcement half is absent (no consumer anywhere — grep for username_change across app/ and routes/ is 0 hits; no handle-history column on `creators`; CreatorController.php:386-396 checks uniqueness only). Two further things a MISSING verdict would cause to be rebuilt: creator.locked_fields (seed 031:50) and the creator_profile_locks table (database/migrations/029_creator_profile_system.sql, live, columns id/creator_id/section/locked_by/reason/locked_at) already model a per-section freeze including the handle. Note for whoever builds it: 'creator' is NOT in SettingsController::GROUPS (app/Controllers/Admin/SettingsController.php:32-48), so the keys exist but have no admin tab — add the tab, do not re-invent the keys.

### Gallery reorder / feature an item

- **Lens:** creator-api · **Effort:** small · **corrected from MISSING**
- **Evidence:** creator_portfolio.sort_order and is_featured exist and are READ in the ordering of CreatorService.php:281 and CreatorService.php:352 ('ORDER BY is_featured DESC, sort_order, id DESC'), but grep across app/Controllers/Api and app/Services/CreatorService.php finds no writer for either column.
- **Gap:** Columns with no writer. Every creator's gallery is permanently ordered by insertion id descending; the 'featured' slot is dead.
- **Why corrected:** The claim's own evidence describes storage that exists AND a read path that consumes it — creator_portfolio.sort_order and is_featured are ordered on at app/Services/CreatorService.php:281 and :352 — which is the definition of PARTIAL under the rules given, not MISSING. I confirmed the write side independently: the only statements touching creator_portfolio in app/ are the INSERT at app/Controllers/Api/CreatorController.php:597 (which sets neither column) and the soft-delete UPDATE at :636; the admin side is read-only (Admin/UserController.php:424 SELECT) and the generic registry entry at app/Controllers/Admin/ProductivityController.php:159-162 is the recycle bin (restore/purge only, no field editor). So: columns present, indexed into the ORDER BY, defaults never written. Labelling this MISSING risks a duplicate migration; the work is one PUT/PATCH handler.

### Media-kit upload

- **Lens:** creator-api · **Effort:** medium
- **Evidence:** Only as a KYC vault slot: kyc_requirements row id=20 (kind=creator, slot='media_kit', document_type='media_kit', is_required=0, pdf, 20MB) seeded at database/seeds/006_kyc_defaults.sql:46; uploaded via POST /me/kyc/documents (routes/api.php:138, KycController.php:61-105).
- **Gap:** There is no creator-profile media kit. Live probe: POST /api/v1/me/creator/media-kit -> 404. The KYC copy is an encrypted private compliance document — it is never joined into CreatorService::profile(), never exposed on the public creator payload, and no brand can see or fetch it.

### Media-kit download

- **Lens:** creator-api · **Effort:** medium
- **Evidence:** GET /me/kyc/documents/{id}/download — routes/api.php:141; KycController.php:124-163, guarded by a DocumentVault signed token bound to document_id + viewer.
- **Gap:** Owner-only, by design (KycController.php:139-143 rejects any viewer that is not the uploader). No brand-facing or public media-kit download exists anywhere.

### Working hours

- **Lens:** creator-api · **Effort:** small
- **Evidence:** creator_availability.slots (JSON) and .note exist and are READ by the public endpoint GET /creators/{id}/availability — CreatorController.php:163 selects `slots`, CreatorController.php:171 json_decodes it. The only writer, PUT /me/creator/availability, validates just three keys — CreatorController.php:692-696: dates, is_available, max_bookings — and its INSERT ... ON DUPLICATE KEY at CreatorController.php:706-719 never touches slots or note.
- **Gap:** Time slots are a column with no writer: the availability API always returns slots: []. There is also no weekly recurring working-hours model at all (searched working_hours, business_hours, weekly_hours, office_hours, availability_hours — 0 hits); availability is per-calendar-date only, capped at a hardcoded 90 dates per call (CreatorController.php:698).

### Custom services

- **Lens:** creator-api · **Effort:** medium · **corrected from MISSING**
- **Evidence:** creator_packages covers fixed packages and is fully CRUD-able (POST/PUT/DELETE /me/creator/packages, routes/api.php:154-156; CreatorController.php:456-559), but its shape is a closed enum: platform and deliverable_type are hardcoded 'in:' lists at CreatorController.php:463-464 and :507-508. Names searched for anything freeform: custom_service, services, add_ons, addons — 0 hits. Live probe: POST /api/v1/me/creator/services -> 404.
- **Gap:** A creator cannot offer a service outside the 7 platforms x 10 deliverable types baked into the validation rules, and those two enums are PHP literals, not taxonomy-driven (a `deliverable_type` and `platform` taxonomy DO exist and are served to the app, so the API and the option list can drift).
- **Why corrected:** The claim's own evidence concedes creator_packages is "fully CRUD-able" (POST/PUT/DELETE at routes/api.php:154-156, handlers at CreatorController.php:456-559) and I confirmed the live columns: title, description, quantity, price, currency, turnaround_days, revisions, includes (json), is_active, sort_order — every one of them freeform. A creator-defined service therefore already has storage and a complete read/write path; exactly two attributes are closed, platform and deliverable_type, and they are closed at both layers (DB ENUMs on creator_packages, plus the PHP literal `in:` rules at CreatorController.php:463-464 and :507-508). That is a constrained implementation, not an absent one. Calling it MISSING invites a second, parallel services table alongside creator_packages; the actual work is an ENUM→VARCHAR migration plus swapping the two literal lists for the taxonomies that already exist and are already served to the app (deliverable_type, 10 live terms; social_platform, 16 live terms seeded at database/migrations/029_creator_profile_system.sql:45-72). The HARDCODED flag the claim raises is correct and is the whole of the gap.

### Social accounts — verify

- **Lens:** creator-api · **Effort:** medium · **corrected from MISSING**
- **Evidence:** creator_social_accounts.is_verified exists and is READ onto the public profile at CreatorService.php:253, but no code writes it — grep of `creator_social_accounts` across app/ shows the sole INSERT (CreatorController.php:656-664) does not set it and there is no UPDATE elsewhere. No admin route touches it either (routes/admin.php:121-124 offers only creator moderate/flags, which act on `creators`).
- **Gap:** Column with no writer. Every social account is permanently unverified; there is no OAuth/handshake flow and no admin override.
- **Why corrected:** By the audit's own stated rule ("a column with no writer is PARTIAL"), this is PARTIAL, and the claim's own evidence establishes it: creator_social_accounts.is_verified exists and is READ onto the public payload at app/Services/CreatorService.php:253. More is already in place than the claim found: the verification_badge taxonomy is live (11 terms) and its 'social_verified' term declares metadata {"auto":true,"source":"creator_social_accounts.is_verified","expires_days":null} — database/migrations/029_creator_profile_system.sql:151 — and the award/history tables creator_badges and creator_badge_events (same migration, live, with uq_creator_badge and a full awarded_by/revoked_by/expires_at audit shape) exist to record the outcome. Setting creator.require_verified_social (database/seeds/031_creator_profile_settings.sql:120, live, value '0') is the policy toggle. What is genuinely absent is any writer: no UPDATE of is_verified anywhere in app/, no OAuth handshake, no admin route, and no code at all references creator_badges. Build the writer, not the schema.

### Social accounts — REORDER

- **Lens:** creator-api · **Effort:** small · **corrected from MISSING**
- **Evidence:** creator_social_accounts has no sort_order column at all (live SHOW COLUMNS: id, creator_id, platform, handle, profile_url, followers, following, posts, avg_likes, avg_comments, avg_views, engagement_rate, audience_stats, is_verified, is_primary, last_synced_at, created_at, updated_at). Ordering is hardcoded 'ORDER BY followers DESC' at CreatorService.php:254 and CreatorController.php:680. Live probe: POST /api/v1/me/creator/social/reorder -> 404.
- **Gap:** No storage and no endpoint. The is_primary column that does exist has no writer either (the only is_primary writes in the codebase are for bank_accounts, WalletController.php:468-485).
- **Why corrected:** The claim's SHOW COLUMNS is stale and its conclusion "creator_social_accounts has no sort_order column at all" is false. database/migrations/029_creator_profile_system.sql:85-90 runs ALTER TABLE creator_social_accounts ... ADD COLUMN `sort_order` SMALLINT NOT NULL DEFAULT 0 AFTER `is_primary` (and ADD COLUMN `label` VARCHAR(80) for custom-link names); :96-99 adds KEY idx_csa_order (creator_id, sort_order); :105-112 backfills sort_order from is_primary/followers rank so existing rows are not all 0. I re-ran SHOW COLUMNS live: the columns are id, creator_id, platform, label, handle, profile_url, followers, following, posts, avg_likes, avg_comments, avg_views, engagement_rate, audience_stats, is_verified, is_primary, sort_order, last_synced_at, created_at, updated_at. Storage plus its index and backfill are done; what is missing is a writer and a reader — ordering is still hardcoded ORDER BY `followers` DESC at CreatorService.php:254, CreatorController.php:680 and Admin/UserController.php:416. A MISSING verdict here would cause a duplicate migration against a column that already exists.

### Contact verification — verify email via OTP

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** Endpoints exist: POST /auth/otp/send and POST /auth/otp/verify (routes/api.php:73-74), both accepting purpose 'verify_email' (AuthController.php:254, :285). The flag write is AuthController.php:294-303.
- **Gap:** BROKEN END TO END. Neither route carries `auth` or `auth.optional` (the group at routes/api.php:66 is ['maintenance'] only), so Request::userId() returns 0 (Request.php:487-490 reads the 'user' attribute that only AuthMiddleware.php:78 sets). AuthService::sendOtp therefore stores user_id = NULL (AuthService.php:915-923), and AuthController.php:295 computes $userId = 0 ?: (int) NULL = 0, so the `if ($userId > 0)` guard at :297 always fails and users.email_verified_at is NEVER written. Live DB corroborates: every row in otp_verifications has user_id NULL.

### Contact verification — verify mobile via OTP

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** Same pair of routes with purpose 'verify_phone' (AuthController.php:254, :285); intended writer AuthController.php:298-302 setting users.phone_verified_at.
- **Gap:** Same defect as verify_email — the user id is never resolved, so phone_verified_at is never set. This also silently disables the `verified` middleware that gates /wallet/topup and /wallet/withdraw (routes/api.php:254-256) for anyone who verified by OTP rather than by Firebase sign-in.

### Features unlocked at configurable completion thresholds

- **Lens:** creator-api · **Effort:** medium · **corrected from MISSING**
- **Evidence:** creators.profile_score is read in exactly three places, all display-only: CreatorController.php:353, CreatorController.php:782, app/Views/admin/creators/index.php:145. No middleware reads it — app/Middleware/ contains AuthMiddleware, KycMiddleware, CronMiddleware, CsrfMiddleware, MaintenanceMiddleware, ThrottleMiddleware, and KycMiddleware gates on kyc profile status only (KycMiddleware.php:39-59). feature_flags (5 live rows) gate on is_enabled/rollout_percent/audience/min_app_version/platforms — no score field. The only completeness threshold in settings is kyc.auto_approve_min_completeness=100, consumed at app/Services/KycService.php:530, which is KYC document completeness driving auto-approval — not creator profile score and not a feature unlock. AutomationService.php:115 exposes profile_score as a readable field on the creator.submitted event, but its settable list is ['search_boost'] only.
- **Gap:** Nothing anywhere gates a platform feature on completion percentage, and there is no configurable threshold to gate on.
- **Why corrected:** The claim states "there is no configurable threshold to gate on". There is. database/seeds/031_creator_profile_settings.sql:35-40 seeds creator.feature_unlock_thresholds as json = {"accept_bookings":60,"appear_in_search":70,"apply_to_campaigns":75,"featured_eligible":90}, is_public=1. It is live in the settings table and a live GET /api/v1/settings returned it decoded as a JSON object, alongside creator.required_completion_percent=60 (seed 031:25-29) and creator.completion_weights (seed 031:31-33). The gating middleware is genuinely missing — that part of the claim holds — but the configurable threshold set is built and already published to clients. Also worth flagging as HARDCODED: CreatorService::computeProfileScore (app/Services/CreatorService.php:577-616) uses PHP literal weights (10/10/15/5/10/15/20/15) that do not match creator.completion_weights ({"basic":15,"contact":10,"social":15,"portfolio":15,"kyc":15,"bio":5,"gallery":5,"pricing":10,"categories":5,"verification":5}) — the score the thresholds would gate on is computed from a second, divergent, uneditable table.

### Bio (short) — distinct from About

- **Lens:** schema · **Effort:** small
- **Evidence:** Three unsynchronised stores, none designated as the creator bio: users.bio VARCHAR(1000) (written by AccountController.php:54 + the PUT /me update loop), creators.about TEXT (written by CreatorController.php:402), kyc_creator_details.bio TEXT (KYC-only). creators has NO `bio` column — confirmed by SHOW FULL COLUMNS FROM creators.
- **Gap:** PUT /creator never touches users.bio and CreatorService::profile (CreatorService.php:223) never selects it, so a creator who fills the account bio sees it nowhere on the creator profile. If bio and about are meant to be two fields, creators needs a second short column; if they are one field, one of the three stores must be retired.

### Gallery

- **Lens:** schema · **Effort:** small
- **Evidence:** creator_portfolio table — 003_categories_and_creators.sql:166-196; media_kind ENUM('post','reel','photo','video') at 003:169. Write path POST /creator/portfolio at CreatorController.php:560-618, delete at CreatorController.php:621-638, read CreatorService::portfolio (CreatorService.php:334) and portfolioCounts (CreatorService.php:370).
- **Gap:** Three columns have NO writer: sort_order (read in the ORDER BY at CreatorService.php:283 but no reorder route exists — routes/api.php:157-158 has only POST and DELETE), is_featured (read in the same ORDER BY, never set), and views_count/likes_count/comments_count (read at CreatorService.php:278, never populated — no sync job touches them). Creators cannot reorder or pin gallery items, and engagement numbers render as 0.

### SUB-categories

- **Lens:** schema · **Effort:** medium
- **Evidence:** categories.parent_id INT UNSIGNED exists (SHOW COLUMNS FROM categories) so a sub-category is structurally representable, and GET /categories returns parent_id at CreatorController.php:262.
- **Gap:** Live DB: 16 categories, ZERO with parent_id set — the tree is flat in practice. creator_categories has only (creator_id, category_id) with no is_primary, no level and no ordering, so a creator's parent category and sub-category are indistinguishable once linked. CreatorController.php:431-441 accepts any mix of ids without checking depth, and the admin creator screen (app/Views/admin/creators/show.php) shows one undifferentiated list. There is no way to say 'Fashion > Streetwear' as a pair.

### Working hours

- **Lens:** schema · **Effort:** medium
- **Evidence:** creator_availability.slots LONGTEXT (JSON) — 003_categories_and_creators.sql:201 area — is READ and json_decoded at app/Controllers/Api/CreatorController.php:171, but NO application code writes it: the upsert at CreatorController.php:703-717 sets only creator_id, available_on, is_available, max_bookings. The 855 populated rows in the live DB came from D:/zustee.com/backend/database/seeds/demo/021_demo_creator_depth.sql, not from the app.
- **Gap:** Per-date time slots are a read-only column with no writer. Separately there is NO recurring weekly working-hours store at all — searched %working_hour%, %business_hour%, %opening%, %schedule%, %timetable%, %weekday% across information_schema; SHOW TABLES has no creator_working_hours or creator_schedule. Timezone is only on users.timezone, not per creator.

### SOCIAL PLATFORMS — the spec names instagram, youtube, facebook, linkedin, x/twitter, snapchat, pinterest, threads, tiktok, moj, josh, sharechat, website, portfolio link, blog and custom links

- **Lens:** schema · **Effort:** medium
- **Evidence:** creator_social_accounts.platform is ENUM('instagram','youtube','tiktok','facebook','twitter','linkedin','snapchat','pinterest','threads') — exactly nine members — D:/zustee.com/backend/database/migrations/003_categories_and_creators.sql:114, confirmed live by SHOW FULL COLUMNS. Write path PUT /creator/social at app/Controllers/Api/CreatorController.php:641-680 (INSERT ... ON DUPLICATE KEY UPDATE).
- **Gap:** CANNOT STORE 7 of the 16 spec platforms: moj, josh, sharechat, website, portfolio link, blog, and custom links. Under STRICT_TRANS_TABLES each is error 1265 on insert, not a silent coercion. The API validator at CreatorController.php:648 re-hardcodes the same nine values instead of reading taxonomy key='platform' (taxonomies id 2), whose own term list is also wrong for this purpose (9 terms + 'multi', missing threads, moj, josh, sharechat, website, blog). Compounding it: unique key uq_csa_creator_platform (creator_id, platform) allows exactly ONE row per platform, so multiple custom links or two YouTube channels are impossible even if the ENUM were widened. There is also NO DELETE route — routes/api.php:159 exposes only PUT /creator/social, so a linked account can never be unlinked.

### SOCIAL PLATFORMS — sort / reorder column

- **Lens:** schema · **Effort:** small · **corrected from MISSING**
- **Evidence:** SHOW FULL COLUMNS FROM creator_social_accounts returns 18 columns and NONE is a sort key: no sort_order, no display_order, no position, no rank, no priority. SHOW INDEX returns only PRIMARY, uq_csa_creator_platform and idx_csa_followers(platform, followers). Ordering is hardcoded to `ORDER BY followers DESC` at app/Services/CreatorService.php:254 and again at app/Controllers/Api/CreatorController.php:673.
- **Gap:** A creator cannot choose which platform leads their profile. Contrast creator_portfolio and creator_packages, which both DO have sort_order (003:184, 003:158) — the omission here is inconsistent. creator_social_accounts.is_primary TINYINT exists but has NO writer (CreatorController.php:653-663 writes only handle, profile_url, followers, engagement_rate, last_synced_at), so even the crude 'pin one' fallback is dead.
- **Why corrected:** WRONG — the column exists and is LIVE. `creator_social_accounts`.`sort_order` SMALLINT NOT NULL DEFAULT 0 was added by D:/zustee.com/backend/database/migrations/029_creator_profile_system.sql:90, with a supporting index idx_csa_order (creator_id, sort_order) at 029:99. Both are confirmed live: SHOW FULL COLUMNS FROM creator_social_accounts now returns 20 columns including `label` varchar(80) and `sort_order` smallint(6) (the claim's '18 columns and NONE is a sort key' is stale), and SHOW INDEX returns PRIMARY, uq_csa_creator_account, idx_csa_followers and idx_csa_order. Existing rows were even back-filled from follower rank by the UPDATE at 029:103-111 so the first render is sensible. PARTIAL, not BUILT, because the claim's other half is correct and unchanged: nothing writes it (CreatorController::upsertSocialAccount at app/Controllers/Api/CreatorController.php:655-672 sets only handle, profile_url, followers, engagement_rate, last_synced_at) and nothing reads it — ordering is still hardcoded `ORDER BY followers DESC` at app/Services/CreatorService.php:254 and CreatorController.php:679. is_primary likewise still has no writer. The work is a reorder route plus two ORDER BY changes, NOT a migration.

### VERIFICATION BADGES — eleven distinct badges with per-badge storage

- **Lens:** schema · **Effort:** large
- **Evidence:** There is NO badge table. Searched SHOW TABLES for badges, creator_badges, user_badges, verification_badges, badge_types, badge_awards, creator_verifications — none of the 118 tables match. What exists is scattered booleans: creators.is_verified + creators.verified_at (003:69-70, written by app/Controllers/Admin/UserController.php:504-508), creators.is_featured (003:71, UserController.php:511-514), users.is_identity_verified / is_business_verified / is_premium_verified, users.email_verified_at, users.phone_verified_at, users.kyc_status + kyc_expires_on, creator_social_accounts.is_verified (003:125). The badge LIST is assembled in PHP by KycService::badgesFor at app/Services/KycService.php:980-1007.
- **Gap:** KycService::badgesFor is HARDCODED — a literal PHP array of exactly four codes with baked-in labels and Material icon names (identity_verified/'Identity Verified'/'verified_user', business_verified, verified_brand|verified_creator, premium_verified), gated only by the boolean setting kyc.badges_enabled. Of the eleven the spec names, three have NO storage anywhere: 'top rated' and 'trusted' (searched %top_rated%, %trusted%, %trust_score% — zero hits) and per-badge 'social account verified' has a column (creator_social_accounts.is_verified) with NO WRITER. Nothing carries per-badge verified_by, per-badge expiry (only users.kyc_expires_on, KYC-wide), or per-badge status. creators.featured_until DATETIME (003:72) has NO writer either — grep across app/ finds zero assignments, the 11 populated rows come from database/seeds/demo/020_demo_users_creators.sql — so a time-boxed Featured badge cannot be set.

### VERIFICATION BADGES — award/revoke history

- **Lens:** schema · **Effort:** medium · **corrected from MISSING**
- **Evidence:** Searched SHOW TABLES for badge_history, badge_events, verification_history, creator_verification_log — absent. The only audit trail near this is kyc_events (profile_id, actor_type, actor_id, action, from_status, to_status, note, metadata, ip_address) which is keyed to a kyc_profiles row and records KYC STATUS transitions only — it has no badge column and never fires for creators.is_verified or is_featured. Toggling those two writes only a generic activity_logs row via UserController.php:551 ('creator.flags_updated').
- **Gap:** No way to answer 'who granted this badge, when, why, and when was it revoked'. creators.verified_at holds a single timestamp that is nulled on un-verify (UserController.php:507), destroying the prior value. No expiry sweep exists for any badge.
- **Why corrected:** WRONG — the storage exists and is LIVE. `creator_badge_events` was created by D:/zustee.com/backend/database/migrations/029_creator_profile_system.sql:204-222 and is present in the live DB (verified: SELECT TABLE_NAME FROM information_schema.TABLES returns it among 126 tables; 0 rows). Its columns are creator_id, badge, actor_type ENUM('creator','admin','system'), actor_id, actor_name, action, from_status, to_status, note, metadata, ip_address, created_at (029:206-217) — deliberately the same shape as kyc_events, per the comment at 029:200-201. Revocation state also lives on `creator_badges` itself: revoked_at, revoked_by, revoke_reason (029:183-185) alongside awarded_at, awarded_by, awarded_note (029:177-180) and idx_cb_expiry(status, expires_at) for the expiry sweep (029:194). The migration is recorded as applied — migrations row id=29, '029_creator_profile_system.sql', batch 21, 2026-08-04 15:43:54. It is PARTIAL, not MISSING, because NO application code touches either table: grep -rn 'creator_badges|creator_badge_events' over app/ and routes/ returns zero hits. The work needed is a service plus routes, NOT a migration — writing one would collide with an applied migration.

### PROFILE SECTION LOCKS — admin locks username, social links, pricing, categories, bio, portfolio, contact, verification independently

- **Lens:** schema · **Effort:** large · **corrected from MISSING**
- **Evidence:** Ran a full information_schema.COLUMNS scan for %lock% across every table in zuste_marketplace. Exactly three hits, none relevant: admins.locked_until, conversations.is_locked, wallets.locked_balance. Also searched %frozen%, %readonly%, %editable%, %restricted% and SHOW TABLES for creator_section_locks, profile_locks, creator_field_locks, field_permissions — absent. creators has no is_locked, locked_sections or locked_fields column (SHOW FULL COLUMNS FROM creators, 43 columns).
- **Gap:** Nothing per-section and nothing per-field. The only lever an admin has is the whole-record creators.status ENUM (003:73) — setting 'suspended' hides the entire profile rather than freezing one section. app/Controllers/Api/CreatorController.php::updateProfile (CreatorController.php:359-455) performs no lock check of any kind before writing.
- **Why corrected:** WRONG — the table exists and is LIVE. `creator_profile_locks` is created at D:/zustee.com/backend/database/migrations/029_creator_profile_system.sql:229-244 and is present in the live schema (0 rows). Columns: id, creator_id, section VARCHAR(40), locked_by, reason VARCHAR(500), locked_at, with UNIQUE KEY uq_creator_section_lock (creator_id, section) at 029:242 and FK to creators ON DELETE CASCADE. The design is documented at 029:232-241: `section` is free text so the lockable vocabulary grows without a migration, and the presence of the row IS the lock (unlocking deletes), which is why the claim's %lock% column scan for is_locked/locked_sections on `creators` found nothing — it was looking for the wrong shape. The claim's own scan even returned creator_profile_locks.locked_by and .locked_at and dismissed them; my rerun of the same %lock% scan returns 5 hits including both. A global complement also exists: settings row creator.locked_fields ('Globally non-editable fields', is_public=1) from database/seeds/031_creator_profile_settings.sql. PARTIAL not BUILT because nothing reads it — CreatorController::updateProfile still performs no lock check, and grep for 'creator_profile_locks' across app/ and routes/ returns zero hits.

### APPROVAL OF CHANGES — admin approves/rejects creator edits; is 'creator' an approval entity type?

- **Lens:** schema · **Effort:** large
- **Evidence:** YES, 'creator' IS a registered approval entity: approval_workflows.entity_type ENUM includes 'creator', and workflow row id=3 'Creator approval' (is_active=1, 'New creator profiles are reviewed before going live') is seeded and live. ApprovalService::ENTITY_TYPES has 'creator' => 'Creator profile' (app/Services/ApprovalService.php:35), ENTITY_SOURCES maps it to ['creators','display_name',true] (ApprovalService.php:53), entityUrl points at /admin/creators/{id} (ApprovalService.php:677), and the apply branch at ApprovalService.php:793-806 flips creators.status pending_review|draft → approved/rejected with approved_by/approved_at.
- **Gap:** It approves the WHOLE RECORD'S STATUS, not a diff. There is NO pending-changes / revision / staging table for creator profiles — searched SHOW TABLES for creator_pending_changes, creator_revisions, creator_drafts, profile_changes; and RevisionService::ENTITIES (app/Services/RevisionService.php:44-93) lists 12 CMS entities (pages, blogs, categories, banners, faqs, testimonials, coupons, popups, redirects, flags, sections, ai-prompts) and does NOT include creators — the `revisions` table has 0 rows overall. Nothing opens a creator request automatically: ApprovalService::open() is called from exactly one place, app/Controllers/Admin/FinanceController.php:421 (withdrawals); the only creator path is the manual admin form WorkflowController::raise (app/Controllers/Admin/WorkflowController.php:379-420). Live DB: approval_requests has 0 rows with entity_type='creator'. Worst of all, the live edit path bypasses it entirely — CreatorController.php:419-441 writes straight to `creators`, and the $needsReview flag at CreatorController.php:419-423 only sets pending_review when the profile was ALREADY draft, so an approved creator can rewrite display_name, about, headline, handle, pricing and categories and it goes public with zero review.

### DYNAMIC SETTINGS — mandatory fields, required verification types, editable/non-editable fields, username change limit, required completion percentage, badge rules, featured rules

- **Lens:** schema · **Effort:** large · **corrected from MISSING**
- **Evidence:** The `settings` table has 20 group_key values: ai, app, auth, booking, branding, cache, commission, discovery, firebase, general, kyc, mail, payment, referral, security, seo, sms, social, upload, wallet. There is NO 'creator' group and no 'profile' group. A full LIKE scan of setting_key for %creator%, %profile%, %badge%, %verif%, %username%, %handle%, %featured%, %mandatory%, %required% returned only seven unrelated rows: app.creator_signup_enabled, commission.creator_commission_percent, kyc.badges_enabled, kyc.brand_mandatory, kyc.creator_mandatory, mail.username, seo.twitter_handle.
- **Gap:** Every one of the seven demanded knobs is absent, and the behaviour they should control is HARDCODED instead: the completion score weights are baked into app/Services/CreatorService.php:586-611 (display_name 10, headline 10, about>=80 chars 15, city 5, category 10, socials min(15, n*8), packages min(20, n*10), portfolio min(15, n*3)); no minimum-completion gate is enforced anywhere before a profile goes live; the username change limit has no counter (CreatorController.php:387-393 checks uniqueness only); there is no editable/non-editable field registry; badge rules live in the PHP literal at KycService.php:980-1007; featured rules do not exist (is_featured is a manual admin toggle at UserController.php:511-514 and featured_until is never written).
- **Why corrected:** WRONG on the central factual claim. The live `settings` table HAS a 'creator' group — 21 groups, not 20 — holding 27 rows seeded by D:/zustee.com/backend/database/seeds/031_creator_profile_settings.sql. All seven demanded knobs are present and populated: required completion percentage = creator.required_completion_percent (60) plus creator.completion_weights (JSON, 10 sections) and creator.feature_unlock_thresholds (JSON: accept_bookings 60, appear_in_search 70, apply_to_campaigns 75, featured_eligible 90); mandatory fields = creator.mandatory_fields ('display_name,handle,headline,about,primary_category_id,city,languages'); required verification types = creator.required_verifications ('email_verified,mobile_verified'); editable/non-editable = creator.locked_fields; username change limit = creator.username_change_limit (3) plus username_change_cooldown_days (30) and username_min_length (3); badge rules = creator.top_rated_min_rating (4.50), top_rated_min_reviews (10), trusted_min_collabs (25); featured rules = creator.featured_max_slots (12), featured_duration_days (30). Also present: approve_profile_edits, reviewed_sections, min/max_social_accounts, require_verified_social, min_portfolio_items, require_portfolio, moderate_gallery_uploads, allow_email_change, allow_mobile_change, otp_verification_enabled, allow_alternate_contacts, default_profile_visibility. The claim's LIKE scan missed them because it searched setting_key for %creator%/%profile% — these keys are scoped by group_key='creator' and named without the prefix. There is even a partial read path: ContentController.php:497-500 serves every is_public=1 row generically via GET /settings, so the 12 public creator.* rows already reach the app. PARTIAL not MISSING because (a) no server code consumes them — grep for setting*('creator' across app/ returns zero hits — and (b) 'creator' is absent from the hardcoded tab list SettingsController::GROUPS (app/Controllers/Admin/SettingsController.php:32-48), so an admin cannot edit them in the UI. The claim's HARDCODED findings still stand and are the real work: completion weights baked in at app/Services/CreatorService.php:586-608 and badges at app/Services/KycService.php:980-1007. Needed: wire the readers and add one GROUPS line — NOT a new seed.

### CONTACT — otp_verifications supports the change-email / change-mobile re-verification flow

- **Lens:** schema · **Effort:** medium
- **Evidence:** otp_verifications.purpose is ENUM('register','login','verify_phone','verify_email','reset_password','withdrawal','delete_account') — confirmed live by SHOW FULL COLUMNS. The generic engine exists: AuthService::sendOtp (app/Services/AuthService.php:887-955) and AuthService::verifyOtp (AuthService.php:957+), reachable via the routes whose validator is at app/Controllers/Api/AuthController.php:254 and :285, with the verify branch stamping users.email_verified_at / phone_verified_at at AuthController.php:294-302.
- **Gap:** There is NO 'change_email' or 'change_mobile' purpose, and — more seriously — no pending-value column to stage an unverified address. PUT /me applies the change FIRST and only then invalidates the flag: app/Controllers/Api/AccountController.php:77-80 writes users.email and sets email_verified_at = NULL; AccountController.php:82-98 does the same for phone. So the account's email of record changes before any code is sent, nothing forces the follow-up OTP, and a typo silently orphans the account. The purpose list is also HARDCODED twice as `in:register,login,verify_phone,verify_email,reset_password,delete_account` at AuthController.php:254 and :285 — both omit 'withdrawal', which the ENUM allows.

## BUILT (34)

### Admin can approve / reject a creator

- **Lens:** admin · **Effort:** trivial
- **Evidence:** UserController::moderateCreator at D:/zustee.com/backend/app/Controllers/Admin/UserController.php:445-496; route routes/admin.php:123 guarded by permission:creators.approve; UI buttons at app/Views/admin/creators/show.php:222-238. Writes creators.status/rejection_reason/approved_by/approved_at (UserController.php:477-482), sends NotificationService creator.approved|creator.rejected (486-491), audit-logs (493). Live DB confirms 3 creator.approve and 1 creator.reject rows in activity_logs.

### Admin can feature a creator

- **Lens:** admin · **Effort:** trivial
- **Evidence:** app/Controllers/Admin/UserController.php:512-515 writes creators.is_featured, second-gated by $this->authorize($request,'creators.feature') at line 513; UI at app/Views/admin/creators/show.php:278-284 behind $this->can('creators.feature'); list filter at UserController.php:331-333. Automation rules can also set it (app/Services/AutomationService.php:133 'settable' => ['is_featured','search_boost'], applied at AutomationService.php:828-832).
- **Gap:** The `creators`.`featured_until` column has ZERO references in the entire app/ tree (grep returned nothing) — featuring has no expiry, so it is permanent until manually unset (and per the finding above, unchecking does not unset it).

### Admin can suspend a creator

- **Lens:** admin · **Effort:** trivial
- **Evidence:** action 'suspend' in the validator at app/Controllers/Admin/UserController.php:451, mapped to status 'suspended' at line 473, reason mandatory at 466-468; UI button at app/Views/admin/creators/show.php:240-244 calling the moderate() prompt at show.php:329-342. Live DB has 1 creator.suspend activity_logs row.

### Admin can review a creator's uploaded documents

- **Lens:** admin · **Effort:** trivial
- **Evidence:** The KYC module (given as existing) owns this: routes/admin.php:129-139 — /kyc, /kyc/creators (:131), /kyc/documents/{id} download (:135, permission kyc.download), /kyc/{id} (:137), /kyc/{id}/review (:138, permission kyc.review). Views at app/Views/admin/kyc/{index,show,requirements,legacy}.php. All four kyc.* slugs exist and are available in the live permissions table.
- **Gap:** Discoverability only: app/Views/admin/creators/show.php has zero occurrences of 'kyc' (grep confirmed) even though UserController.php:388 already selects u.kyc_status. An operator reviewing a creator cannot jump to that creator's documents from the profile.

### Availability: creator opens/blocks booking dates from the app

- **Lens:** app-sync · **Effort:** trivial
- **Evidence:** features/calendar/availability_calendar_screen.dart -> providers/calendar_provider.dart:75 -> data/repositories/creator_repository.dart:146-164 setAvailability -> PUT /me/creator/availability (routes/api.php:160) -> CreatorController::setAvailability:688-719 writes creator_availability (1,146 rows). Entry point: features/profile/menu_screen.dart:163-168 'My availability', gated on user.isCreator.

### Endpoints the app calls that the server does not register

- **Lens:** app-sync · **Effort:** trivial
- **Evidence:** Cross-checked every creator-related member of mobile/lib/core/network/api_endpoints.dart against routes/api.php. All six the app actually calls resolve: creators -> api.php:96; creator(handleOrId) -> :97; creatorPortfolio(id) -> :98; creatorReviews(id) -> :99; creatorAvailability(id) -> :100; toggleFavorite/toggleFollow -> :170-171; creatorSetAvailability -> :160; favorites -> :146. Zero orphan calls.

### AUDIT 3/10 — Login history

- **Lens:** app-sync · **Effort:** trivial
- **Evidence:** login_history (513 rows) with outcome, auth_method, ip, user_agent, country, device_type, is_new_device, failure_reason. Writer: app/Services/SecurityService.php:66 Database::insert('login_history', ...). Readers: Admin > Security (app/Controllers/Admin/SecurityController.php:39-124, incl. CSV export) and the login-methods dashboard (LoginMethodController.php:550,575).
- **Gap:** Admin-only. No API route exposes a user's own login history, so a creator cannot see it in the app.

### AUDIT 5/10 — Profile views

- **Lens:** app-sync · **Effort:** trivial
- **Evidence:** creator_profile_views (481 rows: creator_id, viewer_id, source ENUM, viewed_on, created_at). Writer: app/Controllers/Api/CreatorController.php:96 CreatorController::show -> CreatorService::recordView (app/Services/CreatorService.php:471-500, with a same-day dedupe at :484). Aggregated nightly into creator_analytics_daily by CronRunner.php:188,210 and read back by GET /me/creator/analytics.
- **Gap:** Caveat: the `source` dimension is dead from the app. recordView takes $request->string('source','direct') but creator_repository.dart:69 calls GET /creators/{handle} with no query string, so every real app view records 'direct'. The varied source distribution in the table (search 134 / category 83 / home 68 / share 24 / explore 38) is seeded.

### AUDIT 10/10 — Admin actions

- **Lens:** app-sync · **Effort:** trivial
- **Evidence:** activity_logs with actor_type='admin' — 1,878 rows total, admin actions dominating (admin login 437, settings.updated 121, kyc.exported 53, kyc.viewed 44, wallet.reconciled 31, plus creator.approve / creator.flags_updated). Writer: ActivityLogger::admin (app/Services/ActivityLogger.php:59) via BaseAdminController::log, e.g. UserController::moderateCreator ('creator.'.$action) and toggleCreatorFlags ('creator.flags_updated', with the changed fields in properties). Readers: AuthController.php:552, UserController.php:156, AccessController.php:468 (distinct action list), WidgetService.php:962. Retention: CronRunner.php:315 prunes at 365 days. Secrets scrubbed at ActivityLogger::scrub (:202).

### PUT /creator — the exact validated field allowlist (the gate every profile section must pass through)

- **Lens:** creator-api · **Effort:** small
- **Evidence:** app/Controllers/Api/CreatorController.php:363-378 — exactly 14 keys: display_name, handle, headline, about, primary_category_id, city, state, country, gender, date_of_birth, languages, availability_status, cover_image_url, category_ids. Route: routes/api.php:152 (PUT /me/creator, middleware auth+no-guest+creator). Writer: CreatorController.php:398-443.
- **Gap:** Anything not in those 14 keys cannot be written by a creator, no matter how many columns exist. Two live columns are read but have NO writer anywhere in app/: creators.response_time_hours (read as the 'responds within N hours' search facet, CreatorService.php:142; schema default 48 — so every creator is permanently 48h) and creators.meta_title/meta_description. Also CreatorController.php:398-411 uses array_filter(!== null), so no field can be reset to NULL. And CreatorController.php:419-424 computes $needsReview from substantive edits but only ever writes status='pending_review' when the row was 'draft' — an approved creator can rewrite display_name/about/headline with no re-review, contradicting the comment above it.

### Profile photo upload

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** POST /me with multipart field `avatar` — routes/api.php:125; app/Controllers/Api/AccountController.php:101-103 (UploadService::store(..., 'avatars', 'image')) writing users.avatar_url. URL branch validated at AccountController.php:104-119. Read into the creator payload via the users join, CreatorService.php:223.
- **Gap:** None for the write path. Note it is the account avatar (users.avatar_url), not a creator-scoped photo; kyc_creator_details.photo_url is a separate, unrelated column that no profile read path uses.

### Username/handle change

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** CreatorController.php:365 'handle' => 'nullable|handle|max:60' (regex at app/Core/Validator.php:246); collision check CreatorController.php:387-396 throwing handle_taken; written at CreatorController.php:400.
- **Gap:** None for the change itself.

### Gallery CRUD — create

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** POST /me/creator/portfolio — routes/api.php:157; CreatorController.php:562-619. Accepts a direct file (UploadService::store 'portfolio') or a pre-uploaded media_url.

### Gallery CRUD — delete

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** DELETE /me/creator/portfolio/{id} — routes/api.php:158; CreatorController.php:622-640 (soft delete + is_active=0, ownership checked).

### Languages

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** Storage creators.languages (JSON). Write: CreatorController.php:374 ('languages' => 'nullable|array') then CreatorController.php:413-415 json_encode. Read: CreatorService.php:317. Filterable: CreatorService.php:130-133 (JSON_CONTAINS). Option list is admin-managed — taxonomy key 'language' (database/seeds/007_taxonomies.sql), served by GET /app/taxonomies.
- **Gap:** Not hardcoded. Only nit: no per-element validation against the taxonomy, so an arbitrary string can be stored.

### Sub-categories, distinct from the single primary_category_id

- **Lens:** creator-api · **Effort:** small
- **Evidence:** Storage creator_categories. Write: CreatorController.php:377 ('category_ids' => 'nullable|array') and CreatorController.php:431-442 (delete-then-insertIgnore inside the transaction). Read: CreatorService.php:244-250; used for search joins at CreatorService.php:89 and for the category counts at CreatorController.php:264-272.
- **Gap:** HARDCODED cap of 5 at CreatorController.php:434 (array_slice(..., 0, 5)) with no setting behind it. No per-element 'exists:categories,id' rule — invalid ids are swallowed silently by insertIgnore. Nothing enforces that a sub-category is a child (categories.parent_id) of the primary, so 'sub-category' is really just a flat multi-category tag list.

### Social accounts — add

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** PUT /me/creator/social — routes/api.php:159; CreatorController.php:643-685, INSERT ... ON DUPLICATE KEY UPDATE on (creator_id, platform).

### Social accounts — edit

- **Lens:** creator-api · **Effort:** small
- **Evidence:** Same upsert: CreatorController.php:659-664 updates handle, profile_url, followers, engagement_rate, last_synced_at when the (creator_id, platform) pair already exists.
- **Gap:** Only 5 of the 17 columns are writable. following, posts, avg_likes, avg_comments, avg_views and audience_stats have no writer in app/ — yet avg_views is published on the public profile (CreatorService.php:253), so it renders 0 for everyone.

### Contact verification — change email, change mobile, re-verify after change

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** PUT /me — AccountController.php:54 (email, unique:users,email,{id}) and :56 (phone). Email change resets email_verified_at to null at AccountController.php:77-80; phone change checks (country_code, phone) uniqueness and resets phone_verified_at at AccountController.php:82-99.
- **Gap:** The change and the verification reset are correct. The re-verify step afterwards is the broken OTP flow above, so a user who changes their email can never get the badge back.

### Preview public profile

- **Lens:** creator-api · **Effort:** trivial
- **Evidence:** GET /creators/{handle} (routes/api.php:97, auth.optional group) serves it, and CreatorService::profile explicitly lets the owner through when the profile is not live: CreatorService.php:237-240 ('Only the owner may view a profile that is not live yet'). Self-views are not counted — CreatorService.php:473-481.
- **Gap:** No dedicated /me/creator/preview (live probe -> 404), but the public route with the owner's token is a working preview, so this is genuinely covered.

### Profile completion percentage — CreatorService::computeProfileScore, which sections it weighs and whether the weights are configurable

- **Lens:** creator-api · **Effort:** medium
- **Evidence:** app/Services/CreatorService.php:577-616. Weights, all PHP integer literals: display_name 10 (:587), headline 10 (:588), about >= 80 chars 15 (:589), city 5 (:590), primary_category_id 10 (:591), social accounts min(15, count*8) (:597), active packages min(20, count*10) (:603), portfolio items min(15, count*3) (:609); capped at 100 (:611) and persisted to creators.profile_score (:613). Recomputed on profile update (CreatorController.php:445), package create (:488), portfolio add (:613) and social upsert (:676). Read back by GET /me/creator (CreatorController.php:353) and GET /me/creator/analytics (CreatorController.php:782); rendered in admin at app/Views/admin/creators/index.php:145-148.
- **Gap:** HARDCODED — every weight and every threshold (the 80-character `about` minimum, the *8/*10/*3 per-item values, the 15/20/15 caps) is a literal in PHP. There is no settings row (searched settings for %profile%/%complet% — only kyc.auto_approve_min_completeness, a different metric) and no admin screen. It also weighs nothing about: avatar/profile photo, cover image, languages, gender, date_of_birth, state/country, availability, sub-categories (creator_categories), KYC status or verification — so a creator can hit 100% with no photo and no verification.

### Profile photo

- **Lens:** schema · **Effort:** trivial
- **Evidence:** users.avatar_url VARCHAR(500); written at D:/zustee.com/backend/app/Controllers/Api/AccountController.php:101-118 (multipart 'avatar' via UploadService, or validated http/https avatar_url); read into the creator payload by D:/zustee.com/backend/app/Services/CreatorService.php:223 (`u.avatar_url`) and surfaced in cards at CreatorService.php:653.
- **Gap:** Storage lives on users, not creators — PUT /creator cannot set it, only PUT /me can. A THIRD unsynchronised copy exists at kyc_creator_details.photo_url (D:/zustee.com/backend/database/migrations/013_kyc_verification.sql), which the public profile never reads. Nothing reconciles the two.

### Cover photo

- **Lens:** schema · **Effort:** trivial
- **Evidence:** creators.cover_image_url VARCHAR(500) — D:/zustee.com/backend/database/migrations/003_categories_and_creators.sql:48. Validated at app/Controllers/Api/CreatorController.php:376 and written at CreatorController.php:410. Upload path is the generic POST /me/upload (routes/api.php:128).
- **Gap:** UploadService folder allow-list at AccountController.php:191 has no 'covers' entry, so a cover falls back to the 'general' bucket. Second unsynced copy at kyc_creator_details.cover_url.

### Display name

- **Lens:** schema · **Effort:** trivial
- **Evidence:** creators.display_name VARCHAR(120) NOT NULL — 003_categories_and_creators.sql:45. Validated CreatorController.php:364, written CreatorController.php:399. Seeded on signup by CreatorService::createDraftProfile (CreatorService.php:38).
- **Gap:** None.

### Username / handle

- **Lens:** schema · **Effort:** small
- **Evidence:** creators.handle VARCHAR(60), unique index uq_creators_handle — 003_categories_and_creators.sql:44. Generated by CreatorService::uniqueHandle (CreatorService.php:619-636), changed via CreatorController.php:365 + collision check at CreatorController.php:387-393.
- **Gap:** Storage is complete, but there is NO change-count column, no last_changed_at, and no handle-history table (searched creator_handle_history, handle_changes, username_history, handle_log — absent from SHOW TABLES). The change limit the spec demands has nowhere to live and nothing to count against. Old handles are not reserved, so a freed handle is immediately squattable.

### Headline

- **Lens:** schema · **Effort:** trivial
- **Evidence:** creators.headline VARCHAR(150) — 003_categories_and_creators.sql:46 (COMMENT 'e.g. "Lifestyle Blogger"'). Validated CreatorController.php:366, written CreatorController.php:401, indexed in ft_creators_search (003:93).
- **Gap:** None.

### About (long-form)

- **Lens:** schema · **Effort:** trivial
- **Evidence:** creators.about TEXT — 003_categories_and_creators.sql:47. Validated CreatorController.php:367 (max 5000), written CreatorController.php:402, read CreatorService.php:223 via `c.*`, fulltext-indexed at 003:93.
- **Gap:** None.

### Languages

- **Lens:** schema · **Effort:** trivial
- **Evidence:** creators.languages JSON — 003_categories_and_creators.sql:55. Validated CreatorController.php:374, encoded and written CreatorController.php:413-415, decoded for output CreatorService.php:315. Filterable in search (CreatorService.php search clauses). Option list is admin-managed, not hardcoded: taxonomies row key='language' id=3, allows_multiple=1.
- **Gap:** None.

### Gender

- **Lens:** schema · **Effort:** small
- **Evidence:** creators.gender ENUM('male','female','non_binary','prefer_not_to_say') — 003_categories_and_creators.sql:53. Validated CreatorController.php:372, written CreatorController.php:407.
- **Gap:** HARDCODED TWICE and out of sync with the admin data: the API validator at CreatorController.php:372 hardcodes `in:male,female,non_binary,prefer_not_to_say` rather than reading the taxonomy, and the admin-managed taxonomy key='gender' (taxonomies id 4) has only THREE terms — female, male, non_binary — so 'prefer_not_to_say' is selectable by the API but invisible in the app's option list. Adding a gender in admin cannot work: the column ENUM would reject it under STRICT_TRANS_TABLES.

### Country / state / city

- **Lens:** schema · **Effort:** trivial
- **Evidence:** creators.country VARCHAR(100) DEFAULT 'India', creators.state VARCHAR(100), creators.city VARCHAR(100) — 003_categories_and_creators.sql:49-51. Validated CreatorController.php:369-371, written CreatorController.php:404-406. Indexed idx_creators_city. City option list is admin-managed (taxonomies key='city', id=42).
- **Gap:** No postal code on creators (users.postal_code exists but is account-level). Country and state are free text with no taxonomy backing, so they are unvalidatable.

### Creator category (primary)

- **Lens:** schema · **Effort:** trivial
- **Evidence:** creators.primary_category_id INT UNSIGNED FK to categories — 003_categories_and_creators.sql:48; index idx_creators_category. Validated CreatorController.php:368 (`exists:categories,id`), written CreatorController.php:403, joined for display CreatorService.php:224. Plus the many-to-many creator_categories (003:99-110, PK (creator_id, category_id)), rewritten transactionally at CreatorController.php:431-441.
- **Gap:** creator_categories is capped at 5 by a magic number at CreatorController.php:434 (`array_slice(..., 0, 5)`) — HARDCODED, not a setting.

### Availability calendar

- **Lens:** schema · **Effort:** small
- **Evidence:** creator_availability table — 003_categories_and_creators.sql:197-216, unique key uq_ca_creator_date (creator_id, available_on). Write path PUT /creator/availability at app/Controllers/Api/CreatorController.php:700-720 (upsert, capped at 90 dates by CreatorController.php:695). Public read GET /creators/{id}/availability at CreatorController.php:160-176.
- **Gap:** creator_availability.booked_count is READ at CreatorController.php:172 to compute is_bookable but is NEVER incremented — grep for `creator_availability` across app/ returns exactly two hits, both in CreatorController (read at :164, write at :707), and the write does not include booked_count. BookingService never touches the table. Every day therefore reports is_bookable=true no matter how many bookings land, so max_bookings is unenforceable. The `note` column also has no writer.

### Pricing packages

- **Lens:** schema · **Effort:** small
- **Evidence:** creator_packages table — 003_categories_and_creators.sql:139-165. Full CRUD: POST app/Controllers/Api/CreatorController.php:459-500, PUT CreatorController.php:503-545, soft DELETE CreatorController.php:548-558; ownership check assertOwnsPackage at CreatorController.php:790-800. Read into the profile at CreatorService.php:258-271; price_min/price_max on creators recomputed from packages at CreatorService.php:535-542.
- **Gap:** HARDCODED option lists: platform `in:instagram,youtube,tiktok,facebook,twitter,linkedin,multi` at CreatorController.php:463 and :510, deliverable_type `in:post,reel,story,video,short,live,ugc,blog,review,bundle` at CreatorController.php:464 and :511 — both duplicate the column ENUMs (003:144-145) and ignore the admin taxonomies key='platform' (id 2) and key='deliverable_type' (id 11). Adding a platform in admin cannot reach packages. Also creator_packages.sort_order is read in the profile ORDER BY (CreatorService.php:263) but has no writer — no reorder route.

### Reviews and ratings

- **Lens:** schema · **Effort:** trivial
- **Evidence:** `reviews` table with creator_id, rating plus quality_rating / communication_rating / timeliness_rating, comment, media_urls, reply + replied_at, is_published, is_flagged, moderated_by/at, helpful_count. Write POST /bookings/{id}/review (routes/api.php:245 → ReviewController::store); creator reply POST /reviews/{id}/reply (routes/api.php:248); public read GET /creators/{id}/reviews (routes/api.php:99 → CreatorController.php:118-147) with a star breakdown from CreatorService::ratingBreakdown (CreatorService.php:390). Aggregates creators.rating_avg / rating_count recomputed at CreatorService.php:514-522.
- **Gap:** None for storage. Note the profile embeds only the latest 10 (CreatorService.php:287-295).
