-- ---------------------------------------------------------------------
-- 055 - Sign-up consent: the terms on every sign-up, marketing by opt-in
-- only, an email address on every new account, no Sign in with Apple.
--
-- The operator's decisions (2026-10-10, for Google Play review):
--
--   Play UGC. A Google sign-up accepts the Terms and Privacy Policy just
--       as the email sign-up does: the app shows "By continuing, you agree
--       to the Terms and Privacy Policy" above its Google button and sends
--       accept_terms, and the API refuses a new account without it
--       (422 terms_required). Existing accounts sign in as before.
--   U3. Product updates and offers are opt-in (DPDP Act): an unticked box
--       at sign-up, off for a Google sign-up, a switch in Settings →
--       Notifications. Campaigns and promotional broadcasts reach only
--       accounts that hold "marketing": true.
--   U4. Every new account has an email address. SMS is off, so a number
--       alone could never be verified.
--   D3. Sign-in is email and password, a code sent by email, or Google.
--
--   1. users.terms_accepted_at: when the account accepted the Terms and
--      Privacy Policy at sign-up. Existing accounts stay NULL: when they
--      accepted was never recorded, and it is not invented here.
--   2. auth.identifier_mode = 'email' ('phone' is no longer a mode), and
--      auth.require_terms_accept = 1: accepting the terms is always
--      enforced and no longer a switch in the panel.
--   3. integrations.apple_login switched off. Stored credentials stay.
--
-- Deliberately NOT changed: any stored notification preference. An account
-- that holds "marketing": true keeps it. Whether each of those people chose
-- it cannot be known now (sign-up used to store true for everybody), so
-- nobody is flipped either way. What changed is the code's default: an
-- account with no stored choice (a guest, an account made by an operator)
-- now reads as opted out, where it used to read as opted in.
--
-- Re-runnable: the column is added only when missing, and every other
-- statement writes the same values again. Valid on MariaDB 10.4 and 11.8.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. When the terms were accepted
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `terms_accepted_at` DATETIME NULL
    COMMENT 'When the account accepted the Terms and Privacy Policy at sign-up; NULL before migration 055'
    AFTER `notification_prefs`;

-- ---------------------------------------------------------------------
-- 2. Sign-up policy
--
-- INSERT ... ON DUPLICATE so a row missing on this install is created with
-- its full description; on a fresh install (migrations run before seeds)
-- seed 008 then finds it and keeps the value.
-- ---------------------------------------------------------------------
INSERT INTO `settings`
  (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('auth', 'identifier_mode', 'email', 'string', 'Sign-in identifier',
   'email | email_or_phone: what the sign-in field asks for. Sign-up always needs an email address.', 0, 0, 403),
  ('auth', 'require_terms_accept', '1', 'boolean', 'Require accepting the terms',
   'Always on: every sign-up, Google included, accepts the terms. Kept for apps that read it.', 0, 0, 706)
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `description` = VALUES(`description`);

-- ---------------------------------------------------------------------
-- 3. No Sign in with Apple
--
-- The same values the panel's switch writes when an operator turns a
-- method off. Created when the row is missing, so seed 008 (which never
-- changes an existing row) cannot switch it on again on a fresh install.
-- ---------------------------------------------------------------------
INSERT INTO `integrations` (`integration_key`, `is_enabled`, `status`) VALUES
  ('apple_login', 0, 'disabled')
ON DUPLICATE KEY UPDATE `is_enabled` = 0, `status` = 'disabled';
