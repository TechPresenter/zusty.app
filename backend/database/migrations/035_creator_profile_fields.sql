-- ---------------------------------------------------------------------
-- 035 — Extended creator profile: the fields the profile brief asks for
--
-- The brief lists thirty-five profile sections. Sixteen of them were already
-- modelled before this file existed. Every claim below was checked against the
-- live schema with SHOW CREATE TABLE, not against the brief, because the brief
-- describes an ideal and the database describes what is actually there.
--
-- ALREADY EXISTS — NO NEW SCHEMA WRITTEN FOR ANY OF THESE:
--
--   profile photo        `users`.`avatar_url`. Every creator read in the code
--                        base already joins it — CreatorService::detail(),
--                        BookingService, the admin creator screens. A second
--                        `creators`.`profile_image_url` would be a competing
--                        copy of the same picture with nothing to keep the two
--                        in step, so none is added.
--   cover photo          `creators`.`cover_image_url`.
--   display name         `creators`.`display_name`.
--   username             `creators`.`handle`, VARCHAR(60) NOT NULL UNIQUE.
--   headline             `creators`.`headline`.
--   bio / about me       `users`.`bio` is the short line; `creators`.`about` is
--                        the long form and is already in the FULLTEXT index
--                        `ft_creators_search`. Two fields, both present.
--   languages            `creators`.`languages` (JSON) AND the `language`
--                        taxonomy, ten terms. Section 2 links the two rather
--                        than adding a third place to hold them.
--   gender               `creators`.`gender`.
--   date of birth        `creators`.`date_of_birth`.
--   country/state/city   `creators`.`country`, `state`, `city`, plus the `city`
--                        taxonomy of thirty serviceable Indian cities.
--   creator category     `creators`.`primary_category_id` -> `categories`.
--   SUB categories       `categories`.`parent_id` already exists and
--                        `creator_categories` is already the many-to-many link
--                        that can point at a child row. The capability is
--                        complete; see the closing note for the one reason no
--                        sub-categories are seeded here.
--   portfolio            `creator_portfolio`, 300 rows.
--   gallery              `creator_portfolio` again — see section 7, which makes
--                        the distinction expressible instead of building a
--                        second table with identical columns.
--   pricing packages     `creator_packages`, 200 rows — see section 8, which
--                        reuses it for custom services for the same reason.
--   testimonials         `testimonials` — see section 9.
--   reviews & ratings    `reviews` (per-booking, moderated, with reply) and the
--                        denormalised `creators`.`rating_avg` / `rating_count`.
--                        Fully modelled; nothing added.
--   availability calendar `creator_availability`, 14,561 rows, UNIQUE on
--                        (creator_id, available_on) with a JSON `slots` column.
--                        That is a per-DATE calendar and it is correct as it
--                        stands. It is not, however, a weekly schedule — see
--                        section 5.
--
-- THE GAPS THIS FILE CLOSES:
--
--   1. Scalars with nowhere to live: introduction video, media kit, resume,
--      street address, PIN code, time zone, years in the profession.
--   2. Skills, interests, professions and service areas are not stored at all,
--      and the brief is explicit that none of them may be hardcoded.
--   3. Education, certifications, awards, work history and past brand
--      collaborations have no storage of any kind.
--   4. Working hours — a WEEKLY recurring schedule — cannot be expressed by a
--      per-date calendar without writing a row for every date forever.
--   5. Per-creator FAQs and custom services have no storage.
--
-- THE SHAPE, AND WHY THERE ARE FOUR NEW TABLES RATHER THAN NINE:
--
--   The brief lists education, certifications, awards, work history and brand
--   collaborations as five sections. They are three shapes, not five:
--
--     * a thing an institution granted you on a date  -> creator_credentials
--     * a thing you did for an organisation over a period -> creator_experience
--
--   A degree, a certificate and an award differ in what they are CALLED, and
--   what a thing is called is a value, not a schema. The same is true of a job
--   and a brand collaboration: organisation, role, dates, description, a link.
--   Splitting them would have meant five tables of near-identical columns and a
--   UNION every time the profile screen asks "show me this creator's history".
--   So each becomes one table with a `kind` column sourced from a taxonomy,
--   which also means the next thing an operator wants to record — a patent, a
--   press feature, an ambassadorship — costs a taxonomy row rather than a
--   table and a release.
--
--   Every repeating-row table here carries the same five columns:
--   creator_id, sort_order, is_visible, created_at, updated_at. Identical
--   shape means one reorder endpoint and one visibility toggle serve all of
--   them. `sort_order` is not optional anywhere: a creator decides which
--   collaboration is shown first, and without it the order is whatever the
--   storage engine felt like.
--
--   Nine new taxonomies are created. None of them is an ENUM, and none of the
--   catalogues is a PHP array, because the single hardest rule on this project
--   is that an operator must be able to add a skill, a profession or a service
--   area without a deploy.
-- ---------------------------------------------------------------------


-- =====================================================================
-- 1. Scalar profile fields on `creators`
-- =====================================================================
--
-- These are on `creators` and not in a side table because there is exactly one
-- of each per creator and every one of them is read on the profile screen. A
-- one-to-one side table would buy nothing but a join on the hottest read.
--
-- The address deliberately does NOT reuse `kyc_creator_details`. That row is a
-- verification record — a snapshot of what was checked, frozen at the moment of
-- the check — and the public profile address is editable and current. Writing
-- the profile through the KYC row would quietly mutate evidence.

ALTER TABLE `creators`
    -- Introduction video. A URL and a poster frame, nothing more: the video
    -- itself lives wherever uploads live, and duration and dimensions are
    -- properties of the file, not facts worth a column that can go stale.
    ADD COLUMN IF NOT EXISTS `intro_video_url` VARCHAR(500) NULL
        COMMENT 'Introduction video shown at the top of the profile'
        AFTER `cover_image_url`,
    ADD COLUMN IF NOT EXISTS `intro_video_thumbnail_url` VARCHAR(500) NULL
        COMMENT 'Poster frame; without it the player shows a black rectangle until it buffers'
        AFTER `intro_video_url`,

    -- Media kit and resume are single documents a creator replaces rather than
    -- accumulates, which is what makes them columns and not rows. A creator who
    -- wants several versions on show puts them in the portfolio.
    ADD COLUMN IF NOT EXISTS `media_kit_url` VARCHAR(500) NULL
        COMMENT 'PDF media kit'
        AFTER `intro_video_thumbnail_url`,
    ADD COLUMN IF NOT EXISTS `resume_url` VARCHAR(500) NULL
        COMMENT 'Resume or CV document'
        AFTER `media_kit_url`,

    -- Street address. Two lines rather than one blob so the city, state and
    -- PIN stay in their own columns and remain searchable.
    ADD COLUMN IF NOT EXISTS `address_line1` VARCHAR(255) NULL
        AFTER `country`,
    ADD COLUMN IF NOT EXISTS `address_line2` VARCHAR(255) NULL
        AFTER `address_line1`,
    ADD COLUMN IF NOT EXISTS `pin_code` VARCHAR(12) NULL
        COMMENT 'Postal code; VARCHAR because a PIN can lead with a zero and is never arithmetic'
        AFTER `address_line2`,

    -- A full street address on a public influencer profile is a safety problem,
    -- so it is private unless the creator says otherwise. Defaulting to 0 is
    -- also the only honest backfill: no existing creator has consented to
    -- publishing an address they have not yet entered.
    ADD COLUMN IF NOT EXISTS `address_is_public` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'City and state are always public; the street lines and PIN are shown only when this is 1'
        AFTER `pin_code`,

    -- Mirrors `users`.`timezone` in type and default. It is repeated on the
    -- creator rather than read from the account because the working hours in
    -- section 5 are wall-clock times that must be interpreted in the zone the
    -- creator WORKS in, which is not necessarily the zone their account was
    -- opened in. Default 'Asia/Kolkata' is true of every row today.
    ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata'
        COMMENT 'IANA zone the working hours and availability are expressed in'
        AFTER `address_is_public`,

    -- Experience. A start YEAR rather than a years-of-experience number,
    -- because a number is wrong from the day after it is entered and nobody
    -- ever goes back to correct it. Nine years of experience is a subtraction.
    ADD COLUMN IF NOT EXISTS `career_start_year` SMALLINT UNSIGNED NULL
        COMMENT 'Year the creator started; years of experience is YEAR(NOW()) minus this'
        AFTER `languages`,

    -- The band, for filtering. Sourced from the EXISTING `experience_level`
    -- taxonomy (new, intermediate, experienced, expert) — no new catalogue is
    -- created for something already served to every client.
    ADD COLUMN IF NOT EXISTS `experience_level` VARCHAR(30) NULL
        COMMENT 'taxonomy_terms.value under the existing experience_level taxonomy'
        AFTER `career_start_year`;

