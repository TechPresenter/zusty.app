-- ---------------------------------------------------------------------
-- 038 — Profile change approval, item moderation, and version history
--
-- Written against the live schema and against the code that writes to it,
-- not against the brief. Four of the six things the brief asks for already
-- exist in some form; this migration builds the two that genuinely do not,
-- extends two existing structures by one idea each, and says plainly why the
-- rest is left alone.
--
--
-- WHAT ALREADY EXISTS AND IS DELIBERATELY NOT REBUILT
--
--   * Uploaded document review. `kyc_documents` already carries
--     `status` enum('pending','under_review','verified','rejected'),
--     `reviewed_by`, `reviewed_at` and `rejection_reason`, plus `version`,
--     `is_current` and `replaced_by` for supersession, and `kyc_events` and
--     `kyc_notes` for the trail. There is nothing left to model. A document
--     is reviewed in the KYC module and only there; a second review state
--     living beside it would be two answers to one question.
--
--   * The approvals engine. `approval_workflows` / `approval_requests` /
--     `approval_steps` / `approval_actions` and ApprovalService already do
--     ordered multi-level sign-off, with the request row locked, a four-eyes
--     rule, and an apply step that already moves `creators`.`status`. A
--     second approval mechanism is not built. Section 6 makes the existing
--     one reachable from a change request instead.
--
--   * Version snapshots. `revisions` is a general point-in-time store —
--     UNIQUE (entity_type, entity_id, version), the whole row as JSON in
--     `snapshot` — and RevisionService already computes a field-level
--     before/after from two of them and can write one back over the live
--     row. That IS the "compare previous versions" screen. No
--     creator_profile_versions table is created here. Section 5 adds the one
--     thing `revisions` cannot express today and nothing else.
--
--   * Reporting an item. `reports`.`reportable_type` already covers
--     'creator' and 'portfolio', with a handler, a status and a resolution
--     note, so "someone complained about this gallery item" is modelled.
--     What was missing is the operator's own verdict on it — section 4.
--
--
-- AND FOUR THINGS THE MIGRATIONS EITHER SIDE OF THIS ONE ALREADY DO
--
--   035, 036, 037 and 039 land in the same release, before and after this
--   file, and three of them touch ground this module would otherwise have
--   claimed. They were read, not assumed:
--
--   * 037 keeps its ten scoring buckets under the key `completion_section`
--     and hands `profile_section` to this file, mapping one onto the other
--     with metadata.review_sections. The seventeen terms seeded in 1a are
--     exactly the values 037 names there, so the mapping resolves.
--   * 035 adds `creator_portfolio`.`collection`, so gallery and portfolio
--     are two collections of one table. Section 4 moderates that table once,
--     for every collection, instead of inventing a second distinction.
--   * 036 owns contact changes outright: `contact_change_requests` holds a
--     proposed email or phone number with OTP proof, a unique key that
--     allows one live change per channel, and its own apply step. Contact is
--     therefore marked as handled elsewhere in 1a, and a contact change must
--     never be raised through the queue built here.
--   * 037 records applied handle changes in `creator_handle_history`. That
--     is the trail AFTER the fact; this module holds the proposal BEFORE it.
--     Complementary, not competing — an approved handle change writes both.
--
--
-- WHAT `activity_logs` CAN AND CANNOT ANSWER
--
--   This was checked against the rows in the table, not against the class.
--   ActivityLogger::diff() does produce a before/after payload, but the
--   creator write path never calls it: CreatorController::updateProfile()
--   passes `array_keys($updates)`, so the stored `properties` of every
--   creator.profile_updated row currently in the database reads
--
--       ["city"]
--
--   — the NAME of the field that moved, and nothing else. The admin side is
--   no better: creator.flags_updated stores the after values with no before.
--
--   So the audit trail answers "who touched what, and when" and cannot
--   answer "what did it say before". It cannot reconstruct a version
--   comparison, and this migration does not pretend that it can. The
--   comparison is served by `revisions` (section 5), which stores whole
--   rows; `activity_logs` stays what it is, the accountability trail.
--
--
-- THE GAP THIS ACTUALLY CLOSES
--
--   updateProfile() writes the creator's new value straight into `creators`
--   and then flips `status` to 'pending_review'. The edit is therefore
--   ALREADY LIVE while it waits: an approved creator can rewrite their
--   display name, headline and bio and that is what the marketplace shows
--   until an administrator gets round to the queue. And because the write
--   already happened, the old value is gone — "approve this change" today
--   means re-approving the entire profile without ever being shown what the
--   change was.
--
--   Sections 2 and 3 give a proposed value somewhere to WAIT: the old value
--   and the new one side by side, per field, with a proposer, a reviewer, a
--   decision and a note. Section 4 gives the same treatment to a single
--   gallery or portfolio item, which had no review state at all even though
--   the operator setting `creator.moderate_gallery_uploads` has been
--   switched ON this whole time with nowhere to record its outcome.
--
--
-- NOTHING CHANGES ON THE DAY THIS IS APPLIED
--
--   `creator_portfolio`.`moderation_status` defaults to 'approved', so all
--   300 items already published stay published. The new switch
--   `creator.hold_changes_for_review` seeds to 0, so updateProfile() keeps
--   behaving exactly as it does today until an operator turns it on. This
--   migration adds capability, not a new state of the world.
-- ---------------------------------------------------------------------


