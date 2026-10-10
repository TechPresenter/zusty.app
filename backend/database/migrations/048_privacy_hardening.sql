-- ---------------------------------------------------------------------
-- 048 - Privacy hardening: step-up proofs and contact-change security,
--       data exports, account erasure and its clean-up, scheduled jobs and
--       the published privacy texts.
--
-- Follows the privacy and security review of account management (data
-- export, account deletion, sign-in and contact changes). Every statement is
-- re-runnable and independent of 047: columns and indexes use IF NOT
-- EXISTS, each foreign key is dropped (IF EXISTS) and re-added as its own
-- statement, ENUM MODIFYs list the full value set, settings and
-- scheduled_jobs are upserts that never overwrite a value or is_enabled,
-- and every UPDATE / DELETE is guarded by its own WHERE.
--
--   1. Auth. Two one-time-code purposes for the signed-in step-up
--      (set_password, confirm_contact_change); users.email_changed_at /
--      phone_changed_at, backfilled from applied contact changes and
--      profile updates, hold a just-changed address away from step-up codes
--      and Google/Apple linking; auth_providers gains 'password' where a
--      password predates provider tracking; the step-up settings.
--
--   2. Export. data_export_requests.verified_via gains 'firebase' and
--      'operator', requested_by_admin_id records the staff member behind an
--      operator export, and the stored copy of every download link is
--      forgotten (only its hash is kept). Query strings leave the
--      slow-query log, and one-time codes leave the API request log.
--
--   3. Deletion. account_deletion_requests records who confirmed the erasure
--      (operator, legacy), a given-up balance and the clean-up still to
--      retry after the commit; its user link becomes ON DELETE RESTRICT so
--      the deletion record can never be cascaded away. wallets.closed_at
--      closes an erased account's wallet. Lookup indexes for the purges, the
--      forfeit-limit setting, the clean-up of accounts erased before these
--      fixes (erased tombstones only) and a one-time scrub of payment
--      provider payloads. Its email_changed_at / phone_changed_at NULL-ing
--      runs after part 1's backfill.
--
--   4. Ops. The privacy:finish-deletions job and the retention jobs'
--      descriptions; the Privacy Policy sections 5 and 7, the account
--      deletion FAQ and the terms' termination clause, each guarded on the
--      old wording (seed 002 carries the same text for fresh installs).
--
-- The literals carry UTF-8 arrows and quotation marks, hence SET NAMES.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- =====================================================================
-- 1. AUTH
-- =====================================================================

-- ---------------------------------------------------------------------
-- 048 (auth part): step-up proofs and contact-change security
--
-- Two new one-time-code purposes, issued only by the signed-in
-- POST /me/privacy/verification-code: set_password (a Google, Apple or phone
-- account adding its first password must prove it is the owner) and
-- confirm_contact_change (moving the primary email or mobile number needs
-- the same proof as an export or a deletion).
-- ---------------------------------------------------------------------
ALTER TABLE `otp_verifications`
  MODIFY `purpose` ENUM(
    'register','login','verify_phone','verify_email','reset_password','withdrawal',
    'delete_account','change_email','change_phone','verify_alt_email','verify_alt_phone',
    'data_export','set_password','confirm_contact_change'
  ) NOT NULL;

-- When the primary email or mobile number last changed. For
-- security.privacy_contact_hold_hours afterwards that channel receives no
-- step-up codes and cannot link a Google or Apple sign-in, so a session that
-- moved the email to a mailbox it reads cannot use it to confirm anything.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `email_changed_at` DATETIME NULL
    COMMENT 'Last primary email change; held from step-up for security.privacy_contact_hold_hours'
    AFTER `email_verified_at`,
  ADD COLUMN IF NOT EXISTS `phone_changed_at` DATETIME NULL
    COMMENT 'Last primary phone change; held from step-up for security.privacy_contact_hold_hours'
    AFTER `phone_verified_at`;