-- Discovery filters on the band and only ever within live profiles.
ALTER TABLE `creators`
    ADD INDEX IF NOT EXISTS `idx_creators_experience` (`experience_level`, `status`);


-- =====================================================================
-- 2. Skills, interests, professions, service areas and languages
--    — one link table, many catalogues
-- =====================================================================
--
-- FIVE SECTIONS, ONE TABLE. `creator_skills`, `creator_interests`,
-- `creator_professions`, `creator_service_areas` and `creator_languages` would
-- have been the same four columns five times over, five sets of indexes, and
-- five near-identical CRUD endpoints. They differ only in WHICH catalogue the
-- chosen row comes from, and that is a value.
--
-- So this is a link from a creator to a `taxonomy_terms` row, and the taxonomy
-- the term belongs to is what makes it a skill rather than an interest. The
-- payoff is that the sixth such section — tools, causes, dietary preferences,
-- whatever the product asks for next — needs a taxonomy and no schema at all.
--
-- This is also what makes discovery possible. Today `creators`.`languages` is a
-- JSON array of names, so "creators who speak Tamil" is a LIKE over a text
-- column that no index can serve. As link rows it is an index seek. The JSON
-- column is left exactly as it is and is backfilled from, not replaced —
-- nothing that reads it today changes behaviour.

CREATE TABLE IF NOT EXISTS `creator_profile_terms` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`   BIGINT UNSIGNED NOT NULL,

  -- Denormalised from `taxonomies`.`key`. It is redundant against
  -- taxonomy_terms -> taxonomies and it is worth it: "this creator's skills, in
  -- order" is then one index range on this table alone instead of a two-table
  -- join executed once per section on every profile render. The application
  -- writes it from the term it was given; it is never user input.
  `taxonomy_key` VARCHAR(60) NOT NULL
      COMMENT 'taxonomies.key of the term below — skill, interest, profession, service_area, language, city',

  `term_id`      INT UNSIGNED NOT NULL,

  -- Which profession heads the profile, which skill leads the card. Exactly the
  -- role `is_primary` plays on `creator_social_accounts`; the same word is used
  -- so an operator does not have to learn a second vocabulary.
  `is_primary`   TINYINT(1) NOT NULL DEFAULT 0,

  -- Present for the same reason as on every other table in this migration, and
  -- it earns its place here: a service area or language kept for MATCHING is
  -- not always something a creator wants listed on the public profile.
  `is_visible`   TINYINT(1) NOT NULL DEFAULT 1,

  `sort_order`   SMALLINT NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- A term is chosen or it is not. Picking the same skill twice is not a state
  -- the product has, so the database refuses it rather than the application
  -- remembering to.
  UNIQUE KEY `uq_cpt_creator_term` (`creator_id`, `term_id`),

  -- The profile render: one creator, one section, in the creator's order.
  KEY `idx_cpt_section` (`creator_id`, `taxonomy_key`, `sort_order`),

  -- The other direction, which is discovery: everyone who has this term.
  -- Covering, so a filter never touches a row.
  KEY `idx_cpt_reverse` (`term_id`, `creator_id`),

  CONSTRAINT `fk_cpt_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE,
  -- Deleting a term removes it from the profiles that chose it. That is right:
  -- a term an operator has retired must stop appearing, and there is nothing to
  -- preserve — the choice is not a record of anything that happened.
  CONSTRAINT `fk_cpt_term` FOREIGN KEY (`term_id`) REFERENCES `taxonomy_terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Professions.
--
-- The brief names profession as a field a creator fills in, and it is tempting
-- to make it a column. It is not one: a creator is routinely a photographer AND
-- a video editor AND a fitness trainer, and the platform that forces a choice
-- gets a worse match. `is_primary` on the link row carries the one that heads
-- the profile, so nothing is lost by allowing several.
-- ---------------------------------------------------------------------

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('profession', 'Professions',
        'What a creator does for a living. Shown on the profile and used for matching. Add a row to support a new one — no release required.',
        0, 1, 1, 210, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `icon`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`icon`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'content_creator' AS `value`, 'Content creator' AS `label`, 'movie' AS `icon`, 10 AS `sort_order` UNION ALL
    SELECT 'influencer',          'Influencer',              'campaign',        20 UNION ALL
    SELECT 'youtuber',            'YouTuber',                'smart_display',   30 UNION ALL
    SELECT 'blogger',             'Blogger',                 'article',         40 UNION ALL
    SELECT 'vlogger',             'Vlogger',                 'videocam',        50 UNION ALL
    SELECT 'podcaster',           'Podcaster',               'podcasts',        60 UNION ALL
    SELECT 'model',               'Model',                   'accessibility',   70 UNION ALL
    SELECT 'actor',               'Actor',                   'theater_comedy',  80 UNION ALL
    SELECT 'anchor',              'Anchor / host',           'mic',             90 UNION ALL
    SELECT 'voice_artist',        'Voice artist',            'record_voice_over', 100 UNION ALL
    SELECT 'radio_jockey',        'Radio jockey',            'radio',          110 UNION ALL
    SELECT 'standup_comedian',    'Stand-up comedian',       'sentiment_very_satisfied', 120 UNION ALL
    SELECT 'dancer',              'Dancer',                  'music_note',     130 UNION ALL
    SELECT 'singer',              'Singer',                  'mic_external_on', 140 UNION ALL
    SELECT 'musician',            'Musician',                'piano',          150 UNION ALL
    SELECT 'photographer',        'Photographer',            'photo_camera',   160 UNION ALL
    SELECT 'videographer',        'Videographer',            'video_camera_back', 170 UNION ALL
    SELECT 'video_editor',        'Video editor',            'movie_edit',     180 UNION ALL
    SELECT 'graphic_designer',    'Graphic designer',        'brush',          190 UNION ALL
    SELECT 'makeup_artist',       'Makeup artist',           'face',           200 UNION ALL
    SELECT 'hair_stylist',        'Hair stylist',            'content_cut',    210 UNION ALL
    SELECT 'fashion_stylist',     'Fashion stylist',         'checkroom',      220 UNION ALL
    SELECT 'chef',                'Chef',                    'restaurant',     230 UNION ALL
    SELECT 'home_baker',          'Home baker',              'cake',           240 UNION ALL
    SELECT 'fitness_trainer',     'Fitness trainer',         'fitness_center', 250 UNION ALL
    SELECT 'yoga_instructor',     'Yoga instructor',         'self_improvement', 260 UNION ALL
    SELECT 'nutritionist',        'Nutritionist / dietitian', 'nutrition',     270 UNION ALL
    SELECT 'doctor',              'Doctor',                  'medical_services', 280 UNION ALL
    SELECT 'chartered_accountant', 'Chartered accountant',   'calculate',      290 UNION ALL
    SELECT 'lawyer',              'Lawyer',                  'gavel',          300 UNION ALL
    SELECT 'teacher',             'Teacher / educator',      'school',         310 UNION ALL
    SELECT 'career_coach',        'Career coach',            'trending_up',    320 UNION ALL
    SELECT 'life_coach',          'Life coach',              'psychology',     330 UNION ALL
    SELECT 'motivational_speaker', 'Motivational speaker',   'campaign',       340 UNION ALL
    SELECT 'entrepreneur',        'Entrepreneur',            'storefront',     350 UNION ALL
    SELECT 'gamer',               'Gamer',                   'sports_esports', 360 UNION ALL
    SELECT 'streamer',            'Live streamer',           'live_tv',        370 UNION ALL
    SELECT 'athlete',             'Athlete',                 'sports_soccer',  380 UNION ALL
    SELECT 'artist',              'Artist / illustrator',    'palette',        390 UNION ALL
    SELECT 'writer',              'Writer',                  'edit_note',      400 UNION ALL
    SELECT 'astrologer',          'Astrologer',              'star',           410 UNION ALL
    SELECT 'interior_designer',   'Interior designer',       'chair',          420
  ) v
 WHERE t.`key` = 'profession'
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`);


