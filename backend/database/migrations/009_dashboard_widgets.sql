-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 009
-- Dashboard personalisation: per-admin layouts, widget preferences,
-- sticky notes and a to-do list.
--
-- Purely additive.
-- =====================================================================

SET NAMES utf8mb4;

-- Each admin may keep several named layouts and switch between them.
CREATE TABLE IF NOT EXISTS `dashboard_layouts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED NOT NULL,
  `name`        VARCHAR(100) NOT NULL DEFAULT 'Default',
  `is_default`  TINYINT(1)   NOT NULL DEFAULT 0,
  `is_shared`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Offered to other admins as a template',
  `role_id`     SMALLINT UNSIGNED NULL COMMENT 'When set, the layout is the default for that role',
  `layout`      JSON         NOT NULL COMMENT '[{"key":"total_users","w":3,"collapsed":false}, …]',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dl_admin_name` (`admin_id`, `name`),
  KEY `idx_dl_default` (`admin_id`, `is_default`),
  KEY `idx_dl_role`    (`role_id`, `is_shared`),
  CONSTRAINT `fk_dl_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dl_role`  FOREIGN KEY (`role_id`)  REFERENCES `roles` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-widget preferences that survive a layout change.
CREATE TABLE IF NOT EXISTS `widget_preferences` (
  `admin_id`        INT UNSIGNED NOT NULL,
  `widget_key`      VARCHAR(60)  NOT NULL,
  `is_favorite`     TINYINT(1)   NOT NULL DEFAULT 0,
  `is_collapsed`    TINYINT(1)   NOT NULL DEFAULT 0,
  `refresh_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 disables auto-refresh',
  `options`         JSON         NULL COMMENT 'Widget-specific settings, e.g. {"days":30}',
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`, `widget_key`),
  CONSTRAINT `fk_wp_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Productivity widgets.
CREATE TABLE IF NOT EXISTS `admin_notes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NOT NULL,
  `body`       VARCHAR(2000) NOT NULL,
  `color`      CHAR(7)      NOT NULL DEFAULT '#FFD9DE',
  `is_pinned`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_an_admin` (`admin_id`, `is_pinned`),
  CONSTRAINT `fk_an_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_tasks` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`    INT UNSIGNED NOT NULL,
  `title`       VARCHAR(255) NOT NULL,
  `is_done`     TINYINT(1)   NOT NULL DEFAULT 0,
  `priority`    ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  `due_on`      DATE         NULL,
  `completed_at` DATETIME    NULL,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_at_admin` (`admin_id`, `is_done`, `sort_order`),
  CONSTRAINT `fk_at_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Announcements shown to the admin team on the dashboard.
CREATE TABLE IF NOT EXISTS `admin_announcements` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`       VARCHAR(191) NOT NULL,
  `body`        TEXT         NOT NULL,
  `severity`    ENUM('info','success','warning','critical') NOT NULL DEFAULT 'info',
  `role_id`     SMALLINT UNSIGNED NULL COMMENT 'NULL targets every admin',
  `starts_at`   DATETIME     NULL,
  `ends_at`     DATETIME     NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_announce_active` (`is_active`, `starts_at`, `ends_at`),
  -- Constraint names are global in MySQL/MariaDB, so they carry a
  -- table-specific prefix rather than initials that could collide.
  CONSTRAINT `fk_announce_role`  FOREIGN KEY (`role_id`)    REFERENCES `roles` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_announce_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lightweight visit telemetry, so the traffic/device/geo widgets have a
-- source that does not depend on an external analytics provider.
CREATE TABLE IF NOT EXISTS `visit_stats` (
  `stat_date`    DATE         NOT NULL,
  `dimension`    ENUM('device','browser','os','country','city','language','platform','source') NOT NULL,
  `value`        VARCHAR(100) NOT NULL,
  `visits`       INT UNSIGNED NOT NULL DEFAULT 0,
  `unique_users` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`stat_date`, `dimension`, `value`),
  KEY `idx_vs_dimension` (`dimension`, `visits`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
