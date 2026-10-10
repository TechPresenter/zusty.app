-- ---------------------------------------------------------------------
-- 051 - Legal entity: the business behind Zusty is
--       "Appsgain Technologies Private Limited".
--
-- Earlier seeds named the operator "Zuste Technologies Pvt. Ltd.", which is
-- not a registered company. The operator's decision (2026-10-10) is that the
-- legal entity printed on invoices, legal pages and the website footer is
-- Appsgain Technologies Private Limited.
--
--   1. settings general.company_name, the value invoices, exports, the admin
--      sign-in pages and the website footer print. Changed only while it
--      still holds the old seeded name, so a value an operator has set is
--      kept.
--   2. CMS pages that print the old name (the contact page's "Registered
--      office" line). The seeded city ("Mumbai, Maharashtra, India") was a
--      placeholder, not a real address, so that whole line becomes the new
--      name followed by "India" until the operator adds the registered
--      address. A page whose content changes gets its version bumped, once.
--
-- Invoices already issued keep the name they were issued under.
-- Re-runnable: every statement only touches rows that still hold the old
-- name, and the new name does not contain it.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE `settings`
   SET `value` = 'Appsgain Technologies Private Limited'
 WHERE `group_key` = 'general'
   AND `setting_key` = 'company_name'
   AND (`value` IS NULL
        OR `value` = ''
        OR `value` LIKE BINARY '%Zuste Technologies%');

UPDATE `pages`
   SET `content` = REPLACE(
           REPLACE(
               REPLACE(`content`,
                   'Zuste Technologies Pvt. Ltd., Mumbai, Maharashtra, India',
                   'Appsgain Technologies Private Limited, India'),
               'Zuste Technologies Pvt. Ltd.',
               'Appsgain Technologies Private Limited'),
           'Zuste Technologies',
           'Appsgain Technologies Private Limited'),
       `version` = `version` + 1
 WHERE `content` LIKE BINARY '%Zuste Technologies%';

UPDATE `pages`
   SET `title`            = REPLACE(`title`, 'Zuste Technologies Pvt. Ltd.', 'Appsgain Technologies Private Limited'),
       `meta_title`       = REPLACE(`meta_title`, 'Zuste Technologies Pvt. Ltd.', 'Appsgain Technologies Private Limited'),
       `meta_description` = REPLACE(`meta_description`, 'Zuste Technologies Pvt. Ltd.', 'Appsgain Technologies Private Limited')
 WHERE CONCAT_WS(' ', `title`, `meta_title`, `meta_description`) LIKE BINARY '%Zuste Technologies Pvt. Ltd.%';
