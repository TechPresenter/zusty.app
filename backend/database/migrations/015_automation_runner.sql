-- =====================================================================
-- 015 — Automation runner
--
-- `automation_rules` held the rule book but nothing ran it. This migration
-- adds the two things a runner needs to be safe:
--
--   * a keyset cursor on each rule, so a pass reads only what appeared since
--     the last one and activating a rule never replays the back catalogue;
--   * a ledger with a unique key on (rule, subject), so the INSERT itself is
--     the idempotency guard and an action can never fire twice for the same
--     subject however the cursor or the clock behaves.
--
-- `last_error` gives the rules screen something honest to show when a rule's
-- actions are failing, instead of a silent zero.
-- =====================================================================

ALTER TABLE `automation_rules`
  ADD COLUMN IF NOT EXISTS `watermark_at` DATETIME NULL
    COMMENT 'Cursor: signal timestamp the runner has read up to' AFTER `last_run_at`,
  ADD COLUMN IF NOT EXISTS `watermark_id` BIGINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'Cursor tiebreaker: last subject id read at watermark_at' AFTER `watermark_at`,
  ADD COLUMN IF NOT EXISTS `last_error` VARCHAR(500) NULL
    COMMENT 'Why the last pass could not complete, cleared on a clean pass' AFTER `watermark_id`;

CREATE TABLE IF NOT EXISTS `automation_rule_runs` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id`   INT UNSIGNED    NOT NULL,
  `entity_id` BIGINT UNSIGNED NOT NULL COMMENT 'Primary key of the row in the trigger source table',
  `fired_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_arr_rule_entity` (`rule_id`, `entity_id`),
  KEY `idx_arr_fired` (`fired_at`),
  CONSTRAINT `fk_arr_rule` FOREIGN KEY (`rule_id`) REFERENCES `automation_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
