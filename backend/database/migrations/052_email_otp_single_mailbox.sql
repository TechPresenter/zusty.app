-- ---------------------------------------------------------------------
-- 052 - Sign-in by email code, one public mailbox, no published address.
--
-- The operator's decisions (2026-10-10):
--
--   D1. Exactly one public mailbox exists: support@zusty.app. Any other
--       mailbox at zusty.app or zustee.com (privacy@, legal@, press@,
--       partners@, hello@, contact@, grievance@, no-reply@ in copy, ...)
--       that user-facing copy or a contact/display setting names becomes
--       support@zusty.app. The SMTP login and sender (the `mail` group and
--       MAIL_* in .env) are not touched.
--   D2. No registered office or company address is published. The contact
--       page's "Registered office" line goes, and general.company_address
--       (printed on invoices) is emptied. The company name stays wherever
--       general.company_name already prints it.
--   D3. Sign-in is email and password, a one-time code sent to the
--       account's EMAIL, or Google. No SMS at all; a code signs in an
--       existing account only and never creates one.
--
--   1. auth: otp_login_enabled = 1, otp_channels = ["email"], the new
--      otp_label "Log in with email code" (the app's button), and the
--      mobile_otp method switched on, since it gates code sign-in.
--      sms.enabled = 0.
--   2. CMS pages: the contact page's seeded press/partners sentence is
--      rewritten, any list item or paragraph that opens with "Registered
--      office" or "Registered address" is removed, and every other mailbox
--      becomes support@zusty.app. A page whose content changes gets its
--      version bumped, once.
--   3. FAQs, notification templates, popups and blog posts: mailboxes.
--   4. Settings: mailboxes in any plain setting outside the `mail` group,
--      except keys naming a user, password, secret, token, sender or
--      "from" address. general.company_address is emptied.
--
-- The mailbox pattern needs the whole local part (nothing address-like
-- right before it) and refuses a longer host after the domain, so
-- support@zusty.app itself, https://zusty.app/..., www.zusty.app and
-- @handle paths are never touched. Matching ignores case.
--
-- Re-runnable: every UPDATE is limited to rows that still hold what it
-- changes, and none of its results matches again, so a second run changes
-- nothing and bumps no version. Valid on MariaDB 10.4 and 11.8. In SQL
-- source the regex escapes are doubled: '\\.' reaches the server as \.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Sign-in: email codes, no SMS
--
-- INSERT ... ON DUPLICATE so a row missing on this install is created
-- with its full description; on a fresh install (migrations run before
-- seeds) seed 008 then finds it and keeps the value.
-- ---------------------------------------------------------------------
INSERT INTO `settings`
  (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('auth', 'otp_login_enabled', '1', 'boolean', 'OTP sign-in issues a session',
   'On: a code sent to an existing account''s email signs it in (no account is created). Off: codes only verify an address or number.', 0, 0, 301),
  ('auth', 'otp_channels', '["email"]', 'json', 'OTP channels',
   'Which channels may carry a sign-in code: email, sms. SMS also needs Settings → SMS switched on.', 0, 0, 302),
  ('sms', 'enabled', '0', 'boolean', 'SMS enabled', 'Master switch for SMS delivery.', 0, 0, 1)
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- The label is the operator's to change later, so an existing value is kept.
INSERT INTO `settings`
  (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('auth', 'otp_label', 'Log in with email code', 'string', 'OTP button label',
   'What the sign-in screen calls the one-time code option.', 0, 0, 308)
ON DUPLICATE KEY UPDATE `setting_key` = `settings`.`setting_key`;

UPDATE `integrations`
   SET `is_enabled` = 1,
       `status`     = 'connected'
 WHERE `integration_key` = 'mobile_otp'
   AND (`is_enabled` <> 1 OR `status` <> 'connected');

-- ---------------------------------------------------------------------
-- 2. CMS pages
-- ---------------------------------------------------------------------
UPDATE `pages`
   SET `content` = REGEXP_REPLACE(
           REGEXP_REPLACE(
               REGEXP_REPLACE(
                   REPLACE(`content`,
                       '<p>For press enquiries write to press@zusty.app, and for partnership proposals write to partners@zusty.app.</p>',
                       '<p>Press enquiries and partnership proposals are welcome at the same address.</p>'),
                   '(?is)<li\\b[^>]*>\\s*(?:<(?:strong|b)>\\s*)?Registered\\s+(?:office|address)\\b(?:(?!</li>).)*</li>',
                   ''),
               '(?is)<p\\b[^>]*>\\s*(?:<(?:strong|b)>\\s*)?Registered\\s+(?:office|address)\\b(?:(?!</p>).)*</p>',
               ''),
           '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])',
           'support@zusty.app'),
       `version` = `version` + 1
 WHERE `content` LIKE BINARY '%<p>For press enquiries write to press@zusty.app, and for partnership proposals write to partners@zusty.app.</p>%'
    OR `content` REGEXP '(?is)<li\\b[^>]*>\\s*(?:<(?:strong|b)>\\s*)?Registered\\s+(?:office|address)\\b(?:(?!</li>).)*</li>'
    OR `content` REGEXP '(?is)<p\\b[^>]*>\\s*(?:<(?:strong|b)>\\s*)?Registered\\s+(?:office|address)\\b(?:(?!</p>).)*</p>'
    OR `content` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `pages`
   SET `meta_description` = 'Reach the Zusty team at support@zusty.app.'
 WHERE `slug` = 'contact'
   AND `meta_description` = 'Reach the Zusty support, press and partnerships teams.';

UPDATE `pages`
   SET `title` = REGEXP_REPLACE(`title`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `title` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `pages`
   SET `subtitle` = REGEXP_REPLACE(`subtitle`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `subtitle` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `pages`
   SET `meta_title` = REGEXP_REPLACE(`meta_title`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `meta_title` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `pages`
   SET `meta_description` = REGEXP_REPLACE(`meta_description`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `meta_description` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `pages`
   SET `meta_keywords` = REGEXP_REPLACE(`meta_keywords`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `meta_keywords` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

-- ---------------------------------------------------------------------
-- 3. Other user-facing copy
-- ---------------------------------------------------------------------
UPDATE `faqs`
   SET `question` = REGEXP_REPLACE(`question`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `question` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `faqs`
   SET `answer` = REGEXP_REPLACE(`answer`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `answer` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `notification_templates`
   SET `title` = REGEXP_REPLACE(`title`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `title` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `notification_templates`
   SET `body` = REGEXP_REPLACE(`body`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `body` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `notification_templates`
   SET `email_subject` = REGEXP_REPLACE(`email_subject`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `email_subject` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `notification_templates`
   SET `email_body` = REGEXP_REPLACE(`email_body`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `email_body` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `notification_templates`
   SET `sms_body` = REGEXP_REPLACE(`sms_body`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `sms_body` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `popups`
   SET `title` = REGEXP_REPLACE(`title`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `title` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `popups`
   SET `content` = REGEXP_REPLACE(`content`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `content` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `popups`
   SET `cta_value` = REGEXP_REPLACE(`cta_value`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `cta_value` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `blogs`
   SET `content` = REGEXP_REPLACE(`content`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `content` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `blogs`
   SET `excerpt` = REGEXP_REPLACE(`excerpt`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `excerpt` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

-- ---------------------------------------------------------------------
-- 4. Settings
--
-- Mailboxes in any plain setting that holds a contact or display address.
-- The `mail` group (SMTP host, login, password and sender) is skipped
-- whole, as is any key that names a credential or a sender; a sender
-- address is not a mailbox anyone is told to write to. LIKE patterns
-- avoid "_", which LIKE reads as a wildcard.
-- ---------------------------------------------------------------------
UPDATE `settings`
   SET `value` = REGEXP_REPLACE(`value`, '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', 'support@zusty.app')
 WHERE `is_encrypted` = 0
   AND `group_key` <> 'mail'
   AND `value` REGEXP '(?i)(?<![A-Za-z0-9._%+-])(?!support@zusty\\.app(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-]))[A-Za-z0-9._%+-]+@(?:zusty\\.app|zustee\\.com)(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])'
   AND `setting_key` NOT LIKE '%user%'
   AND `setting_key` NOT LIKE '%login%'
   AND `setting_key` NOT LIKE '%pass%'
   AND `setting_key` NOT LIKE '%secret%'
   AND `setting_key` NOT LIKE '%token%'
   AND `setting_key` NOT LIKE '%key%'
   AND `setting_key` NOT LIKE '%from%'
   AND `setting_key` NOT LIKE '%sender%'
   AND `setting_key` NOT LIKE '%smtp%';

-- D2: no company address is published (it was a placeholder city anyway).
UPDATE `settings`
   SET `value` = ''
 WHERE `group_key` = 'general'
   AND `setting_key` = 'company_address'
   AND `value` IS NOT NULL
   AND `value` <> '';
