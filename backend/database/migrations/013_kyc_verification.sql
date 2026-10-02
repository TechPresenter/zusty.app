-- ---------------------------------------------------------------------
-- 013 — KYC & Business Verification
--
-- Adds a full verification module on top of the flat `kyc_documents` table
-- created in 002. The original table is kept and extended rather than
-- replaced, so existing review screens and rows keep working while the new
-- profile-centric workflow takes over.
--
-- Shape:
--   kyc_profiles        one submission envelope per user, carrying the state
--                       machine, review metadata and expiry
--   kyc_brand_details   1:1 business profile for brand submissions
--   kyc_creator_details 1:1 identity profile for creator submissions
--   kyc_documents       (extended) the uploaded files, now versioned and
--                       categorised, hanging off a profile
--   kyc_events          append-only audit timeline of every action
--   kyc_notes           internal reviewer notes, never shown to the user
--   kyc_requirements    admin-configurable "which documents are required"
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Submission envelope and state machine.
--
--   draft -> submitted -> under_review -> approved | rejected | info_required
--   info_required -> submitted            (user resubmits)
--   rejected      -> submitted            (user resubmits)
--   approved      -> expired | suspended
--   suspended     -> under_review
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kyc_profiles` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `kind`             ENUM('brand','creator') NOT NULL,
  `status`           ENUM('draft','submitted','under_review','info_required','approved','rejected','suspended','expired')
                     NOT NULL DEFAULT 'draft',
  `reference`        VARCHAR(24)  NOT NULL COMMENT 'Human readable, e.g. KYC-B-000123',

  -- Review metadata
  `submitted_at`     DATETIME     NULL,
  `reviewed_by`      INT UNSIGNED NULL,
  `reviewed_at`      DATETIME     NULL,
  `rejection_reason` VARCHAR(1000) NULL,
  `info_requested`   VARCHAR(1000) NULL COMMENT 'What the reviewer asked for',

  -- Lifecycle
  `approved_at`      DATETIME     NULL,
  `expires_on`       DATE         NULL COMMENT 'Derived from the kyc.expiry_months setting',
  `submission_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `completeness`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Percent of required items supplied',

  -- Derived badges, denormalised so listing screens stay cheap
  `is_identity_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `is_business_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `is_premium_verified`  TINYINT(1) NOT NULL DEFAULT 0,

  -- Provenance of the last user-side action (GDPR/audit)
  `last_ip`          VARBINARY(16) NULL,
  `last_user_agent`  VARCHAR(255) NULL,

  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`       DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kyc_profile_user_kind` (`user_id`, `kind`),
  UNIQUE KEY `uq_kyc_profile_reference` (`reference`),
  KEY `idx_kyc_profile_queue`  (`kind`, `status`, `submitted_at`),
  KEY `idx_kyc_profile_expiry` (`status`, `expires_on`),
  CONSTRAINT `fk_kyc_profile_user`     FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_kyc_profile_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Brand (business) profile.
