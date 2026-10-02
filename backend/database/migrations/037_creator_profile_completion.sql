-- ---------------------------------------------------------------------
-- 037 — Profile completion, feature gating and the rules behind them
--
-- WHAT THIS CLOSES, and what it deliberately does not touch.
--
-- The audit (docs/audit/2026-08-04-creator-profile-gaps.md) put it plainly:
-- "NO COMPLETION-BASED GATING EXISTS. profile_score is read only for display."
-- CreatorService::computeProfileScore() adds up seven hardcoded PHP literals
-- — 10 for a display name, 15 for eighty characters of bio, min(20, packages
-- * 10) — writes one number to `creators`.`profile_score`, and that is the
-- whole system. It cannot say WHICH section is short, no operator can change
-- what counts, and nothing anywhere consults the number before letting a
-- creator do something.
--
-- What already exists and is NOT rebuilt here:
--   * `creators`.`profile_score` — the overall 0-100 figure. It stays the one
--     overall number. This migration explains that number per section; it does
--     not add a second total to fall out of step with it.
--   * The `creator` settings group (seed 031) — twenty-seven rows covering
--     required_completion_percent, completion_weights, feature_unlock_
--     thresholds, mandatory_fields, locked_fields, required_verifications,
--     username_change_limit, username_change_cooldown_days, min_social_
--     accounts, min_portfolio_items, require_portfolio, default_profile_
--     visibility and the badge/featured rules. Every one of those is already
--     operator-editable through the settings screen. Not one is duplicated
--     below; the new tables REFERENCE them.
--   * `creator_profile_locks` (029) — per-section editability. Its `section`
--     column is free-text and now has a published vocabulary (below), so
--     "which fields are non-editable" needs no new storage.
--   * `creator_badges` / `verification_badge` taxonomy (029) — the gate below
--     names badge slugs from that taxonomy rather than inventing its own.
--
-- And three things checked against the migrations landing ALONGSIDE this one,
-- because a sibling in the same batch counts as existing:
--   * 039 adds `creators`.`is_public`, the creator-controlled public/private
--     switch. This file originally carried its own visibility column and it
--     has been REMOVED — see section 2. Two columns answering "is this profile
--     public" is precisely the duplication the house rules forbid.
--   * 038 seeds a `profile_section` taxonomy at a finer granularity for locks
--     and review. This module's ten scoring sections are a different list and
--     cannot be that one — section 1 explains why, and maps between them.
--   * 038's `creator_change_fields` records proposed handle changes. It does
--     not replace the handle ledger in section 6, and that section says why
--     rather than assuming.
--
-- Four things genuinely had nowhere to live, and this migration adds them.
--
-- 1. A PER-SECTION BREAKDOWN.  Stored, not computed live. The call is worth
--    justifying because it is a cache and caches rot.
--      - The gate check happens in middleware, on every request that touches a
--        gated feature. Computing it live costs eight COUNT queries plus a
--        settings read per request. Reading one indexed row does not.
--      - The admin list has to answer "show me approved creators stuck below
--        60%" and "who has never finished KYC" — questions that are ORDER BY
--        and WHERE against stored rows, and full table scans in PHP otherwise.
--      - The nightly job needs to spot creators who have just crossed a
--        threshold in order to notify them. That is a diff against a stored
--        previous state; there is nothing to diff against if it is computed.
--    The staleness is bounded on both ends: the same service call that already
--    writes `profile_score` on every profile write rewrites these rows, and
--    `creators:completion` sweeps the whole table nightly. `computed_at` is on
--    the row so a stale breakdown is visible rather than merely wrong.
--
-- 2. THE RULES, as data.  Settings rows were considered first and rejected for
--    this part: a rule is not a scalar. It has a section, a weight, a
--    mandatory flag, a table and column to look at, and a threshold — six
--    fields, times sixty rules. That is a table pretending to be a CSV if it
--    goes in `settings`.`value`. The split that avoids two sources of truth:
--      settings  says how much a SECTION is worth  (creator.completion_weights)
--      the rules say what makes a section COMPLETE (this table)
--    A rule's `weight` is its share WITHIN its section, never a share of 100.
--    And where a threshold already exists as a setting, the rule points at the
--    setting by name (`threshold_setting`) instead of copying the number, so
--    an operator raising creator.min_portfolio_items does not have to remember
--    to edit a rule as well.
--
-- 3. FEATURE GATES.  A row per feature, so an operator can gate a feature that
--    does not exist yet by typing its key — the reason the key is a plain
--    string and not an ENUM, and the reason there is no `platform_feature`
--    taxonomy: the gate row IS the row the operator adds, and a taxonomy would
--    be a second list to keep in step with it for no gain.
--    This is NOT `feature_flags`. That table answers "is this feature switched
--    on, for which audience, at what rollout percentage" — a question about
--    the platform. A gate answers "has THIS creator earned it" — a question
--    about one row in `creators`, which `feature_flags` has no column for.
--    They compose: the flag decides whether anyone gets the feature, the gate
--    decides which creators do.
--
-- 4. A HANDLE-CHANGE RECORD.  `creator.username_change_limit` has been sitting
--    in settings since seed 031 with nothing to count. Checked before adding
--    one: `revisions` does not cover creators (RevisionService lists twelve
--    entity types and `creators` is not among them; the table holds no creator
--    rows), and CreatorController imports ActivityLogger and never calls it —
--    creator-side edits are entirely unaudited. So there was genuinely no
--    counter, and no near-miss to reuse.
--
-- Everything here is additive. Nothing is dropped, nothing is renamed, the
-- seeds are ON DUPLICATE KEY and re-running the file is a no-op.
-- ---------------------------------------------------------------------