-- ---------------------------------------------------------------------
-- Skills.
--
-- Deliberately a different catalogue from `profession`. A profession is who a
-- creator is and there are a few dozen of them; a skill is what a creator can
-- do on a shoot, there are hundreds, and a brand searches on them differently.
-- Folding the two into one list would make both worse: the profession picker
-- would be unusable and the skill filter would be too coarse to be worth
-- offering.
-- ---------------------------------------------------------------------

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('skill', 'Skills',
        'What a creator can do. Multi-select on the profile, filterable in discovery. Operator-extendable.',
        0, 1, 1, 211, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `icon`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`icon`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    -- Content
    SELECT 'reel_making' AS `value`, 'Reel making' AS `label`, 'slow_motion_video' AS `icon`, 10 AS `sort_order` UNION ALL
    SELECT 'short_form_video',    'Short-form video',       'smart_display',   20 UNION ALL
    SELECT 'long_form_video',     'Long-form video',        'movie',           30 UNION ALL
    SELECT 'storytelling',        'Storytelling',           'auto_stories',    40 UNION ALL
    SELECT 'script_writing',      'Script writing',         'edit_note',       50 UNION ALL
    SELECT 'copywriting',         'Copywriting',            'draw',            60 UNION ALL
    SELECT 'content_strategy',    'Content strategy',       'lightbulb',       70 UNION ALL
    SELECT 'trend_spotting',      'Trend spotting',         'trending_up',     80 UNION ALL
    -- Production
    SELECT 'photography',         'Photography',            'photo_camera',    90 UNION ALL
    SELECT 'videography',         'Videography',            'video_camera_back', 100 UNION ALL
    SELECT 'video_editing',       'Video editing',          'movie_edit',     110 UNION ALL
    SELECT 'photo_editing',       'Photo editing',          'tune',           120 UNION ALL
    SELECT 'colour_grading',      'Colour grading',         'gradient',       130 UNION ALL
    SELECT 'sound_design',        'Sound design',           'graphic_eq',     140 UNION ALL
    SELECT 'voice_over',          'Voice-over',             'record_voice_over', 150 UNION ALL
    SELECT 'drone_operation',     'Drone operation',        'flight',         160 UNION ALL
    SELECT 'studio_lighting',     'Studio lighting',        'light_mode',     170 UNION ALL
    -- Design
    SELECT 'graphic_design',      'Graphic design',         'brush',          180 UNION ALL
    SELECT 'motion_graphics',     'Motion graphics',        'animation',      190 UNION ALL
    SELECT 'thumbnail_design',    'Thumbnail design',       'image',          200 UNION ALL
    SELECT 'animation',           'Animation',              'gesture',        210 UNION ALL
    -- On camera
    SELECT 'anchoring',           'Anchoring',              'mic',            220 UNION ALL
    SELECT 'public_speaking',     'Public speaking',        'campaign',       230 UNION ALL
    SELECT 'acting',              'Acting',                 'theater_comedy', 240 UNION ALL
    SELECT 'dancing',             'Dancing',                'music_note',     250 UNION ALL
    SELECT 'singing',             'Singing',                'mic_external_on', 260 UNION ALL
    SELECT 'comedy',              'Comedy',                 'sentiment_very_satisfied', 270 UNION ALL
    SELECT 'product_demo',        'Product demonstration',  'inventory_2',    280 UNION ALL
    SELECT 'unboxing',            'Unboxing',               'package_2',      290 UNION ALL
    -- Craft
    SELECT 'makeup_artistry',     'Makeup artistry',        'face',           300 UNION ALL
    SELECT 'hair_styling',        'Hair styling',           'content_cut',    310 UNION ALL
    SELECT 'fashion_styling',     'Fashion styling',        'checkroom',      320 UNION ALL
    SELECT 'food_styling',        'Food styling',           'restaurant',     330 UNION ALL
    SELECT 'cooking',             'Cooking',                'skillet',        340 UNION ALL
    SELECT 'baking',              'Baking',                 'cake',           350 UNION ALL
    SELECT 'fitness_coaching',    'Fitness coaching',       'fitness_center', 360 UNION ALL
    SELECT 'yoga',                'Yoga',                   'self_improvement', 370 UNION ALL
    -- Business
    SELECT 'brand_collaboration', 'Brand collaboration',    'handshake',      380 UNION ALL
    SELECT 'influencer_marketing', 'Influencer marketing',  'campaign',       390 UNION ALL
    SELECT 'social_media_management', 'Social media management', 'share',     400 UNION ALL
    SELECT 'community_management', 'Community management',  'groups',         410 UNION ALL
    SELECT 'seo',                 'SEO',                    'search',         420 UNION ALL
    SELECT 'affiliate_marketing', 'Affiliate marketing',    'link',           430 UNION ALL
    SELECT 'live_commerce',       'Live commerce',          'live_tv',        440 UNION ALL
    SELECT 'event_hosting',       'Event hosting',          'celebration',    450 UNION ALL
    SELECT 'translation',         'Translation',            'translate',      460 UNION ALL
    SELECT 'subtitling',          'Subtitling',             'closed_caption', 470
  ) v
 WHERE t.`key` = 'skill'
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`);


-- ---------------------------------------------------------------------
-- Interests.
--
-- Not the existing `industry` taxonomy, though the two overlap on a few names,
-- and the distinction is the same one 039 drew between `share_channel` and
-- `social_platform`: they answer different questions and an operator must be
-- able to move them independently. `industry` is a market segment a BRAND
-- belongs to and it drives campaign targeting; an interest is a subject a
-- CREATOR cares about and it drives affinity. Parenting, pets and spirituality
-- are interests that are not industries, and inserting them into `industry`
-- would corrupt the brand-side filters that already read that list.
-- ---------------------------------------------------------------------

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('interest', 'Interests',
        'Subjects a creator cares about, used for affinity matching and shown on the profile. Distinct from the brand-side industry list.',
        0, 1, 1, 212, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `icon`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`icon`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'fashion' AS `value`, 'Fashion' AS `label`, 'checkroom' AS `icon`, 10 AS `sort_order` UNION ALL
    SELECT 'beauty',          'Beauty',              'face',             20 UNION ALL
    SELECT 'skincare',        'Skincare',            'spa',              30 UNION ALL
    SELECT 'fitness',         'Fitness',             'fitness_center',   40 UNION ALL
    SELECT 'wellness',        'Wellness',            'self_improvement', 50 UNION ALL
    SELECT 'food',            'Food',                'restaurant',       60 UNION ALL
    SELECT 'cooking',         'Cooking',             'skillet',          70 UNION ALL
    SELECT 'travel',          'Travel',              'flight',           80 UNION ALL
    SELECT 'technology',      'Technology',          'memory',           90 UNION ALL
    SELECT 'gadgets',         'Gadgets',             'devices',         100 UNION ALL
    SELECT 'gaming',          'Gaming',              'sports_esports',  110 UNION ALL
    SELECT 'music',           'Music',               'music_note',      120 UNION ALL
    SELECT 'dance',           'Dance',               'nightlife',       130 UNION ALL
    SELECT 'films',           'Films & OTT',         'movie',           140 UNION ALL
    SELECT 'books',           'Books',               'auto_stories',    150 UNION ALL
    SELECT 'art',             'Art & craft',         'palette',         160 UNION ALL
    SELECT 'photography',     'Photography',         'photo_camera',    170 UNION ALL
    SELECT 'parenting',       'Parenting',           'child_care',      180 UNION ALL
    SELECT 'pets',            'Pets',                'pets',            190 UNION ALL
    SELECT 'home_decor',      'Home & decor',        'chair',           200 UNION ALL
    SELECT 'gardening',       'Gardening',           'yard',            210 UNION ALL
    SELECT 'finance',         'Personal finance',    'savings',         220 UNION ALL
    SELECT 'education',       'Education',           'school',          230 UNION ALL
    SELECT 'spirituality',    'Spirituality',        'temple_hindu',    240 UNION ALL
    SELECT 'sustainability',  'Sustainability',      'eco',             250 UNION ALL
    SELECT 'automobiles',     'Automobiles',         'directions_car',  260 UNION ALL
    SELECT 'sports',          'Sports',              'sports_soccer',   270 UNION ALL
    SELECT 'comedy',          'Comedy',              'sentiment_very_satisfied', 280
  ) v
 WHERE t.`key` = 'interest'
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`);


