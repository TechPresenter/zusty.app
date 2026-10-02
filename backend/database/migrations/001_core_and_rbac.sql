-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 001
-- Core platform tables: migration ledger, settings, RBAC, admin accounts.
-- Engine: InnoDB / utf8mb4 (MySQL 8.0+ and MariaDB 10.4+ compatible)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Migration ledger (database versioning)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `migrations` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`    VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(191) NOT NULL,
  `checksum`   CHAR(64)     NOT NULL,
  `batch`      INT UNSIGNED NOT NULL DEFAULT 1,
  `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migrations_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Application settings (grouped key/value store)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_key`   VARCHAR(50)  NOT NULL,
  `setting_key` VARCHAR(100) NOT NULL,
  `value`       LONGTEXT     NULL,
  `type`        ENUM('string','integer','decimal','boolean','json','text','file','secret')
                NOT NULL DEFAULT 'string',
  `label`       VARCHAR(191) NULL,
  `description` VARCHAR(255) NULL,
  `is_public`   TINYINT(1)   NOT NULL DEFAULT 0
                COMMENT 'Exposed through the public /settings API endpoint',
  `is_encrypted` TINYINT(1)  NOT NULL DEFAULT 0,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`group_key`, `setting_key`),
  KEY `idx_settings_public` (`is_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Roles