-- =====================================================================
-- 1. The two vocabularies this module needs, as taxonomies
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1a. Profile sections
--
-- This vocabulary exists three times over today and disagrees with itself:
--
--   * CreatorProfileLockService::SECTIONS — sixteen sections, hardcoded in
--     PHP, with a comment conceding they are "held in the application rather
--     than an ENUM so the vocabulary can grow without a migration". It still
--     cannot grow without a release.
--   * `settings`.`creator.reviewed_sections` — labelled "Sections that need
--     review" and holding a CSV of COLUMN names
--     (display_name,handle,headline,about,price_min,price_max): a different
--     vocabulary wearing the same word.
--   * `creator_profile_locks`.`section` — free text, whatever the two above
--     happen to agree on that day.
--
-- One taxonomy replaces all three, and it has to be at FIELD granularity,
-- because that is the granularity of the decisions taken against it: an
-- administrator freezes a handle, and approves a display name. `metadata`
-- carries what each consumer needs — `fields` are the columns the section
-- governs, so the reviewed-fields test becomes a lookup instead of a CSV;
-- `table` says where a section that is not a `creators` column actually
-- lives; `lockable` is what the lock screen offers; `requires_review` is the
-- default answer to "does an edit here need an administrator"; `moderated`
-- marks the sections whose items are approved one at a time in section 4.
--
-- These seventeen are deliberately NOT the ten segments of the completion
-- bar. Migration 037 keeps those under its own key, `completion_section`,
-- pinned to the `creator.completion_weights` settings row, and maps the two
-- lists onto each other with metadata.review_sections — 'basic' covers
-- username, display_name, headline, location and languages; 'gallery' covers
-- photos and gallery; and so on. A progress bar cannot have seventeen
-- segments and a lock cannot be as coarse as "basic", so they are two lists
-- with an explicit mapping rather than one list that serves neither. Every
-- value 037 names in `review_sections` is seeded below, so that mapping
-- resolves the moment both files are applied; `availability` is the
-- seventeenth, which is lockable and reviewable but scores nothing.
--
-- An operator who wants a new lockable, reviewable section adds a row. No
-- ENUM, no PHP list, no release.
-- ---------------------------------------------------------------------

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('profile_section', 'Profile sections',
        'The parts of a creator profile that are locked, reviewed and approved separately. Field-level, unlike the coarser completion_section list that drives the progress bar. Add a row to make a new section lockable and reviewable.',
        1, 1, 1, 63, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`metadata`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    -- The first five carry requires_review true because they are exactly the
    -- columns `creator.reviewed_sections` names today — handle, display_name,
    -- headline, about, price_min/price_max — so reading the taxonomy
    -- reproduces the operator's current setting rather than quietly widening
    -- or narrowing it. Three more are true further down and are additions:
    -- `photos` because a profile photo is the one field impersonation
    -- actually needs, and `documents` and `verification` because neither was
    -- ever a creator's to decide.
    SELECT 'username' AS `value`, 'Username' AS `label`,
           'The @handle the profile is reached by.' AS `description`,
           'at' AS `icon`, '#1D4ED8' AS `color_hex`,
           '{"table":"creators","fields":["handle"],"requires_review":true,"lockable":true,"history":"creator_handle_history"}' AS `metadata`,
           10 AS `sort_order` UNION ALL
    SELECT 'display_name', 'Display name', 'The name shown on the profile and in search.',
           'user', '#1D4ED8',
           '{"table":"creators","fields":["display_name"],"requires_review":true,"lockable":true}', 20 UNION ALL
    SELECT 'headline', 'Professional headline', 'The one-line description under the name.',
           'type', '#0891B2',
           '{"table":"creators","fields":["headline"],"requires_review":true,"lockable":true}', 30 UNION ALL
    SELECT 'bio', 'Bio and about', 'The long-form description of the creator.',
           'file', '#475569',
           '{"table":"creators","fields":["about"],"requires_review":true,"lockable":true}', 40 UNION ALL
    -- price_min / price_max / currency live on `creators`, not on
    -- `creator_packages`. Naming the wrong table here would have made the
    -- requires_review lookup miss every one of those fields, quietly defeating
    -- the review gate this term exists to describe.
    SELECT 'pricing', 'Pricing and packages', 'Rate card, price range and the packages a brand can book.',
           'tag', '#B45309',
           '{"table":"creators","fields":["price_min","price_max","currency"],"requires_review":true,"lockable":true}', 50 UNION ALL
    SELECT 'photos', 'Profile and cover photo', 'Avatar and cover image.',
           'image', '#7C3AED',
           '{"table":"creators","fields":["cover_image_url"],"also":["users.avatar_url"],"requires_review":true,"lockable":true}', 60 UNION ALL
    SELECT 'location', 'Location and address', 'City, state and country.',
           'map', '#475569',
           '{"table":"creators","fields":["city","state","country"],"requires_review":false,"lockable":true}', 70 UNION ALL
    SELECT 'languages', 'Languages', 'The languages a creator works in.',
           'globe', '#475569',
           '{"table":"creators","fields":["languages"],"requires_review":false,"lockable":true}', 80 UNION ALL
    SELECT 'categories', 'Categories and profession', 'Primary category and the additional categories a creator sits in.',
           'folder', '#334155',
           '{"table":"creator_categories","fields":["primary_category_id"],"requires_review":false,"lockable":true}', 90 UNION ALL
    SELECT 'skills', 'Skills and interests', 'Declared skills, specialisms and interests.',
           'star', '#059669',
           '{"table":"creator_profile_terms","taxonomy_keys":["skill","interest","profession"],"requires_review":false,"lockable":true}', 100 UNION ALL
    SELECT 'availability', 'Availability and working hours', 'Booking availability and response commitments.',
           'clock', '#0EA5E9',
           '{"table":"creator_availability","fields":["availability_status","response_time_hours"],"requires_review":false,"lockable":true}', 110 UNION ALL
    SELECT 'social', 'Social accounts', 'Linked networks, handles and their follower figures.',
           'link', '#0EA5E9',
           '{"table":"creator_social_accounts","requires_review":false,"lockable":true}', 120 UNION ALL
    -- Two sections whose ITEMS are moderated one at a time in section 4
    -- rather than diffed field by field. Both are rows of
    -- `creator_portfolio`, told apart by the `collection` column that 035
    -- adds — there is no second gallery table and this does not create one.
    SELECT 'portfolio', 'Portfolio', 'Published work a brand can judge the creator on.',
           'grid', '#7C3AED',
           '{"table":"creator_portfolio","collection":"portfolio","requires_review":false,"lockable":true,"moderated":true}', 130 UNION ALL
    SELECT 'gallery', 'Gallery', 'Photographs and clips shown on the profile.',
           'photo', '#DB2777',
           '{"table":"creator_portfolio","collection":"gallery","requires_review":false,"lockable":true,"moderated":true}', 140 UNION ALL
    -- Owned by 036 end to end: a contact change is not gated by an
    -- administrator reading it, it is gated by the holder of the new value
    -- proving it, through `contact_change_requests` and an OTP. Anything
    -- raised against this section in the queue below is a bug.
    SELECT 'contact', 'Contact details', 'Email address and mobile number.',
           'mail', '#0891B2',
           '{"table":"user_contact_points","fields":["email","phone"],"requires_review":false,"lockable":true,"verified_by":"otp","handled_in":"contact_change_requests"}', 150 UNION ALL
    -- Reviewed in the KYC module. Listed so the lock screen and the section
    -- filter can name it, and marked so nothing here tries to review it.
    SELECT 'documents', 'Documents and media kit', 'Identity documents, media kit and other uploads.',
           'folder', '#334155',
           '{"table":"kyc_documents","requires_review":true,"lockable":true,"reviewed_in":"kyc"}', 160 UNION ALL
    -- Only an administrator ever writes this one, so a creator can never
    -- raise a change request against it.
    SELECT 'verification', 'Verification', 'Badges and verification state.',
           'verified', '#059669',
           '{"table":"creator_badges","requires_review":true,"lockable":true,"admin_only":true}', 170
  ) v
 WHERE t.`key` = 'profile_section'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`),
  `metadata`    = VALUES(`metadata`);


-- ---------------------------------------------------------------------
-- 1b. Moderation reasons.
--
-- A rejection needs a reason an operator can report on, not only a sentence
-- an administrator typed at midnight. The same list serves all three places
-- something is refused — a proposed profile change, a gallery or portfolio
-- item, and a document — so a "why were creators rejected this month"
-- question has one answer instead of three.
--
-- `metadata.applies_to` tells each screen which subset to offer;
-- `metadata.notify` marks the reasons the creator should be told verbatim,
-- as opposed to the ones an operator would rather paraphrase.
-- ---------------------------------------------------------------------

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('moderation_reason', 'Moderation reasons',
        'Why a profile change, a gallery item or a document was rejected. Add a row to offer a new reason on every moderation screen at once.',
        1, 0, 1, 219, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`metadata`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'low_quality' AS `value`, 'Low quality' AS `label`,
           'Blurred, badly cropped or otherwise below the standard the marketplace publishes.' AS `description`,
           'image' AS `icon`, '#B45309' AS `color_hex`,
           '{"applies_to":["portfolio","gallery"],"notify":true}' AS `metadata`, 10 AS `sort_order` UNION ALL
    SELECT 'misleading', 'Misleading or unverifiable', 'Claims a figure or a credential we cannot confirm.',
           'alert', '#DC2626', '{"applies_to":["profile_change","portfolio","gallery"],"notify":true}', 20 UNION ALL
    SELECT 'impersonation', 'Not the creator', 'Presents someone else as the account holder.',
           'user', '#DC2626', '{"applies_to":["profile_change","portfolio","gallery","document"],"notify":true}', 30 UNION ALL
    SELECT 'copyright', 'Copyright or licensing', 'Work the creator does not hold the rights to publish.',
           'shield', '#DC2626', '{"applies_to":["portfolio","gallery"],"notify":true}', 40 UNION ALL
    SELECT 'explicit_content', 'Explicit content', 'Nudity or sexual content.',
           'eye-off', '#DC2626', '{"applies_to":["portfolio","gallery"],"notify":true}', 50 UNION ALL
    SELECT 'violence_or_hate', 'Violence or hate', 'Violent, hateful or harassing material.',
           'alert', '#DC2626', '{"applies_to":["profile_change","portfolio","gallery"],"notify":true}', 60 UNION ALL
    SELECT 'spam_or_ads', 'Spam or advertising', 'Promotional material unrelated to the creator.',
           'megaphone', '#B45309', '{"applies_to":["profile_change","portfolio","gallery"],"notify":true}', 70 UNION ALL
    SELECT 'contact_details', 'Contact details shared', 'Phone, email or a link that takes the booking off the platform.',
           'phone', '#B45309', '{"applies_to":["profile_change","portfolio","gallery"],"notify":true}', 80 UNION ALL
    SELECT 'watermark', 'Third-party watermark or branding', 'Carries another platform or agency mark.',
           'tag', '#B45309', '{"applies_to":["portfolio","gallery"],"notify":true}', 90 UNION ALL
    SELECT 'duplicate', 'Duplicate', 'Already published on this profile.',
           'copy', '#64748B', '{"applies_to":["portfolio","gallery","document"],"notify":true}', 100 UNION ALL
    SELECT 'wrong_category', 'Wrong category', 'Filed under a category it does not belong to.',
           'grid', '#64748B', '{"applies_to":["profile_change","portfolio","gallery"],"notify":true}', 110 UNION ALL
    SELECT 'price_manipulation', 'Pricing not credible', 'A rate change that looks like manipulation rather than repricing.',
           'card', '#B45309', '{"applies_to":["profile_change"],"notify":false}', 120 UNION ALL
    SELECT 'name_policy', 'Name or handle policy', 'Breaches the naming rules: a brand name, a role, or an offensive term.',
           'at', '#DC2626', '{"applies_to":["profile_change"],"notify":true}', 130 UNION ALL
    SELECT 'incomplete', 'Incomplete', 'Not enough was submitted to make a decision.',
           'file', '#64748B', '{"applies_to":["profile_change","portfolio","gallery","document"],"notify":true}', 140 UNION ALL
    SELECT 'unreadable', 'Unreadable file', 'The upload could not be opened or read.',
           'file', '#64748B', '{"applies_to":["portfolio","gallery","document"],"notify":true}', 150 UNION ALL
    SELECT 'expired', 'Expired', 'Valid once, out of date now.',
           'clock', '#64748B', '{"applies_to":["document"],"notify":true}', 160 UNION ALL
    SELECT 'other', 'Other', 'None of the above. The decision note carries the reason.',
           'more', '#64748B', '{"applies_to":["profile_change","portfolio","gallery","document"],"notify":false,"requires_note":true}', 170
  ) v
 WHERE t.`key` = 'moderation_reason'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`),
  `metadata`    = VALUES(`metadata`);


