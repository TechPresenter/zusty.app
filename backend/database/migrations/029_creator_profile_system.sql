-- ---------------------------------------------------------------------
-- 029 — Creator profile: social platforms, verification badges, locks
--
-- Three gaps, all verified against the live schema before this was written.
--
-- 1. SOCIAL PLATFORMS. `creator_social_accounts`.`platform` was an ENUM of
--    nine values. The product needs sixteen named networks plus arbitrary
--    custom links, and an ENUM can never express "arbitrary". Worse, under
--    STRICT_TRANS_TABLES an out-of-enum insert is a hard error, so adding Moj
--    or ShareChat was not a silent no-op — it was a 500.
--
--    The replacement is a taxonomy, not a longer ENUM. `social_platform`
--    terms are admin-managed, already served to every client through
--    /app/taxonomies with an ETag, and already have icon, colour, metadata
--    and sort-order columns. Adding a network that launches next year becomes
--    a row an operator types in, not a migration and a release.
--
-- 2. VERIFICATION BADGES. There was no badge storage of any kind — a scan of
--    every table name for "badge" returned nothing. `creators`.`is_verified`
--    and `is_featured` were the whole model: two booleans with no award date,
--    no verifier, no expiry and no history.
--
--    The badge CATALOGUE is a taxonomy for the same reasons as above. Only the
--    per-creator ASSIGNMENT needs real tables, because it carries state.
--
-- 3. SECTION LOCKS. An administrator must be able to freeze one section of a
--    profile — the username, the pricing, the bio — without freezing the rest.
--    Nothing modelled that.
-- ---------------------------------------------------------------------


-- =====================================================================
-- 1. Social platforms as an admin-managed taxonomy
-- =====================================================================

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('social_platform', 'Social platforms',
        'Networks a creator can link on their profile. Add a row to support a new network — no release required.',
        1, 1, 1, 60, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- `metadata` carries the profile-URL template, so the app can build a link
-- from a handle without knowing anything about the network.
INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`icon`, v.`color_hex`, v.`metadata`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'instagram' AS `value`, 'Instagram'     AS `label`, 'instagram' AS `icon`, '#E4405F' AS `color_hex`, '{"url":"https://instagram.com/{handle}","metrics":true}'  AS `metadata`,  10 AS `sort_order` UNION ALL
    SELECT 'youtube',    'YouTube',       'youtube',   '#FF0000', '{"url":"https://youtube.com/@{handle}","metrics":true}',   20 UNION ALL
    SELECT 'facebook',   'Facebook',      'facebook',  '#1877F2', '{"url":"https://facebook.com/{handle}","metrics":true}',   30 UNION ALL
    SELECT 'linkedin',   'LinkedIn',      'linkedin',  '#0A66C2', '{"url":"https://linkedin.com/in/{handle}","metrics":true}', 40 UNION ALL
    SELECT 'twitter',    'X (Twitter)',   'twitter',   '#000000', '{"url":"https://x.com/{handle}","metrics":true}',           50 UNION ALL
    SELECT 'snapchat',   'Snapchat',      'snapchat',  '#FFFC00', '{"url":"https://snapchat.com/add/{handle}","metrics":true}',60 UNION ALL
    SELECT 'pinterest',  'Pinterest',     'pinterest', '#BD081C', '{"url":"https://pinterest.com/{handle}","metrics":true}',   70 UNION ALL
    SELECT 'threads',    'Threads',       'threads',   '#000000', '{"url":"https://threads.net/@{handle}","metrics":true}',    80 UNION ALL
    SELECT 'tiktok',     'TikTok',        'tiktok',    '#000000', '{"url":"https://tiktok.com/@{handle}","metrics":true}',     90 UNION ALL
    -- The four the ENUM could not hold. Regional networks matter in this
    -- market and were simply unrepresentable before.
    SELECT 'moj',        'Moj',           'play',      '#FF4E64', '{"url":"https://mojapp.in/@{handle}","metrics":true}',     100 UNION ALL
    SELECT 'josh',       'Josh',          'play',      '#F5286F', '{"url":"https://share.myjosh.in/profile/{handle}","metrics":true}', 110 UNION ALL
    SELECT 'sharechat',  'ShareChat',     'chat',      '#28C4A0', '{"url":"https://sharechat.com/profile/{handle}","metrics":true}',   120 UNION ALL
    -- Link-only entries. `metrics:false` tells the client not to render
    -- follower or engagement figures, which do not exist for a website.
    SELECT 'website',    'Website',       'external',  '#475569', '{"url":"{handle}","metrics":false}',                       130 UNION ALL
    SELECT 'portfolio',  'Portfolio',     'grid',      '#7C3AED', '{"url":"{handle}","metrics":false}',                       140 UNION ALL
    SELECT 'blog',       'Blog',          'file',      '#0891B2', '{"url":"{handle}","metrics":false}',                       150 UNION ALL
    SELECT 'other',      'Other link',    'link',      '#64748B', '{"url":"{handle}","metrics":false,"custom_label":true}',   160
  ) v
 WHERE t.`key` = 'social_platform'
