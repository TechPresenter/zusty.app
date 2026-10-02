-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 003
-- Categories, creator profiles, portfolio, packages, availability,
-- social accounts, follows and favourites.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Categories (self-referencing tree, 2 levels used by the app)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`     INT UNSIGNED NULL,
  `name`          VARCHAR(100) NOT NULL,
  `slug`          VARCHAR(120) NOT NULL,
  `description`   VARCHAR(500) NULL,
  `icon`          VARCHAR(64)  NULL COMMENT 'Material Symbols icon name',
  `icon_url`      VARCHAR(500) NULL COMMENT 'Round thumbnail used on the Home carousel',
  `cover_image`   VARCHAR(500) NULL COMMENT 'Hero image used on the Categories grid',
  `color_hex`     CHAR(7)      NULL,
  `creator_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Denormalised, refreshed by cron',
  `is_featured`   TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`    SMALLINT     NOT NULL DEFAULT 0,
  `meta_title`       VARCHAR(191) NULL,
  `meta_description` VARCHAR(255) NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`),
  KEY `idx_categories_active` (`is_active`, `sort_order`),
  CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Creator profiles
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `creators` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `handle`              VARCHAR(60)  NOT NULL COMMENT 'Public @handle, unique',
  `display_name`        VARCHAR(120) NOT NULL,
  `headline`            VARCHAR(150) NULL COMMENT 'e.g. "Lifestyle Blogger"',
  `about`               TEXT         NULL,
  `cover_image_url`     VARCHAR(500) NULL,
  `primary_category_id` INT UNSIGNED NULL,
  `city`                VARCHAR(100) NULL,
  `state`               VARCHAR(100) NULL,
  `country`             VARCHAR(100) NOT NULL DEFAULT 'India',
  `gender`              ENUM('male','female','non_binary','prefer_not_to_say') NULL,
  `date_of_birth`       DATE         NULL,
  `languages`           JSON         NULL COMMENT '["English","Hindi","Marathi"]',
  `price_min`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `price_max`           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency`            CHAR(3)      NOT NULL DEFAULT 'INR',
  `rating_avg`          DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  `rating_count`        INT UNSIGNED NOT NULL DEFAULT 0,
  `followers_total`     BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sum across linked social accounts',
  `platform_followers`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Followers inside Zuste+',
  `posts_count`         INT UNSIGNED NOT NULL DEFAULT 0,
  `collabs_count`       INT UNSIGNED NOT NULL DEFAULT 0,
  `completion_rate`     DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `response_time_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 48,
  `availability_status` ENUM('available','busy','unavailable') NOT NULL DEFAULT 'available',
  `commission_rate`     DECIMAL(5,2) NULL COMMENT 'NULL = inherit global platform commission',
  `is_verified`         TINYINT(1)   NOT NULL DEFAULT 0,
  `verified_at`         DATETIME     NULL,
  `is_featured`         TINYINT(1)   NOT NULL DEFAULT 0,
  `featured_until`      DATETIME     NULL,
  `status`              ENUM('draft','pending_review','approved','rejected','suspended') NOT NULL DEFAULT 'pending_review',
  `rejection_reason`    VARCHAR(500) NULL,
  `approved_by`         INT UNSIGNED NULL,
  `approved_at`         DATETIME     NULL,
  `profile_score`       TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 completeness score',
  `search_boost`        DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  `meta_title`          VARCHAR(191) NULL,
  `meta_description`    VARCHAR(255) NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_creators_user`   (`user_id`),
  UNIQUE KEY `uq_creators_handle` (`handle`),
  KEY `idx_creators_status`   (`status`, `deleted_at`),
  KEY `idx_creators_featured` (`is_featured`, `rating_avg`),
  KEY `idx_creators_category` (`primary_category_id`, `status`),
  KEY `idx_creators_city`     (`city`),
  KEY `idx_creators_price`    (`price_min`, `price_max`),
  KEY `idx_creators_rating`   (`rating_avg`, `rating_count`),
  FULLTEXT KEY `ft_creators_search` (`display_name`, `headline`, `about`),
  CONSTRAINT `fk_creators_user`     FOREIGN KEY (`user_id`)             REFERENCES `users` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_creators_category` FOREIGN KEY (`primary_category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_creators_approver` FOREIGN KEY (`approved_by`)         REFERENCES `admins` (`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `creator_categories` (
  `creator_id`  BIGINT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`creator_id`, `category_id`),
  KEY `idx_cc_category` (`category_id`),
  CONSTRAINT `fk_cc_creator`  FOREIGN KEY (`creator_id`)  REFERENCES `creators` (`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_cc_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Linked social accounts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `creator_social_accounts` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`      BIGINT UNSIGNED NOT NULL,
  `platform`        ENUM('instagram','youtube','tiktok','facebook','twitter','linkedin','snapchat','pinterest','threads') NOT NULL,
  `handle`          VARCHAR(120) NOT NULL,
  `profile_url`     VARCHAR(500) NOT NULL,
  `followers`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `following`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `posts`           INT UNSIGNED NOT NULL DEFAULT 0,
  `avg_likes`       INT UNSIGNED NOT NULL DEFAULT 0,
  `avg_comments`    INT UNSIGNED NOT NULL DEFAULT 0,
  `avg_views`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `engagement_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `audience_stats`  JSON         NULL COMMENT '{"gender":{...},"age":{...},"geo":{...}}',
  `is_verified`     TINYINT(1)   NOT NULL DEFAULT 0,
  `is_primary`      TINYINT(1)   NOT NULL DEFAULT 0,
  `last_synced_at`  DATETIME     NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_csa_creator_platform` (`creator_id`, `platform`),
  KEY `idx_csa_followers` (`platform`, `followers`),
  CONSTRAINT `fk_csa_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Pricing packages (what a brand actually books)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `creator_packages` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`       BIGINT UNSIGNED NOT NULL,
  `title`            VARCHAR(150) NOT NULL,
  `description`      VARCHAR(1000) NULL,
  `platform`         ENUM('instagram','youtube','tiktok','facebook','twitter','linkedin','multi') NOT NULL,
  `deliverable_type` ENUM('post','reel','story','video','short','live','ugc','blog','review','bundle') NOT NULL,
  `quantity`         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `price`            DECIMAL(12,2) NOT NULL,
  `currency`         CHAR(3)      NOT NULL DEFAULT 'INR',
  `turnaround_days`  SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  `revisions`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `includes`         JSON         NULL COMMENT '["Story reshare","Usage rights 30d"]',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`       SMALLINT     NOT NULL DEFAULT 0,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`       DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cp_creator` (`creator_id`, `is_active`, `sort_order`),
  KEY `idx_cp_price`   (`price`),
  CONSTRAINT `fk_cp_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Portfolio media — powers the Posts / Reels / Photos / Videos tabs
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `creator_portfolio` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`     BIGINT UNSIGNED NOT NULL,
  `media_kind`     ENUM('post','reel','photo','video') NOT NULL,
  `title`          VARCHAR(191) NULL,
  `caption`        VARCHAR(1000) NULL,
  `media_url`      VARCHAR(500) NOT NULL,
  `thumbnail_url`  VARCHAR(500) NULL,
  `external_url`   VARCHAR(500) NULL COMMENT 'Original Instagram/YouTube permalink',
  `source_platform` ENUM('instagram','youtube','tiktok','upload','other') NOT NULL DEFAULT 'upload',
  `aspect_ratio`   VARCHAR(10)  NULL COMMENT '3:4 | 9:16 | 16:9',
  `duration_secs`  INT UNSIGNED NULL,
  `views_count`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `likes_count`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `comments_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `published_at`   DATETIME     NULL,
  `is_featured`    TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`     SMALLINT     NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_portfolio_creator` (`creator_id`, `media_kind`, `is_active`, `sort_order`),
  KEY `idx_portfolio_views`   (`views_count`),
  CONSTRAINT `fk_portfolio_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Availability calendar (day granularity + optional slot list)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `creator_availability` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`    BIGINT UNSIGNED NOT NULL,
  `available_on`  DATE         NOT NULL,
  `is_available`  TINYINT(1)   NOT NULL DEFAULT 1,
  `slots`         JSON         NULL COMMENT '[{"from":"10:00","to":"13:00"}]',
  `max_bookings`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `booked_count`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `note`          VARCHAR(255) NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ca_creator_date` (`creator_id`, `available_on`),
  KEY `idx_ca_date` (`available_on`, `is_available`),
  CONSTRAINT `fk_ca_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Favourites (Saved Creators screen) and follows
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `favorites` (
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `creator_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `creator_id`),
  KEY `idx_fav_creator` (`creator_id`),
  CONSTRAINT `fk_fav_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_fav_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `follows` (
  `follower_id` BIGINT UNSIGNED NOT NULL COMMENT 'users.id',
  `creator_id`  BIGINT UNSIGNED NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`follower_id`, `creator_id`),
  KEY `idx_follows_creator` (`creator_id`),
  CONSTRAINT `fk_follows_user`    FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_follows_creator` FOREIGN KEY (`creator_id`)  REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Search analytics — feeds "Recent Searches" and "Popular Searches"
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `search_histories` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NULL,
  `term`        VARCHAR(150) NOT NULL,
  `filters`     JSON         NULL,
  `result_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sh_user` (`user_id`, `created_at`),
  KEY `idx_sh_term` (`term`),
  CONSTRAINT `fk_sh_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `popular_searches` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `term`          VARCHAR(150) NOT NULL,
  `hits`          INT UNSIGNED NOT NULL DEFAULT 1,
  `is_curated`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Pinned manually from the admin panel',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_hit_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ps_term` (`term`),
  KEY `idx_ps_rank` (`is_active`, `hits`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Creator profile view tracking (analytics)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `creator_profile_views` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id` BIGINT UNSIGNED NOT NULL,
  `viewer_id`  BIGINT UNSIGNED NULL,
  `source`     ENUM('search','category','home','direct','share','explore') NOT NULL DEFAULT 'direct',
  `viewed_on`  DATE         NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cpv_creator_date` (`creator_id`, `viewed_on`),
  KEY `idx_cpv_viewer`       (`viewer_id`),
  CONSTRAINT `fk_cpv_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cpv_viewer`  FOREIGN KEY (`viewer_id`)  REFERENCES `users` (`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
