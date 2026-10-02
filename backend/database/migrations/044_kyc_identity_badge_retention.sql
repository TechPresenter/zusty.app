-- ---------------------------------------------------------------------
-- 044 - KYC: Aadhaar + PAN / CIN + GST identity, the manual blue tick,
--       per-document review, consent, and retention.
--
-- 013 built the verification workflow; this migration is what the product
-- actually asks of it:
--
--   * Creators verify with Aadhaar + PAN, brands with GSTIN + CIN. Aadhaar is
--     never stored in full - UIDAI's rules allow keeping only the last four
--     digits - so the creator block gains `aadhaar_last4` and a keyed hash for
--     duplicate detection, nothing else. Every government identifier gets a
--     keyed (HMAC) hash so "this PAN is already linked to another account"
--     can be answered without decrypting every row.
--
--   * KYC approval and the public blue tick are now two separate admin
--     decisions. Approval used to flip `creators.is_verified` as a side effect,
--     so the tick appeared the moment documents cleared and survived a later
--     rejection or suspension. The badge now has its own state on the profile
--     and on `users`, written only by KycService::grantBadge()/revokeBadge().
--
--   * Reviewers judge each document separately and can send specific slots
--     back for re-upload (`resubmit_slots`).
--
--   * The applicant's consent to processing is recorded with the version of
--     the wording they agreed to (DPDP Act 2023).
--
--   * Retention: documents of abandoned / rejected / closed profiles and
--     superseded versions are purged by the `kyc:retention` job; the profile
--     row survives as the minimal record (`purged_at`).
--
-- Re-runnable: every ALTER uses IF NOT EXISTS, every row write is an upsert,
-- and the triggers are dropped before they are created. Seeded demo profiles
-- are not modified - in particular, existing ticks are NOT reset here; see
-- scripts/kyc_reconcile_ticks.php for that, which defaults to a dry run.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Identity hashes and the Aadhaar tail.
--
-- *_hash = HMAC-SHA256 of the normalised value under a key derived from
-- APP_ENCRYPTION_KEY, separated per identifier type (Crypto::blindIndex).
-- Deliberately NOT unique: a purged profile keeps nothing, a rejected one may
-- legitimately be retried, and the "another account" rule is enforced in code
-- where the message can be worded without revealing whose account it is.
-- ---------------------------------------------------------------------
ALTER TABLE `kyc_creator_details`
  ADD COLUMN IF NOT EXISTS `aadhaar_last4` CHAR(4)  NULL COMMENT 'Last four digits only - the full number is never stored' AFTER `id_proof_number`,
  ADD COLUMN IF NOT EXISTS `aadhaar_hash`  CHAR(64) NULL COMMENT 'Keyed hash of the full number, for duplicate detection' AFTER `aadhaar_last4`,
  ADD COLUMN IF NOT EXISTS `pan_hash`      CHAR(64) NULL COMMENT 'Keyed hash of the PAN, for duplicate detection' AFTER `aadhaar_hash`;

ALTER TABLE `kyc_creator_details`
  ADD INDEX IF NOT EXISTS `idx_kyc_creator_aadhaar_hash` (`aadhaar_hash`),
  ADD INDEX IF NOT EXISTS `idx_kyc_creator_pan_hash` (`pan_hash`);

ALTER TABLE `kyc_brand_details`
  ADD COLUMN IF NOT EXISTS `gst_hash` CHAR(64) NULL COMMENT 'Keyed hash of the GSTIN' AFTER `cin_number`,
  ADD COLUMN IF NOT EXISTS `cin_hash` CHAR(64) NULL COMMENT 'Keyed hash of the CIN' AFTER `gst_hash`,
  ADD COLUMN IF NOT EXISTS `pan_hash` CHAR(64) NULL COMMENT 'Keyed hash of the company PAN' AFTER `cin_hash`;

ALTER TABLE `kyc_brand_details`
  ADD INDEX IF NOT EXISTS `idx_kyc_brand_gst_hash` (`gst_hash`),
  ADD INDEX IF NOT EXISTS `idx_kyc_brand_cin_hash` (`cin_hash`),
  ADD INDEX IF NOT EXISTS `idx_kyc_brand_pan_hash` (`pan_hash`);

-- ---------------------------------------------------------------------
-- Per-document review.
--
-- `status`, `reviewed_by`, `reviewed_at` and `rejection_reason` already exist
-- from 002 (the IF NOT EXISTS lines are no-ops there and document intent). The
-- ENUM gains `approved` for the new flow; `verified` / `under_review` stay
-- valid so legacy rows keep reading, and the API maps them onto
-- pending | approved | rejected.
-- ---------------------------------------------------------------------
ALTER TABLE `kyc_documents`
  MODIFY COLUMN `status` ENUM('pending','under_review','verified','rejected','approved') NOT NULL DEFAULT 'pending';

