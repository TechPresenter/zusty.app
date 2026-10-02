-- ---------------------------------------------------------------------
-- KYC defaults: document requirements, module settings, and the
-- notification templates the verification workflow sends.
--
-- Every statement is idempotent so the seed can be re-run after a schema
-- change without duplicating rows.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Brand (business) requirements
--
-- Required: the GST certificate, and the Certificate of Incorporation for
-- company types that carry a CIN (KycService relaxes that row for
-- proprietorships, partnerships and LLPs). Everything else is optional.
-- Kept in step with migration 044, which made the same change on databases
-- that already existed. `is_required` / `is_active` are not in the UPDATE
-- list, so a re-run never overrides an operator's choice.
-- ---------------------------------------------------------------------
INSERT INTO `kyc_requirements`
  (`kind`, `slot`, `label`, `description`, `document_type`, `is_required`, `allows_multiple`, `requires_expiry`, `allowed_formats`, `max_size_mb`, `sort_order`)
VALUES
  ('brand', 'gst_certificate',  'GST certificate',              'Your GST registration certificate as issued by the department.', 'gst_certificate', 1, 0, 0, 'pdf,jpg,jpeg,png', 10, 10),
  ('brand', 'incorporation_certificate', 'Certificate of Incorporation', 'Issued by the MCA and showing your CIN. Required for private limited, public limited and one person companies.', 'business_reg', 1, 0, 0, 'pdf,jpg,jpeg,png', 10, 15),
  ('brand', 'pan_card',         'Company PAN card',             'PAN of the registered business entity.',                        'pan',             0, 0, 0, 'pdf,jpg,jpeg,png', 10, 20),
  ('brand', 'business_reg',     'Business registration',        'Shop licence, partnership deed, LLP agreement or Udyam certificate. Recommended for businesses without a CIN.', 'business_reg', 0, 0, 0, 'pdf,jpg,jpeg,png', 10, 30),
  ('brand', 'bank_proof',       'Cancelled cheque / bank proof','Used to verify the account refunds are returned to.',            'cancelled_cheque',0, 0, 0, 'pdf,jpg,jpeg,png', 10, 40),
  ('brand', 'trademark',        'Trademark or licence',         'Optional. Any brand or product licences you hold.',              'trademark',       0, 1, 1, 'pdf,jpg,jpeg,png', 10, 50),
  ('brand', 'certificates',     'Company certificates',         'Optional. ISO, FSSAI, or other compliance certificates.',        'certificate',     0, 1, 1, 'pdf,jpg,jpeg,png', 10, 60),
  ('brand', 'brochure',         'Company brochure',             'Optional. Helps creators understand your business.',             'brochure',        0, 0, 0, 'pdf',              20, 70),
  ('brand', 'pitch_deck',       'Presentation / pitch deck',    'Optional.',                                                      'pitch_deck',      0, 0, 0, 'pdf,ppt,pptx',     25, 80),
  ('brand', 'brand_guidelines', 'Brand guidelines',             'Optional. Tone, logo usage, dos and donts for creators.',        'brand_guidelines',0, 0, 0, 'pdf',              20, 90),
  ('brand', 'product_catalogue','Product catalogue',            'Optional.',                                                      'product_catalogue',0,0, 0, 'pdf',              25, 100),
  ('brand', 'media_kit',        'Media kit',                    'Optional.',                                                      'media_kit',       0, 0, 0, 'pdf',              20, 110),
  ('brand', 'supporting',       'Additional documents',         'Optional. Anything else supporting your application.',           'other',           0, 1, 0, 'pdf,jpg,jpeg,png', 10, 120)
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `description`   = VALUES(`description`),
  `document_type` = VALUES(`document_type`),
  `sort_order`    = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Creator (personal identity) requirements
--
-- Required: Aadhaar (front and back) and PAN. The Aadhaar copy must be
-- MASKED (first 8 digits hidden); the reviewer attests it before approving.
-- `id_proof` is inactive: a generic "government ID" slot would accept a full,
-- unmasked Aadhaar right next to the dedicated slots.
-- ---------------------------------------------------------------------
INSERT INTO `kyc_requirements`
  (`kind`, `slot`, `label`, `description`, `document_type`, `is_required`, `allows_multiple`, `requires_expiry`, `allowed_formats`, `max_size_mb`, `sort_order`, `is_active`)
