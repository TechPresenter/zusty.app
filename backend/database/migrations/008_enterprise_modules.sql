-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 008
-- Enterprise admin modules: security hardening, developer tools,
-- marketing, CMS builders, workflow, AI and productivity.
--
-- Purely additive. No existing table is altered in a way that changes
-- current behaviour; the two ALTERs below only append nullable columns.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
-- SECURITY
-- =====================================================================

-- Every authentication attempt, successful or not, for both admins and
-- app users. Distinct from `admin_sessions` (live sessions) — this is the
-- immutable forensic record.
CREATE TABLE IF NOT EXISTS `login_history` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type`     ENUM('admin','user') NOT NULL,
  `actor_id`       BIGINT UNSIGNED NULL COMMENT 'NULL when the identifier matched no account',
  `identifier`     VARCHAR(191) NOT NULL COMMENT 'Email or phone that was submitted',
  `outcome`        ENUM('success','bad_credentials','locked','suspended','throttled','2fa_required','2fa_failed')
                   NOT NULL,
  `auth_method`    ENUM('password','google','apple','phone_otp','refresh','2fa') NOT NULL DEFAULT 'password',
  `ip_address`     VARCHAR(45)  NULL,
  `user_agent`     VARCHAR(255) NULL,
  `country`        VARCHAR(2)   NULL,
  `device_type`    ENUM('desktop','mobile','tablet','api','unknown') NOT NULL DEFAULT 'unknown',
  `is_new_device`  TINYINT(1)   NOT NULL DEFAULT 0,
  `failure_reason` VARCHAR(191) NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lh_actor`   (`actor_type`, `actor_id`, `created_at`),
  KEY `idx_lh_outcome` (`outcome`, `created_at`),
  KEY `idx_lh_ip`      (`ip_address`, `created_at`),
  KEY `idx_lh_ident`   (`identifier`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recovery codes for TOTP two-factor. Codes are stored hashed and are
-- single-use, so a stolen database cannot replay them.
CREATE TABLE IF NOT EXISTS `admin_recovery_codes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `code_hash`  CHAR(64)     NOT NULL,
  `used_at`    DATETIME     NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_arc_admin` (`admin_id`, `used_at`),
  CONSTRAINT `fk_arc_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional allow-list for the admin panel. When at least one *enabled*
-- entry exists, panel access is restricted to matching addresses.
CREATE TABLE IF NOT EXISTS `ip_whitelist` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label`       VARCHAR(120) NOT NULL,
  `ip_address`  VARCHAR(45)  NOT NULL COMMENT 'Single IP or CIDR range, e.g. 203.0.113.0/24',
  `scope`       ENUM('admin','api','both') NOT NULL DEFAULT 'admin',
  `is_enabled`  TINYINT(1)   NOT NULL DEFAULT 1,
  `expires_at`  DATETIME     NULL COMMENT 'Temporary access for a contractor, for example',
  `last_seen_at` DATETIME    NULL,
  `hit_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ipw_addr_scope` (`ip_address`, `scope`),
  KEY `idx_ipw_enabled` (`is_enabled`, `scope`),
  CONSTRAINT `fk_ipw_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- REST API access log. Sampled rather than exhaustive so a busy API does
-- not fill the disk; the sample rate is a setting.
CREATE TABLE IF NOT EXISTS `api_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `method`        VARCHAR(10)  NOT NULL,
  `path`          VARCHAR(255) NOT NULL,
  `route_name`    VARCHAR(120) NULL,
  `status_code`   SMALLINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED NULL,
  `ip_address`    VARCHAR(45)  NULL,
  `user_agent`    VARCHAR(255) NULL,
  `platform`      VARCHAR(20)  NULL,
  `app_version`   VARCHAR(20)  NULL,
  `duration_ms`   INT UNSIGNED NOT NULL DEFAULT 0,
  `query_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `memory_kb`     INT UNSIGNED NOT NULL DEFAULT 0,
  `request_body`  TEXT         NULL COMMENT 'Redacted; only stored for errors',
  `error_message` VARCHAR(500) NULL,
  `request_id`    CHAR(36)     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_path`   (`path`(100), `created_at`),
  KEY `idx_api_status` (`status_code`, `created_at`),
  KEY `idx_api_user`   (`user_id`, `created_at`),
  KEY `idx_api_slow`   (`duration_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Slow-query capture for the SQL Query Monitor.
CREATE TABLE IF NOT EXISTS `query_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sql_hash`     CHAR(32)     NOT NULL COMMENT 'md5 of the normalised statement',
  `sql_text`     TEXT         NOT NULL COMMENT 'Placeholders retained; no bound values',
  `duration_ms`  DECIMAL(10,2) NOT NULL,
  `rows_examined` INT UNSIGNED NULL,
  `route`        VARCHAR(191) NULL,
  `occurrences`  INT UNSIGNED NOT NULL DEFAULT 1,
  `max_duration_ms` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `first_seen_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ql_hash` (`sql_hash`),
  KEY `idx_ql_duration` (`max_duration_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- CACHE
-- =====================================================================

-- Database-backed cache. Shared hosting rarely offers Redis or APCu, so
-- this gives the platform a working cache layer regardless of host.
CREATE TABLE IF NOT EXISTS `cache_entries` (
  `cache_key`  VARCHAR(191) NOT NULL,
  `cache_group` VARCHAR(60) NOT NULL DEFAULT 'default',
  `value`      LONGTEXT     NOT NULL,
  `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME     NULL COMMENT 'NULL means it never expires',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cache_key`),
  KEY `idx_cache_group`  (`cache_group`),
  KEY `idx_cache_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- MARKETING
-- =====================================================================

CREATE TABLE IF NOT EXISTS `coupons` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`               VARCHAR(32)  NOT NULL,
  `name`               VARCHAR(150) NOT NULL,
  `description`        VARCHAR(500) NULL,
  `discount_type`      ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `discount_value`     DECIMAL(10,2) NOT NULL,
  `max_discount`       DECIMAL(10,2) NULL COMMENT 'Caps a percentage discount',
  `min_booking_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `applies_to`         ENUM('all','brands','creators','first_booking','category') NOT NULL DEFAULT 'all',
  `category_id`        INT UNSIGNED NULL,
  `usage_limit`        INT UNSIGNED NULL COMMENT 'Total redemptions allowed; NULL = unlimited',
  `usage_limit_per_user` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `used_count`         INT UNSIGNED NOT NULL DEFAULT 0,
  `starts_at`          DATETIME     NULL,
  `expires_at`         DATETIME     NULL,
  `is_active`          TINYINT(1)   NOT NULL DEFAULT 1,
  `is_public`          TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Listed in the app, or redeemable by code only',
  `created_by`         INT UNSIGNED NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`         DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupons_code` (`code`),
  KEY `idx_coupons_active` (`is_active`, `starts_at`, `expires_at`),
  CONSTRAINT `fk_coupons_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_coupons_admin`    FOREIGN KEY (`created_by`)  REFERENCES `admins` (`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_redemptions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `coupon_id`       INT UNSIGNED NOT NULL,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `booking_id`      BIGINT UNSIGNED NULL,
  `discount_amount` DECIMAL(12,2) NOT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cr_coupon` (`coupon_id`, `user_id`),
  KEY `idx_cr_user`   (`user_id`),
  CONSTRAINT `fk_cr_coupon`  FOREIGN KEY (`coupon_id`)  REFERENCES `coupons` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_cr_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_cr_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Newsletter / email campaigns. Distinct from `notification_broadcasts`,
-- which handles transactional in-app + push messaging.
CREATE TABLE IF NOT EXISTS `marketing_campaigns` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150) NOT NULL,
  `channel`        ENUM('email','sms','whatsapp','push') NOT NULL DEFAULT 'email',
  `subject`        VARCHAR(191) NULL,
  `preheader`      VARCHAR(191) NULL,
  `content`        LONGTEXT     NOT NULL,
  `audience`       ENUM('all','brands','creators','verified_creators','subscribers','custom') NOT NULL DEFAULT 'all',
  `audience_filter` JSON        NULL,
  `status`         ENUM('draft','scheduled','sending','sent','paused','failed') NOT NULL DEFAULT 'draft',
  `scheduled_at`   DATETIME     NULL,
  `started_at`     DATETIME     NULL,
  `completed_at`   DATETIME     NULL,
  `recipients_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `sent_count`     INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `opened_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `clicked_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `unsubscribed_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mc_status` (`status`, `scheduled_at`),
  CONSTRAINT `fk_mc_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `campaign_recipients` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id`   INT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED NULL,
  `destination`   VARCHAR(191) NOT NULL,
  `status`        ENUM('pending','sent','failed','opened','clicked','bounced','unsubscribed') NOT NULL DEFAULT 'pending',
  `tracking_token` CHAR(32)    NULL,
  `sent_at`       DATETIME     NULL,
  `opened_at`     DATETIME     NULL,
  `clicked_at`    DATETIME     NULL,
  `error_message` VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_camp_recipient` (`campaign_id`, `destination`),
  UNIQUE KEY `uq_camp_token` (`tracking_token`),
  KEY `idx_camp_status` (`campaign_id`, `status`),
  CONSTRAINT `fk_camprec_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_camprec_user`     FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)                ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`          VARCHAR(191) NOT NULL,
  `name`           VARCHAR(120) NULL,
  `user_id`        BIGINT UNSIGNED NULL,
  `source`         ENUM('app','web','import','admin') NOT NULL DEFAULT 'web',
  `status`         ENUM('subscribed','unsubscribed','bounced') NOT NULL DEFAULT 'subscribed',
  `unsubscribe_token` CHAR(32)  NOT NULL,
  `subscribed_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unsubscribed_at` DATETIME    NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ns_email` (`email`),
  UNIQUE KEY `uq_ns_token` (`unsubscribe_token`),
  KEY `idx_ns_status` (`status`),
  CONSTRAINT `fk_ns_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app / web popups and the announcement bar.
CREATE TABLE IF NOT EXISTS `popups` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(150) NOT NULL,
  `type`          ENUM('modal','banner','slide_in','announcement_bar','full_screen') NOT NULL DEFAULT 'modal',
  `title`         VARCHAR(191) NULL,
  `content`       TEXT         NULL,
  `image_url`     VARCHAR(500) NULL,
  `background_color` CHAR(7)   NULL,
  `text_color`    CHAR(7)      NULL,
  `cta_label`     VARCHAR(60)  NULL,
  `cta_action`    ENUM('none','url','screen','dismiss') NOT NULL DEFAULT 'none',
  `cta_value`     VARCHAR(255) NULL,
  `placement`     ENUM('all','home','search','creator','booking','wallet','checkout') NOT NULL DEFAULT 'all',
  `audience`      ENUM('all','brands','creators','guests','new_users') NOT NULL DEFAULT 'all',
  `trigger_type`  ENUM('immediate','delay','scroll','exit_intent','session_count') NOT NULL DEFAULT 'immediate',
  `trigger_value` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Seconds, percent, or session count',
  `frequency`     ENUM('always','once','once_per_session','once_per_day') NOT NULL DEFAULT 'once_per_session',
  `is_dismissible` TINYINT(1)  NOT NULL DEFAULT 1,
  `impressions`   INT UNSIGNED NOT NULL DEFAULT 0,
  `clicks`        INT UNSIGNED NOT NULL DEFAULT 0,
  `dismissals`    INT UNSIGNED NOT NULL DEFAULT 0,
  `starts_at`     DATETIME     NULL,
  `ends_at`       DATETIME     NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `priority`      SMALLINT     NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_popups_active` (`is_active`, `placement`, `priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- CMS BUILDERS
-- =====================================================================

CREATE TABLE IF NOT EXISTS `menus` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL,
  `slug`       VARCHAR(100) NOT NULL,
  `location`   ENUM('app_drawer','app_bottom','web_header','web_footer','admin') NOT NULL DEFAULT 'app_drawer',
  `description` VARCHAR(255) NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_menus_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `menu_items` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_id`     INT UNSIGNED NOT NULL,
  `parent_id`   INT UNSIGNED NULL,
  `label`       VARCHAR(100) NOT NULL,
  `icon`        VARCHAR(64)  NULL COMMENT 'Material Symbols name',
  `link_type`   ENUM('screen','page','url','category','none') NOT NULL DEFAULT 'screen',
  `link_value`  VARCHAR(255) NULL,
  `badge_text`  VARCHAR(20)  NULL,
  `badge_color` CHAR(7)      NULL,
  `audience`    ENUM('all','brands','creators','guests') NOT NULL DEFAULT 'all',
  `open_external` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_mi_menu`   (`menu_id`, `is_active`, `sort_order`),
  KEY `idx_mi_parent` (`parent_id`),
  CONSTRAINT `fk_mi_menu`   FOREIGN KEY (`menu_id`)   REFERENCES `menus` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_mi_parent` FOREIGN KEY (`parent_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Composable Home screen. The app renders these in order instead of a
-- hard-coded layout, so merchandising changes need no release.
CREATE TABLE IF NOT EXISTS `homepage_sections` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(150) NOT NULL,
  `subtitle`     VARCHAR(255) NULL,
  `section_type` ENUM('banner_carousel','category_strip','creator_grid','creator_carousel',
                      'testimonials','cta_card','blog_strip','stats','custom_html')
                 NOT NULL DEFAULT 'creator_carousel',
  `data_source`  ENUM('featured','top_rated','trending','newest','category','manual','saved') NOT NULL DEFAULT 'top_rated',
  `source_value` VARCHAR(255) NULL COMMENT 'Category slug, or a comma-separated id list for manual',
  `item_limit`   TINYINT UNSIGNED NOT NULL DEFAULT 8,
  `layout`       ENUM('grid_2','grid_3','carousel','list','full') NOT NULL DEFAULT 'carousel',
  `cta_label`    VARCHAR(60)  NULL,
  `cta_action`   VARCHAR(255) NULL,
  `custom_html`  TEXT         NULL,
  `audience`     ENUM('all','brands','creators','guests') NOT NULL DEFAULT 'all',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`   SMALLINT     NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hs_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `redirects` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source_path` VARCHAR(255) NOT NULL,
  `target_path` VARCHAR(500) NOT NULL,
  `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 301,
  `is_regex`    TINYINT(1)   NOT NULL DEFAULT 0,
  `hit_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `last_hit_at` DATETIME     NULL,
  `notes`       VARCHAR(255) NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_redirects_source` (`source_path`),
  KEY `idx_redirects_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- FILES
-- =====================================================================

CREATE TABLE IF NOT EXISTS `media_folders` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`  INT UNSIGNED NULL,
  `name`       VARCHAR(100) NOT NULL,
  `slug`       VARCHAR(100) NOT NULL,
  `path`       VARCHAR(255) NOT NULL COMMENT 'Materialised path, e.g. marketing/2026',
  `file_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `is_system`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mf_path` (`path`),
  KEY `idx_mf_parent` (`parent_id`),
  CONSTRAINT `fk_mf_parent` FOREIGN KEY (`parent_id`) REFERENCES `media_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- WORKFLOW
-- =====================================================================

CREATE TABLE IF NOT EXISTS `approval_workflows` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(120) NOT NULL,
  `entity_type`  ENUM('creator','kyc','withdrawal','refund','blog','page','booking_cancel') NOT NULL,
  `description`  VARCHAR(255) NULL,
  `threshold_amount` DECIMAL(12,2) NULL COMMENT 'Only applies above this value; NULL = always',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aw_entity` (`entity_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `approval_steps` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workflow_id`  INT UNSIGNED NOT NULL,
  `step_order`   TINYINT UNSIGNED NOT NULL,
  `name`         VARCHAR(120) NOT NULL,
  `role_id`      SMALLINT UNSIGNED NULL COMMENT 'Any admin holding this role may approve',
  `permission`   VARCHAR(100) NULL COMMENT 'Or anyone holding this permission',
  `is_optional`  TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_as_order` (`workflow_id`, `step_order`),
  CONSTRAINT `fk_as_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `approval_workflows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_as_role`     FOREIGN KEY (`role_id`)     REFERENCES `roles` (`id`)               ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `approval_requests` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `workflow_id`  INT UNSIGNED NOT NULL,
  `entity_type`  VARCHAR(40)  NOT NULL,
  `entity_id`    BIGINT UNSIGNED NOT NULL,
  `current_step` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `status`       ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `amount`       DECIMAL(12,2) NULL,
  `summary`      VARCHAR(255) NULL,
  `requested_by` INT UNSIGNED NULL,
  `completed_at` DATETIME     NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ar_entity` (`entity_type`, `entity_id`),
  KEY `idx_ar_status` (`status`, `current_step`),
  CONSTRAINT `fk_ar_workflow` FOREIGN KEY (`workflow_id`) REFERENCES `approval_workflows` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `approval_actions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` BIGINT UNSIGNED NOT NULL,
  `step_order` TINYINT UNSIGNED NOT NULL,
  `admin_id`   INT UNSIGNED NOT NULL,
  `action`     ENUM('approved','rejected') NOT NULL,
  `note`       VARCHAR(500) NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aa_request` (`request_id`),
  CONSTRAINT `fk_aa_request` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aa_admin`   FOREIGN KEY (`admin_id`)   REFERENCES `admins` (`id`)            ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event-driven automation rules (when X happens, do Y).
CREATE TABLE IF NOT EXISTS `automation_rules` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(150) NOT NULL,
  `description`  VARCHAR(255) NULL,
  `trigger_event` VARCHAR(60) NOT NULL COMMENT 'booking.completed | creator.approved | user.registered …',
  `conditions`   JSON         NULL COMMENT '[{"field":"total_amount","operator":">=","value":50000}]',
  `actions`      JSON         NOT NULL COMMENT '[{"type":"notify","template":"…"},{"type":"tag","value":"vip"}]',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `run_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `last_run_at`  DATETIME     NULL,
  `created_by`   INT UNSIGNED NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ar_trigger` (`trigger_event`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- AI
-- =====================================================================

CREATE TABLE IF NOT EXISTS `ai_prompts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(60)  NOT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `purpose`     ENUM('creator_match','campaign_brief','bio_writer','moderation','support_reply','tag_suggest','other')
                NOT NULL DEFAULT 'other',
  `system_prompt` TEXT       NULL,
  `user_prompt` TEXT         NOT NULL COMMENT 'Supports {{placeholders}}',
  `model`       VARCHAR(80)  NULL,
  `temperature` DECIMAL(3,2) NOT NULL DEFAULT 0.70,
  `max_tokens`  INT UNSIGNED NOT NULL DEFAULT 1024,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `version`     INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `prompt_id`     INT UNSIGNED NULL,
  `prompt_code`   VARCHAR(60)  NULL,
  `provider`      VARCHAR(40)  NOT NULL DEFAULT 'anthropic',
  `model`         VARCHAR(80)  NULL,
  `input_tokens`  INT UNSIGNED NOT NULL DEFAULT 0,
  `output_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `cost`          DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
  `duration_ms`   INT UNSIGNED NOT NULL DEFAULT 0,
  `status`        ENUM('success','failed','rate_limited','filtered') NOT NULL DEFAULT 'success',
  `error_message` VARCHAR(500) NULL,
  `requested_by_type` ENUM('admin','user','system') NOT NULL DEFAULT 'system',
  `requested_by_id` BIGINT UNSIGNED NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ail_prompt` (`prompt_code`, `created_at`),
  KEY `idx_ail_status` (`status`, `created_at`),
  CONSTRAINT `fk_ail_prompt` FOREIGN KEY (`prompt_id`) REFERENCES `ai_prompts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- PRODUCTIVITY
-- =====================================================================

CREATE TABLE IF NOT EXISTS `saved_filters` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `module`     VARCHAR(60)  NOT NULL COMMENT 'users | bookings | payments …',
  `name`       VARCHAR(120) NOT NULL,
  `query_string` VARCHAR(1000) NOT NULL,
  `is_shared`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Visible to every admin',
  `is_default` TINYINT(1)   NOT NULL DEFAULT 0,
  `use_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sf_name` (`admin_id`, `module`, `name`),
  KEY `idx_sf_module` (`module`, `is_shared`),
  CONSTRAINT `fk_sf_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot history for CMS entities, so an edit can always be rolled back.
CREATE TABLE IF NOT EXISTS `revisions` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`  VARCHAR(40)  NOT NULL,
  `entity_id`    BIGINT UNSIGNED NOT NULL,
  `version`      INT UNSIGNED NOT NULL,
  `snapshot`     LONGTEXT     NOT NULL COMMENT 'Full JSON of the row before the change',
  `change_note`  VARCHAR(255) NULL,
  `admin_id`     INT UNSIGNED NULL,
  `admin_name`   VARCHAR(120) NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rev_version` (`entity_type`, `entity_id`, `version`),
  KEY `idx_rev_entity` (`entity_type`, `entity_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `feature_flags` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `flag_key`      VARCHAR(80)  NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `description`   VARCHAR(500) NULL,
  `is_enabled`    TINYINT(1)   NOT NULL DEFAULT 0,
  `rollout_percent` TINYINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Gradual rollout, 0-100',
  `audience`      ENUM('all','brands','creators','beta','internal') NOT NULL DEFAULT 'all',
  `min_app_version` VARCHAR(20) NULL,
  `platforms`     JSON         NULL COMMENT '["android","ios","web"]',
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ff_key` (`flag_key`),
  KEY `idx_ff_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- ADDITIVE COLUMNS ON EXISTING TABLES
-- All nullable / defaulted, so existing rows and queries are unaffected.
-- =====================================================================

-- Two-factor enrolment state for admins. `two_factor_secret` already
-- exists from migration 001; these track whether it is actually in use.
ALTER TABLE `admins`
  ADD COLUMN `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `two_factor_secret`,
  ADD COLUMN `two_factor_confirmed_at` DATETIME NULL AFTER `two_factor_enabled`;

-- Media files can now live in a managed folder tree and be compressed.
ALTER TABLE `media`
  ADD COLUMN `folder_id` INT UNSIGNED NULL AFTER `folder`,
  ADD COLUMN `original_size_bytes` BIGINT UNSIGNED NULL AFTER `size_bytes`,
  ADD COLUMN `is_optimized` TINYINT(1) NOT NULL DEFAULT 0 AFTER `original_size_bytes`,
  ADD KEY `idx_media_folder_id` (`folder_id`);

-- Coupons applied to a booking.
ALTER TABLE `bookings`
  ADD COLUMN `coupon_id` INT UNSIGNED NULL AFTER `package_id`,
  ADD COLUMN `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `tax_rate`,
  ADD KEY `idx_bookings_coupon` (`coupon_id`);
