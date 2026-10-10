-- ---------------------------------------------------------------------
-- 054 - Blocking, and reports on every kind of user content.
--
-- Google Play's user-generated content policy asks for a way to report
-- objectionable content and to block the people behind it. The app had a
-- Report item in chat only, and nothing to block anyone with, while the
-- community guidelines promised a report on any profile, message or booking.
--
--   1. user_blocks: one row per (blocker, blocked). A block is one person's
--      decision but works both ways: while a row exists in either direction
--      the two cannot message each other or start a booking, an offer, a
--      campaign invitation or an application with each other, and they drop
--      out of each other's search. A booking that is already paid for can
--      still be completed or disputed (BlockService has the full rule).
--      Deleted with either account (ON DELETE CASCADE for a hard delete;
--      an erasure removes the rows itself).
--
--   2. reports: campaign briefs become reportable, the reasons Play names
--      (hate speech, sexual content, violence, self-harm, child safety) are
--      added, and two columns give the moderation queue something to act
--      on: reported_user_id, the account behind the content, and
--      subject_excerpt, a copy of what was reported as it read at the time
--      (a message or a brief can change or go before anyone looks).
--      reported_user_id is backfilled for the reports already filed.
--
--   3. The community guidelines' Reporting section says what is true now.
--      Guarded on the seeded wording; seed 002 carries the same text for
--      fresh installs, where this UPDATE finds nothing.
--
-- Re-runnable: CREATE TABLE IF NOT EXISTS, ENUM MODIFYs that list the full
-- value set, ADD COLUMN / ADD INDEX IF NOT EXISTS, the foreign key dropped
-- (IF EXISTS) and re-added as its own statement, and every UPDATE limited
-- to rows that still hold what it changes. Valid on MariaDB 10.4 and 11.8.
-- The guidelines text carries UTF-8 arrows, hence SET NAMES.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Blocks
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_blocks` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `blocker_id` BIGINT UNSIGNED NOT NULL COMMENT 'The account that made the block',
  `blocked_id` BIGINT UNSIGNED NOT NULL COMMENT 'The account it was made against',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_blocks_pair` (`blocker_id`, `blocked_id`),
  KEY `idx_user_blocks_blocked` (`blocked_id`, `blocker_id`),
  CONSTRAINT `chk_user_blocks_not_self` CHECK (`blocker_id` <> `blocked_id`),
  CONSTRAINT `fk_user_blocks_blocker` FOREIGN KEY (`blocker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_blocks_blocked` FOREIGN KEY (`blocked_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Reports
-- ---------------------------------------------------------------------
ALTER TABLE `reports`
  MODIFY `reportable_type` ENUM('user','creator','booking','review','message','portfolio','campaign') NOT NULL,
  MODIFY `reason` ENUM(
    'spam','fraud','abuse','impersonation','inappropriate','copyright','payment_issue','other',
    'hate_speech','sexual_content','violence','self_harm','child_safety'
  ) NOT NULL,
  ADD COLUMN IF NOT EXISTS `reported_user_id` BIGINT UNSIGNED NULL
    COMMENT 'The account behind the reported content, resolved when the report is filed'
    AFTER `reportable_id`,
  ADD COLUMN IF NOT EXISTS `subject_excerpt` VARCHAR(500) NULL
    COMMENT 'What was reported, as it read when the report was filed'
    AFTER `description`,
  ADD INDEX IF NOT EXISTS `idx_reports_reported_user` (`reported_user_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_reports_reporter_subject` (`reporter_id`, `reportable_type`, `reportable_id`);

-- An account that is hard-deleted leaves the reports about it in place.
ALTER TABLE `reports` DROP FOREIGN KEY IF EXISTS `fk_reports_reported_user`;

ALTER TABLE `reports`
  ADD CONSTRAINT `fk_reports_reported_user` FOREIGN KEY (`reported_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Backfill: the account behind each report filed before this column existed.
-- Every UPDATE joins `users`, so only an account that still exists is written.
UPDATE `reports` r
  JOIN `users` u ON u.`id` = r.`reportable_id`
   SET r.`reported_user_id` = u.`id`
 WHERE r.`reported_user_id` IS NULL
   AND r.`reportable_type` = 'user';

UPDATE `reports` r
  JOIN `creators` c ON c.`id` = r.`reportable_id`
  JOIN `users` u ON u.`id` = c.`user_id`
   SET r.`reported_user_id` = u.`id`
 WHERE r.`reported_user_id` IS NULL
   AND r.`reportable_type` = 'creator';

UPDATE `reports` r
  JOIN `creator_portfolio` p ON p.`id` = r.`reportable_id`
  JOIN `creators` c ON c.`id` = p.`creator_id`
  JOIN `users` u ON u.`id` = c.`user_id`
   SET r.`reported_user_id` = u.`id`
 WHERE r.`reported_user_id` IS NULL
   AND r.`reportable_type` = 'portfolio';

UPDATE `reports` r
  JOIN `reviews` v ON v.`id` = r.`reportable_id`
  JOIN `users` u ON u.`id` = v.`reviewer_id`
   SET r.`reported_user_id` = u.`id`
 WHERE r.`reported_user_id` IS NULL
   AND r.`reportable_type` = 'review';

UPDATE `reports` r
  JOIN `messages` m ON m.`id` = r.`reportable_id` AND m.`sender_type` = 'user'
  JOIN `users` u ON u.`id` = m.`sender_id`
   SET r.`reported_user_id` = u.`id`
 WHERE r.`reported_user_id` IS NULL
   AND r.`reportable_type` = 'message';

-- A booking report is about the other party: whichever side did not file it.
UPDATE `reports` r
  JOIN `bookings` b ON b.`id` = r.`reportable_id`
  JOIN `users` u ON u.`id` = IF(b.`brand_user_id` = r.`reporter_id`, b.`creator_user_id`, b.`brand_user_id`)
   SET r.`reported_user_id` = u.`id`
 WHERE r.`reported_user_id` IS NULL
   AND r.`reportable_type` = 'booking';

-- ---------------------------------------------------------------------
-- 3. Community guidelines: reporting and blocking
-- ---------------------------------------------------------------------
UPDATE `pages`
   SET `content` = REPLACE(`content`,
         '<h2>Reporting</h2><p>Report any profile, message or booking from its overflow menu. Every report is reviewed by a human moderator.</p>',
         '<h2>Reporting and blocking</h2><p>Report a profile, a message, a review, a booking or a campaign brief from its menu in the app. Every report is reviewed by a human moderator, and the person you report is not told who reported them.</p><p>You can also block someone. Once you do, neither of you can message the other or send the other a booking request, an offer, a campaign invitation or an application, and you stop appearing in each other''s search. You can see and unblock the people you blocked under <strong>Settings → Privacy &amp; data → Blocked accounts</strong>. A booking that is already paid for can still be delivered, completed or disputed, so nobody''s payment is held up.</p>'),
       `version` = `version` + 1
 WHERE `slug` = 'community-guidelines'
   AND `content` LIKE BINARY '%<h2>Reporting</h2><p>Report any profile, message or booking from its overflow menu. Every report is reviewed by a human moderator.</p>%';