ALTER TABLE `kyc_documents`
  ADD COLUMN IF NOT EXISTS `reviewed_by`      INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `reviewed_at`      DATETIME     NULL,
  ADD COLUMN IF NOT EXISTS `rejection_reason` VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS `purged_at`        DATETIME     NULL COMMENT 'Blob deleted by retention or account deletion; the row is the audit record' AFTER `deleted_at`;

ALTER TABLE `kyc_documents`
  ADD INDEX IF NOT EXISTS `idx_kyc_doc_retention` (`is_current`, `purged_at`, `created_at`);

-- ---------------------------------------------------------------------
-- Profile: re-upload requests, the blue tick, consent, purge marker.
-- ---------------------------------------------------------------------
ALTER TABLE `kyc_profiles`
  ADD COLUMN IF NOT EXISTS `resubmit_slots`      JSON NULL COMMENT 'List of slots the reviewer asked to re-upload' AFTER `info_requested`,
  ADD COLUMN IF NOT EXISTS `badge_status`        ENUM('none','granted','revoked') NOT NULL DEFAULT 'none' COMMENT 'Blue tick - a separate manual decision from KYC approval' AFTER `is_premium_verified`,
  ADD COLUMN IF NOT EXISTS `badge_granted_by`    INT UNSIGNED NULL AFTER `badge_status`,
  ADD COLUMN IF NOT EXISTS `badge_granted_at`    DATETIME NULL AFTER `badge_granted_by`,
  ADD COLUMN IF NOT EXISTS `badge_revoked_at`    DATETIME NULL AFTER `badge_granted_at`,
  ADD COLUMN IF NOT EXISTS `badge_revoke_reason` VARCHAR(500) NULL AFTER `badge_revoked_at`,
  ADD COLUMN IF NOT EXISTS `consent_at`          DATETIME NULL COMMENT 'When the applicant consented to processing' AFTER `last_user_agent`,
  ADD COLUMN IF NOT EXISTS `consent_version`     VARCHAR(20) NULL COMMENT 'Which consent wording they agreed to' AFTER `consent_at`,
  ADD COLUMN IF NOT EXISTS `purged_at`           DATETIME NULL COMMENT 'Documents and identifiers erased; the row is the minimal legal record' AFTER `deleted_at`;

ALTER TABLE `kyc_profiles`
  ADD INDEX IF NOT EXISTS `idx_kyc_profile_badge` (`status`, `badge_status`);

-- ---------------------------------------------------------------------
-- The public tick, denormalised onto users so brand cards, booking
-- counterparties and chat headers can show it without joining KYC.
-- (Creators keep `creators.is_verified`, kept in step by grant/revoke.)
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `has_blue_tick` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Granted manually by an admin after KYC approval' AFTER `is_premium_verified`,
  ADD COLUMN IF NOT EXISTS `blue_tick_at`  DATETIME NULL AFTER `has_blue_tick`;

-- ---------------------------------------------------------------------
-- Requirements: the required sets the product asks for.
--
--   creator: Aadhaar (front + back) and PAN
--   brand:   GST certificate, and the Certificate of Incorporation when the
--            company type carries a CIN (private / public limited, OPC).
--            KycService decides that per profile; the row says "required"
--            and the code relaxes it for proprietorships, partnerships, LLPs.
--
-- Every other slot stays available as optional. `id_proof` (a generic
-- government ID) is switched off: it would accept a full, unmasked Aadhaar
-- next to the dedicated slots that ask for a masked one.
-- ---------------------------------------------------------------------
INSERT INTO `kyc_requirements`
  (`kind`, `slot`, `label`, `description`, `document_type`, `is_required`, `allows_multiple`, `requires_expiry`, `allowed_formats`, `max_size_mb`, `sort_order`, `is_active`)
VALUES
  ('creator', 'aadhaar_front', 'Aadhaar card (front)',
   'Photo or scan of the front of your Aadhaar. We recommend a masked Aadhaar (first 8 digits hidden) - download it from the UIDAI website.',
   'aadhaar', 1, 0, 0, 'jpg,jpeg,png,pdf', 5, 4, 1),
  ('creator', 'aadhaar_back', 'Aadhaar card (back)',
   'Photo or scan of the back of your Aadhaar, showing the address. A masked Aadhaar is recommended.',
   'aadhaar', 1, 0, 0, 'jpg,jpeg,png,pdf', 5, 6, 1),
  ('brand', 'incorporation_certificate', 'Certificate of Incorporation',
   'Issued by the MCA and showing your CIN. Required for private limited, public limited and one person companies.',
   'business_reg', 1, 0, 0, 'pdf,jpg,jpeg,png', 10, 15, 1)