-- ---------------------------------------------------------------------
-- Service areas.
--
-- This taxonomy holds COVERAGE, not cities, and that is the whole reason it can
-- exist without duplicating anything. There is already a `city` taxonomy of
-- thirty serviceable Indian cities, and because the link table above is generic
-- a creator's city-level coverage is simply link rows carrying
-- taxonomy_key = 'city'. What the city list could never express is "anywhere in
-- India", "remote only", "South India" or "will travel on location" — none of
-- which are cities, and all of which are the answer most creators actually give.
--
-- So: broad coverage from this list, precise coverage from `city`, both through
-- one table, and no third place where a location can be recorded.
-- ---------------------------------------------------------------------

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('service_area', 'Service areas',
        'Where a creator will work. Broad coverage bands; city-level coverage uses the existing city taxonomy through the same link table.',
        0, 1, 1, 213, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'remote_online' AS `value`, 'Remote / online' AS `label`,
           'Delivers entirely online; no travel involved.' AS `description`, 'wifi' AS `icon`, 10 AS `sort_order` UNION ALL
    SELECT 'home_city_only',  'Home city only',      'Works only in their own city.',                   'home',            20 UNION ALL
    SELECT 'nearby_cities',   'Home city and nearby', 'Home city plus a short drive around it.',        'near_me',         30 UNION ALL
    SELECT 'metro_cities',    'Metro cities',        'The major metros.',                               'location_city',   40 UNION ALL
    SELECT 'tier2_cities',    'Tier-2 cities',       'Will travel to tier-2 cities.',                   'apartment',       50 UNION ALL
    SELECT 'tier3_cities',    'Tier-3 cities & towns', 'Will travel to smaller towns.',                 'holiday_village', 60 UNION ALL
    SELECT 'north_india',     'North India',         NULL,                                              'explore',         70 UNION ALL
    SELECT 'south_india',     'South India',         NULL,                                              'explore',         80 UNION ALL
    SELECT 'east_india',      'East India',          NULL,                                              'explore',         90 UNION ALL
    SELECT 'west_india',      'West India',          NULL,                                              'explore',        100 UNION ALL
    SELECT 'central_india',   'Central India',       NULL,                                              'explore',        110 UNION ALL
    SELECT 'northeast_india', 'North-East India',    NULL,                                              'explore',        120 UNION ALL
    SELECT 'pan_india',       'Pan India',           'Will work anywhere in the country.',              'public',         130 UNION ALL
    SELECT 'on_location_travel', 'Travels on location', 'Will travel for a shoot when travel is covered.', 'luggage',     140 UNION ALL
    SELECT 'gcc_middle_east', 'GCC / Middle East',   NULL,                                              'flight_takeoff', 150 UNION ALL
    SELECT 'southeast_asia',  'South-East Asia',     NULL,                                              'flight_takeoff', 160 UNION ALL
    SELECT 'uk_europe',       'UK & Europe',         NULL,                                              'flight_takeoff', 170 UNION ALL
    SELECT 'usa_canada',      'USA & Canada',        NULL,                                              'flight_takeoff', 180 UNION ALL
    SELECT 'australia_nz',    'Australia & NZ',      NULL,                                              'flight_takeoff', 190 UNION ALL
    SELECT 'international',   'International',       'Holds a passport and will travel abroad.',        'travel_explore', 200
  ) v
 WHERE t.`key` = 'service_area'
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `description` = VALUES(`description`), `icon` = VALUES(`icon`);


-- ---------------------------------------------------------------------
-- Backfill the languages that are already on file.
--
-- `creators`.`languages` holds a JSON array of language NAMES, and the existing
-- `language` taxonomy stores those same names as its term values — so the two
-- can be joined without guessing. This copies the existing choices into the
-- link table; it does not touch, rewrite or empty the JSON column, so every
-- query reading it today behaves exactly as before.
--
-- INSERT IGNORE, so a re-run of this migration adds nothing and fails nothing.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO `creator_profile_terms`
  (`creator_id`, `taxonomy_key`, `term_id`, `is_primary`, `is_visible`, `sort_order`, `created_at`, `updated_at`)
SELECT c.`id`, 'language', tt.`id`, 0, 1, tt.`sort_order`, NOW(), NOW()
  FROM `creators` c
  JOIN `taxonomies` t ON t.`key` = 'language'
  JOIN `taxonomy_terms` tt ON tt.`taxonomy_id` = t.`id`
 WHERE c.`languages` IS NOT NULL
   AND JSON_VALID(c.`languages`)
   AND JSON_CONTAINS(c.`languages`, JSON_QUOTE(tt.`value`));


-- =====================================================================
-- 3. Education, certifications, awards — one table
-- =====================================================================
--
-- A degree, a certificate and an award are the same sentence with a different
-- noun: an institution granted this creator a named thing on a date, and here
-- is the proof. The columns bear that out — every one below is meaningful for
-- all three, and `kind` carries the noun.
--
-- The alternative was `creator_education`, `creator_certifications` and
-- `creator_awards`: three tables, three sets of indexes, three endpoints, and a
-- profile screen that has to UNION them to render a single chronological
-- credentials block. The next kind an operator wants — a patent, a press
-- feature, a scholarship — would have been a fourth table. Here it is a row in
-- a taxonomy.

