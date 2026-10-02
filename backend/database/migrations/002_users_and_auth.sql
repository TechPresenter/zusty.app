-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 002
-- App users (brands + creators), Firebase linkage, sessions, KYC.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Users
-- One row per human. `account_type` decides which side of the
-- marketplace they act on; a creator additionally owns a `creators` row.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`                CHAR(36)     NOT NULL,
  `firebase_uid`        VARCHAR(128) NULL COMMENT 'Firebase Authentication UID',
  `account_type`        ENUM('brand','creator') NOT NULL DEFAULT 'brand',
  `name`                VARCHAR(120) NOT NULL,
  `email`               VARCHAR(191) NULL,
  `phone_country_code`  VARCHAR(6)   NOT NULL DEFAULT '+91',
  `phone`               VARCHAR(20)  NULL,
  `password_hash`       VARCHAR(255) NULL COMMENT 'NULL when the account is federated-only',
  `avatar_url`          VARCHAR(500) NULL,
  `bio`                 VARCHAR(1000) NULL,
  `company_name`        VARCHAR(150) NULL,
  `website`             VARCHAR(255) NULL,
  `gst_number`          VARCHAR(20)  NULL,
  `city`                VARCHAR(100) NULL,
  `state`               VARCHAR(100) NULL,
  `country`             VARCHAR(100) NOT NULL DEFAULT 'India',
  `postal_code`         VARCHAR(12)  NULL,
  `locale`              VARCHAR(10)  NOT NULL DEFAULT 'en',
  `timezone`            VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',
  `currency`            CHAR(3)      NOT NULL DEFAULT 'INR',
  `auth_providers`      JSON         NULL COMMENT '["password","google.com","apple.com","phone"]',
  `status`              ENUM('pending','active','suspended','banned','deleted') NOT NULL DEFAULT 'active',
  `kyc_status`          ENUM('none','pending','verified','rejected') NOT NULL DEFAULT 'none',
  `email_verified_at`   DATETIME     NULL,
  `phone_verified_at`   DATETIME     NULL,
  `referral_code`       VARCHAR(16)  NOT NULL,
  `referred_by`         BIGINT UNSIGNED NULL,
  `notification_prefs`  JSON         NULL COMMENT '{"push":true,"email":true,"sms":false,"marketing":true}',
  `last_login_at`       DATETIME     NULL,
  `last_login_ip`       VARCHAR(45)  NULL,
  `last_seen_at`        DATETIME     NULL,
  `suspended_reason`    VARCHAR(255) NULL,
  `deletion_requested_at` DATETIME   NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_uuid`     (`uuid`),
  UNIQUE KEY `uq_users_email`    (`email`),
  UNIQUE KEY `uq_users_phone`    (`phone_country_code`, `phone`),
  UNIQUE KEY `uq_users_firebase` (`firebase_uid`),
  UNIQUE KEY `uq_users_referral` (`referral_code`),
  KEY `idx_users_type_status` (`account_type`, `status`, `deleted_at`),
  KEY `idx_users_referred_by` (`referred_by`),
  KEY `idx_users_created`     (`created_at`),
  CONSTRAINT `fk_users_referrer` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Refresh tokens (rotating). Access tokens are stateless JWTs.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `token_hash`     CHAR(64)     NOT NULL COMMENT 'SHA-256 of the opaque refresh token',
  `parent_id`      BIGINT UNSIGNED NULL COMMENT 'Previous token in the rotation chain',
  `device_id`      VARCHAR(191) NULL,
  `device_name`    VARCHAR(120) NULL,
  `platform`       ENUM('android','ios','web','unknown') NOT NULL DEFAULT 'unknown',
  `ip_address`     VARCHAR(45)  NULL,
  `user_agent`     VARCHAR(255) NULL,
  `expires_at`     DATETIME     NOT NULL,
  `revoked_at`     DATETIME     NULL,
  `revoked_reason` VARCHAR(120) NULL,
  `last_used_at`   DATETIME     NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rt_hash` (`token_hash`),
  KEY `idx_rt_user`   (`user_id`, `revoked_at`, `expires_at`),
  KEY `idx_rt_expiry` (`expires_at`),
  CONSTRAINT `fk_rt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Explicit JWT revocation list (logout-all, password change, ban).