VALUES
  ('creator', 'aadhaar_front', 'Aadhaar card (front)', 'Upload a MASKED Aadhaar: the first 8 digits hidden, only the last 4 visible. Download it from the UIDAI website (myaadhaar.uidai.gov.in). A copy showing the full number is not accepted.', 'aadhaar', 1, 0, 0, 'jpg,jpeg,png,pdf', 5, 4, 1),
  ('creator', 'aadhaar_back',  'Aadhaar card (back)',  'The back of your masked Aadhaar, showing the address. Only the last 4 digits of the number may be visible.', 'aadhaar', 1, 0, 0, 'jpg,jpeg,png,pdf', 5, 6, 1),
  ('creator', 'pan_card',      'PAN card',            'Your personal PAN card (the 4th character of the number is P).', 'pan',            1, 0, 0, 'pdf,jpg,jpeg,png', 10, 10, 1),
  ('creator', 'id_proof',      'Government ID',       'Aadhaar, passport, driving licence, or voter ID.',               'aadhaar',        0, 0, 1, 'pdf,jpg,jpeg,png', 10, 20, 0),
  ('creator', 'selfie',        'Selfie verification', 'A clear photo of your face, holding your ID if possible.',       'selfie',         0, 0, 0, 'jpg,jpeg,png',     10, 30, 1),
  ('creator', 'address_proof', 'Address proof',       'Utility bill, bank statement, or rental agreement.',             'address_proof',  0, 0, 1, 'pdf,jpg,jpeg,png', 10, 40, 1),
  ('creator', 'bank_proof',    'Bank proof',          'Cancelled cheque or passbook page matching your payout account.','cancelled_cheque',0,0, 0, 'pdf,jpg,jpeg,png', 10, 50, 1),
  ('creator', 'tax_document',  'Tax documents',       'Optional. GST or TDS declarations if applicable.',               'tax_document',   0, 1, 1, 'pdf,jpg,jpeg,png', 10, 60, 1),
  ('creator', 'portfolio',     'Portfolio',           'Optional. Your best work as a single PDF.',                      'portfolio',      0, 0, 0, 'pdf',              25, 70, 1),
  ('creator', 'media_kit',     'Media kit',           'Optional. Reach, demographics, and rate card.',                  'media_kit',      0, 0, 0, 'pdf',              20, 80, 1),
  ('creator', 'resume',        'Resume',              'Optional.',                                                      'resume',         0, 0, 0, 'pdf,doc,docx',     10, 90, 1),
  ('creator', 'supporting',    'Additional documents','Optional.',                                                      'other',          0, 1, 0, 'pdf,jpg,jpeg,png', 10, 100, 1)
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `description`   = VALUES(`description`),
  `document_type` = VALUES(`document_type`),
  `sort_order`    = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Module settings, editable from Admin → Settings → KYC.
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `sort_order`)
VALUES
  ('kyc', 'brand_mandatory',      '1',                    'boolean', 'Mandatory for brands',      'Brands must be verified before creating campaigns or paying for bookings.', 1, 10),
  ('kyc', 'creator_mandatory',    '1',                    'boolean', 'Mandatory for creators',    'Creators must be verified before accepting bookings or withdrawing.',      1, 20),
  ('kyc', 'max_upload_mb',        '10',                   'integer', 'Maximum upload size (MB)',  'Applies unless a requirement overrides it.',                                0, 30),
  ('kyc', 'allowed_formats',      'pdf,jpg,jpeg,png,webp,doc,docx,ppt,pptx', 'string', 'Allowed file formats', 'Comma separated extensions.',                                    0, 40),
  ('kyc', 'expiry_months',        '24',                   'integer', 'Verification validity (months)', 'Zero disables expiry entirely.',                                       0, 50),
  ('kyc', 'expiry_reminder_days', '30',                   'integer', 'Expiry reminder (days before)',  'How far ahead to warn users their verification lapses.',               0, 60),
  -- auto_approve / auto_approve_min_completeness / badge_requires_kyc are
  -- fixed by policy (migration 047): kept for compatibility, ignored by the
  -- code, held at 0 / 1. Every verification is approved by a person, and the
  -- blue tick always needs a current KYC approval.
  ('kyc', 'auto_approve',         '0',                    'boolean', 'Auto-approve submissions (disabled by policy)', 'Every verification is approved by a person. This setting is kept for compatibility and has no effect.', 0, 70),
  ('kyc', 'auto_approve_min_completeness', '100',         'integer', 'Auto-approve threshold (disabled by policy)', 'Automatic approval is not available, so this threshold has no effect.', 0, 80),
  ('kyc', 'badges_enabled',       '1',                    'boolean', 'Show verification badges',  'Display Verified Brand / Verified Creator badges across the platform.',     1, 90),
  ('kyc', 'notify_channels',      '["in_app","email","push"]', 'json', 'Notification channels',   'Channels used for KYC status updates.',                                     0, 100),
  ('kyc', 'whatsapp_enabled',     '0',                    'boolean', 'WhatsApp notifications',    'No WhatsApp provider is connected, so this has no effect yet. KYC messages use the notification channels above.', 0, 110),
  ('kyc', 'download_link_ttl',    '300',                  'integer', 'Document link lifetime (s)','How long a signed document download URL stays valid.',                     0, 120),
  ('kyc', 'retention_days_draft', '30',                   'integer', 'Keep never-submitted drafts (days)', 'Documents and identity details of a draft that was never submitted are deleted after this many days without activity.', 0, 125),
  ('kyc', 'retention_days_rejected', '180',              'integer', 'Keep rejected / abandoned documents (days)', 'Documents and identity details of profiles rejected, or left in action-needed, longer than this are deleted.', 0, 130),
  ('kyc', 'retention_days_closed', '90',                 'integer', 'Keep suspended / expired documents (days)', 'Documents and identity details of profiles suspended or expired longer than this are deleted.', 0, 140),
  ('kyc', 'purge_superseded_after_days', '30',           'integer', 'Keep replaced document versions (days)', 'Older versions of a re-uploaded document are deleted after this many days.', 0, 150),
  ('kyc', 'badge_requires_kyc',   '1',                    'boolean', 'Blue tick needs approved KYC (always on)', 'Fixed by policy: the blue tick is only ever granted on a KYC approval that is current. This setting is kept for compatibility and cannot be switched off.', 0, 160),
  ('kyc', 'max_files_per_slot',   '5',                    'integer', 'Files per multi-file document (max)', 'How many files an account can keep at once in a document slot that accepts several (e.g. Additional documents).', 0, 190),
  ('kyc', 'max_versions_per_slot', '10',                  'integer', 'Uploads kept per document (max)', 'How many copies of one document (current and replaced, not yet deleted) an account can hold. Replaced copies are deleted after the replaced-versions window.', 0, 200),
  ('kyc', 'max_profile_mb',       '100',                  'integer', 'Verification storage per account (MB)', 'Total size of the verification documents one account can hold at a time.', 0, 210),
  ('kyc', 'consent_version',      '2026-10',              'string',  'Consent wording version', 'Change this when the consent text changes; new submissions record the version they agreed to.', 0, 170),
  ('kyc', 'consent_text',         'I consent to Zusty processing my identity documents to verify my account, as described in the Privacy Policy.', 'text', 'Consent text', 'Shown next to the consent checkbox before a user submits verification.', 1, 180)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `type`        = VALUES(`type`);