CREATE TABLE IF NOT EXISTS `creator_credentials` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`     BIGINT UNSIGNED NOT NULL,

  `kind`           VARCHAR(30) NOT NULL DEFAULT 'certification'
      COMMENT 'taxonomy_terms.value under the credential_type taxonomy',

  -- The degree, the certificate, the award.
  `title`          VARCHAR(191) NOT NULL,
  -- The university, the certifying body, the organisation that gave the award.
  `issuer`         VARCHAR(191) NULL,
  `field_of_study` VARCHAR(150) NULL COMMENT 'Education: the subject reads as part of the qualification, not the title',
  `grade`          VARCHAR(60)  NULL COMMENT 'Education: percentage, CGPA or class; free text because every board states it differently',

  -- What makes the claim checkable. A certificate number and the issuer's
  -- verification page are the difference between a credential and an assertion,
  -- which is exactly what an operator needs before ticking `is_verified`.
  `credential_id`  VARCHAR(120) NULL,
  `credential_url` VARCHAR(500) NULL COMMENT 'Issuer-hosted verification link',
  `media_url`      VARCHAR(500) NULL COMMENT 'Uploaded scan or photo of the certificate',

  -- One date pair for all three kinds, because a date range degenerates to a
  -- single date without needing a second shape:
  --   education      started_on = course start,  ended_on = graduation
  --   certification  started_on = issued on,     ended_on = expires on (NULL = never)
  --   award          started_on = received on,   ended_on = NULL
  `started_on`     DATE NULL,
  `ended_on`       DATE NULL,
  `is_current`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Still studying, still held; renders as "Present" rather than an end date',

  `description`    VARCHAR(1000) NULL,

  -- Verification is a fact about this row, and the verifier is recorded so the
  -- tick can be defended later. No foreign key on `verified_by`, matching
  -- `creator_badges`.`awarded_by` in 029: an admin leaving must not blank the
  -- provenance of a check they performed.
  `is_verified`    TINYINT(1) NOT NULL DEFAULT 0,
  `verified_at`    DATETIME NULL,
  `verified_by`    INT UNSIGNED NULL COMMENT 'admins.id, intentionally unconstrained so the trail outlives the account',

  `is_visible`     TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`     SMALLINT NOT NULL DEFAULT 0,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- The profile render, one section at a time, in the creator's order.
  KEY `idx_ccr_section` (`creator_id`, `kind`, `is_visible`, `sort_order`),
  -- The admin queue: certificates awaiting a check, and certificates lapsing.
  KEY `idx_ccr_verification` (`is_verified`, `ended_on`),

  CONSTRAINT `fk_ccr_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('credential_type', 'Credential types',
        'What a row in creator_credentials is — a degree, a certificate, an award. Add a row to record a new kind; no release required.',
        0, 0, 1, 214, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- `metadata` tells the client which of the optional columns to put on the form,
-- so the education fields do not appear when someone is adding an award. That
-- lives in the term rather than a switch statement in the app, for the same
-- reason as everything else here: the next kind must not need a release.
INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`metadata`, v.`is_default`, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'education' AS `value`, 'Education' AS `label`,
           'A degree, diploma or schooling record.' AS `description`,
           'school' AS `icon`, '#1D4ED8' AS `color_hex`,
           '{"fields":["issuer","field_of_study","grade","started_on","ended_on","is_current"],"date_label":"Studied"}' AS `metadata`,
           0 AS `is_default`, 10 AS `sort_order` UNION ALL
    SELECT 'certification', 'Certification', 'A certificate issued by a recognised body.',
           'workspace_premium', '#059669',
           '{"fields":["issuer","credential_id","credential_url","started_on","ended_on"],"date_label":"Issued","expiry":true}', 1, 20 UNION ALL
    SELECT 'award', 'Award & achievement', 'A prize or recognition received.',
           'emoji_events', '#B45309',
           '{"fields":["issuer","started_on","description"],"date_label":"Received"}', 0, 30 UNION ALL
    SELECT 'licence', 'Licence', 'A statutory or professional licence.',
           'badge', '#334155',
           '{"fields":["issuer","credential_id","started_on","ended_on"],"date_label":"Issued","expiry":true}', 0, 40 UNION ALL
    SELECT 'course', 'Course', 'A completed short course or programme.',
           'menu_book', '#7C3AED',
           '{"fields":["issuer","credential_url","started_on","ended_on"],"date_label":"Completed"}', 0, 50 UNION ALL
    SELECT 'workshop', 'Workshop & training', 'Attended training, a workshop or a masterclass.',
           'groups', '#0891B2',
           '{"fields":["issuer","started_on"],"date_label":"Attended"}', 0, 60 UNION ALL
    SELECT 'membership', 'Membership', 'Membership of a guild, association or body.',
           'card_membership', '#475569',
           '{"fields":["issuer","credential_id","started_on","ended_on","is_current"],"date_label":"Member since"}', 0, 70 UNION ALL
    SELECT 'publication', 'Publication', 'An article, paper or book the creator authored.',
           'article', '#0EA5E9',
           '{"fields":["issuer","credential_url","started_on"],"date_label":"Published"}', 0, 80 UNION ALL
    SELECT 'press_feature', 'Press feature', 'Coverage of the creator in the press.',
           'newspaper', '#E4405F',
           '{"fields":["issuer","credential_url","started_on"],"date_label":"Featured"}', 0, 90 UNION ALL
    SELECT 'scholarship', 'Scholarship & grant', 'A scholarship, fellowship or grant received.',
           'volunteer_activism', '#B45309',
           '{"fields":["issuer","started_on","ended_on"],"date_label":"Awarded"}', 0, 100
  ) v
 WHERE t.`key` = 'credential_type'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`),
  `metadata`    = VALUES(`metadata`);


-- =====================================================================
-- 4. Work history and previous brand collaborations — one table
-- =====================================================================
--
-- Same argument as section 3, and the columns make it plainly: a job and a
-- brand collaboration are both "I worked with this organisation, in this role,
-- over this period, and here is the proof". The brand-logo strip a profile
-- shows under "brands I have worked with" and the employment list under "work
-- history" are two RENDERS of one set of rows, filtered by `kind`.
--
-- The column that makes this table worth more than a text field is `booking_id`.
-- A self-declared collaboration is a claim; a collaboration that points at a
-- completed booking on this platform is a fact the platform can vouch for, and
-- the profile can say so. That distinction is impossible if the section is free
-- text, and it is the reason the brief's "previous brand collaborations" is
-- schema rather than a paragraph in the bio.

