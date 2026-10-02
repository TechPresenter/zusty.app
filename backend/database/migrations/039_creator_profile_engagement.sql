-- ---------------------------------------------------------------------
-- 039 — Public profile visibility, sharing, and engagement events
--
-- This migration is deliberately small. The brief for this module lists
-- eleven things to "track", and nine of them were already modelled before
-- this file existed. Every one of those was checked against the live schema,
-- not against the brief. What follows is the audit, because knowing what NOT
-- to build is the substance of this change.
--
-- ALREADY EXISTS — NO NEW SCHEMA WRITTEN FOR ANY OF THESE:
--
--   profile views          `creator_profile_views` — 5,481 rows, already
--                          carries creator, viewer, source and a DATE column
--                          keyed (creator_id, viewed_on) for daily rollup.
--   public profile visits  the same table. A visit to the public web profile
--                          is a SOURCE of a view, not a different kind of
--                          event; see section 2, which makes that source
--                          expressible instead of adding a second table that
--                          would have had identical columns.
--   login history          `login_history` — actor, identifier, outcome,
--                          auth_method, ip_address, country, created_at.
--   device history         `login_history` again. It already stores
--                          `user_agent`, `device_type` and `is_new_device`.
--                          A separate device table would duplicate three
--                          columns that are already written on every login,
--                          so none is created here.
--   profile edit history   `activity_logs`. Its `properties` column is
--                          documented as a before/after diff payload and is
--                          indexed by (subject_type, subject_id), which is
--                          exactly "show me every edit to creator 42".
--   admin actions          `activity_logs` again — `actor_type` already
--                          distinguishes admin from user, system and cron.
--   activity logs          `activity_logs`.
--   verification history   `creator_badge_events` — actor, action,
--                          from_status, to_status, note, indexed by creator
--                          and by badge over time.
--   preview public profile  no schema at all. Preview is the owner reading
--                          their own profile with the visibility gate below
--                          skipped. That is a branch in a query, not a table.
--   profile QR code        no schema. See section 1: `creators`.`handle` is
--                          already UNIQUE and NOT NULL, so the share URL is a
--                          pure function of a column that exists. A QR image
--                          is a render of that URL and storing it would mean
--                          storing a derivative that can go stale.
--
-- THE THREE REAL GAPS, and only these:
--
--   1. There is no way to say "my profile is not public". `creators`.`status`
--      gates review, not visibility; an approved creator has no switch. The
--      brief asks for enable/disable and nothing modelled it.
--   2. `creator_profile_views`.`source` is an ENUM, which cannot express a
--      public-web visit or a QR scan, and cannot be extended by an operator.
--   3. Shares, portfolio downloads and social link clicks are recorded
--      nowhere. These are the only genuinely unmodelled events in the list.
-- ---------------------------------------------------------------------


-- =====================================================================
-- 1. The public/private switch
-- =====================================================================
--
-- `status` and `is_public` answer different questions and must not be
-- collapsed into one another. `status` is the platform's verdict on the
-- profile — draft, pending_review, approved, rejected, suspended — and only
-- an administrator moves it. `is_public` is the creator's own choice about
-- whether an approved profile is currently shown. A creator taking their
-- profile down for a month must not land back in the review queue when they
-- put it up again, which is precisely what would happen if this were modelled
-- as a sixth `status` value.
--
-- A profile is live when, and only when:
--     status = 'approved' AND is_public = 1 AND deleted_at IS NULL
--
-- DEFAULT 1 is the correct backfill for every existing row rather than a
-- convenience: with no flag in the schema today, every approved profile is
-- already public, so 1 states what is true right now and no UPDATE is needed.

ALTER TABLE `creators`
    ADD COLUMN IF NOT EXISTS `is_public` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Creator-controlled visibility. Live requires status=approved AND is_public=1 AND deleted_at IS NULL.'
        AFTER `availability_status`;

-- The public directory and every public profile lookup will now filter on
-- three columns. `idx_creators_status` covers only the first and last of
-- them, so without this index the flag turns the hottest read on the site
-- into a filtered scan the moment a meaningful number of creators go private.
--
-- This index has `idx_creators_status` as a leading-column prefix and could
-- replace it, but the house rule is that nothing existing is dropped, so the
-- older index is left in place and simply stops being chosen by the planner.

ALTER TABLE `creators`
    ADD INDEX IF NOT EXISTS `idx_creators_public` (`status`, `is_public`, `deleted_at`);