--
-- Tax identifiers are encrypted at rest with AES-256-GCM via Crypto::encrypt,
-- which is why they are VARCHAR(255) rather than their natural widths.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kyc_brand_details` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_id`          BIGINT UNSIGNED NOT NULL,

  -- Identity
  `company_name`        VARCHAR(191) NOT NULL,
  `legal_name`          VARCHAR(191) NULL,
  `logo_url`            VARCHAR(500) NULL,
  `cover_url`           VARCHAR(500) NULL,
  `description`         TEXT         NULL,
  `industry`            VARCHAR(120) NULL,
  `website`             VARCHAR(255) NULL,
  `business_email`      VARCHAR(191) NULL,
  `business_phone`      VARCHAR(32)  NULL,

  -- Registration
  `gst_number`          VARCHAR(255) NULL COMMENT 'Encrypted',
  `pan_number`          VARCHAR(255) NULL COMMENT 'Encrypted',
  `cin_number`          VARCHAR(255) NULL COMMENT 'Encrypted, optional',
  `company_type`        ENUM('sole_proprietorship','partnership','llp','private_limited','public_limited','opc','trust','society','ngo','other') NULL,
  `year_established`    SMALLINT UNSIGNED NULL,
  `company_size`        ENUM('1-10','11-50','51-200','201-500','500+') NULL,

  -- Addresses
  `registered_address`  VARCHAR(500) NULL,
  `office_address`      VARCHAR(500) NULL,
  `country`             VARCHAR(100) NULL DEFAULT 'India',
  `state`               VARCHAR(100) NULL,
  `city`                VARCHAR(100) NULL,
  `pin_code`            VARCHAR(12)  NULL,
  `map_latitude`        DECIMAL(10,7) NULL,
  `map_longitude`       DECIMAL(10,7) NULL,
  `map_place_url`       VARCHAR(500) NULL,

  -- Social presence
  `social_links`        JSON NULL COMMENT '{"instagram":"…","facebook":"…","linkedin":"…","x":"…","youtube":"…","pinterest":"…","website":"…"}',

  -- Contact person
  `contact_name`        VARCHAR(150) NULL,
  `contact_designation` VARCHAR(120) NULL,
  `contact_email`       VARCHAR(191) NULL,
  `contact_phone`       VARCHAR(32)  NULL,
  `alt_contact_name`    VARCHAR(150) NULL,
  `alt_contact_email`   VARCHAR(191) NULL,
  `alt_contact_phone`   VARCHAR(32)  NULL,

  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kyc_brand_profile` (`profile_id`),
  KEY `idx_kyc_brand_company` (`company_name`),
  CONSTRAINT `fk_kyc_brand_profile` FOREIGN KEY (`profile_id`) REFERENCES `kyc_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Creator (personal identity) profile.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kyc_creator_details` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_id`        BIGINT UNSIGNED NOT NULL,

  -- Identity
  `full_name`         VARCHAR(150) NOT NULL,
  `display_name`      VARCHAR(120) NULL,
  `photo_url`         VARCHAR(500) NULL,
  `cover_url`         VARCHAR(500) NULL,
  `bio`               TEXT         NULL,
  `gender`            ENUM('female','male','non_binary','prefer_not_to_say') NULL,
  `date_of_birth`     DATE         NULL,

  -- Location
  `address`           VARCHAR(500) NULL,
  `country`           VARCHAR(100) NULL DEFAULT 'India',
  `state`             VARCHAR(100) NULL,
  `city`              VARCHAR(100) NULL,
  `pin_code`          VARCHAR(12)  NULL,

  -- Professional
  `languages`         JSON NULL COMMENT '["English","Hindi"]',
  `categories`        JSON NULL COMMENT 'Category ids or slugs',
  `social_links`      JSON NULL,
  `portfolio_url`     VARCHAR(255) NULL,
  `audience_stats`    JSON NULL COMMENT '{"instagram":{"followers":56000,"engagement":4.8}}',

  -- Payout (encrypted)
  `bank_account_name` VARCHAR(150) NULL,
  `bank_account_no`   VARCHAR(255) NULL COMMENT 'Encrypted',
  `bank_ifsc`         VARCHAR(255) NULL COMMENT 'Encrypted',
  `bank_name`         VARCHAR(150) NULL,
  `upi_id`            VARCHAR(255) NULL COMMENT 'Encrypted',

  -- Government identifiers (encrypted)
  `pan_number`        VARCHAR(255) NULL COMMENT 'Encrypted',
  `id_proof_type`     ENUM('aadhaar','passport','driving_license','voter_id') NULL,
  `id_proof_number`   VARCHAR(255) NULL COMMENT 'Encrypted',

  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kyc_creator_profile` (`profile_id`),
  KEY `idx_kyc_creator_name` (`full_name`),
  CONSTRAINT `fk_kyc_creator_profile` FOREIGN KEY (`profile_id`) REFERENCES `kyc_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Extend the original kyc_documents table.
--
-- Existing rows keep working: profile_id is nullable, and the new columns all
-- have defaults. `slot` names which requirement the file satisfies, so a
-- profile's completeness can be computed by matching slots to requirements.
-- ---------------------------------------------------------------------
ALTER TABLE `kyc_documents`
  ADD COLUMN `profile_id`     BIGINT UNSIGNED NULL AFTER `user_id`,
  ADD COLUMN `slot`           VARCHAR(60)  NULL COMMENT 'Requirement key this file satisfies, e.g. gst_certificate' AFTER `document_type`,
  ADD COLUMN `title`          VARCHAR(191) NULL AFTER `slot`,
  ADD COLUMN `file_path`      VARCHAR(500) NULL COMMENT 'Path under storage/kyc, never inside the web root' AFTER `front_image_url`,
  ADD COLUMN `original_name`  VARCHAR(255) NULL AFTER `file_path`,
  ADD COLUMN `mime_type`      VARCHAR(120) NULL AFTER `original_name`,
  ADD COLUMN `file_size`      INT UNSIGNED NOT NULL DEFAULT 0 AFTER `mime_type`,
  ADD COLUMN `checksum`       CHAR(64)     NULL COMMENT 'SHA-256 of the plaintext, for duplicate and tamper detection' AFTER `file_size`,
  ADD COLUMN `is_encrypted`   TINYINT(1)   NOT NULL DEFAULT 1 AFTER `checksum`,
  ADD COLUMN `version`        SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `is_encrypted`,
  ADD COLUMN `is_current`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Superseded uploads are kept for audit but flagged 0' AFTER `version`,
  ADD COLUMN `replaced_by`    BIGINT UNSIGNED NULL AFTER `is_current`,
  ADD COLUMN `uploaded_ip`    VARBINARY(16) NULL AFTER `replaced_by`,
  ADD COLUMN `deleted_at`     DATETIME     NULL AFTER `updated_at`;

ALTER TABLE `kyc_documents`
  ADD KEY `idx_kyc_doc_profile` (`profile_id`, `is_current`),
  ADD KEY `idx_kyc_doc_slot`    (`profile_id`, `slot`, `is_current`),
  ADD KEY `idx_kyc_doc_expiry`  (`expires_on`, `is_current`),
  ADD CONSTRAINT `fk_kyc_doc_profile` FOREIGN KEY (`profile_id`) REFERENCES `kyc_profiles` (`id`) ON DELETE CASCADE;

