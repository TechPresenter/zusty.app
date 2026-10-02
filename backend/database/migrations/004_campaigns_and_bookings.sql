-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 004
-- Campaigns, bookings, counter-offers, status timeline, deliverables,
-- invoices and reviews.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Campaigns — a brand's brief. A booking may belong to a campaign
-- (multi-creator) or stand alone (direct booking from a profile).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(20)  NOT NULL COMMENT 'Human readable, e.g. ZC100045',
  `brand_user_id`  BIGINT UNSIGNED NOT NULL,
  `title`          VARCHAR(191) NOT NULL,
  `description`    TEXT         NOT NULL,
  `category_id`    INT UNSIGNED NULL,
  `platform`       ENUM('instagram','youtube','tiktok','facebook','twitter','linkedin','multi') NOT NULL DEFAULT 'instagram',
  `deliverable_type` ENUM('post','reel','story','video','short','live','ugc','blog','review','bundle') NOT NULL DEFAULT 'post',
  `posts_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `budget_min`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `budget_max`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency`       CHAR(3)      NOT NULL DEFAULT 'INR',
  `brief_url`      VARCHAR(500) NULL,
  `reference_urls` JSON         NULL,
  `requirements`   JSON         NULL COMMENT '{"min_followers":10000,"cities":["Mumbai"],"gender":"any"}',
  `starts_on`      DATE         NULL,
  `ends_on`        DATE         NULL,
  `applications_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `hired_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `status`         ENUM('draft','open','in_progress','completed','cancelled','archived') NOT NULL DEFAULT 'draft',
  `is_public`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Listed in the creator-facing opportunities feed',
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campaigns_code` (`code`),
  KEY `idx_campaigns_brand`  (`brand_user_id`, `status`),
  KEY `idx_campaigns_public` (`is_public`, `status`, `created_at`),
  CONSTRAINT `fk_campaigns_brand`    FOREIGN KEY (`brand_user_id`) REFERENCES `users` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_campaigns_category` FOREIGN KEY (`category_id`)   REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Bookings — the central transactional entity.
