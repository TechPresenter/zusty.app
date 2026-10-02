-- ---------------------------------------------------------------------
-- 016 — Taxonomies
--
-- Renumbered from 014: `014_soft_delete_indexes.sql` already held that version
-- in the ledger, and the ledger is keyed on the prefix — so as 014 this file
-- would have been silently skipped and its tables never created.
--
-- Every option list the apps render — sort orders, platforms, languages,
-- genders, age bands, experience levels, response windows, campaign types,
-- deliverables, industries, support topics — used to be a `const` map inside
-- the Flutter source. Changing one meant a store release.
--
-- This moves them into data. A taxonomy is a named list; a term is one option
-- in it. The apps fetch the whole set in a single call and cache it, so an
-- admin edit reaches Android, iOS and Web without a code change.
--
--   taxonomies       the list itself (key, label, how it is used)
--   taxonomy_terms   the options, ordered, each with an optional icon,
--                    colour, and free-form metadata
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `taxonomies` (
  `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`         VARCHAR(60)  NOT NULL COMMENT 'Stable identifier the clients look up, e.g. creator_sort',
  `name`        VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) NULL COMMENT 'Where this list appears in the apps',

  -- A system taxonomy backs a hard-coded workflow (booking states, sort keys
  -- the API understands). Admins may relabel and reorder its terms, but adding
  -- or removing values would break the server, so the UI locks that off.
  `is_system`   TINYINT(1)   NOT NULL DEFAULT 0,

  `allows_multiple` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether a user may pick more than one term',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_taxonomies_key` (`key`),
  KEY `idx_taxonomies_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `taxonomy_terms` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `taxonomy_id` SMALLINT UNSIGNED NOT NULL,

  -- What the API and database store. For system taxonomies this must match the
  -- value the backend already understands.
  `value`       VARCHAR(60)  NOT NULL,

  -- What the user reads. Safe to change at any time.
  `label`       VARCHAR(150) NOT NULL,
  `description` VARCHAR(500) NULL,

  `icon`        VARCHAR(60)  NULL COMMENT 'Material symbol name',
  `color_hex`   CHAR(7)      NULL,
  `image_url`   VARCHAR(500) NULL,

  -- Extra per-term data the client needs, e.g. {"min":10000,"max":50000} for a
  -- follower band or {"lat":19.07,"lng":72.87} for a city.
  `metadata`    JSON         NULL,

  `is_default`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Pre-selected in the UI',
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_term_value` (`taxonomy_id`, `value`),
  KEY `idx_term_listing` (`taxonomy_id`, `is_active`, `sort_order`),
  CONSTRAINT `fk_term_taxonomy` FOREIGN KEY (`taxonomy_id`) REFERENCES `taxonomies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
