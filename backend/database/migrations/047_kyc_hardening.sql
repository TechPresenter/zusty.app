-- ---------------------------------------------------------------------
-- 047 - KYC hardening: processing consent, the audit trail's parent row,
--       settings fixed by policy, upload caps, draft retention, the masked
--       Aadhaar rule and the rejection deep link.
--
-- Follows the review of the KYC & Verification system. Every statement is
-- re-runnable: columns use IF NOT EXISTS, the foreign key is dropped (IF
-- EXISTS) and re-added, settings and templates are upserts, and every UPDATE
-- is a fixed value or guarded by its own WHERE.
--
--   1. Processing consent. 044 recorded consent only at submission, so the
--      Aadhaar and PAN images of a draft that was never submitted had been
--      processed with no consent at all. Consent is now captured before the
--      first upload or detail save, separately from the submission consent:
--      `processing_consent_at` / `processing_consent_version`. Profiles that
--      already gave the submission consent (whose wording is a consent to
--      processing) are carried over, so nobody already in review is asked
--      again for the same wording.
--
--   2. kyc_profiles.user_id -> users becomes ON DELETE RESTRICT. kyc_events is
--      append-only by trigger, but foreign-key cascades do not fire triggers:
--      a hard delete of a user from the recycle bin used to cascade through
--      kyc_profiles and erase the trail of who approved or rejected that
--      identity. A user with KYC history can no longer be hard-deleted; the
--      recycle bin skips and explains those accounts. (kyc_events -> profile
--      stays CASCADE: nothing in the application deletes a profile row.)
--
--   3. `kyc.auto_approve` and `kyc.badge_requires_kyc` are fixed by policy:
--      every verification is approved by a person, and the blue tick always
--      needs a current approval. The rows stay (nothing that reads them
--      breaks); the code ignores them; they are held at 0 / 1 and relabelled.
--
--   4. New settings: draft retention (never-submitted drafts are deleted after
--      30 days without activity) and per-account upload caps.
--
--   5. The Aadhaar slots REQUIRE a masked copy (first 8 digits hidden); the
--      reviewer attests it before approving.
--
--   6. kyc.rejected opens the verification screen (it was seeded with action
--      'none' by 002, and 006's upsert never corrected it), "under review" is
--      emailed too, and a dedicated "document expired" template exists.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- 1. Processing consent
-- ---------------------------------------------------------------------
ALTER TABLE `kyc_profiles`
  ADD COLUMN IF NOT EXISTS `processing_consent_at` DATETIME NULL
      COMMENT 'When the applicant agreed to processing, before the first upload or detail save' AFTER `consent_version`,
  ADD COLUMN IF NOT EXISTS `processing_consent_version` VARCHAR(20) NULL
      COMMENT 'Which consent wording that agreement was given to' AFTER `processing_consent_at`;

UPDATE `kyc_profiles`
   SET `processing_consent_at`      = `consent_at`,
       `processing_consent_version` = `consent_version`
 WHERE `processing_consent_at` IS NULL
   AND `consent_at` IS NOT NULL;

-- ---------------------------------------------------------------------
-- 2. The audit trail's parent row cannot be cascaded away
-- ---------------------------------------------------------------------
ALTER TABLE `kyc_profiles`
  DROP FOREIGN KEY IF EXISTS `fk_kyc_profile_user`;

ALTER TABLE `kyc_profiles`
  ADD CONSTRAINT `fk_kyc_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- 3. Settings fixed by policy
-- ---------------------------------------------------------------------
UPDATE `settings`
   SET `value`       = '0',
       `label`       = 'Auto-approve submissions (disabled by policy)',
       `description` = 'Every verification is approved by a person. This setting is kept for compatibility and has no effect.'
 WHERE `group_key` = 'kyc' AND `setting_key` = 'auto_approve';

UPDATE `settings`
   SET `label`       = 'Auto-approve threshold (disabled by policy)',
       `description` = 'Automatic approval is not available, so this threshold has no effect.'
 WHERE `group_key` = 'kyc' AND `setting_key` = 'auto_approve_min_completeness';

UPDATE `settings`
   SET `value`       = '1',
       `label`       = 'Blue tick needs approved KYC (always on)',
       `description` = 'Fixed by policy: the blue tick is only ever granted on a KYC approval that is current. This setting is kept for compatibility and cannot be switched off.'
 WHERE `group_key` = 'kyc' AND `setting_key` = 'badge_requires_kyc';

UPDATE `settings`
   SET `description` = 'No WhatsApp provider is connected, so this has no effect yet. KYC messages use the notification channels above.'
 WHERE `group_key` = 'kyc' AND `setting_key` = 'whatsapp_enabled';

UPDATE `settings`
   SET `description` = 'Documents and identity details of profiles rejected, or left in action-needed, longer than this are deleted.'
 WHERE `group_key` = 'kyc' AND `setting_key` = 'retention_days_rejected';

UPDATE `settings`
   SET `description` = 'Documents and identity details of profiles suspended or expired longer than this are deleted.'
 WHERE `group_key` = 'kyc' AND `setting_key` = 'retention_days_closed';

-- ---------------------------------------------------------------------
-- 4. Draft retention and upload caps. `value` is left alone on a re-run so
--    an operator's change survives.
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`)
VALUES
  ('kyc', 'retention_days_draft', '30', 'integer', 'Keep never-submitted drafts (days)',
   'Documents and identity details of a draft that was never submitted are deleted after this many days without activity.', 0, 0, 125),
  ('kyc', 'max_files_per_slot', '5', 'integer', 'Files per multi-file document (max)',
   'How many files an account can keep at once in a document slot that accepts several (e.g. Additional documents).', 0, 0, 190),
  ('kyc', 'max_versions_per_slot', '10', 'integer', 'Uploads kept per document (max)',
   'How many copies of one document (current and replaced, not yet deleted) an account can hold. Replaced copies are deleted after the replaced-versions window.', 0, 0, 200),
  ('kyc', 'max_profile_mb', '100', 'integer', 'Verification storage per account (MB)',
   'Total size of the verification documents one account can hold at a time.', 0, 0, 210)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `type`        = VALUES(`type`),
  `sort_order`  = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- 5. A masked Aadhaar is required, not recommended