-- =====================================================================
-- 2. The submission
-- =====================================================================

-- ---------------------------------------------------------------------
-- One row per save. A creator pressing Save on the profile screen sends the
-- whole form, which is one intent even when it moves five fields, and an
-- administrator should decide it as one intent — so the decision, the note
-- and the reviewer live here and the field-by-field detail lives in
-- `creator_change_fields`.
--
-- Two tables rather than one because the queue reads at two altitudes: the
-- list needs "twelve submissions waiting, oldest three days" without
-- unpacking every field, and the detail screen needs each field's before and
-- after. One flat table would either repeat the reviewer and note on every
-- field row or lose the ability to decide a single field.
--
-- `status` = 'partial' is not a hedge. An administrator who accepts a new
-- bio but refuses the new handle has made both decisions, and the submission
-- as a whole is neither approved nor rejected; without that value the row
-- would have to lie in one direction or the other.
--
-- The request is NOT unique per creator. A creator may have several waiting
-- — one raised on Monday, another on Wednesday — and the second must not
-- silently overwrite the first. `superseded` is how the older one steps
-- aside when the newer one covers the same field.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `creator_change_requests` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`          BIGINT UNSIGNED NOT NULL,

  -- Enough to render the queue row without touching the field table:
  -- "Display name, headline and 2 more".
  `summary`             VARCHAR(255) NULL,

  -- Who asked. An administrator editing a profile on a creator's behalf
  -- goes through the same queue, so the trail reads the same either way.
  -- Polymorphic by design: `requested_by_id` is a `users`.`id` for a
  -- creator, an `admins`.`id` for an admin, and null for the system — which
  -- is why it carries no foreign key. `requested_by_name` is denormalised so
  -- the queue still names the proposer after the account is gone.
  `requested_by_type`   ENUM('creator','admin','system') NOT NULL DEFAULT 'creator',
  `requested_by_id`     BIGINT UNSIGNED NULL,
  `requested_by_name`   VARCHAR(150) NULL,

  `status`              ENUM('pending','approved','partial','rejected','withdrawn','superseded')
                        NOT NULL DEFAULT 'pending',

  -- Set when the approved values were actually written onto the live rows.
  -- Separate from the decision on purpose: approving is an opinion, applying
  -- is a write, and the write can fail on a handle that was taken while the
  -- request sat in the queue. A row that is 'approved' with a null
  -- `applied_at` is exactly the retry list.
  `applied_at`          DATETIME NULL,

  `reviewed_by`         INT UNSIGNED NULL,
  `reviewed_at`         DATETIME NULL,
  `decision_note`       VARCHAR(1000) NULL,
  -- A `moderation_reason` term value, not an ENUM: see 1b.
  `rejection_reason`    VARCHAR(60) NULL,

  -- Set only when the submission was escalated into the existing
  -- multi-level queue (section 6). Null is the ordinary case: one
  -- administrator with `creators.approve` decides it here and no
  -- `approval_requests` row is ever created.
  `approval_request_id` BIGINT UNSIGNED NULL,

  -- The `revisions` row holding the profile as it looked BEFORE this
  -- submission, so the comparison screen has a fixed left-hand side. No
  -- foreign key: `revisions` is a general store shared with the CMS entities
  -- and must not acquire a dependency on the creator module, and a reader
  -- that has already lost the snapshot degrades to "no earlier version" on
  -- its own.
  `revision_id`         BIGINT UNSIGNED NULL,

  `submitted_ip`        VARBINARY(16) NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- The queue itself: everything pending, oldest first.
  KEY `idx_cchr_queue` (`status`, `created_at`),
  KEY `idx_cchr_creator` (`creator_id`, `status`, `created_at`),
  KEY `idx_cchr_reviewer` (`reviewed_by`, `reviewed_at`),
  -- "Approved but never written" — the list a retry job reads.
  KEY `idx_cchr_unapplied` (`status`, `applied_at`),
  KEY `fk_cchr_approval` (`approval_request_id`),
  CONSTRAINT `fk_cchr_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE,
  -- The reviewer's account may be closed; the decision stays. Same rule as
  -- `kyc_documents`.`reviewed_by`.
  CONSTRAINT `fk_cchr_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cchr_approval` FOREIGN KEY (`approval_request_id`) REFERENCES `approval_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 3. What the submission proposes, field by field
-- =====================================================================

-- ---------------------------------------------------------------------
-- One row per field that moved: where it lives, what it said, what it is
-- being asked to say.
--
-- `old_value` is captured at SUBMISSION time and never rewritten. That is
-- the whole point of the table — today the old value is destroyed by the
-- update before anyone sees it, so the reviewer is shown a "change" with
-- only one side.
--
-- Both values are LONGTEXT because an `about` is 5,000 characters, a
-- `languages` value is a JSON array, and a package rewrite is a JSON
-- document. `value_type` tells the client which of those it is holding, so
-- a diff renders as text, as a number, or as a structured comparison
-- without the reader having to guess from the string.
--
-- `target_table` / `target_id` exist because a creator profile is not one
-- row. A price sits in `creator_packages`, a handle in
-- `creator_social_accounts`, a caption in `creator_portfolio`. Without them
-- this table could only ever describe columns of `creators`, and the
-- pricing section — one of the six the operator already marks as reviewed —
-- would be unrepresentable.
--
-- `field` is null for a change that has no single column: a reordered
-- gallery, a replaced package set, a category selection. The section still
-- identifies it and the JSON values still describe it.
--
-- There is deliberately no unique key on (creator, target, field). MySQL
-- cannot express "at most one PENDING row per field" as a constraint —
-- partial indexes do not exist — and a unique key over the decided rows too
-- would forbid the second time a creator ever edits their bio. Supersession
-- is therefore the application's job, and `status` = 'superseded' is where
-- it records it; `idx_ccf_open` is the index that makes finding the row to
-- supersede a single lookup.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `creator_change_fields` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`       BIGINT UNSIGNED NOT NULL,

  -- Denormalised from the parent so "everything pending for this creator"
  -- and "who else has a handle change waiting" are single-index questions.
  -- No second foreign key to `creators`: the row already dies with its
  -- request, which dies with the creator, and one cascade path is easier to
  -- reason about than two that must agree.
  `creator_id`       BIGINT UNSIGNED NOT NULL,

  -- A `profile_section` term value (see 1a), never an ENUM.
  `section`          VARCHAR(40) NOT NULL,
  `field`            VARCHAR(64) NULL COMMENT 'Column name, or NULL for a whole-section change',

  `target_table`     VARCHAR(64) NOT NULL DEFAULT 'creators',
  `target_id`        BIGINT UNSIGNED NULL COMMENT 'Child row id when the change is not to creators itself',

  `value_type`       VARCHAR(20) NOT NULL DEFAULT 'string'
                     COMMENT 'string | text | integer | decimal | boolean | date | url | media | json',
  `old_value`        LONGTEXT NULL COMMENT 'As it stood when the change was proposed',
  `new_value`        LONGTEXT NULL COMMENT 'What the creator is asking for',

  `status`           ENUM('pending','approved','rejected','superseded') NOT NULL DEFAULT 'pending',
  `applied_at`       DATETIME NULL,
  `reviewed_by`      INT UNSIGNED NULL,
  `reviewed_at`      DATETIME NULL,
  `decision_note`    VARCHAR(500) NULL,
  `rejection_reason` VARCHAR(60) NULL COMMENT 'A moderation_reason term value',

  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ccf_request` (`request_id`),
  -- The supersession lookup: "is there already an open change to this
  -- field for this creator?"
  KEY `idx_ccf_open` (`creator_id`, `status`, `target_table`, `target_id`, `field`),
  KEY `idx_ccf_section` (`section`, `status`, `created_at`),
  KEY `idx_ccf_reviewer` (`reviewed_by`, `reviewed_at`),
  CONSTRAINT `fk_ccf_request` FOREIGN KEY (`request_id`) REFERENCES `creator_change_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ccf_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 4. A verdict on one gallery or portfolio item
