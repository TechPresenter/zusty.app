-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 012
-- Third-party integrations hub.
--
-- The catalogue itself lives in code (app/Admin/Integration.php) so a new
-- service is a declaration, not a migration. These tables hold only what an
-- operator supplies: credentials, configuration, webhook secrets, OAuth
-- tokens, and the record of what happened.
--
-- Integrations whose credentials already live in `settings` (mail, payment,
-- sms, firebase, social) keep writing there as well — IntegrationService
-- mirrors them — so MailService, PaymentService and friends keep working
-- unchanged. Nothing here replaces an existing store.
--
-- Purely additive.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `integrations` (
  `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `integration_key`     VARCHAR(60)   NOT NULL COMMENT 'Matches a key declared in Admin\\Integration',
  `is_enabled`          TINYINT(1)    NOT NULL DEFAULT 0,
  `status`              ENUM('not_configured','connected','error','disabled') NOT NULL DEFAULT 'not_configured',

  -- Secrets: one AES-256-GCM blob holding a JSON map of field => value, so a
  -- new credential field never needs a schema change.
  `credentials`         TEXT          NULL COMMENT 'AES-256-GCM ciphertext of a JSON object',
  -- Non-secret configuration (ids, regions, toggles) kept readable for support.
  `config`              LONGTEXT      NULL COMMENT 'JSON object',

  `webhook_url`         VARCHAR(500)  NULL COMMENT 'The URL this platform exposes to the provider',
  `webhook_secret`      VARCHAR(512)  NULL COMMENT 'AES-256-GCM ciphertext',
  `webhook_events`      VARCHAR(500)  NULL,
  `webhook_last_at`     DATETIME      NULL,
  `webhook_count`       INT UNSIGNED  NOT NULL DEFAULT 0,

  `oauth_access_token`  TEXT          NULL COMMENT 'AES-256-GCM ciphertext',
  `oauth_refresh_token` TEXT          NULL COMMENT 'AES-256-GCM ciphertext',
  `oauth_expires_at`    DATETIME      NULL,
  `oauth_scopes`        VARCHAR(500)  NULL,
  `oauth_account`       VARCHAR(191)  NULL COMMENT 'Which account/property the token belongs to',

  `last_test_at`        DATETIME      NULL,
  `last_test_ok`        TINYINT(1)    NULL,
  `last_error`          VARCHAR(1000) NULL,

  `auto_sync`           TINYINT(1)    NOT NULL DEFAULT 0,
  `sync_interval_min`   SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `last_sync_at`        DATETIME      NULL,
  `next_sync_at`        DATETIME      NULL,
  `last_sync_status`    ENUM('never','running','success','failed','partial') NOT NULL DEFAULT 'never',
  `last_sync_summary`   VARCHAR(500)  NULL,

  `configured_by`       INT UNSIGNED  NULL,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_integ_key` (`integration_key`),
  KEY `idx_integ_status` (`status`, `is_enabled`),
  KEY `idx_integ_sync` (`auto_sync`, `next_sync_at`),
  CONSTRAINT `fk_integ_admin` FOREIGN KEY (`configured_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every test, sync, webhook delivery, auth step and failure.
CREATE TABLE IF NOT EXISTS `integration_logs` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `integration_key`  VARCHAR(60)   NOT NULL,
  `event`            ENUM('test','sync','webhook','oauth','config','enable','disable','error') NOT NULL,
  `status`           ENUM('success','failure','warning','info') NOT NULL DEFAULT 'info',
  `message`          VARCHAR(500)  NOT NULL,
  `context`          LONGTEXT      NULL COMMENT 'JSON; secrets are redacted before writing',
  `http_status`      SMALLINT UNSIGNED NULL,
  `duration_ms`      INT UNSIGNED  NULL,
  `admin_id`         INT UNSIGNED  NULL,
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ilog_key` (`integration_key`, `created_at`),
  KEY `idx_ilog_event` (`event`, `status`, `created_at`),
  CONSTRAINT `fk_ilog_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rolled-up usage so the analytics panel never scans the log table.
CREATE TABLE IF NOT EXISTS `integration_usage_daily` (
  `integration_key` VARCHAR(60)   NOT NULL,
  `stat_date`       DATE          NOT NULL,
  `calls`           INT UNSIGNED  NOT NULL DEFAULT 0,
  `failures`        INT UNSIGNED  NOT NULL DEFAULT 0,
  `webhooks`        INT UNSIGNED  NOT NULL DEFAULT 0,
  `avg_duration_ms` INT UNSIGNED  NOT NULL DEFAULT 0,
  `cost`            DECIMAL(12,6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`integration_key`, `stat_date`),
  KEY `idx_iusage_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