ON DUPLICATE KEY UPDATE
  `label`           = VALUES(`label`),
  `description`     = VALUES(`description`),
  `document_type`   = VALUES(`document_type`),
  `is_required`     = VALUES(`is_required`),
  `requires_expiry` = VALUES(`requires_expiry`),
  `allowed_formats` = VALUES(`allowed_formats`),
  `max_size_mb`     = VALUES(`max_size_mb`),
  `sort_order`      = VALUES(`sort_order`),
  `is_active`       = VALUES(`is_active`);

UPDATE `kyc_requirements`
   SET `is_required` = 1, `requires_expiry` = 0, `is_active` = 1,
       `description` = 'Your personal PAN card (the 4th character of the number is P).'
 WHERE `kind` = 'creator' AND `slot` = 'pan_card';

UPDATE `kyc_requirements`
   SET `is_required` = 0, `is_active` = 0
 WHERE `kind` = 'creator' AND `slot` = 'id_proof';

UPDATE `kyc_requirements`
   SET `is_required` = 0
 WHERE `kind` = 'creator' AND `slot` IN ('selfie', 'address_proof', 'bank_proof');

UPDATE `kyc_requirements`
   SET `is_required` = 1, `is_active` = 1
 WHERE `kind` = 'brand' AND `slot` = 'gst_certificate';

UPDATE `kyc_requirements`
   SET `is_required` = 0
 WHERE `kind` = 'brand' AND `slot` IN ('pan_card', 'bank_proof');

UPDATE `kyc_requirements`
   SET `is_required` = 0,
       `description` = 'Shop licence, partnership deed, LLP agreement or Udyam certificate. Recommended for businesses without a CIN.'
 WHERE `kind` = 'brand' AND `slot` = 'business_reg';