CREATE TABLE IF NOT EXISTS `creator_experience` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id`       BIGINT UNSIGNED NOT NULL,

  `kind`             VARCHAR(30) NOT NULL DEFAULT 'brand_collaboration'
      COMMENT 'taxonomy_terms.value under the work_type taxonomy',

  -- The brand, the employer, the client.
  `organisation`     VARCHAR(191) NOT NULL,
  `organisation_logo_url` VARCHAR(500) NULL COMMENT 'The logo strip is the point of the collaborations section',
  `organisation_url` VARCHAR(500) NULL,

  `role`             VARCHAR(150) NULL COMMENT 'Brand Ambassador, Content Lead, Campaign Face',
  `location`         VARCHAR(150) NULL,

  `started_on`       DATE NULL,
  `ended_on`         DATE NULL,
  `is_current`       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Ongoing; renders as "Present"',

  `description`      VARCHAR(1000) NULL,
  `reference_url`    VARCHAR(500) NULL COMMENT 'The campaign post, the case study, the coverage',

  -- NULL means the creator typed this in themselves. Non-null means it happened
  -- here and the platform can confirm it. SET NULL rather than CASCADE because
  -- the collaboration remains true even if the booking row is ever removed —
  -- deleting a booking must not silently erase a creator's history.
  `booking_id`       BIGINT UNSIGNED NULL
      COMMENT 'The booking this came from; NULL for self-declared history',

  `is_verified`      TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Set automatically when booking_id is present, or by an admin who checked',

  -- The handful of logos that head the profile, as against the full list.
  `is_featured`      TINYINT(1) NOT NULL DEFAULT 0,

  `is_visible`       TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`       SMALLINT NOT NULL DEFAULT 0,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  KEY `idx_cex_section` (`creator_id`, `kind`, `is_visible`, `sort_order`),
  -- The logo strip: featured rows for one creator, in order.
  KEY `idx_cex_featured` (`creator_id`, `is_featured`, `sort_order`),
  KEY `idx_cex_booking` (`booking_id`),

  CONSTRAINT `fk_cex_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cex_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- `work_type`, not `engagement_type`: `engagement_filter` and `engagement_event`
-- already exist and mean audience engagement and profile events respectively.
-- A third key on the same stem would be read wrong by whoever comes next.
INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('work_type', 'Work types',
        'What a row in creator_experience is — a brand collaboration, a job, a speaking slot. Operator-extendable.',
        0, 0, 1, 215, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- `metadata.section` tells the client which block on the profile a row belongs
-- to, so the same table renders as two sections without the app hardcoding
-- which kinds are "work" and which are "collaborations".
INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`metadata`, v.`is_default`, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'brand_collaboration' AS `value`, 'Brand collaboration' AS `label`,
           'A paid or gifted campaign with a brand.' AS `description`,
           'handshake' AS `icon`, '#1D4ED8' AS `color_hex`,
           '{"section":"collaborations"}' AS `metadata`, 1 AS `is_default`, 10 AS `sort_order` UNION ALL
    SELECT 'brand_ambassadorship', 'Brand ambassadorship', 'An ongoing ambassador relationship with a brand.',
           'workspace_premium', '#B45309', '{"section":"collaborations"}', 0, 20 UNION ALL
    SELECT 'paid_campaign', 'Paid campaign', 'A one-off paid campaign.',
           'campaign', '#0891B2', '{"section":"collaborations"}', 0, 30 UNION ALL
    SELECT 'barter_collaboration', 'Barter collaboration', 'Product or service in exchange for content.',
           'swap_horiz', '#7C3AED', '{"section":"collaborations"}', 0, 40 UNION ALL
    SELECT 'affiliate', 'Affiliate partnership', 'A commission-based partnership.',
           'link', '#0EA5E9', '{"section":"collaborations"}', 0, 50 UNION ALL
    SELECT 'employment', 'Employment', 'A full-time or part-time job.',
           'work', '#334155', '{"section":"work_history"}', 0, 60 UNION ALL
    SELECT 'freelance', 'Freelance', 'Independent client work.',
           'badge', '#475569', '{"section":"work_history"}', 0, 70 UNION ALL
    SELECT 'consulting', 'Consulting', 'Advisory or consulting work.',
           'support_agent', '#059669', '{"section":"work_history"}', 0, 80 UNION ALL
    SELECT 'internship', 'Internship', 'An internship or traineeship.',
           'school', '#64748B', '{"section":"work_history"}', 0, 90 UNION ALL
    SELECT 'founder', 'Founder', 'Founded or co-founded a venture.',
           'rocket_launch', '#E4405F', '{"section":"work_history"}', 0, 100 UNION ALL
    SELECT 'speaking', 'Speaking engagement', 'Spoke at an event or conference.',
           'mic', '#7C3AED', '{"section":"work_history"}', 0, 110 UNION ALL
    SELECT 'judging', 'Judging & mentoring', 'Judged a competition or mentored a programme.',
           'gavel', '#B45309', '{"section":"work_history"}', 0, 120 UNION ALL
    SELECT 'volunteer', 'Volunteer work', 'Unpaid work for a cause.',
           'volunteer_activism', '#059669', '{"section":"work_history"}', 0, 130
  ) v
 WHERE t.`key` = 'work_type'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`),
  `metadata`    = VALUES(`metadata`);


-- =====================================================================
-- 5. Working hours — the weekly schedule `creator_availability` cannot hold
-- =====================================================================
--
-- `creator_availability` was inspected before this table was written. It is
-- keyed UNIQUE (creator_id, available_on) with a DATE column and a JSON `slots`
-- array, and it holds 14,561 rows. It is a per-DATE calendar and it is right for
-- what it does: exceptions, bookings taken, "I am away on the 14th".
--
-- It cannot express "I work Monday to Friday, 10 to 6" without materialising a
-- row per date forever, and a creator who changes their standard hours would
-- then have to rewrite every future row. The two are different statements:
--
--   creator_availability   what is true on ONE date. Overrides.
--   creator_working_hours  what is true EVERY week. The rule.
--
-- A booking screen reads the rule to draw the week and the calendar to punch
-- holes in it. Neither table can be derived from the other, which is precisely
-- why this is not a duplicate.
--
-- Times are wall-clock in `creators`.`timezone` and are deliberately NOT stored
-- in UTC. "I work from 10am" survives a DST change and a move; the same instant
-- expressed in UTC does not, and would silently shift a creator's advertised
-- hours by an hour twice a year for every brand viewing from another zone.

