-- ---------------------------------------------------------------------
-- 060 - Copy polish before the Play release.
--
-- Copy a user reads that was cut off on a phone, said "safely", promised
-- what the code does not do, or still carried the escrow wording that
-- 059 took out of the templates:
--
--   1. Home banners. The app shows a banner subtitle on two lines, about
--      33 characters a line on a 360 dp phone, and ends anything longer
--      in "...". Banner 1 (80 characters) lost its last word; banner 3
--      (78) is as long, and banner 2 (70) fits a 411 dp phone but not a
--      360 dp one. All three are now under 60.
--   2. Popups shown inline (type banner or announcement_bar): two lines
--      of 11 px text beside the call to action, about 50 characters on a
--      phone. 1518 (brands, Home) was cut after "...apply with their
--      rate"; 1519 (guests) was cut too, and promised saved searches and
--      rate history, which the app does not have. Both are demo rows
--      (seed demo/026); a live install without demo data has neither.
--   3. Onboarding slide 3: "get paid safely" becomes "get paid on
--      approval", and its 105-character subtitle, at risk on the slide's
--      three lines, is shortened to the same payment wording.
--   4. Home sections. "Top Creators For You / Handpicked for your brand"
--      is shown to every audience and lists creators by rating
--      (CreatorService sort 'rating'): nobody picks them, for a brand or
--      anyone else. "Trending Now / Most booked this week" lists them by
--      their self-reported follower total (sort 'popular'), not by
--      bookings. Both now say what the list is.
--   5. Privacy policy, section 2: the wallet also records the corrections
--      our team makes (an admin wallet adjustment, ledger category
--      'adjustment'). The page's "Last updated" line moves to the day
--      the clause was written, and its version is bumped once.
--   6. Notifications stored before 059 changed the templates still say
--      escrow. The three templated sentences are replaced with the
--      templates' current wording (059, seeds 001, 029 and 030); a
--      notification's other text, and email_queue and push_queue, are
--      history and are not touched.
--
-- Each statement matches the exact old value only, so an operator's own
-- wording is kept, and none of the new wording matches again: a second
-- run changes nothing and bumps no version. A rename that would collide
-- with a row an operator already made under the new name is skipped
-- (both tables have a unique key on the title). On a fresh install the
-- tables are empty when this runs and the seeds (002, 003, 009, demo/026)
-- carry the same copy. Valid on MariaDB 10.4 and 11.8.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Home banners
-- ---------------------------------------------------------------------
UPDATE `banners`
   SET `subtitle` = 'Verified creators, clear pricing and payment protection.'
 WHERE `subtitle` = 'Verified creators, transparent pricing, and payment protection on every booking.';

UPDATE `banners`
   SET `subtitle` = 'Set your packages, get booked, get paid on approval.'
 WHERE `subtitle` = 'Set your packages, get booked, and get paid when the work is approved.';

UPDATE `banners`
   SET `subtitle` = 'Earn a reward once an invited friend completes a booking.'
 WHERE `subtitle` = 'Share your code and earn a reward when a friend completes their first booking.';

-- ---------------------------------------------------------------------
-- 2. Inline popups (demo rows)
-- ---------------------------------------------------------------------
UPDATE `popups`
   SET `content` = 'Creators apply with their rate. Pick who to hire.'
 WHERE `content` = 'Publish one campaign brief and matching creators apply with their rate for your exact deliverable. Shortlist, compare and send offers without leaving the screen.';

UPDATE `popups`
   SET `content` = 'Save creators to a list and compare them side by side.'
 WHERE `content` = 'Shortlists, saved searches and rate history stay with your account. Signing up takes an email address and about thirty seconds.';

-- ---------------------------------------------------------------------
-- 3. Onboarding slide 3
-- ---------------------------------------------------------------------
SET @z060_slide_taken := (SELECT COUNT(*) FROM `onboarding_slides`
                           WHERE `title` = 'Collaborate and get paid on approval.' AND `audience` = 'all');

