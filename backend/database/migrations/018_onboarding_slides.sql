-- ---------------------------------------------------------------------
-- 018 — Onboarding slides
--
-- Renumbered from 016: `016_taxonomies.sql` already held that version in the
-- ledger, which is keyed on the prefix — so as 016 this file was never applied
-- and the onboarding_slides table did not exist.
--
-- The three intro screens were hard-coded in the Flutter source, so changing
-- a headline meant a store release. They now live here.
--
-- Cities are *not* given a table: they fit the taxonomy shape exactly (value,
-- label, plus lat/lng in metadata), so they are seeded as a `city` taxonomy
-- and reuse the admin UI and cached bundle that already exist.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `onboarding_slides` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(191) NOT NULL,
  `subtitle`    VARCHAR(500) NULL,
  `image_url`   VARCHAR(500) NULL,
  `icon`        VARCHAR(60)  NULL COMMENT 'Fallback when no image is set',

  -- Which sign-up path this slide belongs to. `all` shows it to everyone,
  -- so a brand-specific pitch can be shown only to brands.
  `audience`    ENUM('all','brands','creators') NOT NULL DEFAULT 'all',

  `cta_label`   VARCHAR(60)  NULL COMMENT 'Overrides the default Next / Get started',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_onboarding_title` (`title`, `audience`),
  KEY `idx_onboarding_listing` (`is_active`, `audience`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