-- Backfill from the two places a change was recorded before: applied primary
-- contact changes, and PUT /me profile updates that wrote the field directly
-- (activity_logs profile.updated lists the changed keys). The latest of the
-- two wins. Only rows still NULL are touched, so a re-run changes nothing.
-- CASE keeps the JSON functions away from anything that is not JSON.
UPDATE `users` u
  JOIN (
    SELECT x.`user_id`, MAX(x.`changed_at`) AS `changed_at`
      FROM (
        SELECT `user_id`, `applied_at` AS `changed_at`
          FROM `contact_change_requests`
         WHERE `target` = 'primary' AND `channel` = 'email' AND `status` = 'applied'
           AND `applied_at` IS NOT NULL
        UNION ALL
        SELECT `subject_id`, `created_at`
          FROM `activity_logs`
         WHERE `action` = 'profile.updated' AND `subject_type` = 'user' AND `subject_id` IS NOT NULL
           AND (CASE WHEN JSON_VALID(`properties`) THEN JSON_CONTAINS(`properties`, JSON_QUOTE('email')) ELSE 0 END) = 1
      ) x
     GROUP BY x.`user_id`
  ) c ON c.`user_id` = u.`id`
   SET u.`email_changed_at` = c.`changed_at`
 WHERE u.`email_changed_at` IS NULL;

UPDATE `users` u
  JOIN (
    SELECT x.`user_id`, MAX(x.`changed_at`) AS `changed_at`
      FROM (
        SELECT `user_id`, `applied_at` AS `changed_at`
          FROM `contact_change_requests`
         WHERE `target` = 'primary' AND `channel` = 'phone' AND `status` = 'applied'
           AND `applied_at` IS NOT NULL
        UNION ALL
        SELECT `subject_id`, `created_at`
          FROM `activity_logs`
         WHERE `action` = 'profile.updated' AND `subject_type` = 'user' AND `subject_id` IS NOT NULL
           AND (CASE WHEN JSON_VALID(`properties`) THEN JSON_CONTAINS(`properties`, JSON_QUOTE('phone')) ELSE 0 END) = 1
      ) x
     GROUP BY x.`user_id`
  ) c ON c.`user_id` = u.`id`
   SET u.`phone_changed_at` = c.`changed_at`
 WHERE u.`phone_changed_at` IS NULL;

-- A password only confirms privacy actions when 'password' is in
-- auth_providers: every path that sets one with proof records it, and only
-- the old proofless set path did not. Accounts from before providers were
-- tracked have a password and no providers at all; they keep the proof.
UPDATE `users`
   SET `auth_providers` = JSON_ARRAY('password')
 WHERE `password_hash` IS NOT NULL
   AND (`auth_providers` IS NULL
        OR `auth_providers` = ''
        OR (CASE WHEN JSON_VALID(`auth_providers`) THEN JSON_LENGTH(`auth_providers`) = 0 ELSE 0 END) = 1);

-- Settings. The value is never overwritten, so an operator's choice survives
-- a re-run.
INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('security', 'privacy_contact_hold_hours', '72', 'integer', 'Hold after a contact change (hours)',
   'After an email address or mobile number changes, it cannot receive codes for privacy actions or link a Google or Apple sign-in for this many hours. 0 turns this off.', 0, 0, 34),
  ('security', 'privacy_reauth_max_attempts', '10', 'integer', 'Identity checks per account',
   'How many times an account can try to confirm it is them for a privacy action (password, code or Google/Apple sign-in, right or wrong) within the window below before it has to wait.', 0, 0, 35),
  ('security', 'privacy_reauth_window_minutes', '60', 'integer', 'Identity check window (minutes)',
   'The window the identity check limit counts over. Once the limit is reached, codes are not sent and nothing is accepted until the window ends.', 0, 0, 36),
  ('security', 'privacy_reauth_max_age_seconds', '300', 'integer', 'Google/Apple sign-in freshness (seconds)',
   'How recent a Google, Apple or phone sign-in must be to confirm a privacy action. Each sign-in can confirm one action.', 0, 0, 37),
  ('security', 'otp_send_window_minutes', '60', 'integer', 'Code sending window (minutes)',
   'One-time codes to one address for one purpose are limited to one code plus the allowed resends (auth: max resends) in this many minutes.', 0, 0, 38)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `type`        = VALUES(`type`),
  `sort_order`  = VALUES(`sort_order`);

