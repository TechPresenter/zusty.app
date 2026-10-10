-- ---------------------------------------------------------------------
-- 057 - Creator tools: a ceiling on portfolio items.
--
-- The app now lets creators manage their own packages, portfolio and
-- social accounts (U1, 2026-10-10). POST /me/creator/portfolio stores an
-- uploaded file and a media row for every item, and had no limit beyond
-- the generic request throttle, so one account could fill storage without
-- bound. The route now has its own upload throttle (30 per 10 minutes),
-- and this setting caps how many items one creator may keep:
--
--   creator.max_portfolio_items = 100. Items still in review or turned
--   down count; deleted ones do not. 0 means no limit. Adding past the
--   ceiling answers 422 portfolio_full, and GET /me/creator/portfolio
--   reports it as meta.max_items so the app can say so first.
--
-- Re-runnable: the row keeps whatever value it already holds; a second
-- run rewrites only its label and description. On a fresh install
-- (migrations run before seeds) seed 031 then finds the row and keeps its
-- value. Valid on MariaDB 10.4 and 11.8.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `settings`
  (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('creator', 'max_portfolio_items', '100', 'integer', 'Maximum portfolio items',
   'The most pieces of work one creator may keep in their portfolio, items still in review included. Each one is a stored file. Zero means no limit.',
   1, 0, 135)
ON DUPLICATE KEY UPDATE
  `type`        = VALUES(`type`),
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`);
