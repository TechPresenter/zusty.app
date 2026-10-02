-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 011
-- AI provider catalogue.
--
-- Replaces the single-provider `settings` rows (ai.provider / ai.api_key /
-- ai.default_model) with a real table, so several providers can be configured
-- side by side, each with its own key, base URL, budget and model list.
--
-- The old settings rows are left in place: seed 004 copies them into the new
-- tables, and AiProviderService falls back to them when the table is empty, so
-- an existing install keeps working before and after this migration runs.
--
-- Purely additive — no existing table is altered.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_providers` (
  `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `code`              VARCHAR(40)      NOT NULL COMMENT 'Stable key used in code and in ai_logs.provider',
  `name`              VARCHAR(100)     NOT NULL,
  -- The adapter decides how a request is signed and shaped. Several providers
  -- speak the OpenAI wire format, so the adapter is not the same as the code.
  `adapter`           ENUM('anthropic','openai','google','azure_openai','mistral','groq','openrouter','openai_compatible')
                                       NOT NULL DEFAULT 'openai_compatible',
  `description`       VARCHAR(255)     NULL,
  `api_key`           VARCHAR(512)     NULL COMMENT 'AES-256-GCM ciphertext; never rendered back to the page',
  `api_base_url`      VARCHAR(255)     NULL COMMENT 'Override for self-hosted or regional endpoints',
  `organisation_id`   VARCHAR(120)     NULL COMMENT 'Organisation / project / resource id where the provider needs one',
  `api_version`       VARCHAR(40)      NULL COMMENT 'Azure and Anthropic pin a dated API version',
  `default_model`     VARCHAR(80)      NULL,
  `is_enabled`        TINYINT(1)       NOT NULL DEFAULT 0,
  `is_default`        TINYINT(1)       NOT NULL DEFAULT 0 COMMENT 'Exactly one provider may hold this',
  `priority`          SMALLINT         NOT NULL DEFAULT 100 COMMENT 'Lower runs first when falling back',
  `timeout_seconds`   SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  `max_retries`       TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `rate_limit_per_min` SMALLINT UNSIGNED NULL,
  `monthly_budget`    DECIMAL(10,2)    NULL COMMENT 'Spend cap in USD, matching ai_logs.cost; null means no cap',
  `verified_at`       DATETIME         NULL COMMENT 'Last successful credential check',
  `last_used_at`      DATETIME         NULL,
  `last_error`        VARCHAR(500)     NULL,
  `created_by`        INT UNSIGNED     NULL,
  `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`        DATETIME         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aiprov_code` (`code`),
  KEY `idx_aiprov_enabled` (`is_enabled`, `priority`),
  KEY `idx_aiprov_default` (`is_default`),
  CONSTRAINT `fk_aiprov_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-model pricing and limits. Costs are per million tokens, which is how
-- every provider publishes them; ai_logs.cost stays in USD, unconverted.
CREATE TABLE IF NOT EXISTS `ai_provider_models` (
  `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `provider_id`           INT UNSIGNED  NOT NULL,
  `model_code`            VARCHAR(80)   NOT NULL COMMENT 'Exact id sent on the wire',
  `label`                 VARCHAR(100)  NOT NULL,
  `input_cost_per_mtok`   DECIMAL(10,4) NULL COMMENT 'USD per 1M input tokens; null means unpriced',
  `output_cost_per_mtok`  DECIMAL(10,4) NULL COMMENT 'USD per 1M output tokens; null means unpriced',
  `context_window`        INT UNSIGNED  NULL,
  `max_output_tokens`     INT UNSIGNED  NULL,
  `supports_vision`       TINYINT(1)    NOT NULL DEFAULT 0,
  `supports_tools`        TINYINT(1)    NOT NULL DEFAULT 1,
  `is_active`             TINYINT(1)    NOT NULL DEFAULT 1,
  `sort_order`            SMALLINT      NOT NULL DEFAULT 0,
  `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aipm_provider_model` (`provider_id`, `model_code`),
  KEY `idx_aipm_active` (`provider_id`, `is_active`, `sort_order`),
  CONSTRAINT `fk_aipm_provider` FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tie a log line back to the provider row that served it, so cost reporting
-- survives a provider being renamed. Nullable: existing rows predate this.
ALTER TABLE `ai_logs`
  ADD COLUMN `provider_id` INT UNSIGNED NULL AFTER `provider`,
  ADD KEY `idx_ailogs_provider_id` (`provider_id`);
