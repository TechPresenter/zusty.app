-- =====================================================================
-- Zuste+ — Migration 019
-- Authentication & Login Integration module.
--
-- NO NEW TABLES. The auth event log already exists and is simply unused for
-- app users: `login_history` (008_enterprise_modules.sql) already carries
-- actor_type ENUM('admin','user'), actor_id, outcome, auth_method, ip_address,
-- user_agent, device_type, is_new_device and failure_reason, is written by
-- SecurityService::recordLogin() and is read, filtered and CSV-exported by
-- SecurityController::loginHistory(). Creating an `auth_logs` table would
-- duplicate a writer, a reader, an exporter and a screen.
--
-- So this migration only widens two ENUMs and adds the guest account type.
-- Every statement is additive: no column dropped, no ENUM member removed, so
-- existing rows and existing code paths are unaffected.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. login_history — app users sign in by methods the ENUM cannot name yet,
--    and "refused because an admin disabled this method" is an outcome worth
--    recording. Without it those refusals would be invisible.
-- ---------------------------------------------------------------------
ALTER TABLE `login_history`
  MODIFY `auth_method` ENUM(
      'password','google','apple','phone_otp','refresh','2fa',
      'email_otp','facebook','guest','magic_link'
  ) NOT NULL DEFAULT 'password',
  MODIFY `outcome` ENUM(
      'success','bad_credentials','locked','suspended','throttled',
      '2fa_required','2fa_failed',
      'method_disabled','provider_error'
  ) NOT NULL;

-- ---------------------------------------------------------------------
-- 2. Guest accounts.
--
--    A guest is a real `users` row rather than a synthetic token, for two
--    reasons that decide the whole design:
--
--    a) Every user_id foreign key — favourites, search history, view history,
--       notifications, device tokens, chat participants — keeps working with
--       no change, and AuthMiddleware::loadUser() needs no branch.
--
--    b) Upgrading a guest to a real account becomes an UPDATE of the same row.
--       The id never changes, so nothing the guest did is lost and no data
--       migration is required. A synthetic guest token would force a merge,
--       and merging two rows means rewriting every FK in the schema.
--
--    account_type (not a separate is_guest flag) is what makes the existing
--    authorisation machinery reject guests with zero new code:
--    AuthMiddleware::requireAccountType() already 403s anything that is not
--    the required 'brand'/'creator', and requireVerified() already 403s an
--    account with neither a verified email nor phone. A boolean flag would
--    leave guests holding full brand privileges until every route was
--    individually patched — which is exactly the kind of gate that gets missed.
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  MODIFY `account_type` ENUM('brand','creator','guest') NOT NULL DEFAULT 'brand',
  ADD COLUMN IF NOT EXISTS `guest_expires_at` DATETIME NULL
      COMMENT 'Set only for account_type=guest; the purge job sweeps past this'
      AFTER `deletion_requested_at`,
  ADD COLUMN IF NOT EXISTS `converted_at` DATETIME NULL
      COMMENT 'When a guest row became a real account'
      AFTER `guest_expires_at`,
  ADD INDEX IF NOT EXISTS `idx_users_guest_sweep` (`account_type`, `converted_at`, `last_seen_at`);
