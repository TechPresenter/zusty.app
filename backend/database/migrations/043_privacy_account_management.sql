-- =====================================================================
-- 043 — Privacy & account management
--
-- Self-service personal-data exports ("Download my data") and account
-- deletion ("Delete my account") for brands and creators.
--
-- Both actions are re-authenticated: the password, or a one-time code sent
-- to the account's own email or phone. Deletion is an erasure, not a hide:
-- personal data is removed or anonymised, and the `users` row survives only
-- as an anonymous tombstone because bookings, payments, invoices, refunds and
-- the wallet ledger must keep pointing at something (031 made those foreign
-- keys RESTRICT for exactly that reason).
-- =====================================================================

-- A one-time code can now confirm a data export, alongside the existing
-- `delete_account` purpose.
ALTER TABLE `otp_verifications`
  MODIFY `purpose` ENUM(
    'register','login','verify_phone','verify_email','reset_password','withdrawal',
    'delete_account','change_email','change_phone','verify_alt_email','verify_alt_phone',
    'data_export'
  ) NOT NULL;

-- ---------------------------------------------------------------------
-- Data exports
--
-- One row per request. The archive lives under storage/exports (outside the
-- web root) and is reachable only through a download token. The token is
-- stored twice: as a sha256 hash for lookup, and encrypted so the signed-in
-- owner can be shown the same link again. The `privacy:purge-exports` job
-- deletes the file once the link expires or is used up, and clears both
-- copies of the token. The row itself stays as the record that an export
-- happened, which is what an operator needs when a user asks "who downloaded
-- my data, and when?".
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `data_export_requests` (
  `id`                 BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `user_id`            BIGINT UNSIGNED   NOT NULL,
  `status`             ENUM('processing','ready','failed','expired') NOT NULL DEFAULT 'processing',
  `file_path`          VARCHAR(255)      NULL COMMENT 'Relative to storage/exports',
  `file_size`          BIGINT UNSIGNED   NULL,
  `checksum`           CHAR(64)          NULL COMMENT 'sha256 of the archive',
  `token_hash`         CHAR(64)          NULL COMMENT 'sha256 of the download token, for lookup',
  `download_token`     VARCHAR(255)      NULL COMMENT 'Crypto::encrypt(token), re-shown to its owner while live',
  `download_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_downloads`      SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  `verified_via`       ENUM('password','email_code','sms_code') NOT NULL,
  `ip_address`         VARCHAR(45)       NULL,
  `user_agent`         VARCHAR(255)      NULL,
  `error`              VARCHAR(255)      NULL,
  `expires_at`         DATETIME          NULL,
  `last_downloaded_at` DATETIME          NULL,
  `created_at`         DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at`       DATETIME          NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_der_token` (`token_hash`),
  KEY `idx_der_user` (`user_id`, `created_at`),
  KEY `idx_der_expiry` (`status`, `expires_at`),
  CONSTRAINT `fk_der_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Account deletions
--
-- The audit record of a self-service erasure: who (the tombstoned user id),
-- when, how they proved it was them, the optional reason they gave, and a
-- JSON summary of what was erased and what was retained, as counts only.
-- It deliberately holds no name, email, phone or IP. The point of the
-- erasure is that those are gone.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `account_deletion_requests` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `account_type` ENUM('brand','creator') NOT NULL,
  `reason_code`  VARCHAR(40)     NULL,
  `reason_text`  VARCHAR(500)    NULL,
  `verified_via` ENUM('password','email_code','sms_code') NOT NULL,
  `platform`     ENUM('android','ios','web','unknown') NOT NULL DEFAULT 'unknown',
  `summary`      LONGTEXT        NULL COMMENT 'JSON: erased / retained counts',
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME        NULL,
  PRIMARY KEY (`id`),
  KEY `idx_adr_user` (`user_id`),
  KEY `idx_adr_created` (`created_at`),
  CONSTRAINT `fk_adr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Operator settings (Settings → Security)
--
-- `value` is left alone on a re-run so an operator's change survives.
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('security', 'privacy_export_cooldown_hours', '24', 'integer', 'Data export cooldown (hours)',
   'How long a user must wait between two personal-data export requests (1–720).', 0, 0, 30),
  ('security', 'privacy_export_link_hours', '72', 'integer', 'Data export link lifetime (hours)',
   'How long a data-export download link works. The archive is deleted when it expires (1–720).', 0, 0, 31),
  ('security', 'privacy_export_max_downloads', '5', 'integer', 'Data export download limit',
   'How many times one export link can be used before it stops working (1–50).', 0, 0, 32)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `sort_order`  = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Scheduled job (System → Jobs)
-- ---------------------------------------------------------------------
INSERT INTO `scheduled_jobs` (`job_key`, `name`, `description`, `schedule_hint`, `is_enabled`) VALUES
  ('privacy:purge-exports', 'Purge expired data exports',
   'Deletes personal-data export archives once their download link has expired.', '15 * * * *', 1)
ON DUPLICATE KEY UPDATE
  `name`          = VALUES(`name`),
  `description`   = VALUES(`description`),
  `schedule_hint` = VALUES(`schedule_hint`);