-- ---------------------------------------------------------------------
-- Share links and QR codes: nothing is added, and here is why.
--
-- `creators`.`handle` is VARCHAR(60), NOT NULL, and carries the UNIQUE key
-- `uq_creators_handle`. The share URL and the QR payload are therefore both
-- derivable — https://zustee.com/@{handle} — with no second identifier to
-- keep in step. A `share_slug` column would be a duplicate of `handle` that
-- can disagree with it, and a stored `qr_image_url` would be a cached render
-- of a string we can rebuild in microseconds.
--
-- The one argument for a separate token is that a creator who renames
-- themselves invalidates every QR code already printed. That is real, but the
-- fix is a handle-history table, not a share token, and the old handle is
-- already recoverable from the before/after diff `activity_logs` records on
-- the rename. Building speculative schema for it here would be guessing.
-- ---------------------------------------------------------------------


-- =====================================================================
-- 2. Where a profile view came from, as a taxonomy
-- =====================================================================
--
-- The question this module was asked to answer was whether a "public profile
-- visit" deserves its own table. It does not — it is a view whose ORIGIN
-- differs, and `creator_profile_views` already has an origin column. A second
-- table would have carried creator_id, viewer_id, a date and a timestamp:
-- the same four columns, split so that "how many times was I looked at" needs
-- a UNION forever.
--
-- What it could not do was hold the value. `source` was
-- enum('search','category','home','direct','share','explore') and under
-- STRICT_TRANS_TABLES an out-of-enum insert is a hard error, not a silent
-- coercion — so recording a QR scan today is a 500, exactly as the social
-- platform ENUM was before migration 029 replaced it.
--
-- Same remedy as 029, for the same reason: the catalogue becomes an
-- admin-managed taxonomy served through /app/taxonomies, and the column
-- becomes a VARCHAR that stores its term values. All 5,481 existing rows keep
-- their strings untouched, and every one of those strings is seeded below.

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
-- is_system = 0 deliberately. TaxonomyController::storeTerm refuses to add a
-- term to a system list ("its options are fixed"), and update() cannot change
-- is_system, so a system taxonomy can only be extended by another migration.
-- These three lists describe themselves as operator-extensible; shipping them
-- locked would have made that description false.
VALUES ('profile_view_source', 'Profile view sources',
        'Where a creator profile view originated. Add a row to track a new acquisition path — no release required.',
        0, 0, 1, 62, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- The first six are the ENUM values that already exist in the data and are
-- restated verbatim so no stored row is left pointing at an unknown term.
-- The last two are the ones the brief asked for and the ENUM could not hold.
INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`is_default`, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'direct'   AS `value`, 'Direct'      AS `label`, 'Opened inside the app without a referring screen.' AS `description`, 'arrow-right' AS `icon`, '#64748B' AS `color_hex`, 1 AS `is_default`, 10 AS `sort_order` UNION ALL
    SELECT 'search',   'Search',     'Reached from a search result.',                      'search',   '#0891B2', 0, 20 UNION ALL
    SELECT 'category', 'Category',   'Reached from a category listing.',                   'grid',     '#7C3AED', 0, 30 UNION ALL
    SELECT 'home',     'Home',       'Reached from a home screen module.',                 'home',     '#1D4ED8', 0, 40 UNION ALL
    SELECT 'explore',  'Explore',    'Reached from the explore or discovery feed.',        'compass',  '#059669', 0, 50 UNION ALL
    SELECT 'share',    'Shared link', 'Opened from a link the creator or a user shared.',  'share',    '#B45309', 0, 60 UNION ALL
    -- The two new paths. `public` is the answer to "track public profile
    -- visits": a hit on the public web profile, by someone who may not have
    -- an account at all, which is why viewer_id on that table is nullable.
    SELECT 'public',   'Public profile', 'Opened on the public web profile, outside the app.', 'globe', '#0EA5E9', 0, 70 UNION ALL
    SELECT 'qr',       'QR code',    'Opened by scanning a profile QR code.',              'qr-code',  '#334155', 0, 80
  ) v
 WHERE t.`key` = 'profile_view_source'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`);

-- ENUM -> VARCHAR preserves every stored string exactly; nothing is rewritten
-- and nothing is lost.
--
-- 60 characters, matching `taxonomy_terms`.`value` exactly. This was 40 on the
-- claim that 40 "matches taxonomy_terms.value headroom" — the column is
-- varchar(60) and the admin validator accepts max:60, so an operator adding a
-- longer term would hit "Data too long for column" under STRICT_TRANS_TABLES:
-- precisely the failure this migration exists to remove.
ALTER TABLE `creator_profile_views`
    MODIFY COLUMN `source` VARCHAR(60) NOT NULL DEFAULT 'direct'
        COMMENT 'taxonomy_terms.value under the profile_view_source taxonomy';


-- =====================================================================
-- 3. Engagement events — shares, downloads, link clicks
-- =====================================================================
--
-- ONE TABLE, NOT THREE. `creator_profile_shares`, `creator_portfolio_downloads`
-- and `creator_social_clicks` would have been the same eight columns three
-- times over, and the creator dashboard question is always "what happened on
-- my profile this week", which across three tables is a UNION that no index
-- can serve well. The events differ only in what they name, and what they name
-- is a value — so it is a column, sourced from a taxonomy, which also means
-- the next event an operator wants to count costs a taxonomy row rather than
-- a fourth table and a fourth release.
--
-- Written to be cheap on the write path, because these fire on a tap:
--   * no UNIQUE key, so an insert never has to read first;
--   * no JSON payload column. Every question the brief asks is answered by
--     (creator, day, event, channel, target). A free-form blob on a
--     high-frequency table only ever becomes a dumping ground of data nobody
--     can query;
--   * two secondary indexes and no more, since each one is paid for on every
--     single insert.
--
-- And cheap to aggregate, because `occurred_on` is a DATE rather than a
-- derivation of `created_at`. Counting a creator-day is then a range scan on
-- `idx_cee_rollup`, and because the index carries event_type as its third
-- part, a GROUP BY event_type over a date window is answered from the index
-- alone without touching a row. This mirrors `creator_profile_views`.
-- `viewed_on`, deliberately: one convention for both event tables.

CREATE TABLE IF NOT EXISTS `creator_engagement_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`  BIGINT UNSIGNED NOT NULL,

  -- The taxonomy term value, not its id — a slug survives the term being
  -- re-created and reads plainly in a query, as in 029.
  `event_type`  VARCHAR(60) NOT NULL
      COMMENT 'taxonomy_terms.value under the engagement_event taxonomy',

  -- One qualifier column serving several vocabularies, because each event
  -- type declares its own: the term metadata carries `channel_taxonomy`, so
  -- a profile_share reads its channel from `share_channel` (whatsapp, QR,
  -- copy link) while a social_click reads its channel from the existing
  -- `social_platform` taxonomy. The taxonomy tells the client which list to
  -- offer; the database does not need to know, and a per-vocabulary column
  -- would leave most of them NULL on every row.
  `channel`     VARCHAR(60) NULL
      COMMENT 'taxonomy_terms.value; which taxonomy is named by the event type metadata',

  -- Which portfolio item was downloaded, which social account was clicked.
  -- Polymorphic, so deliberately no foreign key: the referenced table is
  -- named by the event type, not by the column. That costs nothing in
  -- integrity here because both candidate tables — creator_portfolio and
  -- creator_social_accounts — already cascade from `creators`, and so does
  -- this table, so a target can never outlive the events that point at it.
  `target_id`   BIGINT UNSIGNED NULL
      COMMENT 'Id in the table named by the event type metadata; NULL for whole-profile events',

  -- NULL for anonymous traffic, which is most of it — a share or a QR scan
  -- reaches people with no account. SET NULL rather than CASCADE on purpose:
  -- a deleted user must not silently subtract from a creator historical
  -- engagement figures.
  `actor_id`    BIGINT UNSIGNED NULL,

  `occurred_on` DATE NOT NULL COMMENT 'Event date, denormalised so daily rollups are an index range scan',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- The dashboard index: one creator, a date window, grouped by event type.
  KEY `idx_cee_rollup` (`creator_id`, `occurred_on`, `event_type`),

  -- The other direction: which portfolio item gets downloaded, which social
  -- link gets clicked, over time.
  KEY `idx_cee_target` (`event_type`, `target_id`, `occurred_on`),

  CONSTRAINT `fk_cee_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cee_actor`   FOREIGN KEY (`actor_id`)   REFERENCES `users` (`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- The event catalogue.