-- =====================================================================
-- 2. EXPORT
-- =====================================================================

-- ---------------------------------------------------------------------
-- 048 part: export (data_export_requests, query_log, api_logs)
--
-- Idempotent, and valid on MariaDB 10.4 and 11.8.
-- ---------------------------------------------------------------------

-- How an export was confirmed gains 'firebase' (a fresh Google or Apple
-- sign-in) and 'operator' (staff acting on a support request whose sender
-- was confirmed to control the account's email). A download link is now
-- shown once, when it is issued, and only its hash is kept, so the
-- encrypted copy column is retired (to be dropped by a later migration).
-- requested_by_admin_id records the staff member behind an operator export.
ALTER TABLE `data_export_requests`
  MODIFY `verified_via` ENUM('password','email_code','sms_code','firebase','operator') NOT NULL,
  MODIFY `download_token` VARCHAR(255) NULL COMMENT 'Unused since 048: a link is shown once, at issue, and never stored',
  ADD COLUMN IF NOT EXISTS `requested_by_admin_id` INT UNSIGNED NULL COMMENT 'Staff member who made the export on a confirmed request' AFTER `verified_via`,
  ADD INDEX IF NOT EXISTS `idx_der_admin` (`requested_by_admin_id`);

-- The staff link, re-created so a re-run is harmless. A staff account that
-- is removed leaves the export's record in place.
ALTER TABLE `data_export_requests` DROP FOREIGN KEY IF EXISTS `fk_der_admin`;

ALTER TABLE `data_export_requests`
  ADD CONSTRAINT `fk_der_admin` FOREIGN KEY (`requested_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

-- Forget every stored copy of a download link. Live archives stay: their
-- owners get a new link from the app after confirming it is them.
UPDATE `data_export_requests` SET `download_token` = NULL WHERE `download_token` IS NOT NULL;

-- The slow-query monitor keeps the request path only. A query string can
-- carry a credential, such as a download link's token.
UPDATE `query_log` SET `route` = SUBSTRING_INDEX(`route`, '?', 1) WHERE `route` LIKE '%?%';

-- One-time codes sent with failed requests are no longer kept in the
-- request log. Bodies cut short when they were stored are not valid JSON
-- and are left as they are; their codes expired long ago.
UPDATE `api_logs`
   SET `request_body` = JSON_REPLACE(`request_body`, '$.code', '[REDACTED]')
 WHERE `request_body` LIKE '%"code"%'
   AND JSON_VALID(`request_body`)
   AND JSON_EXISTS(`request_body`, '$.code');

-- =====================================================================
-- 3. DELETION
-- =====================================================================

-- ---------------------------------------------------------------------
-- 048 (deletion part) - account erasure: operator and legacy erasures,
-- retried clean-up, given-up balances, closed wallets, and the clean-up
-- of accounts that were erased before these fixes.
--
-- Every statement is re-runnable: columns and indexes use IF NOT EXISTS,
-- each foreign key is dropped (IF EXISTS) and re-added, ENUM MODIFYs list
-- the full value set, the setting is an upsert that never touches its
-- value, and every UPDATE / DELETE is guarded by its own WHERE.
--
-- Erased tombstone, wherever a backfill below says so:
--   u.status = 'deleted' AND u.name = 'Deleted user' AND u.email IS NULL
-- Accounts that staff only soft-deleted (their email is still set) and the
-- pre-043 "closed" accounts (their name is still set) are never touched.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- D1. account_deletion_requests: who confirmed it, support erasures,
--     given-up balances, and the retried clean-up after the commit
-- ---------------------------------------------------------------------
ALTER TABLE `account_deletion_requests`
  MODIFY `verified_via` ENUM('password','email_code','sms_code','firebase','operator','legacy') NOT NULL,
  MODIFY `platform` ENUM('android','ios','web','unknown','admin') NOT NULL DEFAULT 'unknown',
  ADD COLUMN IF NOT EXISTS `performed_by_admin_id` INT UNSIGNED NULL
      COMMENT 'The admin who erased the account at the user''s request' AFTER `platform`,
  ADD COLUMN IF NOT EXISTS `forfeited_amount` DECIMAL(12,2) NULL
      COMMENT 'Wallet balance the user agreed to give up' AFTER `performed_by_admin_id`,
  ADD COLUMN IF NOT EXISTS `forfeited_currency` CHAR(3) NULL AFTER `forfeited_amount`,
  ADD COLUMN IF NOT EXISTS `pending_steps` VARCHAR(255) NULL
      COMMENT 'JSON list: files, exports, kyc, firebase' AFTER `summary`,
  ADD COLUMN IF NOT EXISTS `pending_files` LONGTEXT NULL
      COMMENT 'JSON: media ids and upload paths still to delete' AFTER `pending_steps`,
  ADD COLUMN IF NOT EXISTS `firebase_uid_enc` VARCHAR(512) NULL
      COMMENT 'Encrypted Firebase uid, kept until Firebase confirms the deletion' AFTER `pending_files`,
  ADD COLUMN IF NOT EXISTS `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `firebase_uid_enc`,
  ADD COLUMN IF NOT EXISTS `next_attempt_at` DATETIME NULL AFTER `attempts`,
  ADD COLUMN IF NOT EXISTS `last_error` VARCHAR(500) NULL AFTER `next_attempt_at`,
  ADD INDEX IF NOT EXISTS `idx_adr_pending` (`completed_at`, `next_attempt_at`),
  ADD INDEX IF NOT EXISTS `idx_adr_admin` (`performed_by_admin_id`);

ALTER TABLE `account_deletion_requests`
  DROP FOREIGN KEY IF EXISTS `fk_adr_admin`;

ALTER TABLE `account_deletion_requests`
  ADD CONSTRAINT `fk_adr_admin` FOREIGN KEY (`performed_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

-- The deletion record must never be cascaded away: users are tombstoned,
-- never hard-deleted (047's rule), and a permanent delete from the recycle
-- bin now fails instead of erasing the proof that the erasure happened.
ALTER TABLE `account_deletion_requests`
  DROP FOREIGN KEY IF EXISTS `fk_adr_user`;

ALTER TABLE `account_deletion_requests`
  ADD CONSTRAINT `fk_adr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------
-- D2. Closed wallets: after an erasure only admin postings are accepted
-- ---------------------------------------------------------------------
ALTER TABLE `wallets`
  ADD COLUMN IF NOT EXISTS `closed_at` DATETIME NULL
      COMMENT 'Set when the account was erased; only admin postings are accepted afterwards' AFTER `frozen_reason`;

-- ---------------------------------------------------------------------
-- D3. Lookups by address (the erasure purges by email and phone)
-- ---------------------------------------------------------------------
ALTER TABLE `email_queue`
  ADD INDEX IF NOT EXISTS `idx_eq_to` (`to_email`);

ALTER TABLE `sms_queue`
  ADD INDEX IF NOT EXISTS `idx_sq_to` (`to_phone`);

ALTER TABLE `campaign_recipients`
  ADD INDEX IF NOT EXISTS `idx_camprec_destination` (`destination`);

-- ---------------------------------------------------------------------
-- D4. Setting: the largest balance a user may give up to delete
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('security', 'privacy_deletion_forfeit_limit', '1000', 'decimal', 'Deletion: largest balance a user may give up',
   'A user may give up a wallet balance up to this amount to delete their account when it cannot be withdrawn (below the minimum withdrawal, or without KYC). 0 turns this off.', 0, 0, 33)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `type`        = VALUES(`type`),
  `sort_order`  = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- D5. Accounts erased before these fixes (erased tombstones only)
-- ---------------------------------------------------------------------

-- Kept financial records keep what the law needs, as issued: the email
-- copied onto an invoice (never printed on it) goes, as does the UPI ID of
-- a payment.
UPDATE `invoices` i
  JOIN `users` u ON u.`id` = i.`user_id`
   SET i.`bill_to_email` = NULL
 WHERE i.`bill_to_email` IS NOT NULL
   AND u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

UPDATE `payments` p
  JOIN `users` u ON u.`id` = p.`user_id`
   SET p.`upi_vpa` = NULL
 WHERE p.`upi_vpa` IS NOT NULL
   AND u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

-- Payout snapshots keep the holder, bank and last digits; the UPI ID is
-- masked exactly as Str::maskEmail() masks it ("it******@upi").
UPDATE `withdrawal_requests` w
  JOIN `users` u ON u.`id` = w.`user_id`
   SET w.`snapshot` = JSON_SET(
         w.`snapshot`,
         '$.upi_id',
         CONCAT(
           LEFT(SUBSTRING_INDEX(JSON_VALUE(w.`snapshot`, '$.upi_id'), '@', 1),
                LEAST(2, GREATEST(1, CHAR_LENGTH(SUBSTRING_INDEX(JSON_VALUE(w.`snapshot`, '$.upi_id'), '@', 1)) - 1))),
           REPEAT('*', GREATEST(1,
             CHAR_LENGTH(SUBSTRING_INDEX(JSON_VALUE(w.`snapshot`, '$.upi_id'), '@', 1))
             - CHAR_LENGTH(LEFT(SUBSTRING_INDEX(JSON_VALUE(w.`snapshot`, '$.upi_id'), '@', 1),
                   LEAST(2, GREATEST(1, CHAR_LENGTH(SUBSTRING_INDEX(JSON_VALUE(w.`snapshot`, '$.upi_id'), '@', 1)) - 1)))))),
           '@',
           SUBSTRING(JSON_VALUE(w.`snapshot`, '$.upi_id'),
                     CHAR_LENGTH(SUBSTRING_INDEX(JSON_VALUE(w.`snapshot`, '$.upi_id'), '@', 1)) + 2)
         )
       )
 WHERE w.`snapshot` IS NOT NULL
   AND JSON_VALID(w.`snapshot`)
   AND JSON_VALUE(w.`snapshot`, '$.upi_id') LIKE '%@%'
   AND JSON_VALUE(w.`snapshot`, '$.upi_id') NOT LIKE '%*%'
   AND u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

-- Marketing rows: unsent ones go, so a paused or retried campaign cannot
-- send again; sent ones stay for the totals, with nothing that identifies
-- anyone.
DELETE cr FROM `campaign_recipients` cr
  JOIN `users` u ON u.`id` = cr.`user_id`
 WHERE cr.`status` IN ('pending', 'failed')
   AND u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

UPDATE `campaign_recipients` cr
  JOIN `users` u ON u.`id` = cr.`user_id`
   SET cr.`user_id`        = NULL,
       cr.`destination`    = CONCAT('deleted:', cr.`id`),
       cr.`tracking_token` = NULL,
       cr.`error_message`  = NULL
 WHERE u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

-- The edit history of erased creator profiles held full snapshots
-- (address, date of birth). 'creator' is CreatorChangeService::REVISION_ENTITY.
DELETE r FROM `revisions` r
  JOIN `creators` c ON r.`entity_type` = 'creator' AND r.`entity_id` = c.`id`
 WHERE c.`deleted_at` IS NOT NULL
   AND c.`display_name` = 'Deleted creator';

-- Sign-in history keeps the time, IP and device, not the email or phone.
UPDATE `login_history` lh
  JOIN `users` u ON u.`id` = lh.`actor_id`
   SET lh.`identifier` = CONCAT('deleted-user-', u.`id`)
 WHERE lh.`actor_type` = 'user'
   AND lh.`identifier` NOT LIKE 'deleted-user-%'
   AND u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

UPDATE `api_logs` a
  JOIN `users` u ON u.`id` = a.`user_id`
   SET a.`request_body` = NULL
 WHERE a.`request_body` IS NOT NULL
   AND u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

-- Their views of and engagement with other creators stay counted, unlinked.
UPDATE `creator_profile_views` v
  JOIN `users` u ON u.`id` = v.`viewer_id`
   SET v.`viewer_id` = NULL
 WHERE u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

UPDATE `creator_engagement_events` e
  JOIN `users` u ON u.`id` = e.`actor_id`
   SET e.`actor_id` = NULL
 WHERE u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

-- No money reaches an erased account any more: its wallet is closed (one is
-- opened closed where it never had one), pending rewards it would have
-- earned are cancelled, and a pending referral of it is void.
UPDATE `wallets` w
  JOIN `users` u ON u.`id` = w.`user_id`
   SET w.`closed_at` = COALESCE(u.`deleted_at`, NOW())
 WHERE w.`closed_at` IS NULL
   AND u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

INSERT INTO `wallets` (`user_id`, `currency`, `closed_at`)
SELECT u.`id`, u.`currency`, COALESCE(u.`deleted_at`, NOW())
  FROM `users` u
 WHERE u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL
   AND NOT EXISTS (SELECT 1 FROM `wallets` w WHERE w.`user_id` = u.`id`);

UPDATE `referrals` r
  JOIN `users` u ON u.`id` = r.`referred_user_id`
   SET r.`status` = 'void'
 WHERE r.`status` = 'pending'
   AND u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

UPDATE `referrals` r
  JOIN `users` u ON u.`id` = r.`referrer_user_id`
   SET r.`referrer_reward` = 0
 WHERE r.`status` = 'pending'
   AND r.`referrer_reward` <> 0
   AND u.`status` = 'deleted' AND u.`name` = 'Deleted user' AND u.`email` IS NULL;

-- Runs after the auth part added and backfilled these columns.
UPDATE `users`
   SET `email_changed_at` = NULL,
       `phone_changed_at` = NULL
 WHERE `status` = 'deleted' AND `name` = 'Deleted user' AND `email` IS NULL
   AND (`email_changed_at` IS NOT NULL OR `phone_changed_at` IS NOT NULL);

-- ---------------------------------------------------------------------
-- D6. One-time scrub of payment-provider payloads, for every row: the
--     payer's email, phone and UPI ID (and card and billing details) go;
--     ids, statuses, amounts, the method and error codes stay. New rows
--     are scrubbed before they are stored (PaymentService).
-- ---------------------------------------------------------------------
UPDATE `payments`
   SET `gateway_response` = JSON_REMOVE(`gateway_response`,
         '$.email', '$.contact', '$.vpa', '$.customer_details', '$.payment_method.upi.upi_id',
         '$.receipt_email', '$.billing_details', '$.card')
 WHERE `gateway_response` IS NOT NULL
   AND JSON_VALID(`gateway_response`)
   AND JSON_CONTAINS_PATH(`gateway_response`, 'one',
         '$.email', '$.contact', '$.vpa', '$.customer_details', '$.payment_method.upi.upi_id',
         '$.receipt_email', '$.billing_details', '$.card');

UPDATE `refunds`
   SET `gateway_response` = JSON_REMOVE(`gateway_response`,
         '$.email', '$.contact', '$.vpa', '$.customer_details', '$.payment_method.upi.upi_id',
         '$.receipt_email', '$.billing_details', '$.card')
 WHERE `gateway_response` IS NOT NULL
   AND JSON_VALID(`gateway_response`)
   AND JSON_CONTAINS_PATH(`gateway_response`, 'one',
         '$.email', '$.contact', '$.vpa', '$.customer_details', '$.payment_method.upi.upi_id',
         '$.receipt_email', '$.billing_details', '$.card');

UPDATE `payment_webhooks`
   SET `payload` = JSON_REMOVE(`payload`,
         '$.payload.payment.entity.email', '$.payload.payment.entity.contact', '$.payload.payment.entity.vpa',
         '$.payload.payment.entity.card', '$.data.customer_details', '$.data.payment.payment_method.upi.upi_id',
         '$.data.object.receipt_email', '$.data.object.charges')
 WHERE JSON_VALID(`payload`)
   AND JSON_CONTAINS_PATH(`payload`, 'one',
         '$.payload.payment.entity.email', '$.payload.payment.entity.contact', '$.payload.payment.entity.vpa',
         '$.payload.payment.entity.card', '$.data.customer_details', '$.data.payment.payment_method.upi.upi_id',
         '$.data.object.receipt_email', '$.data.object.charges');

-- =====================================================================
-- 4. OPS
-- =====================================================================

-- ---------------------------------------------------------------------
-- 048 part: ops (scheduled jobs, the published privacy policy, the
-- account-deletion FAQ and the terms' termination clause)
--
-- Idempotent, and valid on MariaDB 10.4 and 11.8. The literals carry UTF-8
-- arrows and quotation marks, so the assembled file must start with
-- SET NAMES utf8mb4. database/seeds/002_cms_content.sql carries the same
-- page, FAQ and terms text for fresh installs, where these UPDATEs find no
-- rows because the seeds run after the migrations.
-- ---------------------------------------------------------------------

-- An erasure commits in one transaction; deleting the uploaded files, data
-- exports, KYC documents and the Firebase sign-in record comes after it and
-- can fail. This job retries what is left until every step has succeeded.
-- An operator's choice to disable the job is kept on a re-run.
INSERT INTO `scheduled_jobs` (`job_key`, `name`, `description`, `schedule_hint`, `is_enabled`) VALUES
  ('privacy:finish-deletions', 'Finish account deletions',
   'Retries the clean-up after an account is erased: uploaded files, data exports, KYC documents and the Firebase sign-in record.',
   '*/10 * * * *', 1)
ON DUPLICATE KEY UPDATE
  `name`          = VALUES(`name`),
  `description`   = VALUES(`description`),
  `schedule_hint` = VALUES(`schedule_hint`);

-- System -> Jobs describes what the two retention jobs now delete.
UPDATE `scheduled_jobs`
   SET `description` = 'Deletes expired tokens, OTPs, rate-limit buckets and webhooks older than 90 days; sent email, SMS and push copies after 7 days and failed or unsent ones after 30; and backups past their retention.'
 WHERE `job_key` = 'tokens:prune';

UPDATE `scheduled_jobs`
   SET `description` = 'Trims system logs, deletes sign-in history older than a year and API request logs older than 180 days, and removes old log files.'
 WHERE `job_key` = 'logs:rotate';

-- Privacy Policy sections 5 and 7. The published text promised an erasure
-- "within 30 days" and listed only financial records as kept. Deletion is
-- immediate, and messages, reviews, support records, security logs, a
-- minimal identity verification record, a deletion record and backups are
-- kept too, so the policy now says exactly what goes and what stays, and
-- that support acts on a request made by email. Guarded on the old wording:
-- a page an operator has already rewritten is left alone (the ops test
-- suite then reports it), and a re-run finds nothing to change.
UPDATE `pages`
   SET `content` = REPLACE(REPLACE(`content`,
         '<h2>5. Retention</h2><p>Account data is retained while your account is open. After deletion we erase personal data within 30 days, except financial records we must keep for eight years under Indian tax law.</p>',
         '<h2>5. Retention and deletion</h2><p>We keep your account data while your account is open.</p><p><strong>Deleting your account.</strong> You can delete your account at any time in the app under <strong>Settings → Privacy &amp; data → Delete my account</strong>. You confirm it is you with your password or a one-time code sent to your email or phone, then type DELETE. If you cannot use the app, see section 7.</p><p>An account cannot be deleted while something is still in progress: a booking in progress, a dispute under review, money in your wallet (including earnings still held in escrow), a payment into your wallet that is still being confirmed, a withdrawal or refund still being processed, or a wallet on hold. The app shows what needs to be settled. A small balance that cannot be paid out can be given up instead: the app offers this when it applies, or you can contact support and we will pay it out first. We do not cancel bookings for you.</p><p><strong>What we erase.</strong> Deletion takes effect as soon as you confirm and cannot be undone. We erase your name, photo, bio, city and company details, and every email address and phone number on your account, except where listed below; your creator profile, portfolio, packages, social accounts, availability and its edit history; your saved creators, follows, search history, the profiles you viewed and notifications, and our copies of the emails, texts and push messages sent to you; your password, linked Google or Apple sign-in, sessions and devices, and you are signed out everywhere; your saved bank accounts and UPI IDs; the files you uploaded, except files attached to bookings or sent in chat; and any copies of your data still stored on our servers. Open and draft campaigns are closed. Identity (KYC) document images and numbers are deleted; we keep only a minimal record that a verification took place, with its reference, outcome and dates.</p><p><strong>What we keep, and why.</strong></p><ul><li><strong>Bookings, payments, invoices, refunds, payouts and wallet entries</strong>, because Indian tax and accounting law requires us to keep financial records, generally for eight years. They show “Deleted user” in place of your account. Tax invoices and payout statements stay exactly as issued, with the billing name, address and GSTIN printed on them, and payout records keep the account holder''s name, the bank and a masked account number or UPI ID. Payment providers keep their own records of your payments.</li><li><strong>Messages you sent</strong> stay in the other person''s conversation, shown as from “Deleted user”. They are part of that person''s record and may be needed to resolve a dispute. Notifications about you in their app lose your name, but emails we already delivered to them cannot be recalled.</li><li><strong>Reviews you wrote</strong> stay on the creator''s or brand''s page, shown as from “Deleted user” with no photo, so ratings stay honest.</li><li><strong>Support tickets, contact-form messages and reports</strong>: your name and contact details are removed from them, but what you and our team wrote stays as written, for safety, fraud prevention and legal claims.</li><li><strong>Security logs</strong>: the time, IP address and device of each sign-in and of other account activity, without your email address or phone number, kept for 12 months to detect fraud and abuse.</li><li><strong>A minimal identity verification record</strong>: that your identity was checked, when, the outcome, and the name and IP address recorded at each step, kept for the period the law requires.</li><li><strong>A deletion record</strong>: that an account was deleted, when, how it was confirmed, and the reason you chose and anything you wrote with it, with no contact details.</li><li><strong>Backups</strong>: copies of our database are kept for up to 14 days to recover from failures, then deleted. If we ever restore one, we delete your account again straight away.</li></ul><p>Copies of your data that you downloaded stay on your device. We cannot delete those. The same list is on our <a href="/account-deletion">account deletion page</a>.</p>'),
         '<h2>7. Your rights</h2><p>You can access, correct, export or delete your data from Settings, or by writing to <a href="mailto:privacy@zustee.com">privacy@zustee.com</a>. You may withdraw marketing consent at any time.</p>',
         '<h2>7. Your rights</h2><p>You can see and correct most of your data in the app. To get a copy of the personal data we hold about you, use <strong>Settings → Privacy &amp; data → Download my data</strong>: after you confirm it is you, you get a ZIP file through a download link that works for a limited time. To delete your account, see section 5. If you cannot use the app, write to <a href="mailto:privacy@zustee.com">privacy@zustee.com</a> from the email address on your account; we act on the request once we have confirmed it comes from you. You may withdraw marketing consent at any time.</p>'),
       `subtitle`     = 'Last updated: 2 October 2026',
       `version`      = `version` + 1,
       `published_at` = NOW()
 WHERE `slug` = 'privacy'
   AND `content` LIKE BINARY '%After deletion we erase personal data within 30 days%';

-- Help centre FAQ "How do I delete my account?". The seeded answer pointed
-- at a screen that does not exist and promised that open bookings are
-- closed and wallet balances settled for you, and an erasure within 30
-- days. Only the seeded answer is replaced; an operator's edit is kept.
UPDATE `faqs`
   SET `answer` = 'Go to Settings → Privacy & data → Delete my account (also in the Profile menu under Privacy & data). Confirm it''s you with your password or a one-time code, then type DELETE. Anything still in progress must be settled first: bookings in progress, disputes under review, money in your wallet, and withdrawals or refunds still being processed. A small balance that can''t be paid out can be given up instead. The screen shows what is in the way. Deletion happens as soon as you confirm and can''t be undone: your profile, contact details, creator profile, saved items, payout details and uploaded files (except files attached to bookings or sent in chat) are erased, and you''re signed out everywhere. Bookings, payments and invoices are kept because tax law requires it, and messages you sent and reviews you wrote stay without your name. Want a copy of your data? Use Download my data before you delete.'
 WHERE `question` = 'How do I delete my account?'
   AND `answer` LIKE '%within 30 days%';

-- Terms section 10 names everything that has to be settled before an
-- account can be closed, as the deletion screen does.
UPDATE `pages`
   SET `content`  = REPLACE(`content`, 'once open bookings are settled', 'once bookings in progress, disputes, your wallet balance and any pending withdrawals or refunds are settled'),
       `subtitle` = 'Last updated: 2 October 2026',
       `version`  = `version` + 1
 WHERE `slug` = 'terms'
   AND `content` LIKE '%once open bookings are settled%';
