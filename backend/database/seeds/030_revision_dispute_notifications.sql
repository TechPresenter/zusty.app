-- ---------------------------------------------------------------------
-- 030 — Templates for the revision and dispute workflows
--
-- The Flutter deliverable-review screen has offered three branches since it
-- was written — approve, request changes, dispute — and only the first had a
-- server side. Adding the endpoints without these templates would swap one
-- silence for another: NotificationService logs an unknown code and drops the
-- message, so the creator would still never learn their work had been sent
-- back.
--
-- `action_type` must be a member of the notification_templates ENUM; 'booking'
-- is the right one for both, and inventing a new value is a hard error under
-- STRICT_TRANS_TABLES.
-- ---------------------------------------------------------------------

INSERT INTO `notification_templates`
  (`code`, `name`, `category`, `title`, `body`, `email_subject`, `sms_body`, `icon`, `action_type`, `channels`, `is_active`) VALUES

('booking.revision_requested', 'Changes requested', 'booking',
 'Changes requested',
 'The brand has asked for changes to your work on "{{campaign_title}}" ({{booking_code}}). {{note}}',
 'Changes requested on {{booking_code}}',
 'Zusty: changes were requested on {{booking_code}}. Open the app for the details.',
 'edit_note', 'booking', '["in_app","push","email"]', 1),

('booking.disputed', 'Booking disputed', 'booking',
 'This booking is under dispute',
 'A dispute has been raised on "{{campaign_title}}" ({{booking_code}}): {{reason}}. Zusty keeps holding the payment until our team has reviewed it.',
 'Dispute raised on {{booking_code}}',
 'Zusty: a dispute was raised on {{booking_code}}. The payment stays on hold until our team has reviewed it.',
 'gavel', 'booking', '["in_app","push","email"]', 1)

ON DUPLICATE KEY UPDATE
  `title`     = VALUES(`title`),
  `body`      = VALUES(`body`),
  `is_active` = VALUES(`is_active`);