-- =====================================================================

-- ---------------------------------------------------------------------
-- `creator_portfolio` was checked column by column before this was written.
-- It holds `is_active`, `is_featured`, `sort_order` and `deleted_at` — all
-- of them the CREATOR's controls over their own wall — and nothing that
-- records the OPERATOR's opinion. There is no status, no reviewer, no
-- reason, no timestamp. An administrator moderating a gallery could only
-- flip `is_active` to 0, which is indistinguishable from the creator having
-- hidden the item themselves, tells the creator nothing, and is silently
-- undone the next time they toggle it back.
--
-- Meanwhile `settings`.`creator.moderate_gallery_uploads` has been sitting
-- at 1 all along. The operator has asked for gallery moderation and the
-- schema had nowhere to put the answer.
--
-- The two flags stay independent and both must pass for an item to be
-- publicly visible:
--
--     is_active = 1            the creator wants it shown
--     moderation_status = ok   the operator permits it
--
-- DEFAULT 'approved' is the important decision here. Defaulting to 'pending'
-- would take all 300 existing items off every profile the moment this
-- migration ran, which is a content wipe wearing a review queue's clothes.
-- Everything already published stays published; only what is uploaded from
-- now on can arrive pending, and only while the operator's setting says so.
--
-- No separate gallery table, and no per-collection duplication of these
-- columns. Migration 035 has already established that gallery, portfolio,
-- showreel, behind-the-scenes and press are five values of one `collection`
-- column on these same rows. A verdict is a property of an ITEM, not of the
-- block it happens to sit in, so it is recorded once here and every
-- collection 035 seeds — and every one an operator adds later — is moderated
-- by the same five columns with no further migration.
-- ---------------------------------------------------------------------

