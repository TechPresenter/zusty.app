-- ---------------------------------------------------------------------
-- 029 — Campaign notification templates
--
-- BrandCampaignService emits seven `campaign.*` notification codes and not one
-- of them had a template, so every campaign notification was dropped silently:
-- a creator was never told they had been invited or hired, and a brand was
-- never told somebody had applied. system_logs recorded 40 such drops.
--
-- `action_type` must be a member of the `notifications.action_type` ENUM
-- (none, booking, creator, chat, wallet, payment, url, review, ticket, kyc) —
-- see migration 021, which fixed exactly this class of mistake for the KYC
-- templates. `url` is correct here: the deep link is a campaign path, and the
-- ENUM has no `campaign` member.
-- ---------------------------------------------------------------------

INSERT INTO `notification_templates`
  (`code`, `name`, `category`, `title`, `body`, `email_subject`, `email_body`,
   `sms_body`, `icon`, `action_type`, `channels`, `is_active`)
VALUES
  ('campaign.invited', 'Campaign invitation', 'booking',
   'You have been invited',
   '{{brand}} invited you to apply for "{{campaign}}". Take a look and send your rate.',
   '{{brand}} invited you to a campaign', '',
   'Zusty: {{brand}} invited you to "{{campaign}}". Open the app to apply.',
   'campaign', 'url', '["in_app","push","email"]', 1),

  ('campaign.application', 'New campaign application', 'booking',
   'New application',
   '{{creator}} applied to "{{campaign}}". Review their rate and pitch.',
   'New application on {{campaign}}', '',
   NULL,
   'person_add', 'url', '["in_app","push","email"]', 1),

  ('campaign.shortlisted', 'Shortlisted for a campaign', 'booking',
   'You have been shortlisted',
   'Good news — you are shortlisted for "{{campaign}}". The brand will be in touch shortly.',
   'You are shortlisted for {{campaign}}', '',
   'Zusty: you are shortlisted for "{{campaign}}".',
   'star', 'url', '["in_app","push","email"]', 1),

  ('campaign.hired', 'Hired for a campaign', 'booking',
   'You have been hired',
   '{{campaign}} is yours. The brand will raise a booking, and Zusty holds its payment until the work is approved.',
   'You have been hired for {{campaign}}', '',
   'Zusty: you have been hired for "{{campaign}}". Check the app for next steps.',
   'check_circle', 'url', '["in_app","push","email"]', 1),

  ('campaign.declined', 'Campaign application declined', 'booking',
   'Not selected this time',
   'Your application for "{{campaign}}" was not taken forward. {{reason}}',
   'Update on your {{campaign}} application', '',
   NULL,
   'info', 'url', '["in_app","email"]', 1),

  ('campaign.cancelled', 'Campaign cancelled', 'booking',
   'Campaign cancelled',
   '"{{campaign}}" has been cancelled by the brand. No further action is needed.',
   '{{campaign}} was cancelled', '',
   NULL,
   'error', 'url', '["in_app","email"]', 1),

  ('campaign.completed', 'Campaign closed', 'booking',
   'Campaign closed',
   '"{{campaign}}" is now closed. Thank you for taking part.',
   '{{campaign}} has closed', '',
   NULL,
   'check_circle', 'url', '["in_app"]', 1)

ON DUPLICATE KEY UPDATE
  `name`          = VALUES(`name`),
  `title`         = VALUES(`title`),
  `body`          = VALUES(`body`),
  `email_subject` = VALUES(`email_subject`),
  `sms_body`      = VALUES(`sms_body`),
  `icon`          = VALUES(`icon`),
  `action_type`   = VALUES(`action_type`),
  `channels`      = VALUES(`channels`),
  `is_active`     = VALUES(`is_active`);