-- The original ENUM is far too narrow for a full document set. MODIFY keeps
-- every existing value valid while adding the rest.
ALTER TABLE `kyc_documents`
  MODIFY COLUMN `document_type` ENUM(
    'aadhaar','pan','passport','driving_license','gst','business_reg','other',
    'voter_id','selfie','address_proof','cancelled_cheque','bank_proof',
    'gst_certificate','trademark','license','certificate','brochure',
    'pitch_deck','brand_guidelines','product_catalogue','media_kit',
    'portfolio','resume','tax_document'
  ) NOT NULL;

-- Existing rows made these mandatory; new-style uploads carry file_path
-- instead, so they must be allowed to be blank.
ALTER TABLE `kyc_documents`
  MODIFY COLUMN `document_number`  VARCHAR(255) NULL COMMENT 'AES-256-GCM encrypted at rest',
  MODIFY COLUMN `holder_name`      VARCHAR(150) NULL,
  MODIFY COLUMN `front_image_url`  VARCHAR(500) NULL;

-- ---------------------------------------------------------------------
-- Append-only audit timeline. Every transition and document action lands
-- here with actor, IP and timestamp (§ Security: audit logs, IP logging).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kyc_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_id`  BIGINT UNSIGNED NOT NULL,
  `actor_type`  ENUM('user','admin','system') NOT NULL DEFAULT 'system',
  `actor_id`    BIGINT UNSIGNED NULL,
  `actor_name`  VARCHAR(150) NULL COMMENT 'Denormalised so history survives deletion',
  `action`      VARCHAR(60)  NOT NULL COMMENT 'submitted | approved | rejected | info_requested | document_uploaded …',
  `from_status` VARCHAR(30)  NULL,
  `to_status`   VARCHAR(30)  NULL,
  `note`        VARCHAR(1000) NULL,
  `metadata`    JSON         NULL,
  `ip_address`  VARBINARY(16) NULL,
  `user_agent`  VARCHAR(255) NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kyc_event_profile` (`profile_id`, `created_at`),
  KEY `idx_kyc_event_action`  (`action`, `created_at`),
  CONSTRAINT `fk_kyc_event_profile` FOREIGN KEY (`profile_id`) REFERENCES `kyc_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Internal reviewer notes. Never exposed through the user-facing API.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kyc_notes` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `profile_id` BIGINT UNSIGNED NOT NULL,
  `admin_id`   INT UNSIGNED NULL,
  `body`       TEXT NOT NULL,
  `is_pinned`  TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kyc_note_profile` (`profile_id`, `created_at`),
  CONSTRAINT `fk_kyc_note_profile` FOREIGN KEY (`profile_id`) REFERENCES `kyc_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kyc_note_admin`   FOREIGN KEY (`admin_id`)   REFERENCES `admins` (`id`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Which documents are required, per profile kind. Admin-editable, so the
-- rules are data rather than code.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kyc_requirements` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind`          ENUM('brand','creator') NOT NULL,
  `slot`          VARCHAR(60)  NOT NULL COMMENT 'Stable key, matches kyc_documents.slot',
  `label`         VARCHAR(150) NOT NULL,
  `description`   VARCHAR(500) NULL,
  `document_type` VARCHAR(40)  NOT NULL COMMENT 'Maps onto kyc_documents.document_type',
  `is_required`   TINYINT(1)   NOT NULL DEFAULT 1,
  `allows_multiple` TINYINT(1) NOT NULL DEFAULT 0,
  `requires_expiry` TINYINT(1) NOT NULL DEFAULT 0,
  `allowed_formats` VARCHAR(255) NOT NULL DEFAULT 'pdf,jpg,jpeg,png',
  `max_size_mb`   TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kyc_requirement` (`kind`, `slot`),
  KEY `idx_kyc_requirement_active` (`kind`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Verification badges surfaced across the platform. Denormalised onto users
-- so profile cards and search results do not need to join the KYC tables.
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN `kyc_profile_id`       BIGINT UNSIGNED NULL AFTER `kyc_status`,
  ADD COLUMN `is_identity_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `kyc_profile_id`,
  ADD COLUMN `is_business_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_identity_verified`,
  ADD COLUMN `is_premium_verified`  TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_business_verified`,
  ADD COLUMN `kyc_expires_on`       DATE NULL AFTER `is_premium_verified`;

ALTER TABLE `users`
  ADD KEY `idx_users_verified` (`is_identity_verified`, `is_business_verified`);

-- The original users.kyc_status had four values; widen it to mirror the full
-- profile state machine so the denormalised copy cannot drift out of range.
ALTER TABLE `users`
  MODIFY COLUMN `kyc_status`
    ENUM('none','draft','pending','submitted','under_review','info_required','verified','approved','rejected','suspended','expired')
    NOT NULL DEFAULT 'none';