-- =====================================================================
-- 1. The scoring-section catalogue
--
-- Ten sections, and the ten keys are not invented here: they are exactly the
-- keys already in `creator.completion_weights`
--   {"basic":15,"contact":10,"social":15,"portfolio":15,"kyc":15,"bio":5,
--    "gallery":5,"pricing":10,"categories":5,"verification":5}
-- Choosing any other spelling would have silently orphaned that setting, so
-- these ten slugs were not free to change.
--
-- It is a taxonomy because the apps have to render a progress bar with a
-- label, an icon and an order for each segment, and because an operator will
-- eventually want an eleventh section. Adding one is then a term plus a weight
-- plus some rules — no release. TaxonomyService serves every active taxonomy
-- through /app/taxonomies with an ETag, so this reaches the clients with no
-- code at all.
--
-- WHY THE KEY IS `completion_section` AND NOT `profile_section`.
-- Migration 038, landing in the same batch, seeds `profile_section` with
-- seventeen finer-grained terms — username, display_name, headline, photos,
-- location, languages, availability, documents and so on — because a lock and
-- a review decision are taken per FIELD. A progress bar cannot have seventeen
-- segments and this file's slugs are pinned to a settings row that already
-- exists, so the two lists cannot be merged without breaking one module:
-- collapsing 038's would make locks uselessly coarse, expanding this one would
-- orphan `creator.completion_weights`. Sharing the key would be worse than
-- either — both migrations seed with ON DUPLICATE KEY UPDATE, so whichever ran
-- second would silently overwrite the other's labels, icons and metadata on
-- the eight values they happen to share.
--
-- They are therefore two lists with an explicit mapping rather than two lists
-- that pretend to be one: `metadata.review_sections` names the 038 terms each
-- scoring section covers, so "which locks affect the basic-details bar" is a
-- lookup and not a guess in code.
--
-- `metadata.weight_setting` points back at the JSON path inside the settings
-- row that governs the section's share, so an admin screen can render the
-- weight beside the section without hardcoding the mapping.
-- `metadata.lockable` marks the sections `creator_profile_locks` may target —
-- that column was left free-text in 029 precisely so a list like this could
-- arrive later.
-- =====================================================================

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('completion_section', 'Profile completion sections',
        'The ten segments of the profile completion bar. Weights live in the creator.completion_weights setting, the checks in creator_profile_rules, and metadata.review_sections maps each one onto the finer profile_section terms used for locks and review.',
        1, 1, 1, 62, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`metadata`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'basic' AS `value`, 'Basic details' AS `label`,
           'Name, handle, headline, location and languages.' AS `description`,
           'badge' AS `icon`, '#1D4ED8' AS `color_hex`,
           '{"weight_setting":"creator.completion_weights.basic","lockable":true,"review_sections":["username","display_name","headline","location","languages"]}' AS `metadata`, 10 AS `sort_order` UNION ALL
    SELECT 'contact', 'Contact details', 'Email and mobile number, and whether each is confirmed.',
           'mail', '#0891B2', '{"weight_setting":"creator.completion_weights.contact","lockable":true,"review_sections":["contact"]}', 20 UNION ALL
    SELECT 'social', 'Social media', 'Linked accounts across the networks in the social_platform taxonomy.',
           'link', '#0EA5E9', '{"weight_setting":"creator.completion_weights.social","lockable":true,"review_sections":["social"]}', 30 UNION ALL
    SELECT 'portfolio', 'Portfolio', 'Published work samples a brand can judge the creator on.',
           'grid', '#7C3AED', '{"weight_setting":"creator.completion_weights.portfolio","lockable":true,"review_sections":["portfolio"]}', 40 UNION ALL
    SELECT 'kyc', 'KYC', 'Identity and document checks, held in the KYC module.',
           'shield', '#059669', '{"weight_setting":"creator.completion_weights.kyc","lockable":false,"review_sections":["documents"]}', 50 UNION ALL
    SELECT 'bio', 'Bio', 'The long-form about text.',
           'file', '#475569', '{"weight_setting":"creator.completion_weights.bio","lockable":true,"review_sections":["bio"]}', 60 UNION ALL
    SELECT 'gallery', 'Gallery', 'Profile photo, cover image and uploaded media.',
           'image', '#DB2777', '{"weight_setting":"creator.completion_weights.gallery","lockable":true,"review_sections":["photos","gallery"]}', 70 UNION ALL
    SELECT 'pricing', 'Pricing', 'Price range and the packages a brand can book.',
           'tag', '#B45309', '{"weight_setting":"creator.completion_weights.pricing","lockable":true,"review_sections":["pricing"]}', 80 UNION ALL
    SELECT 'categories', 'Categories', 'Primary category and sub-categories.',
           'folder', '#334155', '{"weight_setting":"creator.completion_weights.categories","lockable":true,"review_sections":["categories","skills"]}', 90 UNION ALL
    SELECT 'verification', 'Verification', 'Badges held from the verification_badge taxonomy.',
           'verified', '#059669', '{"weight_setting":"creator.completion_weights.verification","lockable":false,"review_sections":["verification"]}', 100
  ) v
 WHERE t.`key` = 'completion_section'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`),
  `metadata`    = VALUES(`metadata`);


-- =====================================================================
-- 2. Profile visibility — deliberately NOT built here
--
-- The brief lists public/private profile visibility among this module's admin
-- controls, and both halves of it already exist by the time this file runs:
--   * `creator.default_profile_visibility` (seed 031) is the operator default.
--   * `creators`.`is_public` arrives in migration 039, which owns the public
--     profile and defines live as status='approved' AND is_public=1 AND
--     deleted_at IS NULL, with `idx_creators_public` behind it.
-- A `profile_visibility` column here would be a second answer to a question
-- that already has one, and the two would disagree the first time either was
-- written alone. The gate below reads 039's flag; nothing is added.
--
-- The only thing this module needs on `creators` is an index. The admin list's
-- real query is "approved creators below N percent, worst
-- first". It filters on status and sorts on the score, and there is no index
-- covering that pair — `idx_creators_status` stops at (status, deleted_at).
-- Cheap now at 47 rows; the point is that it stays cheap at 47,000.
ALTER TABLE `creators`
  ADD KEY IF NOT EXISTS `idx_creators_completion` (`status`, `profile_score`);


-- =====================================================================
-- 3. The rules
--
-- One row per check. Sixty-odd rows describe what "a complete profile" means,
-- and an operator can add the sixty-first without a release — including for a
-- column a LATER migration adds, which is the whole point: the brief's "no
-- profile data or verification logic should be hardcoded" is unmeetable if the
-- checks themselves are PHP.
--
-- HOW A RULE IS EVALUATED, since the columns only make sense together:
--   source_scope   which id fills source_key — the creator's own id, or the
--                  id of the user who owns the profile
--   source_table   where to look
--   source_key     the column in that table carrying the id above
--   source_column  the column to test (null for the row-count checks)
--   source_filter  extra conditions as a JSON object, {"col":value}, where an
--                  array means IN (...) and null means IS NULL. This is how a
--                  count is narrowed to active, undeleted rows — and why it is
--                  a JSON object of column/value pairs rather than a SQL
--                  fragment: the evaluator binds every value as a parameter
--                  and checks every identifier against information_schema
--                  before it builds the query. An operator-supplied string is
--                  never concatenated into SQL.
--   check_type     what to do with it, see below
--   threshold      the number the check compares against
--   threshold_setting  a settings row that OVERRIDES threshold when present,
--                  written as group.key. This is what keeps
--                  creator.min_portfolio_items the single place that number
--                  lives, rather than copying it into a rule that then drifts.
--
-- check_type is VARCHAR, and it is deliberately NOT a taxonomy even though the
-- house rule says catalogues belong in one. Every value needs an evaluator in
-- code; a term an operator invented would produce a rule that silently never
-- passes and quietly holds a creator below a gate forever. The vocabulary
-- belongs to the application, exactly as `creator_profile_locks`.`section` did
-- before this migration published a list for it. The seven that exist:
--   filled          non-null and not an empty string
--   not_null        the column is set at all (used for the verified-at stamps)
--   flag            the column is truthy
--   min_length      CHAR_LENGTH(column) >= threshold
--   min_value       the numeric column >= threshold
--   min_count       rows in source_table matching source_filter >= threshold
--   json_min_count  the JSON array in column holds >= threshold entries
-- =====================================================================

CREATE TABLE IF NOT EXISTS `creator_profile_rules` (
  `id`                SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- A `completion_section` term value — one of the ten scoring sections, not
  -- one of 038's finer lock/review sections. Not a foreign key to taxonomy_terms: 029
  -- set the precedent of storing the slug rather than the term id, because a
  -- slug survives a term being deleted and re-created and reads plainly in a
  -- query. A rule whose section no longer exists is inert, not broken.
  `section`           VARCHAR(40) NOT NULL,
  `rule_key`          VARCHAR(60) NOT NULL COMMENT 'Unique within the section. Appears in creator_profile_completion.missing.',
  `label`             VARCHAR(150) NOT NULL COMMENT 'What the creator sees in the finish-your-profile checklist',
  `description`       VARCHAR(500) NULL COMMENT 'The hint underneath it',
  `check_type`        VARCHAR(30) NOT NULL DEFAULT 'filled',
  `source_scope`      VARCHAR(10) NOT NULL DEFAULT 'creator' COMMENT 'creator | user — which id source_key is matched against',
  `source_table`      VARCHAR(64) NOT NULL DEFAULT 'creators',
  `source_key`        VARCHAR(64) NOT NULL DEFAULT 'creator_id',
  `source_column`     VARCHAR(64) NULL,
  `source_filter`     LONGTEXT NULL COMMENT 'JSON object of extra conditions; array = IN, null = IS NULL',
  `threshold`         INT UNSIGNED NOT NULL DEFAULT 1,
  `threshold_setting` VARCHAR(120) NULL COMMENT 'group.key of a settings row that overrides threshold',
  -- Share within the section, never a share of 100. The section's own share
  -- comes from creator.completion_weights, and duplicating it here is the one
  -- mistake that would give this module two sources of truth.
  `weight`            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  -- A mandatory rule blocks submission and approval however high the
  -- percentage climbs, so a creator cannot pad the score with optional extras
  -- and skip their phone number.
  `is_mandatory`      TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`         TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`        SMALLINT NOT NULL DEFAULT 0,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cpr_rule` (`section`, `rule_key`),
  KEY `idx_cpr_active` (`is_active`, `section`, `sort_order`),
  KEY `idx_cpr_mandatory` (`is_mandatory`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 4. The stored breakdown
--
-- One row per creator per section. `creators`.`profile_score` remains the
-- overall number — the weighted roll-up of these rows — and is not repeated
-- here.
--
-- `weight` is copied in at compute time rather than read from settings on
-- render, so a widget can draw the whole bar from this table alone and so the
-- stored total is reproducible from the stored parts. When an operator
-- re-weights a section the copy is stale until the next recompute — the same
-- staleness `percent` already carries, cleared by the same sweep, and visible
-- in `computed_at`.
--
-- `missing` carries the rule_keys not yet satisfied. That list IS the
-- "complete your profile" checklist the app renders; without it the client
-- would have to re-derive which of the sixty checks failed, which is the
-- hardcoding this module exists to remove.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `creator_profile_completion` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`        BIGINT UNSIGNED NOT NULL,
  `section`           VARCHAR(40) NOT NULL COMMENT 'completion_section term value',
  `percent`           TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 WITHIN this section',
  `weight`            SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Section share used at compute time, from creator.completion_weights',
  `rules_total`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `rules_met`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Not derivable from `percent`: a mandatory rule may carry a small weight,
  -- so a section can sit at 95% and still be unsubmittable.
  `mandatory_missing` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `missing`           LONGTEXT NULL COMMENT 'JSON array of unmet rule_keys, in sort order',
  `computed_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cpc_creator_section` (`creator_id`, `section`),
  -- "Which approved creators have not finished KYC" — the admin question this
  -- table was stored rather than computed for.
  KEY `idx_cpc_section_percent` (`section`, `percent`),
  -- Lets the nightly sweep take the stalest rows first and stop on a budget.
  KEY `idx_cpc_stale` (`computed_at`),
  CONSTRAINT `fk_cpc_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 5. Feature gates
--
-- `feature_key` is a free string with no catalogue behind it, on purpose: the
-- brief asks that an operator be able to gate a feature that does not exist
-- yet, and the row they type IS that act. A gate for an unknown key is inert
-- until code asks about it, which is the correct failure mode — the opposite
-- arrangement (code asking about a key with no gate) is the one that must not
-- silently lock people out, so an absent gate means ungated.
--
-- `required_badges` and `required_sections` are JSON arrays rather than child
-- tables. They are short lists always read whole with their gate and never
-- joined across gates; MariaDB 10.4's JSON_CONTAINS answers the rare "which
-- gates need kyc_verified" without a table that would otherwise exist only to
-- be selected in full every time.
--
-- `enforcement` is VARCHAR for the same reason as check_type above — each mode
-- is behaviour in code, not a list an operator extends.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `creator_feature_gates` (
  `id`                     SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `feature_key`            VARCHAR(80) NOT NULL,
  `name`                   VARCHAR(150) NOT NULL,
  `description`            VARCHAR(500) NULL,
  `min_completion_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Compared against creators.profile_score',
  `required_sections`      LONGTEXT NULL COMMENT 'JSON array of completion_section values that must be at 100%',
  `required_badges`        LONGTEXT NULL COMMENT 'JSON array of verification_badge values that must be held and active',
  -- Null means any status. 'approved' is the live state on this platform;
  -- there is no 'active' in the creators status enum.
  `required_status`        VARCHAR(30) NULL COMMENT 'creators.status the profile must have reached',
  `enforcement`            VARCHAR(20) NOT NULL DEFAULT 'block' COMMENT 'block = refuse, warn = allow and nag, hide = omit from the UI',
  `locked_message`         VARCHAR(300) NULL COMMENT 'What the app shows on the locked state; falls back to a generic string',
  `is_active`              TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`             SMALLINT NOT NULL DEFAULT 0,
  `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cfg_feature` (`feature_key`),
  KEY `idx_cfg_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Per-creator exceptions.
--
-- Not in the brief, added because the alternative is worse: the first time
-- support needs to let a hand-held creator take a booking at 55%, or to pull
-- payouts from someone gaming the score, the only lever without this table is
-- editing the gate for everybody. Both directions are recorded with a reason
-- and an author.
--
-- No foreign key on `feature_key`. A gate is operator data that may be retired
-- and re-added, and an exception granted to a creator must not vanish with it;
-- the evaluator ignores overrides whose feature has no gate.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `creator_feature_overrides` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`  BIGINT UNSIGNED NOT NULL,
  `feature_key` VARCHAR(80) NOT NULL,
  `mode`        VARCHAR(10) NOT NULL DEFAULT 'grant' COMMENT 'grant = unlock regardless of completion, deny = withhold regardless',
  `reason`      VARCHAR(500) NULL,
  `granted_by`  INT UNSIGNED NULL COMMENT 'admins.id. No FK, matching creator_badges.awarded_by.',
  -- Null never lapses. A temporary grant is the safer default for support to
  -- reach for, so the column exists rather than being bolted on later.
  `expires_at`  DATETIME NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cfo_creator_feature` (`creator_id`, `feature_key`),
  KEY `idx_cfo_feature` (`feature_key`, `mode`),
  KEY `idx_cfo_expiry` (`expires_at`),
  CONSTRAINT `fk_cfo_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 6. Handle changes
--
-- Rows, not a counter column on `creators`. A counter answers only one of the
-- three questions the settings already ask:
--   username_change_limit          -> COUNT(*)
--   username_change_cooldown_days  -> MAX(created_at)
--   and the one nobody wrote down: what was the old handle?
-- The third matters most. A released handle is an impersonation vector — hand
-- @famouscreator to the next claimant the moment it is freed and the platform
-- has manufactured a fraud — and this is the only record that it was ever
-- held, and by whom. A counter would have to be joined to something for that,
-- and that something is this table.
--
-- WHY 038'S CHANGE QUEUE IS NOT THIS.  `creator_change_fields` does record a
-- proposed handle with its old and new value, and it was read closely before
-- this table was kept. Two things stop it standing in:
--   * It fills only while `creator.approve_profile_edits` is on and 'username'
--     is in the reviewed set. Turn review off for a week and every creator
--     renames themselves for free, with no record to restore. A hard limit
--     cannot depend on an unrelated setting being enabled, and this ledger is
--     written when a change is APPLIED, whichever path applied it.
--   * A request is an intent with a verdict; four rejected requests are not
--     four changes, and the limit counts changes.
--   * `old_value` is a LONGTEXT with no index on it, so "who used to hold
--     @foo" is a full scan there and an index lookup here.
-- The two are complementary: 038 records who asked and what was decided, this
-- records what actually happened and which name was vacated.
--
-- CASCADE, matching `creator_badge_events` from 029. It is an audit trail, but
-- a creator-scoped one with no financial standing; the house rule protects
-- money and settlement records, not profile history.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `creator_handle_history` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id` BIGINT UNSIGNED NOT NULL,
  `old_handle` VARCHAR(60) NOT NULL,
  `new_handle` VARCHAR(60) NOT NULL,
  -- Same vocabulary as creator_badge_events.actor_type, deliberately: an
  -- operator reading one trail should not have to learn a second one.
  `actor_type` ENUM('creator','admin','system') NOT NULL DEFAULT 'creator',
  `actor_id`   BIGINT UNSIGNED NULL,
  `actor_name` VARCHAR(150) NULL,
  `reason`     VARCHAR(255) NULL,
  `ip_address` VARBINARY(16) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Both the count and the cooldown read this index.
  KEY `idx_chh_creator` (`creator_id`, `created_at`),
  -- "Who used to hold this handle" — the impersonation check, and the lookup a
  -- redirect from the old public URL would use.
  KEY `idx_chh_old` (`old_handle`),
  CONSTRAINT `fk_chh_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 7. Default rules
--
-- Enough for the system to behave sensibly on the day it is applied, and
-- aligned by hand with what is already configured elsewhere so nothing
-- contradicts anything on day one:
--   * is_mandatory mirrors creator.mandatory_fields exactly
--     (display_name, handle, headline, about, primary_category_id, city,
--     languages) plus creator.required_verifications (email, mobile),
--     require_portfolio=1 and kyc.creator_mandatory=1.
--   * the bio threshold is 80 characters — the number
--     CreatorService::computeProfileScore() has been using all along. The
--     score a creator sees should not jump the day this ships.
--   * every threshold that already exists as a setting points at the setting
--     instead of restating it.
-- The profile photo is left optional despite being the single highest-impact
-- field, because creator.mandatory_fields does not list it and this seed must
-- not quietly overrule an operator's configured list. It is one flag away.
--
-- ON DUPLICATE KEY updates the presentation only. Weights, thresholds and the
-- mandatory flag are exactly what an operator will have tuned, and a re-run of
-- the installer must not undo their work.
-- =====================================================================

INSERT INTO `creator_profile_rules`
  (`section`, `rule_key`, `label`, `description`, `check_type`, `source_scope`, `source_table`, `source_key`, `source_column`, `source_filter`, `threshold`, `threshold_setting`, `weight`, `is_mandatory`, `is_active`, `sort_order`, `created_at`)
VALUES

-- --- basic ------------------------------------------------------------
('basic', 'display_name', 'Add your name', 'The name brands will see on your profile and in search.',
 'filled', 'creator', 'creators', 'id', 'display_name', NULL, 1, NULL, 3, 1, 1, 10, NOW()),
('basic', 'handle', 'Choose your @handle', 'Your public profile address.',
 'filled', 'creator', 'creators', 'id', 'handle', NULL, 1, NULL, 1, 1, 1, 20, NOW()),
('basic', 'headline', 'Add a headline', 'One line describing what you do, for example Lifestyle Blogger.',
 'filled', 'creator', 'creators', 'id', 'headline', NULL, 1, NULL, 3, 1, 1, 30, NOW()),
('basic', 'city', 'Add your city', 'Brands filter by location more than by anything else.',
 'filled', 'creator', 'creators', 'id', 'city', NULL, 1, NULL, 2, 1, 1, 40, NOW()),
('basic', 'state', 'Add your state', NULL,
 'filled', 'creator', 'creators', 'id', 'state', NULL, 1, NULL, 1, 0, 1, 50, NOW()),
('basic', 'languages', 'List the languages you create in', 'At least one.',
 'json_min_count', 'creator', 'creators', 'id', 'languages', NULL, 1, NULL, 3, 1, 1, 60, NOW()),
('basic', 'gender', 'Add your gender', 'Used by brand filters; you can choose not to say.',
 'filled', 'creator', 'creators', 'id', 'gender', NULL, 1, NULL, 1, 0, 1, 70, NOW()),
('basic', 'date_of_birth', 'Add your date of birth', 'Never shown publicly. Used for age-band filters and eligibility.',
 'not_null', 'creator', 'creators', 'id', 'date_of_birth', NULL, 1, NULL, 1, 0, 1, 80, NOW()),

-- --- contact ----------------------------------------------------------
-- source_scope 'user': these live on the account, not the profile row.
('contact', 'email', 'Add an email address', NULL,
 'filled', 'user', 'users', 'id', 'email', NULL, 1, NULL, 2, 1, 1, 10, NOW()),
('contact', 'email_verified', 'Confirm your email address', 'We send booking and payout notices there.',
 'not_null', 'user', 'users', 'id', 'email_verified_at', NULL, 1, NULL, 3, 1, 1, 20, NOW()),
('contact', 'phone', 'Add a mobile number', NULL,
 'filled', 'user', 'users', 'id', 'phone', NULL, 1, NULL, 2, 1, 1, 30, NOW()),
('contact', 'phone_verified', 'Confirm your mobile number', 'Required before withdrawals.',
 'not_null', 'user', 'users', 'id', 'phone_verified_at', NULL, 1, NULL, 3, 1, 1, 40, NOW()),

-- --- social -----------------------------------------------------------
('social', 'linked_account', 'Link a social account', 'Your reach is read from the accounts you link.',
 'min_count', 'creator', 'creator_social_accounts', 'creator_id', NULL, NULL, 1, 'creator.min_social_accounts', 4, 1, 1, 10, NOW()),
('social', 'primary_account', 'Pick your main account', 'The one shown first on your profile.',
 'min_count', 'creator', 'creator_social_accounts', 'creator_id', NULL, '{"is_primary":1}', 1, NULL, 2, 0, 1, 20, NOW()),
-- Optional by default and governed by creator.require_verified_social; an
-- operator turning that setting on flips this row's is_mandatory too.
('social', 'verified_account', 'Verify a linked account', 'Verified reach ranks higher in brand search.',
 'min_count', 'creator', 'creator_social_accounts', 'creator_id', NULL, '{"is_verified":1}', 1, NULL, 1, 0, 1, 30, NOW()),

-- --- portfolio --------------------------------------------------------
-- `creator_portfolio` holds both published work and gallery uploads. They are
-- separated by `collection` (migration 035), NOT by media_kind.
--
-- This originally filtered on media_kind — post/reel meaning "portfolio",
-- photo/video meaning "gallery". That conflated two different things: a photo
-- is a media TYPE, and a photo can perfectly well be published work. It also
-- broke against 035 itself, which backfills every one of the 300 existing rows
-- to collection='portfolio' while adding six further kinds (carousel, short,
-- live, audio, document, case_study) that appeared in neither filter and so
-- scored in neither section. The two migrations ship together, so as written
-- the completion score and the profile sections disagreed about the same rows.
('portfolio', 'work_samples', 'Publish your work', 'Brands shortlist on the work, not the bio.',
 'min_count', 'creator', 'creator_portfolio', 'creator_id', NULL, '{"collection":["portfolio","showreel","press"],"is_active":1,"deleted_at":null}', 3, 'creator.min_portfolio_items', 4, 1, 1, 10, NOW()),
('portfolio', 'featured_sample', 'Feature your best piece', 'It leads your profile.',
 'min_count', 'creator', 'creator_portfolio', 'creator_id', NULL, '{"is_featured":1,"is_active":1,"deleted_at":null}', 1, NULL, 1, 0, 1, 20, NOW()),

-- --- kyc --------------------------------------------------------------
-- kyc_profiles is keyed on user_id, not creator_id, and carries both brand and
-- creator applications — hence the scope and the kind filter.
('kyc', 'kyc_submitted', 'Start your KYC', 'Identity checks take a day or two, so start early.',
 'min_count', 'user', 'kyc_profiles', 'user_id', NULL, '{"kind":"creator","deleted_at":null}', 1, NULL, 1, 0, 1, 10, NOW()),
('kyc', 'kyc_approved', 'Complete your KYC', 'Required before you can be paid.',
 'min_count', 'user', 'kyc_profiles', 'user_id', NULL, '{"kind":"creator","status":"approved","deleted_at":null}', 1, NULL, 4, 1, 1, 20, NOW()),

-- --- bio --------------------------------------------------------------
-- 80 characters is the threshold computeProfileScore has always used. The
-- second rule is what a weighted section is for: a fuller bio earns more
-- without a longer one ever being demanded.
('bio', 'about', 'Write your bio', 'At least eighty characters — who you are and who you make things for.',
 'min_length', 'creator', 'creators', 'id', 'about', NULL, 80, NULL, 3, 1, 1, 10, NOW()),
('bio', 'about_detailed', 'Go into detail', 'Profiles with a fuller bio convert better.',
 'min_length', 'creator', 'creators', 'id', 'about', NULL, 300, NULL, 1, 0, 1, 20, NOW()),

-- --- gallery ----------------------------------------------------------
('gallery', 'profile_photo', 'Add a profile photo', 'A face gets more brand enquiries than a logo.',
 'filled', 'user', 'users', 'id', 'avatar_url', NULL, 1, NULL, 3, 0, 1, 10, NOW()),
('gallery', 'cover_image', 'Add a cover image', 'The banner across the top of your profile.',
 'filled', 'creator', 'creators', 'id', 'cover_image_url', NULL, 1, NULL, 2, 0, 1, 20, NOW()),
-- Filtered by `collection`, matching the portfolio rule above and the
-- discriminator migration 035 establishes. Anything the creator files under
-- the gallery or behind-the-scenes collections counts here, whatever media
-- kind it happens to be.
('gallery', 'gallery_media', 'Upload to your gallery', 'Photos and videos that are not tied to a campaign.',
 'min_count', 'creator', 'creator_portfolio', 'creator_id', NULL, '{"collection":["gallery","behind_the_scenes"],"is_active":1,"deleted_at":null}', 1, NULL, 1, 0, 1, 30, NOW()),

-- --- pricing ----------------------------------------------------------
('pricing', 'price_min', 'Set your starting price', 'Profiles without a price are filtered out of most brand searches.',
 'min_value', 'creator', 'creators', 'id', 'price_min', NULL, 1, NULL, 3, 1, 1, 10, NOW()),
('pricing', 'price_max', 'Set your upper price', 'Gives brands a range to plan against.',
 'min_value', 'creator', 'creators', 'id', 'price_max', NULL, 1, NULL, 1, 0, 1, 20, NOW()),
('pricing', 'package', 'Create a package', 'A named deliverable a brand can book directly.',
 'min_count', 'creator', 'creator_packages', 'creator_id', NULL, '{"is_active":1,"deleted_at":null}', 1, NULL, 2, 0, 1, 30, NOW()),

-- --- categories -------------------------------------------------------
('categories', 'primary_category', 'Choose your main category', 'Decides where you appear in discovery.',
 'not_null', 'creator', 'creators', 'id', 'primary_category_id', NULL, 1, NULL, 3, 1, 1, 10, NOW()),
('categories', 'sub_categories', 'Add sub-categories', 'Up to five, so you match more briefs.',
 'min_count', 'creator', 'creator_categories', 'creator_id', NULL, NULL, 1, NULL, 1, 0, 1, 20, NOW()),

-- --- verification -----------------------------------------------------
-- Badges are counted out of creator_badges rather than re-derived, so the
-- badge service seeded in 029 stays the only thing that decides who holds one.
('verification', 'email_badge', 'Email verified', NULL,
 'min_count', 'creator', 'creator_badges', 'creator_id', NULL, '{"badge":"email_verified","status":"active"}', 1, NULL, 2, 1, 1, 10, NOW()),
('verification', 'mobile_badge', 'Mobile verified', NULL,
 'min_count', 'creator', 'creator_badges', 'creator_id', NULL, '{"badge":"mobile_verified","status":"active"}', 1, NULL, 2, 1, 1, 20, NOW()),
('verification', 'identity_badge', 'Identity verified', 'Awarded once your government ID clears.',
 'min_count', 'creator', 'creator_badges', 'creator_id', NULL, '{"badge":"identity_verified","status":"active"}', 1, NULL, 2, 0, 1, 30, NOW()),
('verification', 'creator_badge', 'Verified creator', 'Awarded by the Zuste+ team.',
 'min_count', 'creator', 'creator_badges', 'creator_id', NULL, '{"badge":"creator_verified","status":"active"}', 1, NULL, 1, 0, 1, 40, NOW())

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`);


-- =====================================================================
-- 8. Default gates
--
-- Exactly the four keys already in `creator.feature_unlock_thresholds`, and
-- the percentages are READ OUT OF that setting rather than restated, so an
-- operator who has already moved accept_bookings to 75 keeps 75. The COALESCE
-- covers a malformed or missing JSON with the seeded default.
--
-- Why the setting survives at all now that gates exist: it is is_public=1 and
-- the apps read it to render "unlocks at 70%" without another endpoint. It
-- cannot express badge or section requirements, which is what the table adds,
-- so the table is what the server enforces. Seeding from the setting is what
-- keeps the two in agreement on day one; keeping them in agreement afterwards
-- is the admin screen's job — it should write the setting from the gates.
--
-- No fifth gate is invented here. A gate the setting does not know about would
-- be a gate the app cannot explain to the creator, and inventing one to look
-- thorough is exactly the drift this comment is trying to prevent.
--
-- `required_sections` names `completion_section` terms. And appear_in_search
-- is a gate, not a switch: it composes with `creators`.`is_public` from 039,
-- which the creator owns. A private profile stays out of search at 100%, and
-- the gate never overrides that — earning discoverability and choosing it are
-- two different questions.
-- =====================================================================

INSERT INTO `creator_feature_gates`
  (`feature_key`, `name`, `description`, `min_completion_percent`, `required_sections`, `required_badges`, `required_status`, `enforcement`, `locked_message`, `is_active`, `sort_order`, `created_at`)
VALUES

('accept_bookings', 'Accept bookings',
 'Receive and accept booking requests from brands.',
 COALESCE(CAST(JSON_VALUE((SELECT `value` FROM `settings` WHERE `group_key` = 'creator' AND `setting_key` = 'feature_unlock_thresholds') , '$.accept_bookings') AS UNSIGNED), 60),
 '["basic","contact","pricing"]',
 '["email_verified","mobile_verified"]',
 'approved', 'block',
 'Finish your profile to start accepting bookings.', 1, 10, NOW()),

('appear_in_search', 'Appear in brand search',
 'Be returned by the brand-side creator search and category listings.',
 COALESCE(CAST(JSON_VALUE((SELECT `value` FROM `settings` WHERE `group_key` = 'creator' AND `setting_key` = 'feature_unlock_thresholds') , '$.appear_in_search') AS UNSIGNED), 70),
 '["basic","bio","categories"]',
 NULL,
 'approved', 'hide',
 'Complete your profile to be discoverable by brands.', 1, 20, NOW()),

('apply_to_campaigns', 'Apply to campaigns',
 'Submit applications to open brand campaigns.',
 COALESCE(CAST(JSON_VALUE((SELECT `value` FROM `settings` WHERE `group_key` = 'creator' AND `setting_key` = 'feature_unlock_thresholds') , '$.apply_to_campaigns') AS UNSIGNED), 75),
 '["portfolio","pricing"]',
 '["email_verified","mobile_verified"]',
 'approved', 'block',
 'Add your work and pricing before applying to campaigns.', 1, 30, NOW()),

('featured_eligible', 'Eligible to be featured',
 'Can be picked up by the featured-creator rotation. Slots and duration stay with creator.featured_max_slots and creator.featured_duration_days.',
 COALESCE(CAST(JSON_VALUE((SELECT `value` FROM `settings` WHERE `group_key` = 'creator' AND `setting_key` = 'feature_unlock_thresholds') , '$.featured_eligible') AS UNSIGNED), 90),
 '["basic","bio","gallery","portfolio","pricing","categories"]',
 '["kyc_verified"]',
 'approved', 'hide',
 'Featured placement opens up at a near-complete profile.', 1, 40, NOW())

ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `description` = VALUES(`description`);


-- =====================================================================
-- 9. The nightly recompute
--
-- Without a row here the admin System > Jobs screen does not list it and an
-- operator has no way to know it should be scheduled — the same reason 034
-- registered creators:badges.
--
-- 00:55, after creators:badges at 00:45, which is after creators:refresh at
-- 00:30. The order is forced, not stylistic: the verification section counts
-- rows in `creator_badges`, and badges are decided from the rating and
-- collaboration figures creators:refresh recomputes. Running this first would
-- score today's profiles against yesterday's badges against the day before's
-- ratings.
-- =====================================================================

INSERT INTO `scheduled_jobs` (`job_key`, `name`, `description`, `schedule_hint`, `is_enabled`)
VALUES (
    'creators:completion',
    'Creator profile completion',
    'Re-evaluates every profile against creator_profile_rules, rewrites the per-section breakdown and creators.profile_score, and notifies creators who have crossed a feature gate.',
    '55 0 * * *',
    1
)
ON DUPLICATE KEY UPDATE
    `name`          = VALUES(`name`),
    `description`   = VALUES(`description`),
    `schedule_hint` = VALUES(`schedule_hint`);
