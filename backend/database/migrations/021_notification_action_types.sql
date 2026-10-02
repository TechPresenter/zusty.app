-- ---------------------------------------------------------------------
-- 021 — Notification action types: add `kyc`, repair the KYC templates
--
-- The KYC notification templates seeded in 006_kyc_defaults.sql use
-- `open_kyc` and `open_support`, neither of which is a member of the
-- `notifications.action_type` ENUM. With sql_mode=STRICT_TRANS_TABLES the
-- INSERT is rejected outright, so EVERY KYC notification threw a PDOException
-- at runtime — submit, approve, reject, info-required, suspend and expire.
-- Verified by calling NotificationService::send(user, 'kyc.submitted'):
--   SQLSTATE[01000]: Data truncated for column 'action_type' at row 1
--
-- Two changes:
--   1. `kyc` becomes a real member — the app has a verification screen at
--      /profile/kyc for the notification to deep-link into.
--   2. The templates are repointed: open_kyc -> kyc, open_support -> ticket
--      (`ticket` already exists and is exactly what a support deep link means).
-- ---------------------------------------------------------------------

ALTER TABLE `notifications`
  MODIFY COLUMN `action_type`
    ENUM('none','booking','creator','chat','wallet','payment','url','review','ticket','kyc')
    NOT NULL DEFAULT 'none';

-- `notification_templates.action_type` is VARCHAR(30), deliberately looser so
-- an admin can author a template before the destination exists. That looseness
-- is exactly how the mismatch got in, so NotificationService now validates the
-- value against the ENUM before writing (see its `ACTION_TYPES` allow-list)
-- rather than letting the database be the only check.

UPDATE `notification_templates`
   SET `action_type` = 'kyc'
 WHERE `action_type` = 'open_kyc';

UPDATE `notification_templates`
   SET `action_type` = 'ticket'
 WHERE `action_type` = 'open_support';

-- Any notification row already written with a now-unknown value would have
-- been coerced to '' by an earlier non-strict insert; normalise those.
UPDATE `notifications`
   SET `action_type` = 'none'
 WHERE `action_type` = '';