CREATE TABLE IF NOT EXISTS `creator_working_hours` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id` BIGINT UNSIGNED NOT NULL,

  -- ISO-8601 numbering, 1 = Monday through 7 = Sunday. A TINYINT and not a
  -- taxonomy: every other catalogue in this file is a list an operator may
  -- extend, and the week is the one list that will never grow.
  `weekday`    TINYINT UNSIGNED NOT NULL COMMENT '1=Monday .. 7=Sunday (ISO-8601)',

  -- A day off is a row with is_working = 0, rather than the absence of a row.
  -- "Closed on Sunday" and "has not filled in Sunday yet" are different answers
  -- and a missing row cannot tell them apart.
  `is_working` TINYINT(1) NOT NULL DEFAULT 1,

  -- One row per continuous window, so a creator who works mornings and evenings
  -- has two rows for that day. A single from/to pair per day cannot express a
  -- lunch break, and a JSON array of slots cannot be indexed or validated.
  `starts_at`  TIME NOT NULL DEFAULT '10:00:00',
  `ends_at`    TIME NOT NULL DEFAULT '18:00:00',

  `note`       VARCHAR(255) NULL COMMENT 'e.g. "Shoots only", "Calls only"',

  `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- Two windows on the same day may not start at the same minute. This is what
  -- makes a re-submitted schedule idempotent instead of doubling.
  UNIQUE KEY `uq_cwh_creator_slot` (`creator_id`, `weekday`, `starts_at`),
  KEY `idx_cwh_week` (`creator_id`, `weekday`, `is_working`),

  CONSTRAINT `fk_cwh_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 6. Per-creator FAQs
-- =====================================================================
--
-- The `faqs` table already exists and is NOT reused, which is the one place in
-- this migration where a near-duplicate shape is accepted on purpose.
--
-- `faqs` is a CMS resource: it has an admin screen, a revision history entry in
-- RevisionService, a `cms.faqs` permission, an `audience` column and a
-- `category` ENUM of help-centre topics. Both of its read paths —
-- ContentController::faqs() and AppContentService — select `WHERE is_active = 1`
-- with no owner predicate. Bolting a nullable `creator_id` onto it would mean
-- every creator's FAQs appearing in the public help centre from the moment they
-- were written, and would put creator-authored text into a moderated CMS
-- surface with an admin editor pointed at it.
--
-- The two also differ in what they are: a help-centre FAQ is written by the
-- platform for everyone, a profile FAQ is written by one creator about their own
-- work. They are separate tables because they have separate owners.

CREATE TABLE IF NOT EXISTS `creator_faqs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `creator_id` BIGINT UNSIGNED NOT NULL,
  `question`   VARCHAR(300) NOT NULL,
  -- Bounded rather than TEXT. 2000 characters matches `reviews`.`comment` and
  -- `testimonials`.`content`, the house bound for long user-entered prose, and
  -- an answer that needs more than that belongs in the about section.
  `answer`     VARCHAR(2000) NOT NULL,

  `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_cfaq_creator` (`creator_id`, `is_visible`, `sort_order`),

  CONSTRAINT `fk_cfaq_creator` FOREIGN KEY (`creator_id`) REFERENCES `creators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 7. Gallery — `creator_portfolio` already is one
-- =====================================================================
--
-- NO GALLERY TABLE IS CREATED. `creator_portfolio` was inspected first, as the
-- brief asked. It already holds media_url, thumbnail_url, external_url, caption,
-- aspect_ratio, duration_secs, view and like counts, is_featured, is_active,
-- sort_order and a soft delete, across 300 live rows. A `creator_gallery` table
-- would have repeated every one of those columns and split "show me this
-- creator's media" into a UNION for the rest of the project's life.
--
-- What the table could not do was distinguish a gallery from a portfolio, which
-- the product does distinguish — CreatorProfileLockService already ships
-- separate 'portfolio' and 'gallery' lockable sections. That difference is
-- WHICH COLLECTION an item belongs to, and a collection is a value. So it costs
-- one column, not one table.

ALTER TABLE `creator_portfolio`
    ADD COLUMN IF NOT EXISTS `collection` VARCHAR(30) NOT NULL DEFAULT 'portfolio'
        COMMENT 'taxonomy_terms.value under the portfolio_collection taxonomy'
        AFTER `media_kind`;

-- DEFAULT 'portfolio' is the correct and complete backfill: every one of the
-- 300 existing rows was uploaded as portfolio work, because until this column
-- existed there was nothing else it could have been.
ALTER TABLE `creator_portfolio`
    ADD INDEX IF NOT EXISTS `idx_portfolio_collection` (`creator_id`, `collection`, `is_active`, `sort_order`);

-- ---------------------------------------------------------------------
-- The two ENUMs on this table, for the same reason 029 replaced the social
-- platform ENUM and 039 replaced the profile-view source ENUM.
--
-- `media_kind` was enum('post','reel','photo','video') while the
-- `portfolio_kind` taxonomy holds those same four values and is already served
-- to every client — a catalogue duplicated in two places, where the copy the
-- database enforces is the one an operator cannot reach. Adding a carousel, a
-- PDF case study or an audio clip meant a migration.
--
-- `source_platform` was enum('instagram','youtube','tiktok','upload','other'),
-- so a clip brought over from Moj, Josh or ShareChat — all seeded into
-- `social_platform` by 029 — could not record where it came from. Under
-- STRICT_TRANS_TABLES that is a hard error on insert, not a silent coercion.
--
-- ENUM -> VARCHAR keeps every stored string exactly as it is. All 300 rows are
-- accounted for: media_kind holds post/reel/photo/video and source_platform
-- holds instagram/youtube/upload, and every one of those values is already a
-- term in the taxonomy that now drives the column.
-- ---------------------------------------------------------------------

ALTER TABLE `creator_portfolio`
    MODIFY COLUMN `media_kind` VARCHAR(40) NOT NULL
        COMMENT 'taxonomy_terms.value under the portfolio_kind taxonomy',
    MODIFY COLUMN `source_platform` VARCHAR(40) NOT NULL DEFAULT 'upload'
        COMMENT 'taxonomy_terms.value under the social_platform taxonomy';

-- The kinds the ENUM could not hold, added to the taxonomy that now owns the
-- list. The original four are untouched and are not restated.
INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `icon`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`icon`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'carousel' AS `value`, 'Carousel' AS `label`, 'view_carousel' AS `icon`, 50 AS `sort_order` UNION ALL
    SELECT 'short',      'Short',          'smart_display',  60 UNION ALL
    SELECT 'live',       'Live recording',  'live_tv',       70 UNION ALL
    SELECT 'audio',      'Audio',          'graphic_eq',     80 UNION ALL
    SELECT 'document',   'Document / PDF', 'picture_as_pdf', 90 UNION ALL
    SELECT 'case_study', 'Case study',     'description',   100
  ) v
 WHERE t.`key` = 'portfolio_kind'
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`);


-- `upload` is the DEFAULT of creator_portfolio.source_platform and is written
-- unconditionally by CreatorController::addPortfolioItem when no external URL
-- is given — 40 of the 300 live rows carry it. Once that column is driven by
-- this taxonomy, a picker built from the terms would neither offer nor accept
-- the column's own default. Seeding it keeps the catalogue honest about a
-- value the application has always written.
INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, 'upload', 'Uploaded directly', 'upload', '#64748B',
       '{"url":"{handle}","metrics":false}', 0, 1, 170, NOW(), NOW()
  FROM `taxonomies` t
 WHERE t.`key` = 'social_platform'
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`);


-- The language backfill below joins `creators`.`languages` against the
-- `language` taxonomy. Three names that creators actually have on file —
-- Urdu (3 creators), Konkani (2) and Rajasthani (1) — are not terms, so
-- without these six rows the join drops them and those languages become
-- permanently unsearchable through the link table this migration builds.
INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'Urdu' AS `value`, 'Urdu' AS `label`, 110 AS `sort_order` UNION ALL
    SELECT 'Konkani',    'Konkani',    120 UNION ALL
    SELECT 'Rajasthani', 'Rajasthani', 130
  ) v
 WHERE t.`key` = 'language'
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);


INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('portfolio_collection', 'Portfolio collections',
        'Which block of the profile a media item appears in. Portfolio and gallery are two collections of one table, not two tables.',
        0, 0, 1, 218, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`is_default`, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'portfolio' AS `value`, 'Portfolio' AS `label`,
           'Commissioned and branded work the creator is selling on.' AS `description`,
           'work' AS `icon`, '#1D4ED8' AS `color_hex`, 1 AS `is_default`, 10 AS `sort_order` UNION ALL
    SELECT 'gallery', 'Gallery', 'Personal photos and clips that show range and personality.',
           'photo_library', '#7C3AED', 0, 20 UNION ALL
    SELECT 'showreel', 'Showreel', 'The short highlight cut a brand watches first.',
           'movie', '#B45309', 0, 30 UNION ALL
    SELECT 'behind_the_scenes', 'Behind the scenes', 'Process and set footage.',
           'videocam', '#0891B2', 0, 40 UNION ALL
    SELECT 'press', 'Press & features', 'Coverage of the creator elsewhere.',
           'newspaper', '#E4405F', 0, 50
  ) v
 WHERE t.`key` = 'portfolio_collection'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`);


-- =====================================================================
-- 8. Custom services — `creator_packages` already is the offerings table
-- =====================================================================
--
-- NO CUSTOM SERVICES TABLE IS CREATED. `creator_packages` holds title,
-- description, quantity, price, currency, turnaround_days, revisions, an
-- `includes` list, is_active, sort_order and a soft delete across 200 rows —
-- which is every column a custom service needs. A `creator_services` table would
-- have been that list again, and the profile's pricing block would have had to
-- merge two sources and sort them together.
--
-- Three things stopped a custom service fitting, and all three are the same
-- fault: a mandatory ENUM.
--
--   `platform` enum(...) NOT NULL         — brand consulting is on no platform.
--   `deliverable_type` enum(...) NOT NULL — an event appearance is no deliverable.
--   there was no way to say "per hour", "per day" or "on request"; every row was
--   implicitly a fixed price for a fixed quantity.
--
-- Both ENUMs become VARCHARs driven by taxonomies that already exist — the
-- `platform` list and the `deliverable_type` list, both already served through
-- /app/taxonomies — and both become nullable, because "not tied to a platform"
-- is a real answer and NULL is how a relational database says it. Every stored
-- value survives verbatim: platform holds instagram/youtube/multi and
-- deliverable_type holds reel/story/video/live/ugc/bundle, and all nine are
-- already terms in those taxonomies. Relaxing NOT NULL cannot invalidate an
-- existing row.