ON DUPLICATE KEY UPDATE
  `label`     = VALUES(`label`),
  `icon`      = VALUES(`icon`),
  `color_hex` = VALUES(`color_hex`),
  `metadata`  = VALUES(`metadata`);


-- ---------------------------------------------------------------------
-- Widen the column the taxonomy now drives.
--
-- ENUM -> VARCHAR keeps every existing string as-is, so the 118 rows already
-- stored survive untouched; their values are all terms seeded above.
-- ---------------------------------------------------------------------

ALTER TABLE `creator_social_accounts`
  MODIFY COLUMN `platform` VARCHAR(40) NOT NULL,
  -- A custom link needs a name the creator chose: "My Substack" rather than
  -- "Other link". Null for every real network, whose label comes from the term.
  ADD COLUMN `label` VARCHAR(80) NULL AFTER `platform`,
  ADD COLUMN `sort_order` SMALLINT NOT NULL DEFAULT 0 AFTER `is_primary`;

-- The old key was UNIQUE (creator_id, platform), which permitted exactly one
-- row per network — correct for Instagram, fatal for "Other link", where a
-- creator may reasonably list several. Adding the handle keeps duplicates of
-- the SAME account out while allowing many custom links.
ALTER TABLE `creator_social_accounts`
  DROP INDEX `uq_csa_creator_platform`,
  ADD UNIQUE KEY `uq_csa_creator_account` (`creator_id`, `platform`, `handle`),
  ADD KEY `idx_csa_order` (`creator_id`, `sort_order`);

-- Existing rows all sort at 0, which would make the order arbitrary. Seed it
-- from the follower count so the first render is sensible rather than random.
SET @row = 0;

UPDATE `creator_social_accounts` s
  JOIN (
    SELECT `id`, (@row := @row + 1) AS `n`
      FROM `creator_social_accounts`
     ORDER BY `creator_id`, `is_primary` DESC, `followers` DESC, `id`
  ) o ON o.`id` = s.`id`
   SET s.`sort_order` = o.`n` % 1000;