--
-- `metadata` carries the two things the application would otherwise have to
-- hardcode: which taxonomy fills `channel`, and which table `target_id`
-- points into. With both in the term, adding "package viewed" or "contact
-- revealed" later is a row an operator types, not a switch statement in PHP.
-- ---------------------------------------------------------------------

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('engagement_event', 'Profile engagement events',
        'Countable actions taken on a creator profile. Add a row to start tracking a new one — no release required.',
        0, 0, 1, 63, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`metadata`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'profile_share' AS `value`, 'Profile shared' AS `label`,
           'Someone shared this profile to another app or copied its link.' AS `description`,
           'share' AS `icon`, '#B45309' AS `color_hex`,
           '{"channel_taxonomy":"share_channel","target_table":null}' AS `metadata`, 10 AS `sort_order` UNION ALL
    SELECT 'qr_download', 'QR code downloaded',
           'Someone saved or printed the profile QR code.',
           'qr-code', '#334155',
           '{"channel_taxonomy":"share_channel","target_table":null}', 20 UNION ALL
    SELECT 'portfolio_download', 'Portfolio item downloaded',
           'A portfolio image or video was downloaded. target_id is the portfolio row.',
           'download', '#7C3AED',
           '{"channel_taxonomy":null,"target_table":"creator_portfolio"}', 30 UNION ALL
    SELECT 'social_click', 'Social link clicked',
           'A linked social account was opened from the profile. channel is the network, target_id the linked account.',
           'link', '#0EA5E9',
           '{"channel_taxonomy":"social_platform","target_table":"creator_social_accounts"}', 40
  ) v
 WHERE t.`key` = 'engagement_event'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`),
  `metadata`    = VALUES(`metadata`);


-- ---------------------------------------------------------------------
-- Where a share went.
--
-- A separate taxonomy from `social_platform` even though the two overlap on
-- names, because they answer different questions and an operator must be able
-- to move them independently: `social_platform` is "networks a creator can
-- link on their profile" and drives profile links, while this is "places a
-- profile can be shared to" and includes copy-link, QR and the OS share
-- sheet, none of which are networks a creator would ever link.
-- ---------------------------------------------------------------------

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('share_channel', 'Share channels',
        'Destinations a profile can be shared to. Drives the share sheet and the share breakdown on the creator dashboard.',
        0, 0, 1, 64, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `icon`, `color_hex`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`icon`, v.`color_hex`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'whatsapp'  AS `value`, 'WhatsApp'      AS `label`, 'whatsapp' AS `icon`, '#25D366' AS `color_hex`, 10 AS `sort_order` UNION ALL
    SELECT 'instagram',  'Instagram',      'instagram', '#E4405F',  20 UNION ALL
    SELECT 'facebook',   'Facebook',       'facebook',  '#1877F2',  30 UNION ALL
    SELECT 'twitter',    'X (Twitter)',    'twitter',   '#000000',  40 UNION ALL
    SELECT 'linkedin',   'LinkedIn',       'linkedin',  '#0A66C2',  50 UNION ALL
    SELECT 'telegram',   'Telegram',       'send',      '#26A5E4',  60 UNION ALL
    SELECT 'snapchat',   'Snapchat',       'snapchat',  '#FFFC00',  70 UNION ALL
    SELECT 'email',      'Email',          'mail',      '#0891B2',  80 UNION ALL
    SELECT 'sms',        'SMS',            'message',   '#64748B',  90 UNION ALL
    -- The three that are not networks at all, and the reason this is not
    -- folded into `social_platform`.
    SELECT 'copy_link',  'Link copied',    'copy',      '#475569', 100 UNION ALL
    SELECT 'qr_code',    'QR code',        'qr-code',   '#334155', 110 UNION ALL
    SELECT 'native',     'Device share sheet', 'share', '#7C3AED', 120 UNION ALL
    SELECT 'other',      'Other',          'link',      '#64748B', 130
  ) v
 WHERE t.`key` = 'share_channel'
ON DUPLICATE KEY UPDATE
  `label`     = VALUES(`label`),
  `icon`      = VALUES(`icon`),
  `color_hex` = VALUES(`color_hex`);


-- ---------------------------------------------------------------------
-- Not built, on purpose: no columns are added to `creator_analytics_daily`.
--
-- Adding share_count and download_count there would mean a nightly job whose
-- output can drift from the events it was computed from, and a creator asking
-- "how many shares today" before the job runs would be told zero.
-- `idx_cee_rollup` answers the same question directly from the events, per
-- creator per day, without a second copy of the truth to keep in step. If the
-- event volume ever outgrows that, the rollup can be added then — the index
-- is what makes the nightly job cheap to write when that day comes.
-- ---------------------------------------------------------------------