-- ---------------------------------------------------------------------
-- Settings (Admin -> Settings -> KYC). `value` is left alone on a re-run so
-- an operator's change survives.
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`)
VALUES
  ('kyc', 'retention_days_rejected', '180', 'integer', 'Keep rejected / abandoned documents (days)',
   'Documents of profiles rejected, or left in draft / action-needed, longer than this are deleted.', 0, 0, 130),
  ('kyc', 'retention_days_closed', '90', 'integer', 'Keep suspended / expired documents (days)',
   'Documents of profiles suspended or expired longer than this are deleted.', 0, 0, 140),
  ('kyc', 'purge_superseded_after_days', '30', 'integer', 'Keep replaced document versions (days)',
   'Older versions of a re-uploaded document are deleted after this many days.', 0, 0, 150),
  ('kyc', 'badge_requires_kyc', '1', 'boolean', 'Blue tick needs approved KYC',
   'Only a profile whose KYC is approved can be given the blue tick.', 0, 0, 160),
  ('kyc', 'consent_version', '2026-10', 'string', 'Consent wording version',
   'Change this when the consent text changes; new submissions record the version they agreed to.', 0, 0, 170),
  ('kyc', 'consent_text',
   'I consent to Zuste+ processing my identity documents to verify my account, as described in the Privacy Policy.',
   'text', 'Consent text',
   'Shown next to the consent checkbox before a user submits verification.', 1, 0, 180)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `type`        = VALUES(`type`),
  `sort_order`  = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Permission: the blue tick is its own decision, separately grantable.
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`group_name`, `name`, `slug`, `description`, `sort_order`)
VALUES
  ('KYC', 'Grant or revoke the blue tick', 'kyc.badge',
   'Award or withdraw the public verified badge on an approved profile.', 50)
ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `group_name`  = VALUES(`group_name`),
  `description` = VALUES(`description`);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
  FROM `roles` r
  JOIN `permissions` p ON p.`slug` = 'kyc.badge'
 WHERE r.`slug` IN ('super-admin', 'admin')
ON DUPLICATE KEY UPDATE `role_id` = `role_permissions`.`role_id`;

-- ---------------------------------------------------------------------
-- Notification templates.
--
-- Str::interpolate() only substitutes {{key}}; it has no {{#x}}...{{/x}}
-- sections, so the old kyc.approved email printed them literally. Approval
-- also no longer means "your badge is live" - the tick is a separate decision.
-- KycService supplies {{name}} to every kyc.* template.
-- ---------------------------------------------------------------------
INSERT INTO `notification_templates`
  (`code`, `name`, `category`, `title`, `body`, `email_subject`, `email_body`, `sms_body`, `icon`, `action_type`, `channels`, `is_active`)
VALUES
  ('kyc.approved', 'KYC approved', 'account',
   'Verification approved',
   'Your verification {{reference}} is approved. The blue tick is reviewed separately.',
   'Your Zuste+ verification is approved',
   '<p>Hi {{name}},</p><p>Good news - your verification ({{reference}}) has been approved. It is valid until {{expires_label}}.</p><p>The blue tick on your profile is a separate review; we will let you know when it is decided.</p>',
   'Zuste+: your verification {{reference}} is approved.',
   'verified', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.document_rejected', 'KYC document not accepted', 'account',
   'Document not accepted',
   'Your {{document}} for {{reference}} was not accepted: {{reason}}',
   'A document in your Zuste+ verification was not accepted',
   '<p>Hi {{name}},</p><p>We could not accept your <strong>{{document}}</strong> for verification {{reference}}.</p><p><strong>Reason:</strong> {{reason}}</p><p>Open the verification screen in the app to see what to do next.</p>',
   NULL,
   'error', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.resubmission_requested', 'KYC re-upload requested', 'account',
   'Please re-upload documents',
   'For {{reference}}, please upload new copies of: {{slots}}. {{message}}',
   'Please re-upload documents for your Zuste+ verification',
   '<p>Hi {{name}},</p><p>To finish verification {{reference}}, please upload new copies of:</p><p><strong>{{slots}}</strong></p><p>{{message}}</p><p>Then submit again from the verification screen.</p>',
   'Zuste+: please re-upload {{slots}} for your verification.',
   'upload_file', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.badge_granted', 'Blue tick granted', 'account',
   'You have the blue tick',
   'Your profile now shows the verified blue tick.',
   'Your Zuste+ profile is now verified',
   '<p>Hi {{name}},</p><p>Your profile now shows the verified blue tick across Zuste+.</p>',
   NULL,
   'verified', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.badge_revoked', 'Blue tick removed', 'account',
   'Blue tick removed',
   'The blue tick has been removed from your profile. Reason: {{reason}}',
   'The blue tick on your Zuste+ profile was removed',
   '<p>Hi {{name}},</p><p>The verified blue tick has been removed from your profile.</p><p><strong>Reason:</strong> {{reason}}</p><p>Contact support if you believe this is a mistake.</p>',
   NULL,
   'block', 'kyc', '["in_app","email","push"]', 1)
ON DUPLICATE KEY UPDATE
  `name`          = VALUES(`name`),
  `title`         = VALUES(`title`),
  `body`          = VALUES(`body`),
  `email_subject` = VALUES(`email_subject`),
  `email_body`    = VALUES(`email_body`),
  `sms_body`      = VALUES(`sms_body`),
  `channels`      = VALUES(`channels`);

-- The other kyc.* emails used {{name}} without it ever being supplied; that is
-- fixed in code. kyc.expiring / kyc.expired spoke of "your badge", which is
-- now the tick - reword so they describe the verification itself.
UPDATE `notification_templates`
   SET `body` = 'Your verification expires on {{expires_on}}. Re-submit your documents to stay verified.',
       `email_body` = '<p>Hi {{name}},</p><p>Your verification expires on <strong>{{expires_on}}</strong>. Re-submit your documents before then to stay verified and avoid interruption to bookings and payouts.</p>'
 WHERE `code` = 'kyc.expiring';

UPDATE `notification_templates`
   SET `body` = 'Your verification has expired. Re-submit your documents to verify again.',
       `email_body` = '<p>Hi {{name}},</p><p>Your verification has expired, and the blue tick (if you had one) has been removed. Re-submitting takes only a few minutes.</p>'
 WHERE `code` = 'kyc.expired';

-- ---------------------------------------------------------------------
-- Append-only audit trail.
--
-- kyc_events records who decided what about a person's identity documents;
-- nothing in the application ever needs to rewrite or remove one. Rows still
-- disappear with their profile through ON DELETE CASCADE - foreign-key
-- actions do not fire triggers - which is what keeps test fixtures and a
-- hard-deleted profile working.
-- ---------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_kyc_events_no_update`;
CREATE TRIGGER `trg_kyc_events_no_update` BEFORE UPDATE ON `kyc_events`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'kyc_events is append-only';

DROP TRIGGER IF EXISTS `trg_kyc_events_no_delete`;
CREATE TRIGGER `trg_kyc_events_no_delete` BEFORE DELETE ON `kyc_events`
  FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'kyc_events is append-only';

-- ---------------------------------------------------------------------
-- The retention sweep (System -> Jobs).
-- ---------------------------------------------------------------------
INSERT INTO `scheduled_jobs` (`job_key`, `name`, `description`, `schedule_hint`, `is_enabled`)
VALUES
  ('kyc:retention', 'KYC document retention',
   'Deletes documents of rejected, abandoned and closed verifications, replaced document versions, and orphaned encrypted files.',
   '40 1 * * *', 1)
ON DUPLICATE KEY UPDATE
  `name`          = VALUES(`name`),
  `description`   = VALUES(`description`),
  `schedule_hint` = VALUES(`schedule_hint`);