-- =====================================================================
-- 2. Verification badges
-- =====================================================================

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('verification_badge', 'Verification badges',
        'Badges an administrator can award to a creator. Each carries its own rules; awarding is recorded in creator_badges.',
        1, 1, 1, 61, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- `metadata.auto` marks a badge the platform awards itself from a rule rather
-- than one an administrator hands out; `metadata.expires_days` is the default
-- validity, null for a badge that does not lapse.
INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`metadata`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'email_verified' AS `value`, 'Email verified' AS `label`,
           'The creator has confirmed their email address.' AS `description`,
           'mail' AS `icon`, '#0891B2' AS `color_hex`,
           '{"auto":true,"source":"users.email_verified_at","expires_days":null}' AS `metadata`, 10 AS `sort_order` UNION ALL
    SELECT 'mobile_verified', 'Mobile verified', 'The creator has confirmed their phone number.',
           'smartphone', '#0891B2', '{"auto":true,"source":"users.phone_verified_at","expires_days":null}', 20 UNION ALL
    SELECT 'identity_verified', 'Identity verified', 'A government ID has been checked against the creator.',
           'shield', '#059669', '{"auto":true,"source":"kyc_profiles.is_identity_verified","expires_days":null}', 30 UNION ALL
    SELECT 'kyc_verified', 'KYC verified', 'Full know-your-customer checks are complete and current.',
           'check-circle', '#059669', '{"auto":true,"source":"kyc_profiles.status","expires_days":365}', 40 UNION ALL
    SELECT 'creator_verified', 'Verified creator', 'The platform has confirmed this is a genuine creator account.',
           'verified', '#1D4ED8', '{"auto":false,"expires_days":null}', 50 UNION ALL
    SELECT 'premium_creator', 'Premium creator', 'A creator on a paid tier.',
           'star', '#B45309', '{"auto":false,"expires_days":365}', 60 UNION ALL
    SELECT 'featured_creator', 'Featured creator', 'Promoted in discovery for a fixed period.',
           'megaphone', '#7C3AED', '{"auto":false,"expires_days":30}', 70 UNION ALL
    SELECT 'business_verified', 'Business verified', 'A registered business, with GST or equivalent on file.',
           'briefcase', '#334155', '{"auto":false,"expires_days":null}', 80 UNION ALL
    SELECT 'social_verified', 'Social verified', 'At least one linked social account is verified on its own platform.',
           'link', '#0EA5E9', '{"auto":true,"source":"creator_social_accounts.is_verified","expires_days":null}', 90 UNION ALL
    SELECT 'top_rated', 'Top rated', 'Consistently high ratings across completed bookings.',
           'trending-up', '#059669', '{"auto":true,"source":"creators.rating_avg","expires_days":90}', 100 UNION ALL
    SELECT 'trusted_creator', 'Trusted creator', 'A long record of completed bookings with no disputes.',
           'award', '#B45309', '{"auto":true,"source":"creators.collabs_count","expires_days":180}', 110
  ) v
 WHERE t.`key` = 'verification_badge'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`),
  `metadata`    = VALUES(`metadata`);


-- ---------------------------------------------------------------------
-- Which creator holds which badge, and on whose authority.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `creator_badges` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`   BIGINT UNSIGNED NOT NULL,
  -- The taxonomy term value, not its id: a slug survives the term being
  -- re-created, and reads plainly in a query.
  `badge`        VARCHAR(60) NOT NULL,
  `status`       ENUM('active','expired','revoked','pending') NOT NULL DEFAULT 'active',
  `awarded_at`   DATETIME NOT NULL,
  -- Null when the platform awarded it from a rule rather than an admin.
  `awarded_by`   INT UNSIGNED NULL,
  `awarded_note` VARCHAR(500) NULL,
  -- Null means the badge does not lapse. The nightly job expires the rest.
  `expires_at`   DATETIME NULL,
  `revoked_at`   DATETIME NULL,
  `revoked_by`   INT UNSIGNED NULL,
  `revoke_reason` VARCHAR(500) NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- One row per badge per creator. Re-awarding a revoked badge updates the
  -- row and appends to the event log rather than creating a second one, so
  -- "does this creator hold X" is always a single-row question.
  UNIQUE KEY `uq_creator_badge` (`creator_id`, `badge`),
  KEY `idx_cb_badge` (`badge`, `status`),
  KEY `idx_cb_expiry` (`status`, `expires_at`),
  CONSTRAINT `fk_cb_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Badge history. Same shape as `kyc_events`, deliberately: an operator
-- reading one audit trail should not have to learn a second vocabulary.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `creator_badge_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`  BIGINT UNSIGNED NOT NULL,
  `badge`       VARCHAR(60) NOT NULL,
  `actor_type`  ENUM('creator','admin','system') NOT NULL,
  `actor_id`    BIGINT UNSIGNED NULL,
  `actor_name`  VARCHAR(150) NULL,
  `action`      VARCHAR(60) NOT NULL,
  `from_status` VARCHAR(30) NULL,
  `to_status`   VARCHAR(30) NULL,
  `note`        VARCHAR(1000) NULL,
  `metadata`    LONGTEXT NULL,
  `ip_address`  VARBINARY(16) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cbe_creator` (`creator_id`, `created_at`),
  KEY `idx_cbe_badge` (`badge`, `created_at`),
  CONSTRAINT `fk_cbe_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 3. Per-section profile locks
-- =====================================================================

CREATE TABLE IF NOT EXISTS `creator_profile_locks` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id` BIGINT UNSIGNED NOT NULL,
  -- Free-text rather than an ENUM for the same reason as `platform` above:
  -- the set of lockable sections will grow, and it should grow without a
  -- migration. The application owns the vocabulary.
  `section`    VARCHAR(40) NOT NULL,
  `locked_by`  INT UNSIGNED NULL,
  `reason`     VARCHAR(500) NULL,
  `locked_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Presence of the row IS the lock; there is no is_locked flag to fall out
  -- of step with it. Unlocking deletes.
  UNIQUE KEY `uq_creator_section_lock` (`creator_id`, `section`),
  CONSTRAINT `fk_cpl_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
