-- ---------------------------------------------------------------------
-- 050 - Mail domain: every published @zustee.com mailbox becomes @zusty.app.
--
-- zustee.com takes no mail, so any address at it is dead. Several live rows
-- still named one: the Privacy Policy section 7 that migration 048 writes
-- (privacy@), the terms' contact line (legal@), the contact page (support@,
-- press@, partners@), and the support and sender addresses in settings. The
-- same local part moves to zusty.app: privacy@zustee.com becomes
-- privacy@zusty.app, and so on for any local part.
--
-- Only MAILBOXES change. The pattern needs a local-part character right
-- before the "@" and refuses a longer host after "zustee.com", so
-- https://zustee.com/..., www.zustee.com and @handle paths are never
-- touched, and neither is a host such as zustee.com.au. Matching ignores
-- case, as mail domains do.
--
--   1. CMS pages: title, subtitle, content and the meta fields. A page whose
--      content changes gets its version bumped, once.
--   2. The privacy and terms pages' "Last updated: 2 October 2026" line (set
--      by 048) moves to 10 October 2026. A line an operator has rewritten is
--      left alone. Seed 002 carries the same date for fresh installs.
--   3. Other user-facing copy that could name a mailbox: FAQs, notification
--      templates, popups and blog posts.
--   4. Settings that hold mail addresses (support_email, mail from_address,
--      reply-to and contact addresses and the like). Encrypted values and
--      credentials (any key naming a user, password, secret or token, such as
--      the SMTP username) are never rewritten: a login must be changed at the
--      mail provider first.
--
-- Re-runnable: every UPDATE is limited by REGEXP to rows that still hold a
-- @zustee.com mailbox (or by equality to the old date line), so a second run
-- changes nothing and bumps no version. Valid on MariaDB 10.4 and 11.8
-- (REGEXP_REPLACE with \1 back-references). In SQL source the regex escapes
-- are doubled: '\\.' reaches the server as \. and '\\1' as \1.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. CMS pages
-- ---------------------------------------------------------------------
UPDATE `pages`
   SET `content` = REGEXP_REPLACE(`content`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app'),
       `version` = `version` + 1
 WHERE `content` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `pages`
   SET `title` = REGEXP_REPLACE(`title`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `title` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `pages`
   SET `subtitle` = REGEXP_REPLACE(`subtitle`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `subtitle` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `pages`
   SET `meta_title` = REGEXP_REPLACE(`meta_title`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `meta_title` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `pages`
   SET `meta_description` = REGEXP_REPLACE(`meta_description`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `meta_description` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `pages`
   SET `meta_keywords` = REGEXP_REPLACE(`meta_keywords`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `meta_keywords` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

-- ---------------------------------------------------------------------
-- 2. The legal pages' effective date
-- ---------------------------------------------------------------------
UPDATE `pages`
   SET `subtitle` = 'Last updated: 10 October 2026'
 WHERE `slug` IN ('privacy', 'terms')
   AND `subtitle` = 'Last updated: 2 October 2026';

-- ---------------------------------------------------------------------
-- 3. Other user-facing copy
-- ---------------------------------------------------------------------
UPDATE `faqs`
   SET `question` = REGEXP_REPLACE(`question`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `question` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `faqs`
   SET `answer` = REGEXP_REPLACE(`answer`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `answer` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `notification_templates`
   SET `title` = REGEXP_REPLACE(`title`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `title` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `notification_templates`
   SET `body` = REGEXP_REPLACE(`body`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `body` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `notification_templates`
   SET `email_subject` = REGEXP_REPLACE(`email_subject`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `email_subject` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `notification_templates`
   SET `email_body` = REGEXP_REPLACE(`email_body`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `email_body` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `notification_templates`
   SET `sms_body` = REGEXP_REPLACE(`sms_body`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `sms_body` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `popups`
   SET `content` = REGEXP_REPLACE(`content`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `content` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `blogs`
   SET `content` = REGEXP_REPLACE(`content`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `content` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

UPDATE `blogs`
   SET `excerpt` = REGEXP_REPLACE(`excerpt`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `excerpt` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])';

-- ---------------------------------------------------------------------
-- 4. Settings that hold mail addresses
--
-- Limited to keys whose name says they carry an address (email, address,
-- reply, contact, from, recipient, notify, mail), and never a credential.
-- LIKE patterns avoid "_", which LIKE reads as a wildcard.
-- ---------------------------------------------------------------------
UPDATE `settings`
   SET `value` = REGEXP_REPLACE(`value`, '(?i)([A-Za-z0-9._%+-])@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])', '\\1@zusty.app')
 WHERE `is_encrypted` = 0
   AND `value` REGEXP '(?i)[A-Za-z0-9._%+-]@zustee\\.com(?![A-Za-z0-9_-]|\\.[A-Za-z0-9_-])'
   AND (`setting_key` LIKE '%email%' OR `setting_key` LIKE '%address%' OR `setting_key` LIKE '%reply%'
        OR `setting_key` LIKE '%contact%' OR `setting_key` LIKE '%from%' OR `setting_key` LIKE '%recipient%'
        OR `setting_key` LIKE '%notify%' OR `setting_key` LIKE '%mail%')
   AND `setting_key` NOT LIKE '%user%'
   AND `setting_key` NOT LIKE '%pass%'
   AND `setting_key` NOT LIKE '%secret%'
   AND `setting_key` NOT LIKE '%token%';