-- `level` is a numeric rank: higher wins. Super Admin = 100.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(64)  NOT NULL,
  `slug`        VARCHAR(64)  NOT NULL,
  `description` VARCHAR(255) NULL,
  `level`       TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `is_system`   TINYINT(1)   NOT NULL DEFAULT 0
                COMMENT 'System roles cannot be deleted from the admin panel',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Permissions (module.action)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permissions` (
  `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_name`  VARCHAR(64)  NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `slug`        VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_slug` (`slug`),
  KEY `idx_permissions_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`       SMALLINT UNSIGNED NOT NULL,
  `permission_id` SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `idx_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role`       FOREIGN KEY (`role_id`)       REFERENCES `roles` (`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Admin accounts (panel login — independent of Firebase / app users)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id`            SMALLINT UNSIGNED NOT NULL,
  `name`               VARCHAR(120) NOT NULL,
  `email`              VARCHAR(191) NOT NULL,
  `phone`              VARCHAR(20)  NULL,
  `password_hash`      VARCHAR(255) NOT NULL,
  `avatar`             VARCHAR(255) NULL,
  `status`             ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `two_factor_secret`  VARCHAR(64)  NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `failed_attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`       DATETIME     NULL,
  `last_login_at`      DATETIME     NULL,
  `last_login_ip`      VARCHAR(45)  NULL,
  `password_changed_at` DATETIME    NULL,
  `created_by`         INT UNSIGNED NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`         DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_email` (`email`),
  KEY `idx_admins_role` (`role_id`),
  KEY `idx_admins_status` (`status`, `deleted_at`),
  CONSTRAINT `fk_admins_role`    FOREIGN KEY (`role_id`)    REFERENCES `roles` (`id`)  ON DELETE RESTRICT,
  CONSTRAINT `fk_admins_creator` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-admin permission overrides (grant/revoke on top of the role).
CREATE TABLE IF NOT EXISTS `admin_permissions` (
  `admin_id`      INT UNSIGNED NOT NULL,
  `permission_id` SMALLINT UNSIGNED NOT NULL,
  `effect`        ENUM('allow','deny') NOT NULL DEFAULT 'allow',
  PRIMARY KEY (`admin_id`, `permission_id`),
  KEY `idx_ap_permission` (`permission_id`),
  CONSTRAINT `fk_ap_admin`      FOREIGN KEY (`admin_id`)      REFERENCES `admins` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_ap_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_password_resets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     NULL,
  `ip_address` VARCHAR(45)  NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_apr_token` (`token_hash`),
  KEY `idx_apr_admin` (`admin_id`, `expires_at`),
  CONSTRAINT `fk_apr_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Server-side admin session registry (allows remote logout / session audit).
CREATE TABLE IF NOT EXISTS `admin_sessions` (
  `id`            CHAR(64)     NOT NULL COMMENT 'SHA-256 of the PHP session id',
  `admin_id`      INT UNSIGNED NOT NULL,
  `ip_address`    VARCHAR(45)  NULL,
  `user_agent`    VARCHAR(255) NULL,
  `payload`       TEXT         NULL,
  `last_activity` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_as_admin` (`admin_id`, `last_activity`),
  CONSTRAINT `fk_as_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Rate limiting buckets (shared by API + admin login throttling)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket_key` VARCHAR(191) NOT NULL,
  `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME     NOT NULL,
  PRIMARY KEY (`bucket_key`),
  KEY `idx_rl_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit + system logs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type`   ENUM('admin','user','system','cron') NOT NULL DEFAULT 'system',
  `actor_id`     INT UNSIGNED NULL,
  `actor_name`   VARCHAR(120) NULL,
  `action`       VARCHAR(64)  NOT NULL COMMENT 'created | updated | deleted | login | payout.approved ...',
  `subject_type` VARCHAR(64)  NULL COMMENT 'booking | user | creator | setting ...',
  `subject_id`   BIGINT UNSIGNED NULL,
  `description`  VARCHAR(255) NULL,
  `properties`   JSON         NULL COMMENT 'before/after diff payload',
  `ip_address`   VARCHAR(45)  NULL,
  `user_agent`   VARCHAR(255) NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_al_actor`   (`actor_type`, `actor_id`, `created_at`),
  KEY `idx_al_subject` (`subject_type`, `subject_id`),
  KEY `idx_al_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level`      ENUM('debug','info','notice','warning','error','critical','alert','emergency')
               NOT NULL DEFAULT 'info',
  `channel`    VARCHAR(40)  NOT NULL DEFAULT 'app',
  `message`    TEXT         NOT NULL,
  `context`    JSON         NULL,
  `request_id` CHAR(36)     NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sl_level`   (`level`, `created_at`),
  KEY `idx_sl_channel` (`channel`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Media library (used by the admin Media Manager and all uploads)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `media` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `disk`           ENUM('local','firebase') NOT NULL DEFAULT 'local',
  `folder`         VARCHAR(100) NOT NULL DEFAULT 'general',
  `file_name`      VARCHAR(191) NOT NULL,
  `original_name`  VARCHAR(191) NOT NULL,
  `path`           VARCHAR(255) NOT NULL,
  `url`            VARCHAR(500) NOT NULL,
  `thumbnail_url`  VARCHAR(500) NULL,
  `mime_type`      VARCHAR(100) NOT NULL,
  `extension`      VARCHAR(10)  NOT NULL,
  `size_bytes`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `width`          INT UNSIGNED NULL,
  `height`         INT UNSIGNED NULL,
  `duration_secs`  INT UNSIGNED NULL,
  `alt_text`       VARCHAR(255) NULL,
  `uploader_type`  ENUM('admin','user','system') NOT NULL DEFAULT 'admin',
  `uploader_id`    INT UNSIGNED NULL,
  `checksum`       CHAR(32)     NULL COMMENT 'md5 for duplicate detection',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_media_folder`   (`folder`, `created_at`),
  KEY `idx_media_uploader` (`uploader_type`, `uploader_id`),
  KEY `idx_media_checksum` (`checksum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Outbound queues (drained by cron)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_queue` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email`      VARCHAR(191) NOT NULL,
  `to_name`       VARCHAR(120) NULL,
  `subject`       VARCHAR(255) NOT NULL,
  `body_html`     LONGTEXT     NOT NULL,
  `body_text`     LONGTEXT     NULL,
  `reply_to`      VARCHAR(191) NULL,
  `attachments`   JSON         NULL,
  `status`        ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error`    VARCHAR(500) NULL,
  `available_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`       DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eq_dispatch` (`status`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sms_queue` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_phone`     VARCHAR(20)  NOT NULL,
  `message`      VARCHAR(1000) NOT NULL,
  `template_id`  VARCHAR(64)  NULL COMMENT 'DLT template id (India TRAI compliance)',
  `status`       ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error`   VARCHAR(500) NULL,
  `provider_ref` VARCHAR(120) NULL,
  `available_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`      DATETIME     NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sq_dispatch` (`status`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_queue` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target_type`   ENUM('token','topic','user','segment') NOT NULL DEFAULT 'user',
  `target_value`  VARCHAR(255) NOT NULL,
  `title`         VARCHAR(191) NOT NULL,
  `body`          VARCHAR(500) NOT NULL,
  `image_url`     VARCHAR(500) NULL,
  `data_payload`  JSON         NULL,
  `status`        ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error`    VARCHAR(500) NULL,
  `success_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `failure_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `available_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`       DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pq_dispatch` (`status`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
