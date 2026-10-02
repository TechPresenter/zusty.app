-- ---------------------------------------------------------------------
-- 046 - Rebrand: "Zuste+" becomes "Zusty" in live presentation data.
--
-- The product's visible name changes from "Zuste+" to "Zusty" (the new logo
-- reads "Zusty.app"). Code, templates and seeds carry the new name for fresh
-- installs; this migration brings an EXISTING database in line by rewriting
-- the rows people actually read: app/mail/SEO settings, notification
-- templates, CMS pages, FAQs, blogs, popups, coupons, homepage rails,
-- system-generated creator SEO text, marketing copy, media alt text and the
-- staff display label on support replies.
--
-- Three case-sensitive passes, in this order:
--
--   1. "Zuste+"      -> "Zusty"   (the brand as written in the product)
--   2. "Zuste plus"  -> "Zusty"   (the spelled-out form some alt text uses;
--                                  without this pass it would read
--                                  "Zusty plus")
--   3. "Zuste" as a whole word -> "Zusty", EXCEPT when followed by
--      " Technologies": "Zuste Technologies Pvt. Ltd." is the registered
--      legal entity printed on invoices and terms, and it does not change.
--
-- (?-i) forces case-sensitive matching even though every column here is
-- utf8mb4_unicode_ci, so lower-case identifiers are never touched:
-- zuste_marketplace, zustee.com / zuste.com URLs, @zusty.app addresses,
-- slugs such as "...-on-zuste", picsum seeds such as "zuste-media-1418".
-- The word boundary keeps longer words intact ("Zustee" is a different
-- spelling and is left as it is). In SQL source the regex escapes are
-- doubled: '\\+' reaches the server as \+ and '\\b' as \b.
--
-- Deliberately NOT rewritten - these are records of what was sent, logged
-- or written by a person, not presentation copy:
--   email_queue, push_queue, notifications, notification_broadcasts,
--   activity_logs, api_logs, system_logs, wallet_transactions,
--   withdrawal_requests, messages, conversations, contact_messages,
--   support_ticket_messages.message, creators.about, user_devices.
-- Also left alone: settings general.company_name (the legal entity), and
-- marketing campaigns that were already sent. kyc.consent_text is renamed
-- together with a kyc.consent_version bump (see the end of this file).
--
-- Re-runnable: every UPDATE is limited to rows that still contain the old
-- name, and a second run finds nothing left to change (the only remaining
-- matches are "Zuste Technologies" and "Zustee", which the passes skip).
-- Replacements never lengthen a value, so no column can overflow.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Settings: only the four rows that carry the product name.
-- ---------------------------------------------------------------------
UPDATE `settings`
   SET `value` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`value`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `value` LIKE BINARY '%Zuste%'
   AND ((`group_key` = 'general' AND `setting_key` = 'app_name')
     OR (`group_key` = 'mail'    AND `setting_key` = 'from_name')
     OR (`group_key` = 'seo'     AND `setting_key` IN ('site_title', 'site_description')));

-- ---------------------------------------------------------------------
-- Notification templates (future sends only; already-queued and
-- already-delivered messages keep the wording they went out with).
-- ---------------------------------------------------------------------
UPDATE `notification_templates`
   SET `title` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`title`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `title` LIKE BINARY '%Zuste%';

UPDATE `notification_templates`
   SET `body` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`body`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `body` LIKE BINARY '%Zuste%';

UPDATE `notification_templates`
   SET `email_subject` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`email_subject`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `email_subject` LIKE BINARY '%Zuste%';

UPDATE `notification_templates`
   SET `email_body` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`email_body`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `email_body` LIKE BINARY '%Zuste%';

UPDATE `notification_templates`
   SET `sms_body` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`sms_body`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `sms_body` LIKE BINARY '%Zuste%';

-- ---------------------------------------------------------------------
-- CMS pages. The contact page names the legal entity; pass 3 skips it.
-- ---------------------------------------------------------------------
UPDATE `pages`
   SET `title` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`title`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `title` LIKE BINARY '%Zuste%';

UPDATE `pages`
   SET `content` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`content`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `content` LIKE BINARY '%Zuste%';

UPDATE `pages`
   SET `meta_title` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`meta_title`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `meta_title` LIKE BINARY '%Zuste%';

UPDATE `pages`
   SET `meta_description` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`meta_description`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `meta_description` LIKE BINARY '%Zuste%';

-- ---------------------------------------------------------------------
-- FAQs
-- ---------------------------------------------------------------------
UPDATE `faqs`
   SET `question` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`question`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `question` LIKE BINARY '%Zuste%';

UPDATE `faqs`
   SET `answer` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`answer`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `answer` LIKE BINARY '%Zuste%';