ALTER TABLE `creator_portfolio`
  ADD COLUMN IF NOT EXISTS `moderation_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved'
      COMMENT 'The operator verdict. Independent of is_active, which is the creator control.'
      AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `moderation_reason` VARCHAR(60) NULL
      COMMENT 'A moderation_reason taxonomy term value'
      AFTER `moderation_status`,
  ADD COLUMN IF NOT EXISTS `moderation_note` VARCHAR(500) NULL
      AFTER `moderation_reason`,
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT UNSIGNED NULL
      AFTER `moderation_note`,
  ADD COLUMN IF NOT EXISTS `reviewed_at` DATETIME NULL
      AFTER `reviewed_by`;

-- The moderation queue reads "everything pending, oldest first" across all
-- creators, which the existing per-creator index cannot serve.
ALTER TABLE `creator_portfolio`
  ADD KEY IF NOT EXISTS `idx_portfolio_moderation` (`moderation_status`, `created_at`);

-- Same rule as every other reviewer column in this schema: the decision
-- outlives the account that made it.
ALTER TABLE `creator_portfolio`
  ADD CONSTRAINT `fk_portfolio_reviewer` FOREIGN KEY IF NOT EXISTS (`reviewed_by`)
      REFERENCES `admins` (`id`) ON DELETE SET NULL;


-- =====================================================================
-- 5. Version history: one column pair on the store that already exists
-- =====================================================================

-- ---------------------------------------------------------------------
-- `revisions` is already the right table. It is entity-agnostic
-- (`entity_type` VARCHAR(40)), it versions per entity with a unique key,
-- it stores the whole row as JSON, and RevisionService already turns two
-- snapshots into a labelled field-level diff and can write one back over the
-- live row. Creating a creator_profile_versions table beside it would be a
-- second implementation of a solved problem.
--
-- Exactly one thing stops it serving creator profiles: attribution. Its only
-- actor columns are `admin_id` and `admin_name`, because until now every
-- versioned entity was a CMS record that only an administrator could touch.
-- A creator editing their own profile is not an admin, and recording that
-- version with a null `admin_id` would produce a history where half the
-- entries have no author.
--
-- So the actor becomes explicit. `actor_type` defaults to 'admin', which is
-- true of every write RevisionService makes without being changed, so nothing
-- already running has to move. `actor_id` is BIGINT because a `users`.`id`
-- does not fit in the INT that `admin_id` is.
--
-- `admin_id` and `admin_name` are left exactly as they are — still written,
-- still read by the CMS revision screens. Nothing is dropped or renamed.
-- ---------------------------------------------------------------------

ALTER TABLE `revisions`
  ADD COLUMN IF NOT EXISTS `actor_type` ENUM('admin','user','system') NOT NULL DEFAULT 'admin'
      COMMENT 'Who authored this version. admin = admins.id, user = users.id'
      AFTER `change_note`,
  ADD COLUMN IF NOT EXISTS `actor_id` BIGINT UNSIGNED NULL
      COMMENT 'admins.id or users.id, read according to actor_type'
      AFTER `actor_type`;

-- `revisions` is empty on this installation — RevisionService is wired up and
-- has never been called — so this is a no-op here and is written for the
-- installations where it is not. Any snapshot that does exist was an
-- administrator editing a CMS record, because until now nothing else could
-- write one, so copying `admin_id` across restates a fact rather than
-- inventing an attribution.
UPDATE `revisions`
   SET `actor_id` = `admin_id`
 WHERE `actor_id` IS NULL
   AND `admin_id` IS NOT NULL;

-- "Everything this creator has ever changed", across entity types.
ALTER TABLE `revisions`
  ADD KEY IF NOT EXISTS `idx_rev_actor` (`actor_type`, `actor_id`, `created_at`);


-- =====================================================================
-- 6. Letting the existing approvals engine take a change request
-- =====================================================================

-- ---------------------------------------------------------------------
-- Most profile edits want one administrator and a button. Some — a handle
-- change on a top creator, a rate card rewritten overnight — want the
-- two-signature treatment that `approval_workflows` already implements.
--
-- The change request cannot be routed through it as-is, and the reason is
-- worth writing down: `approval_requests` carries UNIQUE (entity_type,
-- entity_id), so a creator can hold exactly ONE approval request for their
-- entire lifetime. That key is correct for what it was built for — the
-- initial "is this profile fit to go live" decision, which happens once —
-- and fatal for a recurring change queue, where ApprovalService::open()
-- would hand back the long-since-approved original instead of raising a new
-- one. Dropping that key is not on the table: it is what makes open()
-- idempotent under a race, and it is load-bearing for five other entity
-- types.
--
-- The fix is to escalate the REQUEST, not the creator. A
-- `creator_change_requests` row has a fresh id every time, so
-- (entity_type='creator_change', entity_id=<request id>) is unique without
-- touching anything. `approval_requests`.`entity_type` is already a
-- VARCHAR(40) and needs nothing; only the workflow table constrains the
-- vocabulary with an ENUM, so one value is appended to it. Appending to an
-- ENUM preserves every existing value's stored index, so the three workflow
-- rows configured today are untouched and none of the seven existing values
-- moves.
--
-- No workflow row is seeded. ApprovalService::ENTITY_TYPES,
-- ::ENTITY_SOURCES and ::applyDecision() are PHP constants and a PHP switch;
-- until 'creator_change' is added to all three, a seeded workflow would sit
-- in the admin screen looking functional while open() refused every request
-- raised against it. The schema is made ready here and the application
-- decides when to use it — `creator_change_requests`.`approval_request_id`
-- stays null until then, which is the ordinary single-approver path.
-- ---------------------------------------------------------------------

ALTER TABLE `approval_workflows`
  MODIFY COLUMN `entity_type`
    ENUM('creator','kyc','withdrawal','refund','blog','page','booking_cancel','creator_change') NOT NULL;


-- =====================================================================
-- 7. The switch that turns the queue on
-- =====================================================================

-- ---------------------------------------------------------------------
-- `creator.approve_profile_edits` already exists and already works, but it
-- only decides whether an edit re-opens the profile for review — the new
-- value is written live either way. This is the second, separate question:
-- should the new value WAIT.
--
-- Seeded at 0 on purpose. On the day this migration is applied nothing
-- behaves differently; updateProfile() keeps writing straight through and
-- the new tables stay empty until an operator decides otherwise. A migration
-- that silently starts holding every creator's edits hostage is not a
-- migration anyone should have to discover from a support ticket.
--
-- ON DUPLICATE KEY UPDATE deliberately does not touch `value`: re-running
-- the installer must never reset a switch an operator has since changed.
-- ---------------------------------------------------------------------

INSERT INTO `settings`
  (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`, `updated_at`)
VALUES
  ('creator', 'hold_changes_for_review', '0', 'boolean',
   'Hold profile edits until approved',
   'When on, a reviewed field keeps its current value until an administrator approves the change. When off, the edit goes live immediately and is only flagged for review.',
   0, 0, 205, NOW()),
  ('creator', 'change_review_sla_hours', '48', 'integer',
   'Profile change review target (hours)',
   'How long a submitted change should wait before the queue marks it overdue.',
   0, 0, 206, NOW())
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `type`        = VALUES(`type`);