-- Held at their policy values whatever an earlier install stored.
UPDATE `settings` SET `value` = '0' WHERE `group_key` = 'kyc' AND `setting_key` = 'auto_approve';
UPDATE `settings` SET `value` = '1' WHERE `group_key` = 'kyc' AND `setting_key` = 'badge_requires_kyc';

-- ---------------------------------------------------------------------
-- Notification templates for every KYC event.
--
-- The upsert re-applies `icon` and `action_type` too: 002 seeds kyc.rejected
-- with action 'none' and runs first, so without them the rejection notice
-- never opened the verification screen.
-- ---------------------------------------------------------------------
INSERT INTO `notification_templates`
  (`code`, `name`, `category`, `title`, `body`, `email_subject`, `email_body`, `sms_body`, `icon`, `action_type`, `channels`, `is_active`)
VALUES
  ('kyc.submitted', 'KYC submitted', 'account',
   'Verification submitted',
   'We have received your documents for {{reference}}. Reviews usually finish within 24-48 hours.',
   'We have received your Zusty verification',
   '<p>Hi {{name}},</p><p>Thanks for submitting your verification ({{reference}}). Our team reviews most submissions within 24-48 hours, and you will hear from us as soon as there is a decision.</p>',
   'Zusty: verification {{reference}} received. We will update you within 24-48 hours.',
   'verified_user', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.under_review', 'KYC under review', 'account',
   'Verification under review',
   'A reviewer has picked up {{reference}}. No action is needed from you right now.',
   'Your Zusty verification is being reviewed',
   '<p>Hi {{name}},</p><p>A member of our team is reviewing your submission ({{reference}}). We will let you know the moment it is decided.</p>',
   NULL,
   'hourglass_top', 'kyc', '["in_app","email","push"]', 1),

  -- Approval no longer means "your badge is live": the blue tick is a
  -- separate admin decision (kyc.badge_granted). Str::interpolate() has no
  -- {{#x}}...{{/x}} sections, so the email names a pre-formatted label.
  ('kyc.approved', 'KYC approved', 'account',
   'Verification approved',
   'Your verification {{reference}} is approved. The blue tick is reviewed separately.',
   'Your Zusty verification is approved',
   '<p>Hi {{name}},</p><p>Good news - your verification ({{reference}}) has been approved. It is valid until {{expires_label}}.</p><p>The blue tick on your profile is a separate review; we will let you know when it is decided.</p>',
   'Zusty: your verification {{reference}} is approved.',
   'verified', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.rejected', 'KYC rejected', 'account',
   'Verification needs attention',
   'We could not approve {{reference}}. Reason: {{reason}}',
   'Action needed on your Zusty verification',
   '<p>Hi {{name}},</p><p>We were unable to approve your verification ({{reference}}).</p><p><strong>Reason:</strong> {{reason}}</p><p>You can correct the details and resubmit at any time.</p>',
   'Zusty: verification could not be approved. {{reason}}',
   'error', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.info_required', 'KYC additional information required', 'account',
   'More information needed',
   'We need a little more for {{reference}}: {{request}}',
   'We need one more thing for your Zusty verification',
   '<p>Hi {{name}},</p><p>Your verification ({{reference}}) is almost there. Could you provide the following?</p><blockquote>{{request}}</blockquote><p>Once uploaded, it goes straight back into the review queue.</p>',
   'Zusty: we need more info for your verification. Check the app.',
   'upload_file', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.suspended', 'KYC suspended', 'account',
   'Verification suspended',
   'Your verification has been suspended. Reason: {{reason}}',
   'Your Zusty verification has been suspended',
   '<p>Hi {{name}},</p><p>Your verification has been suspended.</p><p><strong>Reason:</strong> {{reason}}</p><p>Please contact support if you believe this is a mistake.</p>',
   NULL,
   'block', 'ticket', '["in_app","email"]', 1),

  ('kyc.expiring', 'KYC expiring soon', 'account',
   'Verification expires soon',
   'Your verification expires on {{expires_on}}. Re-submit your documents to stay verified.',
   'Your Zusty verification expires soon',
   '<p>Hi {{name}},</p><p>Your verification expires on <strong>{{expires_on}}</strong>. Re-submit your documents before then to stay verified and avoid interruption to bookings and payouts.</p>',
   'Zusty: your verification expires on {{expires_on}}. Re-submit your documents to stay verified.',
   'schedule', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.expired', 'KYC expired', 'account',
   'Verification expired',
   'Your verification has expired. Re-submit your documents to verify again.',
   'Your Zusty verification has expired',
   '<p>Hi {{name}},</p><p>Your verification has expired, and the blue tick (if you had one) has been removed. Re-submitting takes only a few minutes.</p>',
   'Zusty: your verification has expired. Re-verify in the app.',
   'event_busy', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.document_rejected', 'KYC document not accepted', 'account',
   'Document not accepted',
   'Your {{document}} for {{reference}} was not accepted: {{reason}}',
   'A document in your Zusty verification was not accepted',
   '<p>Hi {{name}},</p><p>We could not accept your <strong>{{document}}</strong> for verification {{reference}}.</p><p><strong>Reason:</strong> {{reason}}</p><p>Open the verification screen in the app to see what to do next.</p>',
   NULL,
   'error', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.resubmission_requested', 'KYC re-upload requested', 'account',
   'Please re-upload documents',
   'For {{reference}}, please upload new copies of: {{slots}}. {{message}}',
   'Please re-upload documents for your Zusty verification',
   '<p>Hi {{name}},</p><p>To finish verification {{reference}}, please upload new copies of:</p><p><strong>{{slots}}</strong></p><p>{{message}}</p><p>Then submit again from the verification screen.</p>',
   'Zusty: please re-upload {{slots}} for your verification.',
   'upload_file', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.badge_granted', 'Blue tick granted', 'account',
   'You have the blue tick',
   'Your profile now shows the verified blue tick.',
   'Your Zusty profile is now verified',
   '<p>Hi {{name}},</p><p>Your profile now shows the verified blue tick across Zusty.</p>',
   NULL,
   'verified', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.badge_revoked', 'Blue tick removed', 'account',
   'Blue tick removed',
   'The blue tick has been removed from your profile. Reason: {{reason}}',
   'The blue tick on your Zusty profile was removed',
   '<p>Hi {{name}},</p><p>The verified blue tick has been removed from your profile.</p><p><strong>Reason:</strong> {{reason}}</p><p>Contact support if you believe this is a mistake.</p>',
   NULL,
   'block', 'kyc', '["in_app","email","push"]', 1),

  ('kyc.document_expired', 'KYC document expired', 'account',
   'Document expired',
   'Your {{document}} for {{reference}} expired on {{expires_on}}. Upload a current copy from the verification screen.',
   'A document in your Zusty verification has expired',
   '<p>Hi {{name}},</p><p>Your <strong>{{document}}</strong> for verification {{reference}} expired on {{expires_on}}.</p><p>Upload a current copy from the verification screen in the app when you next update your verification.</p>',
   NULL,
   'event_busy', 'kyc', '["in_app","email","push"]', 1)
ON DUPLICATE KEY UPDATE
  `title`         = VALUES(`title`),
  `body`          = VALUES(`body`),
  `email_subject` = VALUES(`email_subject`),
  `email_body`    = VALUES(`email_body`),
  `icon`          = VALUES(`icon`),
  `action_type`   = VALUES(`action_type`),
  `channels`      = VALUES(`channels`);

-- ---------------------------------------------------------------------
-- Permissions for the KYC admin module.
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`group_name`, `name`, `slug`, `description`, `sort_order`)
VALUES
  ('KYC', 'View KYC submissions',   'kyc.view',     'See the verification queues and profile details.',            10),
  ('KYC', 'Approve or reject KYC',  'kyc.review',   'Make verification decisions and request more information.',   20),
  ('KYC', 'Download KYC documents', 'kyc.download', 'Retrieve the underlying identity documents.',                 30),
  ('KYC', 'Manage KYC settings',    'kyc.settings', 'Change requirements, expiry rules, and mandatory flags.',     40),
  ('KYC', 'Grant or revoke the blue tick', 'kyc.badge', 'Award or withdraw the public verified badge on an approved profile.', 50)
ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `group_name`  = VALUES(`group_name`),
  `description` = VALUES(`description`);

-- Grant the new permissions to the super-admin role (id 1 by convention).
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, p.`id` FROM `permissions` p WHERE p.`slug` LIKE 'kyc.%'
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;

-- The blue tick is also an Admin-role decision (migration 044 does the same).
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
  FROM `roles` r
  JOIN `permissions` p ON p.`slug` = 'kyc.badge'
 WHERE r.`slug` IN ('super-admin', 'admin')
ON DUPLICATE KEY UPDATE `role_id` = `role_permissions`.`role_id`;

-- ---------------------------------------------------------------------
-- The expiry sweep. `CronRunner` dispatches it, but without a row here it
-- never appears under System → Jobs — so an operator cannot see its last run,
-- trigger it, or disable it, and a sweep that has been failing looks exactly
-- like one that is working.
-- ---------------------------------------------------------------------
INSERT INTO `scheduled_jobs` (`job_key`, `name`, `description`, `schedule_hint`, `is_enabled`)
VALUES
  ('kyc:expiry', 'Expire lapsed KYC',
   'Clears verification flags on approved profiles whose expiry date has passed, and warns those approaching it.',
   '20 1 * * *', 1),
  ('kyc:retention', 'KYC document retention',
   'Deletes documents of rejected, abandoned and closed verifications, replaced document versions, and orphaned encrypted files.',
   '40 1 * * *', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `schedule_hint` = VALUES(`schedule_hint`);