-- ---------------------------------------------------------------------
-- Homepage rails ("New on Zuste+").
--
-- `title` is part of UNIQUE (title, section_type), and the 003 seed now
-- upserts "New on Zusty". If the seeds ran first (install.php --seed-only)
-- that row already exists and a plain rename would fail on the unique key,
-- so a row is only renamed when its new title is still free. MariaDB 10.3.2+
-- allows the subquery to read the table being updated.
-- ---------------------------------------------------------------------
UPDATE `homepage_sections` h
   SET h.`title` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(h.`title`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE h.`title` LIKE BINARY '%Zuste%'
   AND NOT EXISTS (
       SELECT 1 FROM `homepage_sections` d
        WHERE d.`section_type` = h.`section_type`
          AND d.`id` <> h.`id`
          AND d.`title` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(h.`title`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
   );

-- ---------------------------------------------------------------------
-- Popups
-- ---------------------------------------------------------------------
UPDATE `popups`
   SET `content` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`content`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `content` LIKE BINARY '%Zuste%';

-- ---------------------------------------------------------------------
-- Blogs and blog categories. Slugs are routes and stay as they are.
-- ---------------------------------------------------------------------
UPDATE `blogs`
   SET `title` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`title`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `title` LIKE BINARY '%Zuste%';

UPDATE `blogs`
   SET `excerpt` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`excerpt`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `excerpt` LIKE BINARY '%Zuste%';

UPDATE `blogs`
   SET `content` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`content`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `content` LIKE BINARY '%Zuste%';

UPDATE `blogs`
   SET `author_name` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`author_name`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `author_name` LIKE BINARY '%Zuste%';

UPDATE `blogs`
   SET `meta_title` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`meta_title`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `meta_title` LIKE BINARY '%Zuste%';

UPDATE `blogs`
   SET `meta_description` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`meta_description`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `meta_description` LIKE BINARY '%Zuste%';

UPDATE `blog_categories`
   SET `description` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`description`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `description` LIKE BINARY '%Zuste%';

-- ---------------------------------------------------------------------
-- Coupons and creator profile rules (admin-written descriptions shown in
-- the app). Coupon codes are untouched.
-- ---------------------------------------------------------------------
UPDATE `coupons`
   SET `description` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`description`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `description` LIKE BINARY '%Zuste%';

UPDATE `creator_profile_rules`
   SET `description` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`description`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `description` LIKE BINARY '%Zuste%';

-- ---------------------------------------------------------------------
-- Creators: only the system-generated SEO fields ("... | Zuste+").
-- `about` is the creator's own words and is not rewritten.
-- ---------------------------------------------------------------------
UPDATE `creators`
   SET `meta_title` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`meta_title`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `meta_title` LIKE BINARY '%Zuste%';

UPDATE `creators`
   SET `meta_description` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`meta_description`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `meta_description` LIKE BINARY '%Zuste%';

-- ---------------------------------------------------------------------
-- Marketing campaigns that have not gone out yet. Sent, sending and failed
-- campaigns are a record of what was mailed and keep their wording, like
-- notification_broadcasts. Older demo copy also spells the brand "Zustee";
-- a fourth pass renames that word too, but never the zustee.com domain
-- (a following "." or letter means it is a host name or a longer word).
-- ---------------------------------------------------------------------
UPDATE `marketing_campaigns`
   SET `content` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`content`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty'), '(?-i)\\bZustee\\b(?![.\\w])', 'Zusty')
 WHERE `content` LIKE BINARY '%Zuste%'
   AND `status` IN ('draft', 'scheduled', 'paused');

UPDATE `marketing_campaigns`
   SET `preheader` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`preheader`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty'), '(?-i)\\bZustee\\b(?![.\\w])', 'Zusty')
 WHERE `preheader` LIKE BINARY '%Zuste%'
   AND `status` IN ('draft', 'scheduled', 'paused');

-- ---------------------------------------------------------------------
-- KYC consent wording. Consent is versioned, so the new wording gets a new
-- version: profiles already submitted keep the version they agreed to, and
-- anyone submitting from now on agrees to the "Zusty" text. Only bumped
-- while the text still names the old brand, so a re-run changes nothing.
-- ---------------------------------------------------------------------
UPDATE `settings` v
  JOIN `settings` t
    ON t.`group_key` = 'kyc' AND t.`setting_key` = 'consent_text'
   SET v.`value` = '2026-10.1'
 WHERE v.`group_key` = 'kyc' AND v.`setting_key` = 'consent_version'
   AND t.`value` LIKE BINARY '%Zuste%'
   AND v.`value` = '2026-10';

UPDATE `settings`
   SET `value` = REGEXP_REPLACE(REGEXP_REPLACE(`value`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `group_key` = 'kyc' AND `setting_key` = 'consent_text'
   AND `value` LIKE BINARY '%Zuste%';

-- ---------------------------------------------------------------------
-- Media library alt text. File names (`original_name`, `file_name`,
-- `path`) are left exactly as uploaded.
-- ---------------------------------------------------------------------
UPDATE `media`
   SET `alt_text` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`alt_text`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `alt_text` LIKE BINARY '%Zuste%';

-- ---------------------------------------------------------------------
-- Support replies: the staff display label ("Zuste+ Support",
-- "Zuste+ System") only. Message bodies are not rewritten.
-- ---------------------------------------------------------------------
UPDATE `support_ticket_messages`
   SET `sender_name` = REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(`sender_name`, '(?-i)Zuste\\+', 'Zusty'), '(?-i)\\bZuste plus\\b', 'Zusty'), '(?-i)\\bZuste\\b(?! Technologies)', 'Zusty')
 WHERE `sender_name` LIKE BINARY '%Zuste%';

-- ---------------------------------------------------------------------
-- Cached payloads that embed the old name (widgets, rendered blocks).
-- Dropping a cache row is always safe: the next read rebuilds it. The
-- pattern matches "Zuste+" as well as the bare word, and skips entries
-- whose only mention is the legal entity.
-- ---------------------------------------------------------------------
DELETE FROM `cache_entries`
 WHERE `value` LIKE BINARY '%Zuste%'
   AND `value` REGEXP '(?-i)\\bZuste\\b(?! Technologies)';
