-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 007
-- CMS (pages, blogs, banners, FAQs, testimonials), contact inbox,
-- analytics roll-ups and scheduled-job bookkeeping.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Static / legal pages (About, Terms, Privacy, Refund Policy …)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pages` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`             VARCHAR(120) NOT NULL,
  `title`            VARCHAR(191) NOT NULL,
  `subtitle`         VARCHAR(255) NULL,
  `content`          LONGTEXT     NOT NULL,
  `hero_image`       VARCHAR(500) NULL,
  `template`         ENUM('default','legal','landing','contact') NOT NULL DEFAULT 'default',
  `is_system`        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'System pages cannot be deleted',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `show_in_app`      TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`       SMALLINT     NOT NULL DEFAULT 0,
  `meta_title`       VARCHAR(191) NULL,
  `meta_description` VARCHAR(300) NULL,
  `meta_keywords`    VARCHAR(300) NULL,
  `og_image`         VARCHAR(500) NULL,
  `version`          INT UNSIGNED NOT NULL DEFAULT 1,
  `published_at`     DATETIME     NULL,
  `updated_by`       INT UNSIGNED NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pages_slug` (`slug`),
  KEY `idx_pages_active` (`is_active`, `sort_order`),
  CONSTRAINT `fk_pages_admin` FOREIGN KEY (`updated_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Blog
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blog_categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `slug`        VARCHAR(120) NOT NULL,
  `description` VARCHAR(500) NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blogcat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blogs` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`      INT UNSIGNED NULL,
  `slug`             VARCHAR(191) NOT NULL,
  `title`            VARCHAR(191) NOT NULL,
  `excerpt`          VARCHAR(500) NULL,
  `content`          LONGTEXT     NOT NULL,
  `cover_image`      VARCHAR(500) NULL,
  `author_id`        INT UNSIGNED NULL,
  `author_name`      VARCHAR(120) NULL,
  `tags`             JSON         NULL,
  `reading_minutes`  TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `views_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `status`           ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
  `is_featured`      TINYINT(1)   NOT NULL DEFAULT 0,
  `published_at`     DATETIME     NULL,
  `meta_title`       VARCHAR(191) NULL,
  `meta_description` VARCHAR(300) NULL,
  `meta_keywords`    VARCHAR(300) NULL,
  `og_image`         VARCHAR(500) NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`       DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blogs_slug` (`slug`),
  KEY `idx_blogs_status`   (`status`, `published_at`),
  KEY `idx_blogs_category` (`category_id`, `status`),
  FULLTEXT KEY `ft_blogs_search` (`title`, `excerpt`, `content`),
  CONSTRAINT `fk_blogs_category` FOREIGN KEY (`category_id`) REFERENCES `blog_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_blogs_author`   FOREIGN KEY (`author_id`)   REFERENCES `admins` (`id`)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Banners (Home hero carousel + promotional slots)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `banners` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`          VARCHAR(191) NOT NULL,
  `subtitle`       VARCHAR(255) NULL,
  `cta_label`      VARCHAR(60)  NULL,
  `image_url`      VARCHAR(500) NOT NULL,
  `image_url_dark` VARCHAR(500) NULL,
  `position`       ENUM('home_hero','home_mid','search_top','category_top','wallet','profile') NOT NULL DEFAULT 'home_hero',
  `action_type`    ENUM('none','category','creator','url','campaign','screen') NOT NULL DEFAULT 'none',
  `action_value`   VARCHAR(255) NULL,
  `audience`       ENUM('all','brands','creators','guests') NOT NULL DEFAULT 'all',
  `impressions`    INT UNSIGNED NOT NULL DEFAULT 0,
  `clicks`         INT UNSIGNED NOT NULL DEFAULT 0,
  `starts_at`      DATETIME     NULL,
  `ends_at`        DATETIME     NULL,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`     SMALLINT     NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_banners_slot` (`position`, `is_active`, `sort_order`),
  KEY `idx_banners_window` (`starts_at`, `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- FAQs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `faqs` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category`      ENUM('general','booking','payment','wallet','creator','brand','account','security') NOT NULL DEFAULT 'general',
  `question`      VARCHAR(300) NOT NULL,
  `answer`        TEXT         NOT NULL,
  `audience`      ENUM('all','brands','creators') NOT NULL DEFAULT 'all',
  `helpful_yes`   INT UNSIGNED NOT NULL DEFAULT 0,
  `helpful_no`    INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`    SMALLINT     NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_faqs_category` (`category`, `is_active`, `sort_order`),
  FULLTEXT KEY `ft_faqs_search` (`question`, `answer`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Testimonials
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `testimonials` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `author_name` VARCHAR(120) NOT NULL,
  `author_role` VARCHAR(150) NULL,
  `company`     VARCHAR(150) NULL,
  `avatar_url`  VARCHAR(500) NULL,
  `content`     VARCHAR(2000) NOT NULL,
  `rating`      TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `source`      ENUM('manual','review') NOT NULL DEFAULT 'manual',
  `review_id`   BIGINT UNSIGNED NULL,
  `is_featured` TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_testimonials_active` (`is_active`, `sort_order`),
  CONSTRAINT `fk_testimonials_review` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Contact form inbox
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `email`      VARCHAR(191) NOT NULL,
  `phone`      VARCHAR(20)  NULL,
  `subject`    VARCHAR(191) NOT NULL,
  `message`    TEXT         NOT NULL,
  `source`     ENUM('app','web','admin') NOT NULL DEFAULT 'app',
  `status`     ENUM('new','read','replied','archived','spam') NOT NULL DEFAULT 'new',
  `replied_by` INT UNSIGNED NULL,
  `replied_at` DATETIME     NULL,
  `reply_body` TEXT         NULL,
  `ip_address` VARCHAR(45)  NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contact_status` (`status`, `created_at`),
  CONSTRAINT `fk_contact_admin` FOREIGN KEY (`replied_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Analytics daily roll-up (fast dashboard reads, refreshed by cron)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `analytics_daily` (
  `stat_date`           DATE         NOT NULL,
  `users_new`           INT UNSIGNED NOT NULL DEFAULT 0,
  `brands_new`          INT UNSIGNED NOT NULL DEFAULT 0,
  `creators_new`        INT UNSIGNED NOT NULL DEFAULT 0,
  `users_active`        INT UNSIGNED NOT NULL DEFAULT 0,
  `bookings_created`    INT UNSIGNED NOT NULL DEFAULT 0,
  `bookings_accepted`   INT UNSIGNED NOT NULL DEFAULT 0,
  `bookings_completed`  INT UNSIGNED NOT NULL DEFAULT 0,
  `bookings_cancelled`  INT UNSIGNED NOT NULL DEFAULT 0,
  `gmv`                 DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Gross merchandise value',
  `revenue`             DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Platform fee + commission',
  `refunds`             DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `payouts`             DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `wallet_topups`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `messages_sent`       INT UNSIGNED NOT NULL DEFAULT 0,
  `reviews_posted`      INT UNSIGNED NOT NULL DEFAULT 0,
  `searches`            INT UNSIGNED NOT NULL DEFAULT 0,
  `profile_views`       INT UNSIGNED NOT NULL DEFAULT 0,
  `tickets_opened`      INT UNSIGNED NOT NULL DEFAULT 0,
  `computed_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-creator daily analytics (powers the creator Analytics screen).
CREATE TABLE IF NOT EXISTS `creator_analytics_daily` (
  `creator_id`        BIGINT UNSIGNED NOT NULL,
  `stat_date`         DATE         NOT NULL,
  `profile_views`     INT UNSIGNED NOT NULL DEFAULT 0,
  `booking_requests`  INT UNSIGNED NOT NULL DEFAULT 0,
  `bookings_accepted` INT UNSIGNED NOT NULL DEFAULT 0,
  `bookings_completed` INT UNSIGNED NOT NULL DEFAULT 0,
  `earnings`          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `new_followers`     INT UNSIGNED NOT NULL DEFAULT 0,
  `avg_rating`        DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  `computed_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`creator_id`, `stat_date`),
  KEY `idx_cad_date` (`stat_date`),
  CONSTRAINT `fk_cad_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Cron job registry (visible in Admin → System → Scheduled Jobs)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `scheduled_jobs` (
  `id`             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_key`        VARCHAR(60)  NOT NULL,
  `name`           VARCHAR(120) NOT NULL,
  `description`    VARCHAR(255) NULL,
  `schedule_hint`  VARCHAR(60)  NOT NULL COMMENT 'Human readable crontab expression',
  `is_enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_run_at`    DATETIME     NULL,
  `last_status`    ENUM('never','running','success','failed') NOT NULL DEFAULT 'never',
  `last_duration_ms` INT UNSIGNED NULL,
  `last_output`    VARCHAR(2000) NULL,
  `run_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `failure_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jobs_key` (`job_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Backup registry (Admin → System → Backup / Restore)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `backups` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_name`   VARCHAR(191) NOT NULL,
  `path`        VARCHAR(500) NOT NULL,
  `type`        ENUM('database','uploads','full') NOT NULL DEFAULT 'database',
  `size_bytes`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `checksum`    CHAR(64)     NULL,
  `trigger`     ENUM('manual','cron') NOT NULL DEFAULT 'manual',
  `status`      ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  `error`       VARCHAR(1000) NULL,
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME    NULL,
  PRIMARY KEY (`id`),
  KEY `idx_backups_created` (`created_at`),
  CONSTRAINT `fk_backups_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
