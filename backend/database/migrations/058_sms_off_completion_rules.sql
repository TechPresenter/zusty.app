-- ---------------------------------------------------------------------
-- 058 - Profile completion with SMS off, and phone numbers out of the
-- request log.
--
-- 1. Completion rules. Migration 037 made "Add a mobile number",
--    "Confirm your mobile number" and the "Mobile verified" badge
--    mandatory. A number is added and confirmed by a code sent by SMS,
--    and SMS is off (sms.enabled = 0, the standing decision), so
--    Email & mobile refuses a new number with 422 sms_unavailable and no
--    creator could ever meet them: every profile, the review account's
--    included, showed a mandatory item that led nowhere. They become
--    optional here. The code also stops scoring them while SMS is off
--    (ProfileCompletionService::applicableRules()), so switching SMS on
--    later brings them back as optional items with no data change.
--    The rules are found by what they read (a mobile-number column on
--    `users`, or a count of the mobile_verified badge), the same test the
--    code uses, so a renamed rule_key is still covered.
-- 2. Feature gates. accept_bookings and apply_to_campaigns asked for the
--    mobile_verified badge, which nobody can earn without SMS. It is taken
--    out of every gate that names it; the code also skips it while SMS is
--    off, should an operator add it back.
-- 3. creator.required_verifications drops mobile_verified the same way.
-- 4. The sms.enabled settings row gets its definition back (type boolean,
--    label, description, sort order) wherever it was re-created bare, as
--    the sign-up consent test suite used to do. Its value is not touched.
-- 5. Stored completion rows that list a mobile rule as missing are
--    removed. They are a cache: the app's completion screen refills a
--    creator's rows on the next read, and creators:completion rescores
--    everyone nightly. Run that job from System -> Jobs after deploying to
--    refresh the admin list at once.
-- 6. api_logs. The request log masked only the exact keys email and
--    phone, so a failed POST /payments/booking/{id} kept customer_phone in
--    the clear for the 180 days a row lives. ApiLogService now masks any
--    key containing phone, mobile, msisdn, whatsapp or email. Bodies
--    already stored get the same here: a number keeps its last four
--    digits behind six stars, an email address its first character and
--    its domain. Only JSON bodies are stored for API requests; a body cut
--    short at 2,000 characters is matched as text all the same.
-- 7. api_logs, the contacts endpoints. POST /me/contacts/change and
--    /me/contacts/alternates send {"channel": "email|phone", "value": ...},
--    and `value` names nothing personal, so a refused change kept the new
--    address or number in the clear. ApiLogService now masks a value beside
--    a contact channel, a phone-shaped value under value, new_value,
--    old_value or contact, and an email address wherever it appears. The
--    stored bodies get the same here: phone-shaped values under those keys,
--    quoted or bare, and every email address in the body, under any key, in
--    a list or inside text (a file name such as logo@2x.png is left alone).
--
-- Re-runnable: every UPDATE and DELETE is limited to rows that still hold
-- what it changes, and none of its results matches again. Valid on
-- MariaDB 10.4 and 11.8. In SQL source the regex escapes are doubled:
-- '\\s' reaches the server as \s and '\\1' as the first group.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Mobile rules become optional
-- ---------------------------------------------------------------------
UPDATE `creator_profile_rules`
   SET `is_mandatory` = 0
 WHERE `is_mandatory` = 1
   AND (
         (`source_table` = 'users'
          AND `source_column` IN ('phone', 'phone_country_code', 'phone_verified_at'))
      OR (`source_table` = 'creator_badges'
          AND JSON_VALID(`source_filter`)
          AND JSON_UNQUOTE(JSON_EXTRACT(`source_filter`, '$.badge')) = 'mobile_verified')
   );

-- ---------------------------------------------------------------------
-- 2. No gate asks for the mobile_verified badge
-- ---------------------------------------------------------------------
UPDATE `creator_feature_gates`
   SET `required_badges` = JSON_REMOVE(
           `required_badges`,
           JSON_UNQUOTE(JSON_SEARCH(`required_badges`, 'one', 'mobile_verified')))
 WHERE JSON_VALID(`required_badges`)
   AND JSON_SEARCH(`required_badges`, 'one', 'mobile_verified') IS NOT NULL;

-- ---------------------------------------------------------------------
-- 3. creator.required_verifications: email only
--
-- A comma-separated list. Wrapped in commas so the slug is matched whole,
-- then the wrapping is trimmed off again. Absent on a fresh install until
-- seed 031 runs, which carries the same list.
-- ---------------------------------------------------------------------
UPDATE `settings`
   SET `value` = TRIM(BOTH ',' FROM REPLACE(CONCAT(',', REPLACE(`value`, ' ', ''), ','), ',mobile_verified,', ','))
 WHERE `group_key` = 'creator'
   AND `setting_key` = 'required_verifications'
   AND FIND_IN_SET('mobile_verified', REPLACE(`value`, ' ', '')) > 0;

-- ---------------------------------------------------------------------
-- 4. sms.enabled keeps its definition (seed 001, migration 052)
-- ---------------------------------------------------------------------
UPDATE `settings`
   SET `type`         = 'boolean',
       `label`        = 'SMS enabled',
       `description`  = 'Master switch for SMS delivery.',
       `is_public`    = 0,
       `is_encrypted` = 0,
       `sort_order`   = 1
 WHERE `group_key` = 'sms'
   AND `setting_key` = 'enabled'
   AND (`type` <> 'boolean' OR `label` IS NULL OR `label` = '' OR `description` IS NULL);

