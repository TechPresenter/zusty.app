-- ---------------------------------------------------------------------
-- 022 — Campaign applications
--
-- The brand-campaign feature shipped with a `campaigns` table and a full
-- Flutter UI, but nothing in between: there is no campaigns API controller,
-- and no table recording which creators were invited to or applied for a
-- campaign. `campaign_recipients` looks superficially similar but is the
-- MARKETING broadcast table — its columns are `destination`, `tracking_token`,
-- `opened_at`, `clicked_at`, and its statuses are sent/opened/bounced/
-- unsubscribed. Reusing it would conflate an email send with a creator
-- applying for paid work.
--
-- This table is the missing middle. Its statuses match the client's
-- RecipientStatus enum exactly (invited, applied, shortlisted, hired,
-- declined), so no translation layer is needed.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `campaign_applications` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id`   BIGINT UNSIGNED NOT NULL,
  `creator_id`    BIGINT UNSIGNED NOT NULL,
  `creator_user_id` BIGINT UNSIGNED NOT NULL
                  COMMENT 'Denormalised so ownership checks avoid a join',

  `status`        ENUM('invited','applied','shortlisted','hired','declined')
                  NOT NULL DEFAULT 'invited',

  -- What the creator is asking for. NULL on an invitation the creator has
  -- not yet responded to.
  `quoted_amount` DECIMAL(12,2) NULL,
  `currency`      CHAR(3) NOT NULL DEFAULT 'INR',
  `pitch`         VARCHAR(2000) NULL,

  -- Set once the brand converts a shortlisted application into real work.
  `booking_id`    BIGINT UNSIGNED NULL,

  `invited_at`    DATETIME NULL,
  `applied_at`    DATETIME NULL,
  `responded_at`  DATETIME NULL COMMENT 'When the brand last changed the status',
  `decline_reason` VARCHAR(500) NULL,

  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL,

  PRIMARY KEY (`id`),

  -- A creator appears at most once per campaign, whether invited or applying.
  -- This is also what makes "invite" idempotent.
  UNIQUE KEY `uq_campaign_creator` (`campaign_id`, `creator_id`),

  KEY `idx_ca_campaign_status` (`campaign_id`, `status`, `deleted_at`),
  KEY `idx_ca_creator`         (`creator_user_id`, `status`),
  KEY `idx_ca_booking`         (`booking_id`),
  KEY `idx_ca_deleted`         (`deleted_at`),

  -- Constraint names are unique per DATABASE in InnoDB, not per table:
  -- `fk_ca_creator` was already taken by creator_availability, so these
  -- carry a `capp_` prefix.
  CONSTRAINT `fk_capp_campaign` FOREIGN KEY (`campaign_id`)
    REFERENCES `campaigns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_capp_creator` FOREIGN KEY (`creator_id`)
    REFERENCES `creators` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_capp_creator_user` FOREIGN KEY (`creator_user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_capp_booking` FOREIGN KEY (`booking_id`)
    REFERENCES `bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `campaigns.applications_count` and `hired_count` are denormalised counters
-- the list view reads. They are maintained by BrandCampaignService on every
-- status change; this index keeps the recount cheap.
ALTER TABLE `campaigns`
  ADD INDEX `idx_campaigns_brand_status` (`brand_user_id`, `status`, `deleted_at`);