-- ---------------------------------------------------------------------
UPDATE `kyc_requirements`
   SET `description` = 'Upload a MASKED Aadhaar: the first 8 digits hidden, only the last 4 visible. Download it from the UIDAI website (myaadhaar.uidai.gov.in). A copy showing the full number is not accepted.'
 WHERE `kind` = 'creator' AND `slot` = 'aadhaar_front';

UPDATE `kyc_requirements`
   SET `description` = 'The back of your masked Aadhaar, showing the address. Only the last 4 digits of the number may be visible.'
 WHERE `kind` = 'creator' AND `slot` = 'aadhaar_back';

-- ---------------------------------------------------------------------
-- 6. Notifications
-- ---------------------------------------------------------------------
UPDATE `notification_templates`
   SET `action_type` = 'kyc', `icon` = 'error'
 WHERE `code` = 'kyc.rejected';

UPDATE `notification_templates`
   SET `action_type` = 'kyc'
 WHERE `code` = 'kyc.verified';

-- Rejections already delivered get the link too (notifications is not an
-- audit table; only the tap target changes).
UPDATE `notifications`
   SET `action_type` = 'kyc'
 WHERE `type` = 'kyc.rejected' AND `action_type` = 'none';

UPDATE `notification_templates`
   SET `channels` = '["in_app","email","push"]'
 WHERE `code` = 'kyc.under_review' AND `channels` = '["in_app","push"]';

INSERT INTO `notification_templates`
  (`code`, `name`, `category`, `title`, `body`, `email_subject`, `email_body`, `sms_body`, `icon`, `action_type`, `channels`, `is_active`)
VALUES
  ('kyc.document_expired', 'KYC document expired', 'account',
   'Document expired',
   'Your {{document}} for {{reference}} expired on {{expires_on}}. Upload a current copy from the verification screen.',
   'A document in your Zusty verification has expired',
   '<p>Hi {{name}},</p><p>Your <strong>{{document}}</strong> for verification {{reference}} expired on {{expires_on}}.</p><p>Upload a current copy from the verification screen in the app when you next update your verification.</p>',
   NULL,
   'event_busy', 'kyc', '["in_app","email","push"]', 1)
ON DUPLICATE KEY UPDATE
  `name`          = VALUES(`name`),
  `title`         = VALUES(`title`),
  `body`          = VALUES(`body`),
  `email_subject` = VALUES(`email_subject`),
  `email_body`    = VALUES(`email_body`),
  `icon`          = VALUES(`icon`),
  `action_type`   = VALUES(`action_type`);