--
-- Status machine:
--   pending  -> accepted | rejected | countered | cancelled | expired
--   countered-> accepted | rejected | cancelled
--   accepted -> awaiting_payment
--   awaiting_payment -> confirmed (payment captured into escrow)
--   confirmed-> in_progress -> delivered -> completed
--   any live -> disputed -> completed | refunded
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bookings` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`              VARCHAR(20)  NOT NULL COMMENT 'Displayed as #ZB123456',
  `campaign_id`       BIGINT UNSIGNED NULL,
  `brand_user_id`     BIGINT UNSIGNED NOT NULL,
  `creator_id`        BIGINT UNSIGNED NOT NULL,
  `creator_user_id`   BIGINT UNSIGNED NOT NULL COMMENT 'Denormalised for fast "my bookings" queries',
  `package_id`        BIGINT UNSIGNED NULL,
  `title`             VARCHAR(191) NOT NULL,
  `description`       TEXT         NULL,
  `campaign_type`     VARCHAR(80)  NOT NULL DEFAULT 'Instagram Post',
  `platform`          ENUM('instagram','youtube','tiktok','facebook','twitter','linkedin','multi') NOT NULL DEFAULT 'instagram',
  `deliverable_type`  ENUM('post','reel','story','video','short','live','ugc','blog','review','bundle') NOT NULL DEFAULT 'post',
  `posts_count`       SMALLINT UNSIGNED NOT NULL DEFAULT 1,

  -- Money (all amounts in minor-unit-safe DECIMAL, currency held once)
  `currency`          CHAR(3)      NOT NULL DEFAULT 'INR',
  `amount`            DECIMAL(12,2) NOT NULL COMMENT 'Creator payout base (agreed fee)',
  `platform_fee`      DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Charged to the brand',
  `commission_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Deducted from the creator payout',
  `tax_amount`        DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'GST on the platform fee',
  `tax_rate`          DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `total_amount`      DECIMAL(12,2) NOT NULL COMMENT 'amount + platform_fee + tax_amount — what the brand pays',
  `creator_earning`   DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'amount - commission_amount',

  `status`            ENUM('pending','countered','accepted','rejected','awaiting_payment','confirmed',
                           'in_progress','delivered','completed','cancelled','expired','disputed','refunded')
                      NOT NULL DEFAULT 'pending',
  `payment_status`    ENUM('unpaid','processing','paid','partially_refunded','refunded','failed')
                      NOT NULL DEFAULT 'unpaid',
  `escrow_status`     ENUM('none','held','released','refunded') NOT NULL DEFAULT 'none',

  `requested_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`        DATETIME     NULL COMMENT 'Auto-expire un-answered requests',
  `responded_at`      DATETIME     NULL,
  `accepted_at`       DATETIME     NULL,
  `paid_at`           DATETIME     NULL,
  `started_at`        DATETIME     NULL,
  `delivery_due_at`   DATETIME     NULL,
  `delivered_at`      DATETIME     NULL,
  `completed_at`      DATETIME     NULL,
  `cancelled_at`      DATETIME     NULL,
  `cancelled_by_type` ENUM('brand','creator','admin','system') NULL,
  `cancelled_by_id`   BIGINT UNSIGNED NULL,
  `cancellation_reason` VARCHAR(500) NULL,
  `rejection_reason`  VARCHAR(500) NULL,

  `brand_reviewed`    TINYINT(1)   NOT NULL DEFAULT 0,
  `creator_reviewed`  TINYINT(1)   NOT NULL DEFAULT 0,
  `unread_brand`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `unread_creator`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `metadata`          JSON         NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`        DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bookings_code` (`code`),
  KEY `idx_bookings_brand`    (`brand_user_id`, `status`, `created_at`),
  KEY `idx_bookings_creator`  (`creator_user_id`, `status`, `created_at`),
  KEY `idx_bookings_creator_profile` (`creator_id`, `status`),
  KEY `idx_bookings_campaign` (`campaign_id`),
  KEY `idx_bookings_status`   (`status`, `created_at`),
  KEY `idx_bookings_payment`  (`payment_status`, `escrow_status`),
  KEY `idx_bookings_expiry`   (`status`, `expires_at`),
  CONSTRAINT `fk_bookings_campaign`     FOREIGN KEY (`campaign_id`)     REFERENCES `campaigns` (`id`)         ON DELETE SET NULL,
  CONSTRAINT `fk_bookings_brand`        FOREIGN KEY (`brand_user_id`)   REFERENCES `users` (`id`)             ON DELETE CASCADE,
  CONSTRAINT `fk_bookings_creator`      FOREIGN KEY (`creator_id`)      REFERENCES `creators` (`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_bookings_creator_user` FOREIGN KEY (`creator_user_id`) REFERENCES `users` (`id`)             ON DELETE CASCADE,
  CONSTRAINT `fk_bookings_package`      FOREIGN KEY (`package_id`)      REFERENCES `creator_packages` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Counter offers / negotiation trail
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking_offers` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`    BIGINT UNSIGNED NOT NULL,
  `offered_by`    ENUM('brand','creator') NOT NULL,
  `offered_by_id` BIGINT UNSIGNED NOT NULL,
  `amount`        DECIMAL(12,2) NOT NULL,
  `posts_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `delivery_days` SMALLINT UNSIGNED NULL,
  `message`       VARCHAR(1000) NULL,
  `status`        ENUM('pending','accepted','rejected','superseded','withdrawn') NOT NULL DEFAULT 'pending',
  `responded_at`  DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bo_booking` (`booking_id`, `created_at`),
  KEY `idx_bo_status`  (`status`),
  CONSTRAINT `fk_bo_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bo_user`    FOREIGN KEY (`offered_by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Status timeline (rendered as the booking progress stepper in the app)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking_status_history` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`     BIGINT UNSIGNED NOT NULL,
  `from_status`    VARCHAR(32)  NULL,
  `to_status`      VARCHAR(32)  NOT NULL,
  `changed_by_type` ENUM('brand','creator','admin','system','cron') NOT NULL DEFAULT 'system',
  `changed_by_id`  BIGINT UNSIGNED NULL,
  `note`           VARCHAR(500) NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bsh_booking` (`booking_id`, `created_at`),
  CONSTRAINT `fk_bsh_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Deliverables submitted by the creator, approved by the brand
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking_deliverables` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`    BIGINT UNSIGNED NOT NULL,
  `title`         VARCHAR(191) NOT NULL,
  `description`   VARCHAR(1000) NULL,
  `content_url`   VARCHAR(500) NULL COMMENT 'Live post permalink',
  `media_url`     VARCHAR(500) NULL COMMENT 'Uploaded proof / raw asset',
  `thumbnail_url` VARCHAR(500) NULL,
  `metrics`       JSON         NULL COMMENT '{"views":120000,"likes":8400,"saves":320}',
  `status`        ENUM('pending','submitted','revision_requested','approved','rejected') NOT NULL DEFAULT 'pending',
  `revision_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `remarks`       VARCHAR(1000) NULL,
  `submitted_at`  DATETIME     NULL,
  `reviewed_at`   DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bd_booking` (`booking_id`, `status`),
  CONSTRAINT `fk_bd_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Invoices (generated on payment capture)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(30)  NOT NULL COMMENT 'ZI/2025-26/000123',
  `booking_id`     BIGINT UNSIGNED NOT NULL,
  `user_id`        BIGINT UNSIGNED NOT NULL COMMENT 'Billed party (the brand)',
  `type`           ENUM('brand_invoice','creator_payout') NOT NULL DEFAULT 'brand_invoice',
  `bill_to_name`   VARCHAR(191) NOT NULL,
  `bill_to_email`  VARCHAR(191) NULL,
  `bill_to_address` VARCHAR(500) NULL,
  `bill_to_gst`    VARCHAR(20)  NULL,
  `line_items`     JSON         NOT NULL,
  `subtotal`       DECIMAL(12,2) NOT NULL,
  `fee_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate`       DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`          DECIMAL(12,2) NOT NULL,
  `currency`       CHAR(3)      NOT NULL DEFAULT 'INR',
  `status`         ENUM('draft','issued','paid','cancelled','refunded') NOT NULL DEFAULT 'issued',
  `issued_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `due_at`         DATETIME     NULL,
  `paid_at`        DATETIME     NULL,
  `pdf_path`       VARCHAR(500) NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_number` (`invoice_number`),
  KEY `idx_invoices_booking` (`booking_id`),
  KEY `idx_invoices_user`    (`user_id`, `issued_at`),
  CONSTRAINT `fk_invoices_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoices_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Reviews & ratings (one per booking per direction)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reviews` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`    BIGINT UNSIGNED NOT NULL,
  `direction`     ENUM('brand_to_creator','creator_to_brand') NOT NULL DEFAULT 'brand_to_creator',
  `reviewer_id`   BIGINT UNSIGNED NOT NULL,
  `reviewee_id`   BIGINT UNSIGNED NOT NULL,
  `creator_id`    BIGINT UNSIGNED NULL COMMENT 'Set when direction = brand_to_creator',
  `rating`        TINYINT UNSIGNED NOT NULL COMMENT '1-5',
  `quality_rating`       TINYINT UNSIGNED NULL,
  `communication_rating` TINYINT UNSIGNED NULL,
  `timeliness_rating`    TINYINT UNSIGNED NULL,
  `title`         VARCHAR(191) NULL,
  `comment`       VARCHAR(2000) NULL,
  `media_urls`    JSON         NULL,
  `reply`         VARCHAR(2000) NULL,
  `replied_at`    DATETIME     NULL,
  `is_published`  TINYINT(1)   NOT NULL DEFAULT 1,
  `is_flagged`    TINYINT(1)   NOT NULL DEFAULT 0,
  `moderated_by`  INT UNSIGNED NULL,
  `moderated_at`  DATETIME     NULL,
  `helpful_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reviews_booking_direction` (`booking_id`, `direction`),
  KEY `idx_reviews_creator`  (`creator_id`, `is_published`, `created_at`),
  KEY `idx_reviews_reviewee` (`reviewee_id`, `is_published`),
  KEY `idx_reviews_rating`   (`rating`),
  CONSTRAINT `fk_reviews_booking`   FOREIGN KEY (`booking_id`)   REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_reviewer`  FOREIGN KEY (`reviewer_id`)  REFERENCES `users` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_reviewee`  FOREIGN KEY (`reviewee_id`)  REFERENCES `users` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_creator`   FOREIGN KEY (`creator_id`)   REFERENCES `creators` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_moderator` FOREIGN KEY (`moderated_by`) REFERENCES `admins` (`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Abuse / content reports
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reports` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reporter_id`     BIGINT UNSIGNED NOT NULL,
  `reportable_type` ENUM('user','creator','booking','review','message','portfolio') NOT NULL,
  `reportable_id`   BIGINT UNSIGNED NOT NULL,
  `reason`          ENUM('spam','fraud','abuse','impersonation','inappropriate','copyright','payment_issue','other') NOT NULL,
  `description`     VARCHAR(2000) NULL,
  `evidence_urls`   JSON         NULL,
  `status`          ENUM('open','investigating','resolved','dismissed') NOT NULL DEFAULT 'open',
  `handled_by`      INT UNSIGNED NULL,
  `handled_at`      DATETIME     NULL,
  `resolution_note` VARCHAR(1000) NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reports_subject` (`reportable_type`, `reportable_id`),
  KEY `idx_reports_status`  (`status`, `created_at`),
  CONSTRAINT `fk_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_reports_handler`  FOREIGN KEY (`handled_by`)  REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Disputes raised against a booking
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `booking_disputes` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`    BIGINT UNSIGNED NOT NULL,
  `raised_by`     ENUM('brand','creator') NOT NULL,
  `raised_by_id`  BIGINT UNSIGNED NOT NULL,
  `reason`        ENUM('not_delivered','poor_quality','late_delivery','payment_issue','scope_change','other') NOT NULL,
  `description`   VARCHAR(2000) NOT NULL,
  `evidence_urls` JSON         NULL,
  `status`        ENUM('open','under_review','resolved_brand','resolved_creator','resolved_split','withdrawn') NOT NULL DEFAULT 'open',
  `refund_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payout_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `resolution_note` VARCHAR(2000) NULL,
  `handled_by`    INT UNSIGNED NULL,
  `resolved_at`   DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_disputes_booking` (`booking_id`),
  KEY `idx_disputes_status`  (`status`, `created_at`),
  CONSTRAINT `fk_disputes_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_disputes_handler` FOREIGN KEY (`handled_by`) REFERENCES `admins` (`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
