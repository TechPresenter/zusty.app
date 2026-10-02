-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 005
-- Wallets, ledger, payment gateway records, refunds, withdrawals,
-- settlements and platform commission tracking.
--
-- Money model
-- -----------
-- Every balance change is written as an immutable `wallet_transactions`
-- row inside the same DB transaction that mutates `wallets`. The wallet
-- row is the fast read-model; the ledger is the source of truth and can
-- always be replayed to verify a balance.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Wallets — exactly one per user, created on registration.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallets` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `currency`         CHAR(3)      NOT NULL DEFAULT 'INR',
  `balance`          DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Withdrawable / spendable',
  `locked_balance`   DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Held in escrow or pending withdrawal',
  `lifetime_credit`  DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `lifetime_debit`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `is_frozen`        TINYINT(1)   NOT NULL DEFAULT 0,
  `frozen_reason`    VARCHAR(255) NULL,
  `last_activity_at` DATETIME     NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wallets_user` (`user_id`),
  KEY `idx_wallets_frozen` (`is_frozen`),
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Immutable double-entry-style ledger.
-- `idempotency_key` guarantees a retried API call never double-posts.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`       VARCHAR(24)  NOT NULL COMMENT 'Public txn reference, e.g. ZTXN0000012345',
  `wallet_id`       BIGINT UNSIGNED NOT NULL,
  `user_id`         BIGINT UNSIGNED NOT NULL,
  `direction`       ENUM('credit','debit') NOT NULL,
  `category`        ENUM('deposit','withdrawal','booking_payment','booking_earning','escrow_hold',
                         'escrow_release','commission','platform_fee','refund','bonus','referral',
                         'adjustment','reversal','tax')
                    NOT NULL,
  `amount`          DECIMAL(14,2) NOT NULL,
  `balance_before`  DECIMAL(14,2) NOT NULL,
  `balance_after`   DECIMAL(14,2) NOT NULL,
  `currency`        CHAR(3)      NOT NULL DEFAULT 'INR',
  `status`          ENUM('pending','completed','failed','reversed') NOT NULL DEFAULT 'completed',
  `reference_type`  ENUM('booking','payment','refund','withdrawal','referral','manual','settlement') NULL,
  `reference_id`    BIGINT UNSIGNED NULL,
  `counterparty_id` BIGINT UNSIGNED NULL COMMENT 'The other user in the transfer, when applicable',
  `description`     VARCHAR(255) NOT NULL,
  `idempotency_key` VARCHAR(120) NULL,
  `metadata`        JSON         NULL,
  `performed_by_type` ENUM('user','admin','system','cron','webhook') NOT NULL DEFAULT 'system',
  `performed_by_id` BIGINT UNSIGNED NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wt_reference`   (`reference`),
  UNIQUE KEY `uq_wt_idempotency` (`idempotency_key`),
  KEY `idx_wt_wallet`    (`wallet_id`, `created_at`),
  KEY `idx_wt_user`      (`user_id`, `created_at`),
  KEY `idx_wt_category`  (`category`, `created_at`),
  KEY `idx_wt_reference` (`reference_type`, `reference_id`),
  CONSTRAINT `fk_wt_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wt_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Gateway payments (Razorpay / Stripe / wallet)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`          VARCHAR(24)  NOT NULL COMMENT 'Public payment reference, e.g. ZPAY0000004321',
  `booking_id`         BIGINT UNSIGNED NULL,
  `user_id`            BIGINT UNSIGNED NOT NULL,
  `purpose`            ENUM('booking','wallet_topup') NOT NULL DEFAULT 'booking',
  `gateway`            ENUM('razorpay','stripe','wallet','manual') NOT NULL,
  `method`             VARCHAR(40)  NULL COMMENT 'card | upi | netbanking | wallet | emi',
  `gateway_order_id`   VARCHAR(120) NULL,
  `gateway_payment_id` VARCHAR(120) NULL,
  `gateway_signature`  VARCHAR(255) NULL,
  `amount`             DECIMAL(12,2) NOT NULL,
  `fee_amount`         DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Gateway fee, informational',
  `amount_refunded`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency`           CHAR(3)      NOT NULL DEFAULT 'INR',
  `status`             ENUM('created','pending','authorized','captured','failed','cancelled','refunded','partially_refunded')
                       NOT NULL DEFAULT 'created',
  `failure_code`       VARCHAR(80)  NULL,
  `failure_reason`     VARCHAR(500) NULL,
  `card_last4`         CHAR(4)      NULL,
  `card_network`       VARCHAR(30)  NULL,
  `upi_vpa`            VARCHAR(120) NULL,
  `bank_name`          VARCHAR(120) NULL,
  `idempotency_key`    VARCHAR(120) NULL,
  `gateway_response`   JSON         NULL,
  `ip_address`         VARCHAR(45)  NULL,
  `authorized_at`      DATETIME     NULL,
  `paid_at`            DATETIME     NULL,
  `failed_at`          DATETIME     NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_reference`   (`reference`),
  UNIQUE KEY `uq_payments_idempotency` (`idempotency_key`),
  UNIQUE KEY `uq_payments_gw_payment`  (`gateway`, `gateway_payment_id`),
  KEY `idx_payments_booking` (`booking_id`),
  KEY `idx_payments_user`    (`user_id`, `created_at`),
  KEY `idx_payments_status`  (`status`, `created_at`),
  KEY `idx_payments_order`   (`gateway_order_id`),
  CONSTRAINT `fk_payments_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Raw webhook log — every gateway callback is persisted before handling
-- so a failed handler can be replayed safely.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_webhooks` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gateway`        ENUM('razorpay','stripe') NOT NULL,
  `event_id`       VARCHAR(120) NOT NULL,
  `event_type`     VARCHAR(120) NOT NULL,
  `signature`      VARCHAR(500) NULL,
  `payload`        LONGTEXT     NOT NULL,
  `is_verified`    TINYINT(1)   NOT NULL DEFAULT 0,
  `status`         ENUM('received','processed','failed','ignored') NOT NULL DEFAULT 'received',
  `error_message`  VARCHAR(500) NULL,
  `attempts`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `processed_at`   DATETIME     NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pw_event` (`gateway`, `event_id`),
  KEY `idx_pw_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Refunds
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `refunds` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`         VARCHAR(24)  NOT NULL,
  `payment_id`        BIGINT UNSIGNED NOT NULL,
  `booking_id`        BIGINT UNSIGNED NULL,
  `user_id`           BIGINT UNSIGNED NOT NULL,
  `amount`            DECIMAL(12,2) NOT NULL,
  `currency`          CHAR(3)      NOT NULL DEFAULT 'INR',
  `destination`       ENUM('source','wallet') NOT NULL DEFAULT 'source',
  `reason`            ENUM('booking_cancelled','booking_rejected','booking_expired','dispute','duplicate','other') NOT NULL,
  `notes`             VARCHAR(1000) NULL,
  `gateway_refund_id` VARCHAR(120) NULL,
  `status`            ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `failure_reason`    VARCHAR(500) NULL,
  `initiated_by_type` ENUM('user','admin','system') NOT NULL DEFAULT 'system',
  `initiated_by_id`   BIGINT UNSIGNED NULL,
  `gateway_response`  JSON         NULL,
  `processed_at`      DATETIME     NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refunds_reference` (`reference`),
  KEY `idx_refunds_payment` (`payment_id`),
  KEY `idx_refunds_user`    (`user_id`, `created_at`),
  KEY `idx_refunds_status`  (`status`),
  CONSTRAINT `fk_refunds_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_refunds_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_refunds_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Withdrawal (payout) requests raised by creators
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `withdrawal_requests` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`        VARCHAR(24)  NOT NULL,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `bank_account_id`  BIGINT UNSIGNED NULL,
  `amount`           DECIMAL(12,2) NOT NULL,
  `fee_amount`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_amount`       DECIMAL(12,2) NOT NULL,
  `currency`         CHAR(3)      NOT NULL DEFAULT 'INR',
  `method`           ENUM('bank','upi') NOT NULL DEFAULT 'bank',
  `status`           ENUM('pending','approved','processing','completed','rejected','failed','cancelled')
                     NOT NULL DEFAULT 'pending',
  `payout_reference` VARCHAR(120) NULL COMMENT 'UTR / NEFT reference from the bank',
  `remarks`          VARCHAR(1000) NULL,
  `rejection_reason` VARCHAR(500) NULL,
  `processed_by`     INT UNSIGNED NULL,
  `approved_at`      DATETIME     NULL,
  `processed_at`     DATETIME     NULL,
  `snapshot`         JSON         NULL COMMENT 'Frozen copy of the payout destination at request time',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wr_reference` (`reference`),
  KEY `idx_wr_user`   (`user_id`, `status`, `created_at`),
  KEY `idx_wr_status` (`status`, `created_at`),
  CONSTRAINT `fk_wr_user`    FOREIGN KEY (`user_id`)         REFERENCES `users` (`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_wr_bank`    FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`)  ON DELETE SET NULL,
  CONSTRAINT `fk_wr_admin`   FOREIGN KEY (`processed_by`)    REFERENCES `admins` (`id`)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Daily settlement roll-up (finance reporting + reconciliation)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settlements` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `settlement_date`  DATE         NOT NULL,
  `gross_volume`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `platform_fees`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `commissions`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `taxes`            DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `refunds_total`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `payouts_total`    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `net_revenue`      DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `bookings_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `payments_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `currency`         CHAR(3)      NOT NULL DEFAULT 'INR',
  `status`           ENUM('open','closed','reconciled') NOT NULL DEFAULT 'open',
  `closed_at`        DATETIME     NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settlements_date` (`settlement_date`, `currency`),
  KEY `idx_settlements_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Platform earnings ledger (commission + fee recognised per booking)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `platform_earnings` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`    BIGINT UNSIGNED NOT NULL,
  `payment_id`    BIGINT UNSIGNED NULL,
  `platform_fee`  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `commission`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_collected` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total`         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `currency`      CHAR(3)      NOT NULL DEFAULT 'INR',
  `status`        ENUM('accrued','recognised','reversed') NOT NULL DEFAULT 'accrued',
  `earned_on`     DATE         NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pe_booking` (`booking_id`),
  KEY `idx_pe_date`   (`earned_on`),
  KEY `idx_pe_status` (`status`),
  CONSTRAINT `fk_pe_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pe_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