CREATE TABLE IF NOT EXISTS `token_blacklist` (
  `jti`        CHAR(36)     NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`jti`),
  KEY `idx_tb_expiry` (`expires_at`),
  KEY `idx_tb_user`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Registered devices / FCM push tokens
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_devices` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `device_id`     VARCHAR(191) NOT NULL,
  `fcm_token`     VARCHAR(255) NULL,
  `platform`      ENUM('android','ios','web') NOT NULL,
  `device_model`  VARCHAR(120) NULL,
  `os_version`    VARCHAR(40)  NULL,
  `app_version`   VARCHAR(20)  NULL,
  `locale`        VARCHAR(10)  NULL,
  `push_enabled`  TINYINT(1)   NOT NULL DEFAULT 1,
  `last_active_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ud_user_device` (`user_id`, `device_id`),
  KEY `idx_ud_token` (`fcm_token`(100)),
  CONSTRAINT `fk_ud_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- OTP verifications (phone / email) — codes are stored hashed.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_verifications` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NULL,
  `channel`     ENUM('sms','email') NOT NULL,
  `destination` VARCHAR(191) NOT NULL COMMENT 'phone in E.164 or email address',
  `purpose`     ENUM('register','login','verify_phone','verify_email','reset_password','withdrawal','delete_account')
                NOT NULL,
  `code_hash`   CHAR(64)     NOT NULL,
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `expires_at`  DATETIME     NOT NULL,
  `verified_at` DATETIME     NULL,
  `ip_address`  VARCHAR(45)  NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_lookup` (`destination`, `purpose`, `expires_at`),
  KEY `idx_otp_user`   (`user_id`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     NULL,
  `ip_address` VARCHAR(45)  NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pr_token` (`token_hash`),
  KEY `idx_pr_user` (`user_id`, `expires_at`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- KYC documents
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kyc_documents` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `document_type`  ENUM('aadhaar','pan','passport','driving_license','gst','business_reg','other') NOT NULL,
  `document_number` VARCHAR(64)  NOT NULL COMMENT 'AES-256-GCM encrypted at rest',
  `holder_name`    VARCHAR(150) NOT NULL,
  `front_image_url` VARCHAR(500) NOT NULL,
  `back_image_url` VARCHAR(500) NULL,
  `selfie_url`     VARCHAR(500) NULL,
  `status`         ENUM('pending','under_review','verified','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by`    INT UNSIGNED NULL,
  `reviewed_at`    DATETIME     NULL,
  `rejection_reason` VARCHAR(500) NULL,
  `expires_on`     DATE         NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kyc_user`   (`user_id`, `status`),
  KEY `idx_kyc_status` (`status`, `created_at`),
  CONSTRAINT `fk_kyc_user`     FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_kyc_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Referral programme
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `referrals` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `referrer_user_id` BIGINT UNSIGNED NOT NULL,
  `referred_user_id` BIGINT UNSIGNED NOT NULL,
  `code`             VARCHAR(16)  NOT NULL,
  `referrer_reward`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `referred_reward`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status`           ENUM('pending','qualified','rewarded','void') NOT NULL DEFAULT 'pending',
  `qualified_at`     DATETIME     NULL COMMENT 'Set when the referred user completes their first paid booking',
  `rewarded_at`      DATETIME     NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_referrals_referred` (`referred_user_id`),
  KEY `idx_referrals_referrer` (`referrer_user_id`, `status`),
  CONSTRAINT `fk_ref_referrer` FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ref_referred` FOREIGN KEY (`referred_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Bank accounts / payout destinations
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `method`              ENUM('bank','upi') NOT NULL DEFAULT 'bank',
  `account_holder_name` VARCHAR(150) NULL,
  `account_number`      VARCHAR(255) NULL COMMENT 'AES-256-GCM encrypted at rest',
  `account_last4`       CHAR(4)      NULL COMMENT 'Plain last 4 digits for display',
  `ifsc_code`           VARCHAR(15)  NULL,
  `bank_name`           VARCHAR(150) NULL,
  `branch_name`         VARCHAR(150) NULL,
  `upi_id`              VARCHAR(120) NULL,
  `is_primary`          TINYINT(1)   NOT NULL DEFAULT 0,
  `is_verified`         TINYINT(1)   NOT NULL DEFAULT 0,
  `verified_at`         DATETIME     NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ba_user` (`user_id`, `deleted_at`),
  CONSTRAINT `fk_ba_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