ALTER TABLE `creator_packages`
    MODIFY COLUMN `platform` VARCHAR(40) NULL
        COMMENT 'taxonomy_terms.value under the platform taxonomy; NULL = not tied to a platform',
    MODIFY COLUMN `deliverable_type` VARCHAR(40) NULL
        COMMENT 'taxonomy_terms.value under the deliverable_type taxonomy; NULL = bespoke service';

ALTER TABLE `creator_packages`
    -- Which block of the pricing section this row renders in. DEFAULT 'package'
    -- is the truth about all 200 existing rows.
    ADD COLUMN IF NOT EXISTS `offering_type` VARCHAR(30) NOT NULL DEFAULT 'package'
        COMMENT 'taxonomy_terms.value under the offering_type taxonomy'
        AFTER `description`,

    -- How the price is to be read. Without it, 5000 on an "event appearance"
    -- row is unreadable: per hour, per day, or for the whole thing.
    ADD COLUMN IF NOT EXISTS `pricing_model` VARCHAR(30) NOT NULL DEFAULT 'fixed'
        COMMENT 'taxonomy_terms.value under the pricing_model taxonomy'
        AFTER `price`,

    -- A custom service is usually quoted as a range. `price` stays the lower
    -- bound so every existing sort, filter and "from ₹X" label keeps working
    -- untouched; this is the optional upper bound.
    ADD COLUMN IF NOT EXISTS `price_max` DECIMAL(12,2) NULL
        COMMENT 'Upper bound of a quoted range; NULL when the price is exact'
        AFTER `pricing_model`;

ALTER TABLE `creator_packages`
    ADD INDEX IF NOT EXISTS `idx_cp_offering` (`creator_id`, `offering_type`, `is_active`, `sort_order`);


INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('offering_type', 'Offering types',
        'Whether a creator_packages row is a standard package, a bespoke service or an add-on. Drives which block of the pricing section it renders in.',
        0, 0, 1, 216, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`is_default`, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'package' AS `value`, 'Package' AS `label`,
           'A standard, priced content package on a platform.' AS `description`,
           'inventory_2' AS `icon`, '#1D4ED8' AS `color_hex`, 1 AS `is_default`, 10 AS `sort_order` UNION ALL
    SELECT 'custom_service', 'Custom service', 'A bespoke offering that is not a platform deliverable.',
           'design_services', '#7C3AED', 0, 20 UNION ALL
    SELECT 'add_on', 'Add-on', 'Bought alongside another package — usage rights, extra revisions, a story reshare.',
           'add_circle', '#059669', 0, 30 UNION ALL
    SELECT 'consultation', 'Consultation', 'Paid advice or a strategy call.',
           'support_agent', '#0891B2', 0, 40
  ) v
 WHERE t.`key` = 'offering_type'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`);


-- `metadata.suffix` is what the client prints after the amount, so the app never
-- holds a map of model to label. `metadata.amount` = false marks the models
-- where the number is not to be shown at all.
INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('pricing_model', 'Pricing models',
        'How the price on an offering is to be read — fixed, hourly, retainer, on request. Operator-extendable.',
        0, 0, 1, 217, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `icon`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`icon`, v.`metadata`, v.`is_default`, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'fixed' AS `value`, 'Fixed price' AS `label`, 'sell' AS `icon`,
           '{"suffix":null,"amount":true}' AS `metadata`, 1 AS `is_default`, 10 AS `sort_order` UNION ALL
    SELECT 'per_deliverable', 'Per deliverable', 'inventory_2', '{"suffix":"per deliverable","amount":true}', 0, 20 UNION ALL
    SELECT 'hourly',          'Per hour',        'schedule',    '{"suffix":"per hour","amount":true}',        0, 30 UNION ALL
    SELECT 'daily',           'Per day',         'today',       '{"suffix":"per day","amount":true}',         0, 40 UNION ALL
    SELECT 'per_event',       'Per event',       'celebration', '{"suffix":"per event","amount":true}',       0, 50 UNION ALL
    SELECT 'monthly_retainer', 'Monthly retainer', 'event_repeat', '{"suffix":"per month","amount":true}',    0, 60 UNION ALL
    SELECT 'package_bundle',  'Bundle',          'category',    '{"suffix":"for the bundle","amount":true}',  0, 70 UNION ALL
    SELECT 'revenue_share',   'Revenue share',   'percent',     '{"suffix":"revenue share","amount":false}',  0, 80 UNION ALL
    SELECT 'barter',          'Barter',          'swap_horiz',  '{"suffix":"barter","amount":false}',         0, 90 UNION ALL
    SELECT 'negotiable',      'On request',      'chat',        '{"suffix":"on request","amount":false}',     0, 100
  ) v
 WHERE t.`key` = 'pricing_model'
ON DUPLICATE KEY UPDATE
  `label`    = VALUES(`label`),
  `icon`     = VALUES(`icon`),
  `metadata` = VALUES(`metadata`);


-- =====================================================================
-- 9. Testimonials — the existing table, given an owner
-- =====================================================================
--
-- `testimonials` was inspected and it is already the right shape to the column:
-- author_name, author_role, company, avatar_url, content, rating, is_featured,
-- is_active, sort_order, and a `source` of 'manual' or 'review' with a
-- `review_id` for the case where a testimonial was promoted from a real review.
-- A `creator_testimonials` table would have been a transcription of it.
--
-- The one thing it lacks is an owner. All eight rows in it today are marketing
-- copy for the site itself, and that stays the meaning of creator_id IS NULL —
-- so no existing row changes and no data is rewritten.
--
-- CASCADE and not SET NULL: a deleted creator's testimonials must not fall back
-- into the site-wide pool and start appearing on the home page.

ALTER TABLE `testimonials`
    ADD COLUMN IF NOT EXISTS `creator_id` BIGINT UNSIGNED NULL
        COMMENT 'Owning creator; NULL means a site-wide marketing testimonial, which is what all existing rows are'
        AFTER `id`,
    ADD INDEX IF NOT EXISTS `idx_testimonials_creator` (`creator_id`, `is_active`, `sort_order`);

ALTER TABLE `testimonials`
    ADD CONSTRAINT `fk_testimonials_creator` FOREIGN KEY IF NOT EXISTS (`creator_id`)
        REFERENCES `creators` (`id`) ON DELETE CASCADE;


-- ---------------------------------------------------------------------
-- NOT BUILT, ON PURPOSE — and the one thing left for the application layer.
--
-- SUB-CATEGORIES. No taxonomy and no table. `categories` already has
-- `parent_id` with `fk_categories_parent`, and `creator_categories` is already
-- the many-to-many that can point at a child row. A `sub_category` taxonomy
-- would have forked the category tree into two systems — one with an admin
-- screen, icons, cover images and a denormalised creator_count, and one
-- without — and creators would then belong to categories from both.
--
-- No sub-categories are SEEDED either, and that is a deliberate omission rather
-- than an oversight. All sixteen categories are currently top-level, and not one
-- of the queries that lists them filters on `parent_id` — ContentController,
-- AppContentService, SearchService, SitemapService and the admin pickers all
-- select every active row. Inserting children today would flatten them into the
-- home carousel, the category grid, the search facets and the sitemap. The
-- storage is ready; the listings need `parent_id IS NULL` before they are
-- populated, and that is a code change, not a migration.
--
-- PROFILE COMPLETENESS. `creators`.`profile_score` already exists for it. This
-- migration adds fields it should now count, but the scoring rules belong in
-- the service that computes it, not in the schema.
-- ---------------------------------------------------------------------