UPDATE `onboarding_slides`
   SET `title` = 'Collaborate and get paid on approval.'
 WHERE `title` = 'Collaborate and get paid safely.'
   AND `audience` = 'all'
   AND @z060_slide_taken = 0;

UPDATE `onboarding_slides`
   SET `subtitle` = 'Briefs, offers and chat in one place. Zusty holds the payment until the work is approved.'
 WHERE `subtitle` = 'Briefs, offers and payment in one place. The brand’s payment is held by Zusty until the work is approved.';

-- ---------------------------------------------------------------------
-- 4. Home sections
--
-- The title is the unique key with section_type (seed 003 upserts on
-- it), so a title is renamed only while it is the seeded one. The
-- subtitle is changed only where the list is still built the way the new
-- subtitle describes.
-- ---------------------------------------------------------------------
SET @z060_top_taken := (SELECT COUNT(*) FROM `homepage_sections`
                         WHERE `title` = 'Top-rated creators' AND `section_type` = 'creator_grid');

UPDATE `homepage_sections`
   SET `title` = 'Top-rated creators'
 WHERE `title` = 'Top Creators For You'
   AND `section_type` = 'creator_grid'
   AND @z060_top_taken = 0;

UPDATE `homepage_sections`
   SET `subtitle` = 'Highest rated first'
 WHERE `subtitle` = 'Handpicked for your brand'
   AND `data_source` = 'top_rated';

SET @z060_trending_taken := (SELECT COUNT(*) FROM `homepage_sections`
                              WHERE `title` = 'Largest audiences' AND `section_type` = 'creator_carousel');

UPDATE `homepage_sections`
   SET `title` = 'Largest audiences'
 WHERE `title` = 'Trending Now'
   AND `section_type` = 'creator_carousel'
   AND @z060_trending_taken = 0;

UPDATE `homepage_sections`
   SET `subtitle` = 'Ranked by self-reported followers'
 WHERE `subtitle` = 'Most booked this week'
   AND `data_source` = 'trending';

-- ---------------------------------------------------------------------
-- 5. Privacy policy: what the wallet holds
-- ---------------------------------------------------------------------
UPDATE `pages`
   SET `content` = REPLACE(`content`,
           'The wallet holds a creator’s earnings, any refunds credited to it, and referral rewards:',
           'The wallet holds a creator’s earnings, any refunds credited to it, any corrections our team makes to fix an error, and referral rewards:'),
       `subtitle` = IF(`subtitle` = 'Last updated: 10 October 2026', 'Last updated: 11 October 2026', `subtitle`),
       `version`      = `version` + 1,
       `published_at` = NOW()
 WHERE `slug` = 'privacy'
   AND `content` LIKE BINARY '%The wallet holds a creator’s earnings, any refunds credited to it, and referral rewards:%';

-- ---------------------------------------------------------------------
-- 6. Notifications stored with the old template wording
--
-- The type is the template code (NotificationService::send). LEFT keeps
-- a body that was already at the column's 1,000 characters within it.
-- ---------------------------------------------------------------------
UPDATE `notifications`
   SET `body` = LEFT(REPLACE(`body`,
           'Your funds are held safely in escrow.',
           'Zusty holds it until you approve the work.'), 1000)
 WHERE `type` = 'payment.success'
   AND `body` LIKE BINARY '%Your funds are held safely in escrow.%';

UPDATE `notifications`
   SET `body` = LEFT(REPLACE(`body`,
           'The brand will raise a booking so the payment can be held in escrow.',
           'The brand will raise a booking, and Zusty holds its payment until the work is approved.'), 1000)
 WHERE `type` = 'campaign.hired'
   AND `body` LIKE BINARY '%The brand will raise a booking so the payment can be held in escrow.%';

UPDATE `notifications`
   SET `body` = LEFT(REPLACE(`body`,
           'The payment stays in escrow until our team has reviewed it.',
           'Zusty keeps holding the payment until our team has reviewed it.'), 1000)
 WHERE `type` = 'booking.disputed'
   AND `body` LIKE BINARY '%The payment stays in escrow until our team has reviewed it.%';