-- ---------------------------------------------------------------------
-- 5. Stored completion rows naming a mobile rule as missing
-- ---------------------------------------------------------------------
DELETE FROM `creator_profile_completion`
 WHERE JSON_VALID(`missing`)
   AND EXISTS (
         SELECT 1
           FROM `creator_profile_rules` r
          WHERE r.`section` = `creator_profile_completion`.`section`
            AND (
                  (r.`source_table` = 'users'
                   AND r.`source_column` IN ('phone', 'phone_country_code', 'phone_verified_at'))
               OR (r.`source_table` = 'creator_badges'
                   AND JSON_VALID(r.`source_filter`)
                   AND JSON_UNQUOTE(JSON_EXTRACT(r.`source_filter`, '$.badge')) = 'mobile_verified')
                )
            AND JSON_CONTAINS(`creator_profile_completion`.`missing`, JSON_QUOTE(r.`rule_key`))
       );

-- ---------------------------------------------------------------------
-- 6. Phone numbers and email addresses already in the request log
--
-- The keys: any name containing phone, mobile, msisdn or whatsapp (and,
-- for addresses, email), plus identifier, destination and recipient,
-- which carry the address a sign-in or a code was for. A number is seven
-- or more digits, spaces, dots, dashes or brackets ending in four digits,
-- quoted or bare. A value that is already masked starts with a star and
-- is not matched again.
-- ---------------------------------------------------------------------
UPDATE `api_logs`
   SET `request_body` = REGEXP_REPLACE(
           `request_body`,
           '(?i)("(?:[a-z0-9_.-]*(?:phone|mobile|msisdn|whatsapp)[a-z0-9_.-]*|identifier|destination|recipient)"\\s*:\\s*")\\+?[0-9][0-9 ().-]{2,}([0-9]{4})"',
           '\\1******\\2"')
 WHERE `request_body` REGEXP '(?i)"(?:[a-z0-9_.-]*(?:phone|mobile|msisdn|whatsapp)[a-z0-9_.-]*|identifier|destination|recipient)"\\s*:\\s*"\\+?[0-9][0-9 ().-]{2,}[0-9]{4}"';

UPDATE `api_logs`
   SET `request_body` = REGEXP_REPLACE(
           `request_body`,
           '(?i)("(?:[a-z0-9_.-]*(?:phone|mobile|msisdn|whatsapp)[a-z0-9_.-]*|identifier|destination|recipient)"\\s*:\\s*)[0-9]{3,}([0-9]{4})(?=\\s*[,}\\]])',
           '\\1"******\\2"')
 WHERE `request_body` REGEXP '(?i)"(?:[a-z0-9_.-]*(?:phone|mobile|msisdn|whatsapp)[a-z0-9_.-]*|identifier|destination|recipient)"\\s*:\\s*[0-9]{7,}\\s*[,}\\]]';

UPDATE `api_logs`
   SET `request_body` = REGEXP_REPLACE(
           `request_body`,
           '(?i)("(?:[a-z0-9_.-]*(?:email|phone|mobile)[a-z0-9_.-]*|identifier|destination|recipient)"\\s*:\\s*")([^"@*\\\\])[^"@*\\\\]*@',
           '\\1\\2****@')
 WHERE `request_body` REGEXP '(?i)"(?:[a-z0-9_.-]*(?:email|phone|mobile)[a-z0-9_.-]*|identifier|destination|recipient)"\\s*:\\s*"[^"@*\\\\]+@';

-- ---------------------------------------------------------------------
-- 7. Contact values and email addresses anywhere in the request log
--
-- A phone number under value, new_value, old_value or contact is seven or
-- more characters of digits, spaces, dots, dashes or brackets ending in
-- four digits when quoted, or seven to fifteen digits when bare. An email
-- address is matched wherever it is; its local part cannot hold a star,
-- so one already masked is not matched again.
-- ---------------------------------------------------------------------
UPDATE `api_logs`
   SET `request_body` = REGEXP_REPLACE(
           `request_body`,
           '(?i)("(?:value|new_value|old_value|contact)"\\s*:\\s*")\\+?[0-9][0-9 ().-]{2,}([0-9]{4})"',
           '\\1******\\2"')
 WHERE `request_body` REGEXP '(?i)"(?:value|new_value|old_value|contact)"\\s*:\\s*"\\+?[0-9][0-9 ().-]{2,}[0-9]{4}"';

UPDATE `api_logs`
   SET `request_body` = REGEXP_REPLACE(
           `request_body`,
           '(?i)("(?:value|new_value|old_value|contact)"\\s*:\\s*)[0-9]{3,11}([0-9]{4})(?=\\s*[,}\\]])',
           '\\1"******\\2"')
 WHERE `request_body` REGEXP '(?i)"(?:value|new_value|old_value|contact)"\\s*:\\s*[0-9]{7,15}\\s*[,}\\]]';

UPDATE `api_logs`
   SET `request_body` = REGEXP_REPLACE(
           `request_body`,
           '(?i)([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9-]+(?:\\.[A-Za-z0-9-]+)*\\.(?!(?:png|jpe?g|gif|webp|svg|pdf|mp4|mov|heic|avif)(?![A-Za-z]))[A-Za-z]{2,})',
           '\\1****@\\2')
 WHERE `request_body` REGEXP '(?i)[A-Za-z0-9._%+-]@[A-Za-z0-9-]+(?:\\.[A-Za-z0-9-]+)*\\.(?!(?:png|jpe?g|gif|webp|svg|pdf|mp4|mov|heic|avif)(?![A-Za-z]))[A-Za-z]{2,}';
