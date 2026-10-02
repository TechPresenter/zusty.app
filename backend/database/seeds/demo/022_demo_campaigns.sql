-- =====================================================================
-- 022_demo_campaigns.sql   (Zusty demo seed)
-- =====================================================================
-- Seeds brand campaign briefs and the creator-invitation e-mail blasts
-- that go with them.
--
-- ID RANGES OWNED BY THIS FILE
--   campaigns            301 - 330   (30 brand briefs, brands 1001-1020)
--   marketing_campaigns  301 - 330   (1 invite blast per brand campaign)
--   campaign_recipients  30001 - 30xxx (invited creators, users 2001-2045)
--
-- SCHEMA NOTES - please read before editing.
--
-- 1. `campaign_recipients` is NOT a creator-application table. Its FK is
--    fk_camprec_campaign -> marketing_campaigns(id), and its status ENUM is
--    ('pending','sent','failed','opened','clicked','bounced','unsubscribed').
--    It is the delivery log for outbound e-mail/SMS blasts. This file
--    therefore models "creator invited to a campaign" as an invite blast
--    (one marketing_campaigns row per brand campaign) plus one recipient
--    row per invited creator, mapped as:
--        pending      -> queued, invite not sent yet (draft campaigns)
--        sent         -> invited, not opened
--        opened       -> invite viewed
--        clicked      -> viewed and clicked through to apply
--        bounced      -> mailbox rejected the invite
--        unsubscribed -> creator opted out of brand invites
--        failed       -> transport error (error_message populated)
--    Creator applications / shortlisting / acceptance live in `bookings`
--    and `booking_offers` (bookings.campaign_id -> campaigns.id), which is
--    a different seed file's territory.
--
-- 2. `campaigns` has no cover_image, published_at, application_deadline,
--    cities or deliverables columns. Those all live inside the
--    `requirements` JSON column (which carries a json_valid CHECK), and
--    moodboard imagery lives in `reference_urls` JSON.
--
-- 3. campaigns.status ENUM is ('draft','open','in_progress','completed',
--    'cancelled','archived') - there is no 'published'/'active'/'closed'.
--    Distribution here: 11 open, 8 in_progress, 4 completed, 4 draft,
--    2 cancelled, 1 archived. All 11 open campaigns are is_public = 1 with
--    a future application_deadline and a future ends_on, so the app's
--    "Featured Campaigns" rail always has live content.
--
-- Idempotent: safe to re-run. Requires users 1001-1020 (brands),
-- users 2001-2045 (creators), categories 1-16 and admins.id = 1.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. campaigns 301-330
-- ---------------------------------------------------------------------

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (301,
   'ZC100301',
   1001,
   'Monsoon Glow Skincare Routine - Reel Series',
   'We are looking for beauty and skincare creators to build a two-part Reel series around a monsoon-ready glow routine. Reel 1 walks through the morning routine (cleanser, serum, gel moisturiser, SPF) shot in natural light. Reel 2 is a 7-day before/after check-in with honest skin feedback. Product hamper worth Rs 4,500 is shipped in advance. Please keep the tone conversational, avoid over-filtered footage, and disclose the partnership with #ad in the first line of the caption. Raw footage must be shared with the brand for whitelisting.',
   7,
   'instagram',
   'reel',
   2,
   28000.00,
   65000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100301-monsoon-glow-skincare.pdf',
   '["https://picsum.photos/seed/zc-monsoon-glow-skincare-mood-1/1080/1350","https://picsum.photos/seed/zc-monsoon-glow-skincare-mood-2/1080/1350","https://picsum.photos/seed/zc-monsoon-glow-skincare-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100301-monsoon-glow-skincare.pdf"]',
   CONCAT('{"min_followers":25000,"max_followers":200000,"min_engagement_rate":2.8,"cities":["Mumbai","Pune","Bengaluru","Delhi"],"categories":[7,1],"gender":"female","age_range":"21-34","languages":["Hindi","English"],"deliverables":["1 Instagram Reel (30-45s) - routine walkthrough","1 Instagram Reel (20-30s) - 7 day check-in","3 Stories with product link sticker","2 raw video files for whitelisting"],"cover_image":"https://picsum.photos/seed/zc-monsoon-glow-skincare-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-monsoon-glow-skincare-cover/600/400","usage_rights_days":90,"exclusivity_days":30,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 18 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 381 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 22 DAY),
   DATE_ADD(CURDATE(), INTERVAL 62 DAY),
   24,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 16 DAY),
   DATE_SUB(NOW(), INTERVAL 5 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (302,
   'ZC100302',
   1002,
   'Festive Ethnic Edit - Styling Reels and Stories',
   'Festive season styling collaboration for our new handloom-blend ethnic line. We want three distinct looks styled by the creator - one daytime pooja look, one sangeet look and one office-festive look. Deliverables are one carousel post (5-7 frames), one transition Reel across the three looks, and a story set with the shopping link. Outfits are gifted and retained. Shoot must be vertical 9:16, minimum 1080p. We will supply a shot list and the campaign hashtag. Turnaround is 12 days from delivery of the parcel.',
   2,
   'instagram',
   'bundle',
   3,
   45000.00,
   120000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100302-festive-ethnic-edit.pdf',
   '["https://picsum.photos/seed/zc-festive-ethnic-edit-mood-1/1080/1350","https://picsum.photos/seed/zc-festive-ethnic-edit-mood-2/1080/1350","https://picsum.photos/seed/zc-festive-ethnic-edit-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100302-festive-ethnic-edit.pdf"]',
   CONCAT('{"min_followers":50000,"max_followers":400000,"min_engagement_rate":2.2,"cities":["Mumbai","Delhi","Jaipur","Ahmedabad","Lucknow"],"categories":[2,1],"gender":"female","age_range":"22-38","languages":["Hindi","English"],"deliverables":["1 Instagram carousel post (5-7 frames)","1 transition Reel (30s)","5 Stories with product tags","Usage rights for paid amplification"],"cover_image":"https://picsum.photos/seed/zc-festive-ethnic-edit-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-festive-ethnic-edit-cover/600/400","usage_rights_days":120,"exclusivity_days":45,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 13 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 261 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 16 DAY),
   DATE_ADD(CURDATE(), INTERVAL 58 DAY),
   38,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 11 DAY),
   DATE_SUB(NOW(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (303,
   'ZC100303',
   1003,
   'Cloud Kitchen Launch - Bengaluru Food Reels',
   'Our new North Indian cloud kitchen goes live across HSR, Koramangala and Indiranagar. We need Bengaluru food creators to order (fully reimbursed, up to Rs 1,200) and shoot an honest tasting Reel. Please film the unboxing, the first bite and a genuine verdict - we do not want scripted praise. Mention the 40 percent launch discount code and tag the delivery partner handle. Reels must go live between 6 and 9 PM on a Friday or Saturday for reach.',
   4,
   'instagram',
   'reel',
   1,
   12000.00,
   38000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100303-cloud-kitchen-bengaluru.pdf',
   '["https://picsum.photos/seed/zc-cloud-kitchen-bengaluru-mood-1/1080/1350","https://picsum.photos/seed/zc-cloud-kitchen-bengaluru-mood-2/1080/1350","https://picsum.photos/seed/zc-cloud-kitchen-bengaluru-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100303-cloud-kitchen-bengaluru.pdf"]',
   CONCAT('{"min_followers":15000,"max_followers":150000,"min_engagement_rate":3.4,"cities":["Bengaluru"],"categories":[4,1],"gender":"any","age_range":"21-40","languages":["English","Kannada","Hindi"],"deliverables":["1 Instagram Reel (25-40s) - order to first bite","2 Stories with the discount code","1 Google review after delivery"],"cover_image":"https://picsum.photos/seed/zc-cloud-kitchen-bengaluru-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-cloud-kitchen-bengaluru-cover/600/400","usage_rights_days":60,"exclusivity_days":15,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 31 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1245 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 24 DAY),
   DATE_ADD(CURDATE(), INTERVAL 9 DAY),
   41,
   7,
   'in_progress',
   1,
   DATE_SUB(NOW(), INTERVAL 52 DAY),
   DATE_SUB(NOW(), INTERVAL 17 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (304,
   'ZC100304',
   1004,
   'True Wireless Earbuds - Honest 8 Minute Review',
   'Long-form review of our new ANC earbuds for tech channels. We are explicitly asking for an honest review including the weak points - battery under ANC and call quality in wind are the two areas we know need context. Please include a mic test, a codec comparison and a price-to-performance verdict against two competitors. Minimum 8 minutes of runtime with the sponsored segment disclosed in the first 30 seconds and in the description. Review unit is retained by the creator.',
   6,
   'youtube',
   'video',
   1,
   150000.00,
   420000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100304-tws-earbuds-review.pdf',
   '["https://picsum.photos/seed/zc-tws-earbuds-review-mood-1/1080/1350","https://picsum.photos/seed/zc-tws-earbuds-review-mood-2/1080/1350","https://picsum.photos/seed/zc-tws-earbuds-review-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100304-tws-earbuds-review.pdf"]',
   CONCAT('{"min_followers":150000,"max_followers":1200000,"min_engagement_rate":1.8,"cities":["Delhi","Bengaluru","Mumbai","Hyderabad","Pune"],"categories":[6],"gender":"any","age_range":"22-40","languages":["Hindi","English"],"deliverables":["1 YouTube review (8+ minutes)","1 YouTube Community post","1 pinned comment with the affiliate link","Custom thumbnail approval before publish"],"cover_image":"https://picsum.photos/seed/zc-tws-earbuds-review-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-tws-earbuds-review-cover/600/400","usage_rights_days":180,"exclusivity_days":60,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 47 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1629 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 40 DAY),
   DATE_ADD(CURDATE(), INTERVAL 14 DAY),
   19,
   4,
   'in_progress',
   1,
   DATE_SUB(NOW(), INTERVAL 68 DAY),
   DATE_SUB(NOW(), INTERVAL 22 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (305,
   'ZC100305',
   1005,
   '30 Day Home Strength Challenge - Transformation Posts',
   'Fitness creators host a 30-day bodyweight strength challenge using our resistance band kit. Weekly posts document week 1 form basics, week 2 progression, week 3 plateau honesty and week 4 results with measurable numbers (reps, hold times - not weight loss claims). No unverified health claims and no before/after body shaming language. We provide the programme PDF and a nutritionist for a Q and A story. Kits ship to the creator and to 3 giveaway winners.',
   5,
   'instagram',
   'post',
   4,
   55000.00,
   140000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100305-30-day-strength-challenge.pdf',
   '["https://picsum.photos/seed/zc-30-day-strength-challenge-mood-1/1080/1350","https://picsum.photos/seed/zc-30-day-strength-challenge-mood-2/1080/1350","https://picsum.photos/seed/zc-30-day-strength-challenge-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100305-30-day-strength-challenge.pdf"]',
   CONCAT('{"min_followers":40000,"max_followers":350000,"min_engagement_rate":3.1,"cities":["Mumbai","Bengaluru","Hyderabad","Chandigarh","Pune","Delhi"],"categories":[5,9],"gender":"any","age_range":"23-40","languages":["Hindi","English"],"deliverables":["4 weekly Instagram posts (carousel or single)","8 Stories across the 4 weeks","1 giveaway Reel at week 4","Programme completion recap in captions"],"cover_image":"https://picsum.photos/seed/zc-30-day-strength-challenge-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-30-day-strength-challenge-cover/600/400","usage_rights_days":90,"exclusivity_days":45,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 15 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 189 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 19 DAY),
   DATE_ADD(CURDATE(), INTERVAL 79 DAY),
   17,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 8 DAY),
   DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (306,
   'ZC100306',
   1006,
   'Hidden Homestays of Coorg - Travel Shorts',
   'Three-night hosted stay across two partner homestays in Coorg in exchange for a Shorts series. We want the plantation walk, the local Kodava breakfast and the drive up from Bengaluru covered as three separate Shorts. Stay, meals and fuel reimbursement up to Rs 6,000 are covered on top of the fee. Shorts must be vertical, under 60 seconds, with location tags. One horizontal 3-4 minute cut is optional and paid separately at Rs 25,000.',
   3,
   'youtube',
   'short',
   3,
   60000.00,
   180000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100306-coorg-hidden-homestays.pdf',
   '["https://picsum.photos/seed/zc-coorg-hidden-homestays-mood-1/1080/1350","https://picsum.photos/seed/zc-coorg-hidden-homestays-mood-2/1080/1350","https://picsum.photos/seed/zc-coorg-hidden-homestays-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100306-coorg-hidden-homestays.pdf"]',
   CONCAT('{"min_followers":60000,"max_followers":600000,"min_engagement_rate":2.6,"cities":["Bengaluru","Chennai","Kochi","Hyderabad","Goa"],"categories":[3,1],"gender":"any","age_range":"23-42","languages":["English","Hindi","Kannada"],"deliverables":["3 YouTube Shorts (under 60s each)","1 Instagram Reel cross-post","6 Stories with the booking link","15 edited stills for the brand library"],"cover_image":"https://picsum.photos/seed/zc-coorg-hidden-homestays-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-coorg-hidden-homestays-cover/600/400","usage_rights_days":365,"exclusivity_days":30,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 21 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 141 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 28 DAY),
   DATE_ADD(CURDATE(), INTERVAL 68 DAY),
   12,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 6 DAY),
   DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (307,
   'ZC100307',
   1007,
   'Baby Safe Detergent - UGC Content Pack',
   'Pure UGC engagement - content is licensed for our paid ads and does not need to be posted on the creator handle. We need five vertical clips: the sniff test, a stain removal demo on a bib, the dermatologist-tested claim read to camera, a laundry-day routine and a 6-second hook variant. Shot on phone, natural light, no music bed. Parents of children under 4 only. Two rounds of revisions included. Full commercial usage for 12 months across Meta and YouTube.',
   13,
   'instagram',
   'ugc',
   5,
   18000.00,
   48000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100307-baby-safe-detergent-ugc.pdf',
   '["https://picsum.photos/seed/zc-baby-safe-detergent-ugc-mood-1/1080/1350","https://picsum.photos/seed/zc-baby-safe-detergent-ugc-mood-2/1080/1350","https://picsum.photos/seed/zc-baby-safe-detergent-ugc-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100307-baby-safe-detergent-ugc.pdf"]',
   CONCAT('{"min_followers":5000,"max_followers":120000,"min_engagement_rate":4,"cities":["Mumbai","Pune","Nagpur","Indore","Bhopal","Delhi"],"categories":[13,9],"gender":"any","age_range":"26-40","languages":["Hindi","English","Marathi"],"deliverables":["5 vertical UGC clips (15-25s each)","2 hook variants per clip","Raw files delivered over Drive","Signed 12 month usage licence"],"cover_image":"https://picsum.photos/seed/zc-baby-safe-detergent-ugc-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-baby-safe-detergent-ugc-cover/600/400","usage_rights_days":365,"exclusivity_days":0,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 25 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1053 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 18 DAY),
   DATE_ADD(CURDATE(), INTERVAL 12 DAY),
   33,
   9,
   'in_progress',
   1,
   DATE_SUB(NOW(), INTERVAL 44 DAY),
   DATE_SUB(NOW(), INTERVAL 14 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (308,
   'ZC100308',
   1008,
   'SIP Simplified - Explainer Series for First Time Investors',
   'Two-part explainer series for personal finance channels covering how a systematic investment plan actually works and what the common first-year mistakes are. All copy is reviewed by our compliance team before publish - expect one review cycle of 3 working days. Mandatory risk disclaimer card for 5 seconds and in the description. No return projections, no guaranteed-return language and no stock tips. AMFI registration number is provided for the description.',
   12,
   'youtube',
   'video',
   2,
   120000.00,
   320000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100308-sip-simplified-series.pdf',
   '["https://picsum.photos/seed/zc-sip-simplified-series-mood-1/1080/1350","https://picsum.photos/seed/zc-sip-simplified-series-mood-2/1080/1350","https://picsum.photos/seed/zc-sip-simplified-series-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100308-sip-simplified-series.pdf"]',
   CONCAT('{"min_followers":100000,"max_followers":900000,"min_engagement_rate":2,"cities":["Mumbai","Delhi","Bengaluru","Pune","Ahmedabad"],"categories":[12,11],"gender":"any","age_range":"25-45","languages":["Hindi","English"],"deliverables":["2 YouTube explainers (6-10 minutes each)","1 pinned comment with the disclaimer","1 Instagram Reel cut-down","Compliance review before publish"],"cover_image":"https://picsum.photos/seed/zc-sip-simplified-series-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-sip-simplified-series-cover/600/400","usage_rights_days":365,"exclusivity_days":90,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 71 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 2301 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 62 DAY),
   DATE_ADD(CURDATE(), INTERVAL 21 DAY),
   22,
   5,
   'in_progress',
   1,
   DATE_SUB(NOW(), INTERVAL 96 DAY),
   DATE_SUB(NOW(), INTERVAL 32 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (309,
   'ZC100309',
   1009,
   'Electric Scooter City Range Test',
   'Real-world range test of our 3.2 kWh electric scooter across a full city commute loop. We want the actual number, not the brochure number - start at 100 percent, ride a mixed loop of traffic and flyover, and report where it stops. Include charging time from a standard 5A socket and the cost per 100 km against petrol. Scooter is delivered for 10 days and collected after. Please film the odometer clearly at start and end.',
   14,
   'youtube',
   'video',
   1,
   180000.00,
   500000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100309-ev-scooter-range-test.pdf',
   '["https://picsum.photos/seed/zc-ev-scooter-range-test-mood-1/1080/1350","https://picsum.photos/seed/zc-ev-scooter-range-test-mood-2/1080/1350","https://picsum.photos/seed/zc-ev-scooter-range-test-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100309-ev-scooter-range-test.pdf"]',
   CONCAT('{"min_followers":120000,"max_followers":1100000,"min_engagement_rate":2.1,"cities":["Bengaluru","Pune","Hyderabad","Chennai","Delhi"],"categories":[14,6],"gender":"any","age_range":"24-42","languages":["Hindi","English","Telugu"],"deliverables":["1 YouTube range test (10-14 minutes)","1 YouTube Short highlight","1 Instagram Reel with the final number","Telemetry screenshots shared with the brand"],"cover_image":"https://picsum.photos/seed/zc-ev-scooter-range-test-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-ev-scooter-range-test-cover/600/400","usage_rights_days":180,"exclusivity_days":60,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 14 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 213 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 17 DAY),
   DATE_ADD(CURDATE(), INTERVAL 52 DAY),
   15,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 9 DAY),
   DATE_SUB(NOW(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (310,
   'ZC100310',
   1010,
   'Battle Royale Season Launch - Live Stream Takeover',
   'Season 12 launch week. Two live streams of minimum 90 minutes each on launch day and the following weekend, playing the new map with the branded overlay pack we supply. We need the creator code called out at least four times per stream and a pinned link in chat. Custom room codes for 200 viewers are provided for the second stream. Streams must be on YouTube Gaming - no simulcast to competitor platforms during the window.',
   8,
   'youtube',
   'live',
   2,
   90000.00,
   260000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100310-br-season-launch-live.pdf',
   '["https://picsum.photos/seed/zc-br-season-launch-live-mood-1/1080/1350","https://picsum.photos/seed/zc-br-season-launch-live-mood-2/1080/1350","https://picsum.photos/seed/zc-br-season-launch-live-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100310-br-season-launch-live.pdf"]',
   CONCAT('{"min_followers":80000,"max_followers":800000,"min_engagement_rate":3.6,"cities":["Delhi","Mumbai","Lucknow","Kanpur","Jaipur","Indore"],"categories":[8],"gender":"any","age_range":"18-30","languages":["Hindi","English"],"deliverables":["2 live streams (90+ minutes each)","1 YouTube Short recap","Branded overlay for the full stream","Creator code called out 4+ times per stream"],"cover_image":"https://picsum.photos/seed/zc-br-season-launch-live-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-br-season-launch-live-cover/600/400","usage_rights_days":90,"exclusivity_days":21,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 19 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 789 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 12 DAY),
   DATE_ADD(CURDATE(), INTERVAL 8 DAY),
   28,
   6,
   'in_progress',
   1,
   DATE_SUB(NOW(), INTERVAL 33 DAY),
   DATE_SUB(NOW(), INTERVAL 11 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (311,
   'ZC100311',
   1011,
   'Diwali Home Makeover - Story Takeover',
   'Diwali festive decor takeover. Creators received a Rs 12,000 decor credit and documented a single-room makeover as a 10-frame story sequence with the swipe-up shopping links. Emphasis was on the diya wall, the entryway rangoli corner and the fairy-light styling. All stories were saved to a permanent highlight titled Diwali with the brand mention retained.',
   1,
   'instagram',
   'story',
   1,
   22000.00,
   60000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100311-diwali-home-makeover.pdf',
   '["https://picsum.photos/seed/zc-diwali-home-makeover-mood-1/1080/1350","https://picsum.photos/seed/zc-diwali-home-makeover-mood-2/1080/1350","https://picsum.photos/seed/zc-diwali-home-makeover-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100311-diwali-home-makeover.pdf"]',
   CONCAT('{"min_followers":30000,"max_followers":250000,"min_engagement_rate":2.9,"cities":["Mumbai","Delhi","Jaipur","Kolkata","Ahmedabad"],"categories":[1,16],"gender":"any","age_range":"25-42","languages":["Hindi","English"],"deliverables":["10 frame Story takeover with product links","1 permanent Story highlight","6 edited stills for the brand library"],"cover_image":"https://picsum.photos/seed/zc-diwali-home-makeover-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-diwali-home-makeover-cover/600/400","usage_rights_days":180,"exclusivity_days":30,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 281 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 7101 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 274 DAY),
   DATE_SUB(CURDATE(), INTERVAL 252 DAY),
   46,
   11,
   'completed',
   1,
   DATE_SUB(NOW(), INTERVAL 296 DAY),
   DATE_SUB(NOW(), INTERVAL 98 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (312,
   'ZC100312',
   1012,
   'Matte Lip Crayon Swatch Reels',
   'Swatch-led Reel for our 12 shade matte crayon range. Creators swatched a minimum of six shades on the arm and two full lip looks, with a 6-hour wear test in the same Reel. Undertone guidance for Indian skin tones was a hard requirement - we asked creators to call out which shades work for warm, neutral and cool undertones. Shade names were on screen as text overlays.',
   7,
   'instagram',
   'reel',
   1,
   16000.00,
   52000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100312-matte-lip-crayon-swatch.pdf',
   '["https://picsum.photos/seed/zc-matte-lip-crayon-swatch-mood-1/1080/1350","https://picsum.photos/seed/zc-matte-lip-crayon-swatch-mood-2/1080/1350","https://picsum.photos/seed/zc-matte-lip-crayon-swatch-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100312-matte-lip-crayon-swatch.pdf"]',
   CONCAT('{"min_followers":20000,"max_followers":180000,"min_engagement_rate":3.2,"cities":["Mumbai","Delhi","Bengaluru","Hyderabad","Kolkata","Chennai"],"categories":[7],"gender":"female","age_range":"20-35","languages":["Hindi","English"],"deliverables":["1 Instagram Reel (30-45s) swatch and wear test","3 Stories with the shade finder link","Shade names as on-screen text"],"cover_image":"https://picsum.photos/seed/zc-matte-lip-crayon-swatch-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-matte-lip-crayon-swatch-cover/600/400","usage_rights_days":120,"exclusivity_days":30,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 203 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5085 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 196 DAY),
   DATE_SUB(CURDATE(), INTERVAL 166 DAY),
   52,
   14,
   'completed',
   1,
   DATE_SUB(NOW(), INTERVAL 212 DAY),
   DATE_SUB(NOW(), INTERVAL 70 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (313,
   'ZC100313',
   1013,
   'Ayurvedic Immunity Ritual - Educational Carousels',
   'Two educational carousels explaining the ingredient story behind our chyawanprash-alternative immunity range. We want the sourcing (Uttarakhand amla, Kerala tulsi), the daily ritual and what the AYUSH licence actually means. Facts are supplied in a brief deck and must not be embellished - no cure claims, no COVID references. Designers can use our brand fonts or their own template. Carousels should be 6-8 frames with a saveable summary frame.',
   9,
   'instagram',
   'post',
   2,
   26000.00,
   72000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100313-ayurvedic-immunity-ritual.pdf',
   '["https://picsum.photos/seed/zc-ayurvedic-immunity-ritual-mood-1/1080/1350","https://picsum.photos/seed/zc-ayurvedic-immunity-ritual-mood-2/1080/1350","https://picsum.photos/seed/zc-ayurvedic-immunity-ritual-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100313-ayurvedic-immunity-ritual.pdf"]',
   CONCAT('{"min_followers":25000,"max_followers":220000,"min_engagement_rate":2.7,"cities":["Delhi","Lucknow","Kanpur","Jaipur","Bhopal","Indore","Mumbai"],"categories":[9,4],"gender":"any","age_range":"25-45","languages":["Hindi","English"],"deliverables":["2 Instagram carousels (6-8 frames each)","4 Stories with a saveable summary","Fact check sign-off before publish"],"cover_image":"https://picsum.photos/seed/zc-ayurvedic-immunity-ritual-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-ayurvedic-immunity-ritual-cover/600/400","usage_rights_days":120,"exclusivity_days":30,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 16 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 309 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 20 DAY),
   DATE_ADD(CURDATE(), INTERVAL 65 DAY),
   21,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 13 DAY),
   DATE_SUB(NOW(), INTERVAL 4 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (314,
   'ZC100314',
   1014,
   'Indie Single Drop - Trending Audio Challenge',
   'Our indie artist drops a new single and we want the hook to travel. Creators pick the 15-second hook from the Instagram audio page and build any Reel around it - dance, transition, comedy, cooking, whatever suits the handle. We are not prescribing the concept, only the audio and the tag. Music creators and dancers get priority but the brief is open to all verticals. Bonus of Rs 10,000 if the Reel crosses 500k plays in 14 days.',
   10,
   'instagram',
   'reel',
   1,
   14000.00,
   46000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100314-indie-single-audio-challenge.pdf',
   '["https://picsum.photos/seed/zc-indie-single-audio-challenge-mood-1/1080/1350","https://picsum.photos/seed/zc-indie-single-audio-challenge-mood-2/1080/1350","https://picsum.photos/seed/zc-indie-single-audio-challenge-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100314-indie-single-audio-challenge.pdf"]',
   CONCAT('{"min_followers":20000,"max_followers":400000,"min_engagement_rate":4.2,"cities":["Mumbai","Delhi","Bengaluru","Pune","Kolkata","Chandigarh"],"categories":[10,15,1],"gender":"any","age_range":"18-32","languages":["Hindi","English","Punjabi"],"deliverables":["1 Instagram Reel using the official audio","2 Stories with the streaming link","Audio credit and artist tag retained"],"cover_image":"https://picsum.photos/seed/zc-indie-single-audio-challenge-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-indie-single-audio-challenge-cover/600/400","usage_rights_days":90,"exclusivity_days":0,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 9 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 117 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 11 DAY),
   DATE_ADD(CURDATE(), INTERVAL 41 DAY),
   63,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 5 DAY),
   DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (315,
   'ZC100315',
   1015,
   'Crack CAT Quant - 60 Second Shorts',
   'Six Shorts, one concept each, aimed at CAT and MBA aspirants - time speed distance shortcuts, percentage traps, ratio quick wins, number system basics, geometry heuristics and a mock strategy short. Each Short must solve one real previous-year question end to end within 60 seconds. Our academic team validates every solution before publish. Course link in the description and a discount code on screen for the last 3 seconds.',
   11,
   'youtube',
   'short',
   6,
   48000.00,
   130000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100315-cat-quant-shorts.pdf',
   '["https://picsum.photos/seed/zc-cat-quant-shorts-mood-1/1080/1350","https://picsum.photos/seed/zc-cat-quant-shorts-mood-2/1080/1350","https://picsum.photos/seed/zc-cat-quant-shorts-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100315-cat-quant-shorts.pdf"]',
   CONCAT('{"min_followers":40000,"max_followers":500000,"min_engagement_rate":3,"cities":["Delhi","Kanpur","Lucknow","Indore","Pune","Hyderabad"],"categories":[11],"gender":"any","age_range":"22-38","languages":["Hindi","English"],"deliverables":["6 YouTube Shorts (under 60s each)","1 pinned comment with the course link","Solutions validated by the academic team"],"cover_image":"https://picsum.photos/seed/zc-cat-quant-shorts-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-cat-quant-shorts-cover/600/400","usage_rights_days":365,"exclusivity_days":60,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 58 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1845 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 49 DAY),
   DATE_ADD(CURDATE(), INTERVAL 18 DAY),
   18,
   3,
   'in_progress',
   1,
   DATE_SUB(NOW(), INTERVAL 77 DAY),
   DATE_SUB(NOW(), INTERVAL 25 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (316,
   'ZC100316',
   1016,
   'Work From Home Woes - Branded Sketch Reels',
   'Comedy sketch Reel for our ergonomic chair and desk range. Premise is open but must land the product naturally - the punchline can be the chair, not an ad read. Think the mute-button disaster, the standing desk that never stands, the kitchen-stool-as-office-chair arc. We only need the product visible for 5 continuous seconds and one line about the 8-year warranty. No forced brand talk. Chair is delivered and retained.',
   15,
   'instagram',
   'reel',
   1,
   35000.00,
   95000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100316-wfh-woes-sketch.pdf',
   '["https://picsum.photos/seed/zc-wfh-woes-sketch-mood-1/1080/1350","https://picsum.photos/seed/zc-wfh-woes-sketch-mood-2/1080/1350","https://picsum.photos/seed/zc-wfh-woes-sketch-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100316-wfh-woes-sketch.pdf"]',
   CONCAT('{"min_followers":50000,"max_followers":500000,"min_engagement_rate":3.8,"cities":["Mumbai","Delhi","Bengaluru","Pune","Hyderabad","Chandigarh"],"categories":[15,1,6],"gender":"any","age_range":"21-38","languages":["Hindi","English"],"deliverables":["1 Instagram Reel sketch (40-70s)","2 Stories with the product link","Product visible for 5 continuous seconds"],"cover_image":"https://picsum.photos/seed/zc-wfh-woes-sketch-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-wfh-woes-sketch-cover/600/400","usage_rights_days":120,"exclusivity_days":30,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 11 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 93 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 14 DAY),
   DATE_ADD(CURDATE(), INTERVAL 49 DAY),
   31,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 4 DAY),
   DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (317,
   'ZC100317',
   1017,
   'Handmade Ceramic Studio - Process Carousels',
   'Draft brief pending budget sign-off. Concept is a slow-content series with art and design creators documenting the wheel-throwing, trimming and glaze-firing stages at our Pondicherry studio. Two carousels plus behind-the-scenes stories. Travel and a two-day studio residency would be covered. Holding this until the Q3 marketing budget is confirmed.',
   16,
   'instagram',
   'post',
   2,
   20000.00,
   58000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100317-ceramic-studio-process.pdf',
   '["https://picsum.photos/seed/zc-ceramic-studio-process-mood-1/1080/1350","https://picsum.photos/seed/zc-ceramic-studio-process-mood-2/1080/1350","https://picsum.photos/seed/zc-ceramic-studio-process-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100317-ceramic-studio-process.pdf"]',
   CONCAT('{"min_followers":15000,"max_followers":150000,"min_engagement_rate":3.3,"cities":["Chennai","Bengaluru","Kochi","Goa"],"categories":[16,1],"gender":"any","age_range":"23-40","languages":["English","Tamil"],"deliverables":["2 Instagram carousels (process documentation)","8 behind-the-scenes Stories","20 edited stills for the studio archive"],"cover_image":"https://picsum.photos/seed/zc-ceramic-studio-process-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-ceramic-studio-process-cover/600/400","usage_rights_days":180,"exclusivity_days":0,"barter_only":true,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 38 DAY), '%Y-%m-%d'), '","published_at":', 'null', '}'),
   DATE_ADD(CURDATE(), INTERVAL 45 DAY),
   DATE_ADD(CURDATE(), INTERVAL 85 DAY),
   0,
   0,
   'draft',
   0,
   DATE_SUB(NOW(), INTERVAL 7 DAY),
   DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (318,
   'ZC100318',
   1018,
   'Millet Snack Range - Taste Test Reels',
   'Two Reels for our baked millet snack range - one blind taste test against a leading fried competitor and one lunchbox or evening-snack integration. We want honest reactions including if a flavour does not land. Nutrition panel comparison must be on screen for 3 seconds. Creators with a genuine health-conscious audience preferred. Sample box of 9 SKUs is shipped. Please avoid weight loss framing entirely.',
   4,
   'instagram',
   'reel',
   2,
   24000.00,
   68000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100318-millet-snack-taste-test.pdf',
   '["https://picsum.photos/seed/zc-millet-snack-taste-test-mood-1/1080/1350","https://picsum.photos/seed/zc-millet-snack-taste-test-mood-2/1080/1350","https://picsum.photos/seed/zc-millet-snack-taste-test-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100318-millet-snack-taste-test.pdf"]',
   CONCAT('{"min_followers":25000,"max_followers":250000,"min_engagement_rate":3.1,"cities":["Hyderabad","Bengaluru","Chennai","Mumbai","Pune","Indore"],"categories":[4,9],"gender":"any","age_range":"24-42","languages":["Hindi","English","Telugu","Tamil"],"deliverables":["2 Instagram Reels (30-45s each)","4 Stories with the D2C store link","Nutrition panel on screen for 3 seconds"],"cover_image":"https://picsum.photos/seed/zc-millet-snack-taste-test-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-millet-snack-taste-test-cover/600/400","usage_rights_days":120,"exclusivity_days":30,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 17 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 237 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 18 DAY),
   DATE_ADD(CURDATE(), INTERVAL 55 DAY),
   26,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 10 DAY),
   DATE_SUB(NOW(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (319,
   'ZC100319',
   1019,
   'Monsoon Footwear Drop - Multi City Lookbook',
   'Monsoon-ready footwear launch shot across six cities to show the range in genuinely wet conditions. Each creator delivered one lookbook carousel, one walking-through-rain Reel and a story set with sizing guidance. The waterproofing demo was mandatory - a real puddle, not a styled one. Shipping and a Rs 3,000 styling allowance were provided on top of the fee.',
   2,
   'instagram',
   'bundle',
   3,
   38000.00,
   105000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100319-monsoon-footwear-lookbook.pdf',
   '["https://picsum.photos/seed/zc-monsoon-footwear-lookbook-mood-1/1080/1350","https://picsum.photos/seed/zc-monsoon-footwear-lookbook-mood-2/1080/1350","https://picsum.photos/seed/zc-monsoon-footwear-lookbook-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100319-monsoon-footwear-lookbook.pdf"]',
   CONCAT('{"min_followers":40000,"max_followers":300000,"min_engagement_rate":2.4,"cities":["Mumbai","Pune","Kochi","Kolkata","Goa","Bengaluru"],"categories":[2,1],"gender":"any","age_range":"22-36","languages":["Hindi","English","Marathi"],"deliverables":["1 lookbook carousel (6 frames)","1 Reel with the waterproofing demo","5 Stories with sizing guidance","Raw stills for the brand lookbook"],"cover_image":"https://picsum.photos/seed/zc-monsoon-footwear-lookbook-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-monsoon-footwear-lookbook-cover/600/400","usage_rights_days":180,"exclusivity_days":45,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 140 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 3621 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 133 DAY),
   DATE_SUB(CURDATE(), INTERVAL 96 DAY),
   44,
   10,
   'completed',
   1,
   DATE_SUB(NOW(), INTERVAL 151 DAY),
   DATE_SUB(NOW(), INTERVAL 50 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (320,
   'ZC100320',
   1020,
   'Smart Home Starter Kit - Cross Platform Review',
   'Cross-platform review of our smart home starter kit (hub, 4 bulbs, 2 plugs, 1 door sensor). One long-form YouTube setup and review, plus one Instagram Reel focused on the 12-minute setup claim - if it takes longer, say so on camera. We want the Alexa and Google Home pairing shown live, and a genuine note on what the app still does badly. Kit is retained. Firmware update is pushed before shipping.',
   6,
   'multi',
   'review',
   2,
   140000.00,
   380000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100320-smart-home-starter-review.pdf',
   '["https://picsum.photos/seed/zc-smart-home-starter-review-mood-1/1080/1350","https://picsum.photos/seed/zc-smart-home-starter-review-mood-2/1080/1350","https://picsum.photos/seed/zc-smart-home-starter-review-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100320-smart-home-starter-review.pdf"]',
   CONCAT('{"min_followers":100000,"max_followers":900000,"min_engagement_rate":2.2,"cities":["Bengaluru","Hyderabad","Mumbai","Delhi","Pune"],"categories":[6,1],"gender":"any","age_range":"24-42","languages":["Hindi","English"],"deliverables":["1 YouTube setup and review (9-13 minutes)","1 Instagram Reel on the setup time claim","3 Stories with the launch offer","Setup timing shown unedited"],"cover_image":"https://picsum.photos/seed/zc-smart-home-starter-review-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-smart-home-starter-review-cover/600/400","usage_rights_days":365,"exclusivity_days":90,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 42 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1413 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 35 DAY),
   DATE_ADD(CURDATE(), INTERVAL 16 DAY),
   16,
   4,
   'in_progress',
   1,
   DATE_SUB(NOW(), INTERVAL 59 DAY),
   DATE_SUB(NOW(), INTERVAL 19 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (321,
   'ZC100321',
   1001,
   'Sunscreen SPF 50 - Daily Ritual Stories',
   'Summer sun care push. Creators documented a real morning with the SPF 50 gel sunscreen as a 6-frame story set - application amount (two finger rule), the no-white-cast check on camera and the reapplication reminder at 3 PM. Dermatologist Q and A sticker was included in frame 5. The campaign ran through peak summer across the south and west markets.',
   7,
   'instagram',
   'story',
   1,
   15000.00,
   42000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100321-spf50-daily-ritual.pdf',
   '["https://picsum.photos/seed/zc-spf50-daily-ritual-mood-1/1080/1350","https://picsum.photos/seed/zc-spf50-daily-ritual-mood-2/1080/1350","https://picsum.photos/seed/zc-spf50-daily-ritual-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100321-spf50-daily-ritual.pdf"]',
   CONCAT('{"min_followers":15000,"max_followers":150000,"min_engagement_rate":3,"cities":["Chennai","Hyderabad","Bengaluru","Mumbai","Ahmedabad","Surat"],"categories":[7,9],"gender":"any","age_range":"20-38","languages":["Hindi","English","Tamil","Telugu"],"deliverables":["6 frame Story set with the two finger rule","1 Q and A sticker frame","Story highlight retained for 30 days"],"cover_image":"https://picsum.photos/seed/zc-spf50-daily-ritual-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-spf50-daily-ritual-cover/600/400","usage_rights_days":90,"exclusivity_days":21,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 111 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 2829 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 104 DAY),
   DATE_SUB(CURDATE(), INTERVAL 74 DAY),
   37,
   9,
   'completed',
   1,
   DATE_SUB(NOW(), INTERVAL 118 DAY),
   DATE_SUB(NOW(), INTERVAL 39 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (322,
   'ZC100322',
   1003,
   'Filter Coffee Culture - Chennai Photo Essays',
   'Photo-essay series on Chennai filter coffee culture pairing our decoction pack with heritage cafes in Mylapore and Triplicane. Cancelled after the cafe partnership fell through two weeks before the shoot window. Creators who had already been onboarded were paid a 30 percent kill fee. We intend to revive this brief next quarter with a different location partner.',
   4,
   'instagram',
   'post',
   2,
   18000.00,
   50000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100322-chennai-filter-coffee.pdf',
   '["https://picsum.photos/seed/zc-chennai-filter-coffee-mood-1/1080/1350","https://picsum.photos/seed/zc-chennai-filter-coffee-mood-2/1080/1350","https://picsum.photos/seed/zc-chennai-filter-coffee-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100322-chennai-filter-coffee.pdf"]',
   CONCAT('{"min_followers":20000,"max_followers":200000,"min_engagement_rate":2.8,"cities":["Chennai"],"categories":[4,3],"gender":"any","age_range":"24-45","languages":["Tamil","English"],"deliverables":["2 Instagram photo-essay posts","4 Stories with the cafe location tags","12 edited stills for the brand library"],"cover_image":"https://picsum.photos/seed/zc-chennai-filter-coffee-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-chennai-filter-coffee-cover/600/400","usage_rights_days":90,"exclusivity_days":0,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 158 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 4173 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 146 DAY),
   DATE_SUB(CURDATE(), INTERVAL 116 DAY),
   14,
   2,
   'cancelled',
   0,
   DATE_SUB(NOW(), INTERVAL 174 DAY),
   DATE_SUB(NOW(), INTERVAL 58 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (323,
   'ZC100323',
   1005,
   'Marathon Prep Series - Long Form Training Vlogs',
   'Three long-form vlogs following a 12-week half marathon build using our running shoe and recovery range. Week 1 baseline and gait analysis, week 6 tempo block, week 11 taper and race-week nerves. We want the honest bad days included - a missed week or an injury scare is more useful to our audience than a clean arc. Race entry for the Mumbai or Bengaluru event is covered. Gait analysis session at our Bengaluru lab is arranged.',
   5,
   'youtube',
   'video',
   3,
   110000.00,
   300000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100323-marathon-prep-vlogs.pdf',
   '["https://picsum.photos/seed/zc-marathon-prep-vlogs-mood-1/1080/1350","https://picsum.photos/seed/zc-marathon-prep-vlogs-mood-2/1080/1350","https://picsum.photos/seed/zc-marathon-prep-vlogs-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100323-marathon-prep-vlogs.pdf"]',
   CONCAT('{"min_followers":80000,"max_followers":700000,"min_engagement_rate":2.5,"cities":["Mumbai","Bengaluru","Pune","Hyderabad","Delhi"],"categories":[5,9],"gender":"any","age_range":"24-42","languages":["Hindi","English"],"deliverables":["3 YouTube vlogs (10-18 minutes each)","3 Instagram Reel cut-downs","Weekly Stories through the 12 week block","Gait analysis footage shared with the brand"],"cover_image":"https://picsum.photos/seed/zc-marathon-prep-vlogs-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-marathon-prep-vlogs-cover/600/400","usage_rights_days":365,"exclusivity_days":90,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 20 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 453 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 26 DAY),
   DATE_ADD(CURDATE(), INTERVAL 110 DAY),
   11,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 19 DAY),
   DATE_SUB(NOW(), INTERVAL 6 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (324,
   'ZC100324',
   1006,
   'Goa Off Season Getaway - Creator Residency',
   'Four-night monsoon residency at our North Goa property for four creators travelling together. The angle is off-season Goa - empty beaches, green hills, spice farms and the food scene without the December crowd. Deliverables are one Reel each, one shared carousel, a daily story set and a group content day with our photographer. Travel from any metro, stay and meals are covered on top of the fee.',
   3,
   'instagram',
   'bundle',
   4,
   70000.00,
   190000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100324-goa-off-season-residency.pdf',
   '["https://picsum.photos/seed/zc-goa-off-season-residency-mood-1/1080/1350","https://picsum.photos/seed/zc-goa-off-season-residency-mood-2/1080/1350","https://picsum.photos/seed/zc-goa-off-season-residency-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100324-goa-off-season-residency.pdf"]',
   CONCAT('{"min_followers":60000,"max_followers":450000,"min_engagement_rate":2.7,"cities":["Mumbai","Delhi","Bengaluru","Pune","Goa"],"categories":[3,1,4],"gender":"any","age_range":"23-38","languages":["Hindi","English","Konkani"],"deliverables":["1 Instagram Reel per creator","1 shared carousel post","Daily Story sets across 4 nights","Group content day with the brand photographer"],"cover_image":"https://picsum.photos/seed/zc-goa-off-season-residency-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-goa-off-season-residency-cover/600/400","usage_rights_days":365,"exclusivity_days":45,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 16 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 909 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 9 DAY),
   DATE_ADD(CURDATE(), INTERVAL 24 DAY),
   29,
   4,
   'in_progress',
   1,
   DATE_SUB(NOW(), INTERVAL 38 DAY),
   DATE_SUB(NOW(), INTERVAL 12 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (325,
   'ZC100325',
   1009,
   'Weekend Ride Essentials - Rider Gear Reels',
   'Draft. Two Reels covering the weekend ride kit - helmet, riding jacket, gloves and the tank bag - with a genuine safety-first angle. Waiting on the ISI certification paperwork for the new jacket SKU before this goes live to creators. Do not publish until legal clears the abrasion resistance claim.',
   14,
   'instagram',
   'reel',
   2,
   30000.00,
   85000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100325-weekend-ride-essentials.pdf',
   '["https://picsum.photos/seed/zc-weekend-ride-essentials-mood-1/1080/1350","https://picsum.photos/seed/zc-weekend-ride-essentials-mood-2/1080/1350","https://picsum.photos/seed/zc-weekend-ride-essentials-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100325-weekend-ride-essentials.pdf"]',
   CONCAT('{"min_followers":30000,"max_followers":300000,"min_engagement_rate":2.9,"cities":["Bengaluru","Pune","Delhi","Chandigarh","Jaipur","Nagpur"],"categories":[14,3],"gender":"any","age_range":"22-40","languages":["Hindi","English"],"deliverables":["2 Instagram Reels on the weekend ride kit","4 Stories with the gear checklist","Safety disclaimer in every caption"],"cover_image":"https://picsum.photos/seed/zc-weekend-ride-essentials-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-weekend-ride-essentials-cover/600/400","usage_rights_days":120,"exclusivity_days":30,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 27 DAY), '%Y-%m-%d'), '","published_at":', 'null', '}'),
   DATE_ADD(CURDATE(), INTERVAL 34 DAY),
   DATE_ADD(CURDATE(), INTERVAL 74 DAY),
   0,
   0,
   'draft',
   0,
   DATE_SUB(NOW(), INTERVAL 3 DAY),
   DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (326,
   'ZC100326',
   1011,
   'Small Space Big Style - Rental Makeover Reels',
   'Rental-friendly makeover Reels for tenants who cannot drill, paint or renovate. Two Reels - one full room reveal with a before and after, one focused on the five no-damage hacks (tension rods, peel and stick, modular storage, rug zoning, plug-in sconces). Budget cap of Rs 25,000 in product per creator, chosen from our catalogue. Landlord-safe is the whole point, so please avoid anything that needs a wall anchor.',
   1,
   'instagram',
   'reel',
   2,
   32000.00,
   88000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100326-small-space-big-style.pdf',
   '["https://picsum.photos/seed/zc-small-space-big-style-mood-1/1080/1350","https://picsum.photos/seed/zc-small-space-big-style-mood-2/1080/1350","https://picsum.photos/seed/zc-small-space-big-style-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100326-small-space-big-style.pdf"]',
   CONCAT('{"min_followers":30000,"max_followers":300000,"min_engagement_rate":3,"cities":["Bengaluru","Mumbai","Pune","Hyderabad","Gurugram","Delhi"],"categories":[1,16],"gender":"any","age_range":"23-38","languages":["Hindi","English"],"deliverables":["1 room reveal Reel with before and after","1 Reel on five no-damage hacks","5 Stories with shoppable product links","Before photos shared with the brand"],"cover_image":"https://picsum.photos/seed/zc-small-space-big-style-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-small-space-big-style-cover/600/400","usage_rights_days":180,"exclusivity_days":30,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 12 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 165 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_ADD(CURDATE(), INTERVAL 15 DAY),
   DATE_ADD(CURDATE(), INTERVAL 50 DAY),
   23,
   0,
   'open',
   1,
   DATE_SUB(NOW(), INTERVAL 7 DAY),
   DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (327,
   'ZC100327',
   1013,
   'Sleep Better in 21 Days - Wellness Series',
   'Archived after the 2024 festive cycle. Two-part series on sleep hygiene built around our magnesium and chamomile night range, with a sleep-tracker screenshot check at day 1 and day 21. Delivered on schedule and performed well, but the SKU has since been reformulated so the creative is no longer usable. Retained for reference only - do not reuse the footage.',
   9,
   'youtube',
   'video',
   2,
   80000.00,
   210000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100327-sleep-better-21-days.pdf',
   '["https://picsum.photos/seed/zc-sleep-better-21-days-mood-1/1080/1350","https://picsum.photos/seed/zc-sleep-better-21-days-mood-2/1080/1350","https://picsum.photos/seed/zc-sleep-better-21-days-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100327-sleep-better-21-days.pdf"]',
   CONCAT('{"min_followers":60000,"max_followers":500000,"min_engagement_rate":2.3,"cities":["Mumbai","Delhi","Bengaluru","Pune"],"categories":[9,5],"gender":"any","age_range":"25-45","languages":["Hindi","English"],"deliverables":["2 YouTube videos (7-11 minutes each)","Sleep tracker screenshots at day 1 and day 21","2 Instagram Reel cut-downs"],"cover_image":"https://picsum.photos/seed/zc-sleep-better-21-days-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-sleep-better-21-days-cover/600/400","usage_rights_days":180,"exclusivity_days":45,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 375 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 9309 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 366 DAY),
   DATE_SUB(CURDATE(), INTERVAL 330 DAY),
   25,
   6,
   'archived',
   0,
   DATE_SUB(NOW(), INTERVAL 388 DAY),
   DATE_SUB(NOW(), INTERVAL 129 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (328,
   'ZC100328',
   1016,
   'Wedding Season Chaos - Sketch Series',
   'Wedding season comedy Reel tying our travel-friendly steamer to the shaadi-week outfit panic. Cancelled when the product launch slipped by a quarter and the wedding window closed. Two creators had already been shortlisted and were released with a written note - no kill fee was due as no contracts had been countersigned.',
   15,
   'instagram',
   'reel',
   1,
   28000.00,
   75000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100328-wedding-season-chaos.pdf',
   '["https://picsum.photos/seed/zc-wedding-season-chaos-mood-1/1080/1350","https://picsum.photos/seed/zc-wedding-season-chaos-mood-2/1080/1350","https://picsum.photos/seed/zc-wedding-season-chaos-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100328-wedding-season-chaos.pdf"]',
   CONCAT('{"min_followers":40000,"max_followers":400000,"min_engagement_rate":3.5,"cities":["Delhi","Jaipur","Lucknow","Chandigarh","Mumbai"],"categories":[15,2],"gender":"any","age_range":"21-36","languages":["Hindi","English","Punjabi"],"deliverables":["1 Instagram Reel sketch (45-70s)","3 Stories with the product link"],"cover_image":"https://picsum.photos/seed/zc-wedding-season-chaos-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-wedding-season-chaos-cover/600/400","usage_rights_days":90,"exclusivity_days":21,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 226 DAY), '%Y-%m-%d'), '","published_at":', CONCAT('"', DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5781 HOUR), '%Y-%m-%d %H:%i:%s'), '"'), '}'),
   DATE_SUB(CURDATE(), INTERVAL 214 DAY),
   DATE_SUB(CURDATE(), INTERVAL 184 DAY),
   19,
   0,
   'cancelled',
   0,
   DATE_SUB(NOW(), INTERVAL 241 DAY),
   DATE_SUB(NOW(), INTERVAL 80 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (329,
   'ZC100329',
   1018,
   'Cold Pressed Juice - UGC Content Library',
   'Draft brief for a rolling UGC library - four vertical clips per creator covering the fridge-open moment, the ingredient pour, a morning routine integration and a straight-to-camera testimonial. Content is for paid ads only and will not be posted on creator handles. Rates and the licence term are still being finalised with our media buying agency.',
   4,
   'instagram',
   'ugc',
   4,
   14000.00,
   40000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100329-cold-pressed-juice-ugc.pdf',
   '["https://picsum.photos/seed/zc-cold-pressed-juice-ugc-mood-1/1080/1350","https://picsum.photos/seed/zc-cold-pressed-juice-ugc-mood-2/1080/1350","https://picsum.photos/seed/zc-cold-pressed-juice-ugc-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100329-cold-pressed-juice-ugc.pdf"]',
   CONCAT('{"min_followers":3000,"max_followers":80000,"min_engagement_rate":4.5,"cities":["Hyderabad","Bengaluru","Mumbai","Pune"],"categories":[4,5,9],"gender":"any","age_range":"22-40","languages":["Hindi","English","Telugu"],"deliverables":["4 vertical UGC clips (12-20s each)","2 hook variants per clip","Raw files delivered over Drive"],"cover_image":"https://picsum.photos/seed/zc-cold-pressed-juice-ugc-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-cold-pressed-juice-ugc-cover/600/400","usage_rights_days":270,"exclusivity_days":0,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 24 DAY), '%Y-%m-%d'), '","published_at":', 'null', '}'),
   DATE_ADD(CURDATE(), INTERVAL 30 DAY),
   DATE_ADD(CURDATE(), INTERVAL 90 DAY),
   0,
   0,
   'draft',
   0,
   DATE_SUB(NOW(), INTERVAL 2 DAY),
   DATE_SUB(NOW(), INTERVAL 0 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `campaigns`
  (`id`, `code`, `brand_user_id`, `title`, `description`, `category_id`, `platform`, `deliverable_type`, `posts_count`, `budget_min`, `budget_max`, `currency`, `brief_url`, `reference_urls`, `requirements`, `starts_on`, `ends_on`, `applications_count`, `hired_count`, `status`, `is_public`, `created_at`, `updated_at`)
VALUES
  (330,
   'ZC100330',
   1020,
   'SaaS Founders Speak - LinkedIn Thought Leadership',
   'Draft B2B brief. Two long-form LinkedIn posts from operator-creators on how Indian SaaS teams actually pick their internal tooling - the procurement reality, not the vendor pitch. We want opinionated writing with a real anecdote, not a feature list. Ghostwritten content is not acceptable. Holding until our founder is back from the US roadshow to approve the point of view.',
   6,
   'linkedin',
   'post',
   2,
   45000.00,
   125000.00,
   'INR',
   'https://cdn.zustee.com/briefs/ZC100330-saas-founders-speak.pdf',
   '["https://picsum.photos/seed/zc-saas-founders-speak-mood-1/1080/1350","https://picsum.photos/seed/zc-saas-founders-speak-mood-2/1080/1350","https://picsum.photos/seed/zc-saas-founders-speak-mood-3/1080/1080","https://cdn.zustee.com/briefs/ZC100330-saas-founders-speak.pdf"]',
   CONCAT('{"min_followers":10000,"max_followers":200000,"min_engagement_rate":2,"cities":["Bengaluru","Mumbai","Delhi","Hyderabad","Pune","Chennai"],"categories":[6,12,11],"gender":"any","age_range":"28-48","languages":["English"],"deliverables":["2 long-form LinkedIn posts (900+ words)","1 comment-thread engagement pass over 48 hours","No ghostwriting - creator voice only"],"cover_image":"https://picsum.photos/seed/zc-saas-founders-speak-cover/1200/675","thumbnail_image":"https://picsum.photos/seed/zc-saas-founders-speak-cover/600/400","usage_rights_days":365,"exclusivity_days":0,"barter_only":false,"commission_pct":15,"gst_pct":18,"application_deadline":"', DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 33 DAY), '%Y-%m-%d'), '","published_at":', 'null', '}'),
   DATE_ADD(CURDATE(), INTERVAL 40 DAY),
   DATE_ADD(CURDATE(), INTERVAL 100 DAY),
   0,
   0,
   'draft',
   0,
   DATE_SUB(NOW(), INTERVAL 5 DAY),
   DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);


-- ---------------------------------------------------------------------
-- 2. marketing_campaigns 301-330 - the creator invite blast behind each
--    brand campaign. campaign_recipients FKs to this table.
-- ---------------------------------------------------------------------

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (301,
   'Creator invites - Monsoon Glow Skincare Routine - Reel Series',
   'email',
   'You are invited: Monsoon Glow Skincare Routine - Reel Series',
   'Budget Rs 28,000 - Rs 65,000 | Mumbai, Pune, Bengaluru | Apply on Zusty',
   '<h2>Monsoon Glow Skincare Routine - Reel Series</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 28,000 - Rs 65,000 per creator</li><li>Platform: Instagram / reel x 2</li><li>Cities: Mumbai, Pune, Bengaluru, Delhi</li><li>Minimum followers: 25,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 Instagram Reel (30-45s) - routine walkthrough</li><li>1 Instagram Reel (20-30s) - 7 day check-in</li><li>3 Stories with product link sticker</li><li>2 raw video files for whitelisting</li></ul><p><a href="https://app.zustee.com/campaigns/301">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":301,"campaign_code":"ZC100301","categories":[7,1],"cities":["Mumbai","Pune","Bengaluru","Delhi"],"min_followers":25000,"max_followers":200000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 382 HOUR),
   DATE_SUB(NOW(), INTERVAL 381 HOUR),
   DATE_SUB(NOW(), INTERVAL 380 HOUR),
   11,
   10,
   1,
   7,
   3,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 382 HOUR),
   DATE_SUB(NOW(), INTERVAL 127 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (302,
   'Creator invites - Festive Ethnic Edit - Styling Reels and Stories',
   'email',
   'You are invited: Festive Ethnic Edit - Styling Reels and Stories',
   'Budget Rs 45,000 - Rs 120,000 | Mumbai, Delhi, Jaipur | Apply on Zusty',
   '<h2>Festive Ethnic Edit - Styling Reels and Stories</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 45,000 - Rs 120,000 per creator</li><li>Platform: Instagram / bundle x 3</li><li>Cities: Mumbai, Delhi, Jaipur, Ahmedabad, Lucknow</li><li>Minimum followers: 50,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 Instagram carousel post (5-7 frames)</li><li>1 transition Reel (30s)</li><li>5 Stories with product tags</li><li>Usage rights for paid amplification</li></ul><p><a href="https://app.zustee.com/campaigns/302">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":302,"campaign_code":"ZC100302","categories":[2,1],"cities":["Mumbai","Delhi","Jaipur","Ahmedabad","Lucknow"],"min_followers":50000,"max_followers":400000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 262 HOUR),
   DATE_SUB(NOW(), INTERVAL 261 HOUR),
   DATE_SUB(NOW(), INTERVAL 260 HOUR),
   14,
   14,
   1,
   8,
   3,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 262 HOUR),
   DATE_SUB(NOW(), INTERVAL 87 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (303,
   'Creator invites - Cloud Kitchen Launch - Bengaluru Food Reels',
   'email',
   'You are invited: Cloud Kitchen Launch - Bengaluru Food Reels',
   'Budget Rs 12,000 - Rs 38,000 | Bengaluru | Apply on Zusty',
   '<h2>Cloud Kitchen Launch - Bengaluru Food Reels</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 12,000 - Rs 38,000 per creator</li><li>Platform: Instagram / reel x 1</li><li>Cities: Bengaluru</li><li>Minimum followers: 15,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 Instagram Reel (25-40s) - order to first bite</li><li>2 Stories with the discount code</li><li>1 Google review after delivery</li></ul><p><a href="https://app.zustee.com/campaigns/303">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":303,"campaign_code":"ZC100303","categories":[4,1],"cities":["Bengaluru"],"min_followers":15000,"max_followers":150000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 1246 HOUR),
   DATE_SUB(NOW(), INTERVAL 1245 HOUR),
   DATE_SUB(NOW(), INTERVAL 1244 HOUR),
   9,
   9,
   0,
   5,
   1,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 1246 HOUR),
   DATE_SUB(NOW(), INTERVAL 415 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (304,
   'Creator invites - True Wireless Earbuds - Honest 8 Minute Review',
   'email',
   'You are invited: True Wireless Earbuds - Honest 8 Minute Review',
   'Budget Rs 150,000 - Rs 420,000 | Delhi, Bengaluru, Mumbai | Apply on Zusty',
   '<h2>True Wireless Earbuds - Honest 8 Minute Review</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 150,000 - Rs 420,000 per creator</li><li>Platform: Youtube / video x 1</li><li>Cities: Delhi, Bengaluru, Mumbai, Hyderabad, Pune</li><li>Minimum followers: 150,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 YouTube review (8+ minutes)</li><li>1 YouTube Community post</li><li>1 pinned comment with the affiliate link</li><li>Custom thumbnail approval before publish</li></ul><p><a href="https://app.zustee.com/campaigns/304">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":304,"campaign_code":"ZC100304","categories":[6],"cities":["Delhi","Bengaluru","Mumbai","Hyderabad","Pune"],"min_followers":150000,"max_followers":1200000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 1630 HOUR),
   DATE_SUB(NOW(), INTERVAL 1629 HOUR),
   DATE_SUB(NOW(), INTERVAL 1628 HOUR),
   8,
   8,
   0,
   4,
   1,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 1630 HOUR),
   DATE_SUB(NOW(), INTERVAL 543 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (305,
   'Creator invites - 30 Day Home Strength Challenge - Transformation Posts',
   'email',
   'You are invited: 30 Day Home Strength Challenge - Transformation Posts',
   'Budget Rs 55,000 - Rs 140,000 | Mumbai, Bengaluru, Hyderabad | Apply on Zusty',
   '<h2>30 Day Home Strength Challenge - Transformation Posts</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 55,000 - Rs 140,000 per creator</li><li>Platform: Instagram / post x 4</li><li>Cities: Mumbai, Bengaluru, Hyderabad, Chandigarh, Pune, Delhi</li><li>Minimum followers: 40,000</li></ul><p><strong>Deliverables</strong></p><ul><li>4 weekly Instagram posts (carousel or single)</li><li>8 Stories across the 4 weeks</li><li>1 giveaway Reel at week 4</li><li>Programme completion recap in captions</li></ul><p><a href="https://app.zustee.com/campaigns/305">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":305,"campaign_code":"ZC100305","categories":[5,9],"cities":["Mumbai","Bengaluru","Hyderabad","Chandigarh","Pune","Delhi"],"min_followers":40000,"max_followers":350000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 190 HOUR),
   DATE_SUB(NOW(), INTERVAL 189 HOUR),
   DATE_SUB(NOW(), INTERVAL 188 HOUR),
   9,
   9,
   1,
   4,
   2,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 190 HOUR),
   DATE_SUB(NOW(), INTERVAL 63 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (306,
   'Creator invites - Hidden Homestays of Coorg - Travel Shorts',
   'email',
   'You are invited: Hidden Homestays of Coorg - Travel Shorts',
   'Budget Rs 60,000 - Rs 180,000 | Bengaluru, Chennai, Kochi | Apply on Zusty',
   '<h2>Hidden Homestays of Coorg - Travel Shorts</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 60,000 - Rs 180,000 per creator</li><li>Platform: Youtube / short x 3</li><li>Cities: Bengaluru, Chennai, Kochi, Hyderabad, Goa</li><li>Minimum followers: 60,000</li></ul><p><strong>Deliverables</strong></p><ul><li>3 YouTube Shorts (under 60s each)</li><li>1 Instagram Reel cross-post</li><li>6 Stories with the booking link</li><li>15 edited stills for the brand library</li></ul><p><a href="https://app.zustee.com/campaigns/306">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":306,"campaign_code":"ZC100306","categories":[3,1],"cities":["Bengaluru","Chennai","Kochi","Hyderabad","Goa"],"min_followers":60000,"max_followers":600000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 142 HOUR),
   DATE_SUB(NOW(), INTERVAL 141 HOUR),
   DATE_SUB(NOW(), INTERVAL 140 HOUR),
   10,
   10,
   1,
   4,
   2,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 142 HOUR),
   DATE_SUB(NOW(), INTERVAL 47 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (307,
   'Creator invites - Baby Safe Detergent - UGC Content Pack',
   'email',
   'You are invited: Baby Safe Detergent - UGC Content Pack',
   'Budget Rs 18,000 - Rs 48,000 | Mumbai, Pune, Nagpur | Apply on Zusty',
   '<h2>Baby Safe Detergent - UGC Content Pack</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 18,000 - Rs 48,000 per creator</li><li>Platform: Instagram / ugc x 5</li><li>Cities: Mumbai, Pune, Nagpur, Indore, Bhopal, Delhi</li><li>Minimum followers: 5,000</li></ul><p><strong>Deliverables</strong></p><ul><li>5 vertical UGC clips (15-25s each)</li><li>2 hook variants per clip</li><li>Raw files delivered over Drive</li><li>Signed 12 month usage licence</li></ul><p><a href="https://app.zustee.com/campaigns/307">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":307,"campaign_code":"ZC100307","categories":[13,9],"cities":["Mumbai","Pune","Nagpur","Indore","Bhopal","Delhi"],"min_followers":5000,"max_followers":120000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 1054 HOUR),
   DATE_SUB(NOW(), INTERVAL 1053 HOUR),
   DATE_SUB(NOW(), INTERVAL 1052 HOUR),
   12,
   12,
   1,
   7,
   4,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 1054 HOUR),
   DATE_SUB(NOW(), INTERVAL 351 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (308,
   'Creator invites - SIP Simplified - Explainer Series for First Time Investors',
   'email',
   'You are invited: SIP Simplified - Explainer Series for First Time Investors',
   'Budget Rs 120,000 - Rs 320,000 | Mumbai, Delhi, Bengaluru | Apply on Zusty',
   '<h2>SIP Simplified - Explainer Series for First Time Investors</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 120,000 - Rs 320,000 per creator</li><li>Platform: Youtube / video x 2</li><li>Cities: Mumbai, Delhi, Bengaluru, Pune, Ahmedabad</li><li>Minimum followers: 100,000</li></ul><p><strong>Deliverables</strong></p><ul><li>2 YouTube explainers (6-10 minutes each)</li><li>1 pinned comment with the disclaimer</li><li>1 Instagram Reel cut-down</li><li>Compliance review before publish</li></ul><p><a href="https://app.zustee.com/campaigns/308">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":308,"campaign_code":"ZC100308","categories":[12,11],"cities":["Mumbai","Delhi","Bengaluru","Pune","Ahmedabad"],"min_followers":100000,"max_followers":900000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 2302 HOUR),
   DATE_SUB(NOW(), INTERVAL 2301 HOUR),
   DATE_SUB(NOW(), INTERVAL 2300 HOUR),
   10,
   9,
   2,
   5,
   3,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 2302 HOUR),
   DATE_SUB(NOW(), INTERVAL 767 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (309,
   'Creator invites - Electric Scooter City Range Test',
   'email',
   'You are invited: Electric Scooter City Range Test',
   'Budget Rs 180,000 - Rs 500,000 | Bengaluru, Pune, Hyderabad | Apply on Zusty',
   '<h2>Electric Scooter City Range Test</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 180,000 - Rs 500,000 per creator</li><li>Platform: Youtube / video x 1</li><li>Cities: Bengaluru, Pune, Hyderabad, Chennai, Delhi</li><li>Minimum followers: 120,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 YouTube range test (10-14 minutes)</li><li>1 YouTube Short highlight</li><li>1 Instagram Reel with the final number</li><li>Telemetry screenshots shared with the brand</li></ul><p><a href="https://app.zustee.com/campaigns/309">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":309,"campaign_code":"ZC100309","categories":[14,6],"cities":["Bengaluru","Pune","Hyderabad","Chennai","Delhi"],"min_followers":120000,"max_followers":1100000,"kyc_verified":true}',
   'sending',
   DATE_SUB(NOW(), INTERVAL 214 HOUR),
   DATE_SUB(NOW(), INTERVAL 213 HOUR),
   NULL,
   7,
   7,
   1,
   3,
   1,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 214 HOUR),
   DATE_SUB(NOW(), INTERVAL 71 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (310,
   'Creator invites - Battle Royale Season Launch - Live Stream Takeover',
   'email',
   'You are invited: Battle Royale Season Launch - Live Stream Takeover',
   'Budget Rs 90,000 - Rs 260,000 | Delhi, Mumbai, Lucknow | Apply on Zusty',
   '<h2>Battle Royale Season Launch - Live Stream Takeover</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 90,000 - Rs 260,000 per creator</li><li>Platform: Youtube / live x 2</li><li>Cities: Delhi, Mumbai, Lucknow, Kanpur, Jaipur, Indore</li><li>Minimum followers: 80,000</li></ul><p><strong>Deliverables</strong></p><ul><li>2 live streams (90+ minutes each)</li><li>1 YouTube Short recap</li><li>Branded overlay for the full stream</li><li>Creator code called out 4+ times per stream</li></ul><p><a href="https://app.zustee.com/campaigns/310">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":310,"campaign_code":"ZC100310","categories":[8],"cities":["Delhi","Mumbai","Lucknow","Kanpur","Jaipur","Indore"],"min_followers":80000,"max_followers":800000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 790 HOUR),
   DATE_SUB(NOW(), INTERVAL 789 HOUR),
   DATE_SUB(NOW(), INTERVAL 788 HOUR),
   15,
   15,
   1,
   9,
   4,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 790 HOUR),
   DATE_SUB(NOW(), INTERVAL 263 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (311,
   'Creator invites - Diwali Home Makeover - Story Takeover',
   'email',
   'You are invited: Diwali Home Makeover - Story Takeover',
   'Budget Rs 22,000 - Rs 60,000 | Mumbai, Delhi, Jaipur | Apply on Zusty',
   '<h2>Diwali Home Makeover - Story Takeover</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 22,000 - Rs 60,000 per creator</li><li>Platform: Instagram / story x 1</li><li>Cities: Mumbai, Delhi, Jaipur, Kolkata, Ahmedabad</li><li>Minimum followers: 30,000</li></ul><p><strong>Deliverables</strong></p><ul><li>10 frame Story takeover with product links</li><li>1 permanent Story highlight</li><li>6 edited stills for the brand library</li></ul><p><a href="https://app.zustee.com/campaigns/311">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":311,"campaign_code":"ZC100311","categories":[1,16],"cities":["Mumbai","Delhi","Jaipur","Kolkata","Ahmedabad"],"min_followers":30000,"max_followers":250000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 7102 HOUR),
   DATE_SUB(NOW(), INTERVAL 7101 HOUR),
   DATE_SUB(NOW(), INTERVAL 7100 HOUR),
   10,
   10,
   1,
   7,
   3,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 7102 HOUR),
   DATE_SUB(NOW(), INTERVAL 2367 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (312,
   'Creator invites - Matte Lip Crayon Swatch Reels',
   'email',
   'You are invited: Matte Lip Crayon Swatch Reels',
   'Budget Rs 16,000 - Rs 52,000 | Mumbai, Delhi, Bengaluru | Apply on Zusty',
   '<h2>Matte Lip Crayon Swatch Reels</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 16,000 - Rs 52,000 per creator</li><li>Platform: Instagram / reel x 1</li><li>Cities: Mumbai, Delhi, Bengaluru, Hyderabad, Kolkata, Chennai</li><li>Minimum followers: 20,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 Instagram Reel (30-45s) swatch and wear test</li><li>3 Stories with the shade finder link</li><li>Shade names as on-screen text</li></ul><p><a href="https://app.zustee.com/campaigns/312">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":312,"campaign_code":"ZC100312","categories":[7],"cities":["Mumbai","Delhi","Bengaluru","Hyderabad","Kolkata","Chennai"],"min_followers":20000,"max_followers":180000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 5086 HOUR),
   DATE_SUB(NOW(), INTERVAL 5085 HOUR),
   DATE_SUB(NOW(), INTERVAL 5084 HOUR),
   14,
   14,
   1,
   9,
   4,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 5086 HOUR),
   DATE_SUB(NOW(), INTERVAL 1695 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (313,
   'Creator invites - Ayurvedic Immunity Ritual - Educational Carousels',
   'email',
   'You are invited: Ayurvedic Immunity Ritual - Educational Carousels',
   'Budget Rs 26,000 - Rs 72,000 | Delhi, Lucknow, Kanpur | Apply on Zusty',
   '<h2>Ayurvedic Immunity Ritual - Educational Carousels</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 26,000 - Rs 72,000 per creator</li><li>Platform: Instagram / post x 2</li><li>Cities: Delhi, Lucknow, Kanpur, Jaipur, Bhopal, Indore, Mumbai</li><li>Minimum followers: 25,000</li></ul><p><strong>Deliverables</strong></p><ul><li>2 Instagram carousels (6-8 frames each)</li><li>4 Stories with a saveable summary</li><li>Fact check sign-off before publish</li></ul><p><a href="https://app.zustee.com/campaigns/313">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":313,"campaign_code":"ZC100313","categories":[9,4],"cities":["Delhi","Lucknow","Kanpur","Jaipur","Bhopal","Indore","Mumbai"],"min_followers":25000,"max_followers":220000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 310 HOUR),
   DATE_SUB(NOW(), INTERVAL 309 HOUR),
   DATE_SUB(NOW(), INTERVAL 308 HOUR),
   15,
   15,
   1,
   9,
   4,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 310 HOUR),
   DATE_SUB(NOW(), INTERVAL 103 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (314,
   'Creator invites - Indie Single Drop - Trending Audio Challenge',
   'email',
   'You are invited: Indie Single Drop - Trending Audio Challenge',
   'Budget Rs 14,000 - Rs 46,000 | Mumbai, Delhi, Bengaluru | Apply on Zusty',
   '<h2>Indie Single Drop - Trending Audio Challenge</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 14,000 - Rs 46,000 per creator</li><li>Platform: Instagram / reel x 1</li><li>Cities: Mumbai, Delhi, Bengaluru, Pune, Kolkata, Chandigarh</li><li>Minimum followers: 20,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 Instagram Reel using the official audio</li><li>2 Stories with the streaming link</li><li>Audio credit and artist tag retained</li></ul><p><a href="https://app.zustee.com/campaigns/314">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":314,"campaign_code":"ZC100314","categories":[10,15,1],"cities":["Mumbai","Delhi","Bengaluru","Pune","Kolkata","Chandigarh"],"min_followers":20000,"max_followers":400000,"kyc_verified":true}',
   'sending',
   DATE_SUB(NOW(), INTERVAL 118 HOUR),
   DATE_SUB(NOW(), INTERVAL 117 HOUR),
   NULL,
   11,
   11,
   0,
   8,
   3,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 118 HOUR),
   DATE_SUB(NOW(), INTERVAL 39 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (315,
   'Creator invites - Crack CAT Quant - 60 Second Shorts',
   'email',
   'You are invited: Crack CAT Quant - 60 Second Shorts',
   'Budget Rs 48,000 - Rs 130,000 | Delhi, Kanpur, Lucknow | Apply on Zusty',
   '<h2>Crack CAT Quant - 60 Second Shorts</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 48,000 - Rs 130,000 per creator</li><li>Platform: Youtube / short x 6</li><li>Cities: Delhi, Kanpur, Lucknow, Indore, Pune, Hyderabad</li><li>Minimum followers: 40,000</li></ul><p><strong>Deliverables</strong></p><ul><li>6 YouTube Shorts (under 60s each)</li><li>1 pinned comment with the course link</li><li>Solutions validated by the academic team</li></ul><p><a href="https://app.zustee.com/campaigns/315">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":315,"campaign_code":"ZC100315","categories":[11],"cities":["Delhi","Kanpur","Lucknow","Indore","Pune","Hyderabad"],"min_followers":40000,"max_followers":500000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 1846 HOUR),
   DATE_SUB(NOW(), INTERVAL 1845 HOUR),
   DATE_SUB(NOW(), INTERVAL 1844 HOUR),
   12,
   11,
   1,
   7,
   3,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 1846 HOUR),
   DATE_SUB(NOW(), INTERVAL 615 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (316,
   'Creator invites - Work From Home Woes - Branded Sketch Reels',
   'email',
   'You are invited: Work From Home Woes - Branded Sketch Reels',
   'Budget Rs 35,000 - Rs 95,000 | Mumbai, Delhi, Bengaluru | Apply on Zusty',
   '<h2>Work From Home Woes - Branded Sketch Reels</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 35,000 - Rs 95,000 per creator</li><li>Platform: Instagram / reel x 1</li><li>Cities: Mumbai, Delhi, Bengaluru, Pune, Hyderabad, Chandigarh</li><li>Minimum followers: 50,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 Instagram Reel sketch (40-70s)</li><li>2 Stories with the product link</li><li>Product visible for 5 continuous seconds</li></ul><p><a href="https://app.zustee.com/campaigns/316">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":316,"campaign_code":"ZC100316","categories":[15,1,6],"cities":["Mumbai","Delhi","Bengaluru","Pune","Hyderabad","Chandigarh"],"min_followers":50000,"max_followers":500000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 94 HOUR),
   DATE_SUB(NOW(), INTERVAL 93 HOUR),
   DATE_SUB(NOW(), INTERVAL 92 HOUR),
   13,
   13,
   1,
   8,
   4,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 94 HOUR),
   DATE_SUB(NOW(), INTERVAL 31 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (317,
   'Creator invites - Handmade Ceramic Studio - Process Carousels',
   'email',
   '[Draft] Invitation: Handmade Ceramic Studio - Process Carousels',
   'Budget Rs 20,000 - Rs 58,000 | Chennai, Bengaluru, Kochi | Apply on Zusty',
   '<h2>Handmade Ceramic Studio - Process Carousels</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 20,000 - Rs 58,000 per creator</li><li>Platform: Instagram / post x 2</li><li>Cities: Chennai, Bengaluru, Kochi, Goa</li><li>Minimum followers: 15,000</li></ul><p><strong>Deliverables</strong></p><ul><li>2 Instagram carousels (process documentation)</li><li>8 behind-the-scenes Stories</li><li>20 edited stills for the studio archive</li></ul><p><a href="https://app.zustee.com/campaigns/317">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":317,"campaign_code":"ZC100317","categories":[16,1],"cities":["Chennai","Bengaluru","Kochi","Goa"],"min_followers":15000,"max_followers":150000,"kyc_verified":true}',
   'draft',
   NULL,
   NULL,
   NULL,
   9,
   0,
   0,
   0,
   0,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 166 HOUR),
   DATE_SUB(NOW(), INTERVAL 55 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (318,
   'Creator invites - Millet Snack Range - Taste Test Reels',
   'email',
   'You are invited: Millet Snack Range - Taste Test Reels',
   'Budget Rs 24,000 - Rs 68,000 | Hyderabad, Bengaluru, Chennai | Apply on Zusty',
   '<h2>Millet Snack Range - Taste Test Reels</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 24,000 - Rs 68,000 per creator</li><li>Platform: Instagram / reel x 2</li><li>Cities: Hyderabad, Bengaluru, Chennai, Mumbai, Pune, Indore</li><li>Minimum followers: 25,000</li></ul><p><strong>Deliverables</strong></p><ul><li>2 Instagram Reels (30-45s each)</li><li>4 Stories with the D2C store link</li><li>Nutrition panel on screen for 3 seconds</li></ul><p><a href="https://app.zustee.com/campaigns/318">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":318,"campaign_code":"ZC100318","categories":[4,9],"cities":["Hyderabad","Bengaluru","Chennai","Mumbai","Pune","Indore"],"min_followers":25000,"max_followers":250000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 238 HOUR),
   DATE_SUB(NOW(), INTERVAL 237 HOUR),
   DATE_SUB(NOW(), INTERVAL 236 HOUR),
   7,
   7,
   0,
   5,
   1,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 238 HOUR),
   DATE_SUB(NOW(), INTERVAL 79 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (319,
   'Creator invites - Monsoon Footwear Drop - Multi City Lookbook',
   'email',
   'You are invited: Monsoon Footwear Drop - Multi City Lookbook',
   'Budget Rs 38,000 - Rs 105,000 | Mumbai, Pune, Kochi | Apply on Zusty',
   '<h2>Monsoon Footwear Drop - Multi City Lookbook</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 38,000 - Rs 105,000 per creator</li><li>Platform: Instagram / bundle x 3</li><li>Cities: Mumbai, Pune, Kochi, Kolkata, Goa, Bengaluru</li><li>Minimum followers: 40,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 lookbook carousel (6 frames)</li><li>1 Reel with the waterproofing demo</li><li>5 Stories with sizing guidance</li><li>Raw stills for the brand lookbook</li></ul><p><a href="https://app.zustee.com/campaigns/319">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":319,"campaign_code":"ZC100319","categories":[2,1],"cities":["Mumbai","Pune","Kochi","Kolkata","Goa","Bengaluru"],"min_followers":40000,"max_followers":300000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 3622 HOUR),
   DATE_SUB(NOW(), INTERVAL 3621 HOUR),
   DATE_SUB(NOW(), INTERVAL 3620 HOUR),
   8,
   8,
   0,
   4,
   1,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 3622 HOUR),
   DATE_SUB(NOW(), INTERVAL 1207 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (320,
   'Creator invites - Smart Home Starter Kit - Cross Platform Review',
   'email',
   'You are invited: Smart Home Starter Kit - Cross Platform Review',
   'Budget Rs 140,000 - Rs 380,000 | Bengaluru, Hyderabad, Mumbai | Apply on Zusty',
   '<h2>Smart Home Starter Kit - Cross Platform Review</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 140,000 - Rs 380,000 per creator</li><li>Platform: Multi / review x 2</li><li>Cities: Bengaluru, Hyderabad, Mumbai, Delhi, Pune</li><li>Minimum followers: 100,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 YouTube setup and review (9-13 minutes)</li><li>1 Instagram Reel on the setup time claim</li><li>3 Stories with the launch offer</li><li>Setup timing shown unedited</li></ul><p><a href="https://app.zustee.com/campaigns/320">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":320,"campaign_code":"ZC100320","categories":[6,1],"cities":["Bengaluru","Hyderabad","Mumbai","Delhi","Pune"],"min_followers":100000,"max_followers":900000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 1414 HOUR),
   DATE_SUB(NOW(), INTERVAL 1413 HOUR),
   DATE_SUB(NOW(), INTERVAL 1412 HOUR),
   13,
   13,
   1,
   7,
   4,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 1414 HOUR),
   DATE_SUB(NOW(), INTERVAL 471 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (321,
   'Creator invites - Sunscreen SPF 50 - Daily Ritual Stories',
   'email',
   'You are invited: Sunscreen SPF 50 - Daily Ritual Stories',
   'Budget Rs 15,000 - Rs 42,000 | Chennai, Hyderabad, Bengaluru | Apply on Zusty',
   '<h2>Sunscreen SPF 50 - Daily Ritual Stories</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 15,000 - Rs 42,000 per creator</li><li>Platform: Instagram / story x 1</li><li>Cities: Chennai, Hyderabad, Bengaluru, Mumbai, Ahmedabad, Surat</li><li>Minimum followers: 15,000</li></ul><p><strong>Deliverables</strong></p><ul><li>6 frame Story set with the two finger rule</li><li>1 Q and A sticker frame</li><li>Story highlight retained for 30 days</li></ul><p><a href="https://app.zustee.com/campaigns/321">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":321,"campaign_code":"ZC100321","categories":[7,9],"cities":["Chennai","Hyderabad","Bengaluru","Mumbai","Ahmedabad","Surat"],"min_followers":15000,"max_followers":150000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 2830 HOUR),
   DATE_SUB(NOW(), INTERVAL 2829 HOUR),
   DATE_SUB(NOW(), INTERVAL 2828 HOUR),
   9,
   9,
   1,
   4,
   2,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 2830 HOUR),
   DATE_SUB(NOW(), INTERVAL 943 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (322,
   'Creator invites - Filter Coffee Culture - Chennai Photo Essays',
   'email',
   'You are invited: Filter Coffee Culture - Chennai Photo Essays',
   'Budget Rs 18,000 - Rs 50,000 | Chennai | Apply on Zusty',
   '<h2>Filter Coffee Culture - Chennai Photo Essays</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 18,000 - Rs 50,000 per creator</li><li>Platform: Instagram / post x 2</li><li>Cities: Chennai</li><li>Minimum followers: 20,000</li></ul><p><strong>Deliverables</strong></p><ul><li>2 Instagram photo-essay posts</li><li>4 Stories with the cafe location tags</li><li>12 edited stills for the brand library</li></ul><p><a href="https://app.zustee.com/campaigns/322">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":322,"campaign_code":"ZC100322","categories":[4,3],"cities":["Chennai"],"min_followers":20000,"max_followers":200000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 4174 HOUR),
   DATE_SUB(NOW(), INTERVAL 4173 HOUR),
   DATE_SUB(NOW(), INTERVAL 4172 HOUR),
   15,
   14,
   2,
   9,
   4,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 4174 HOUR),
   DATE_SUB(NOW(), INTERVAL 1391 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (323,
   'Creator invites - Marathon Prep Series - Long Form Training Vlogs',
   'email',
   'You are invited: Marathon Prep Series - Long Form Training Vlogs',
   'Budget Rs 110,000 - Rs 300,000 | Mumbai, Bengaluru, Pune | Apply on Zusty',
   '<h2>Marathon Prep Series - Long Form Training Vlogs</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 110,000 - Rs 300,000 per creator</li><li>Platform: Youtube / video x 3</li><li>Cities: Mumbai, Bengaluru, Pune, Hyderabad, Delhi</li><li>Minimum followers: 80,000</li></ul><p><strong>Deliverables</strong></p><ul><li>3 YouTube vlogs (10-18 minutes each)</li><li>3 Instagram Reel cut-downs</li><li>Weekly Stories through the 12 week block</li><li>Gait analysis footage shared with the brand</li></ul><p><a href="https://app.zustee.com/campaigns/323">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":323,"campaign_code":"ZC100323","categories":[5,9],"cities":["Mumbai","Bengaluru","Pune","Hyderabad","Delhi"],"min_followers":80000,"max_followers":700000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 454 HOUR),
   DATE_SUB(NOW(), INTERVAL 453 HOUR),
   DATE_SUB(NOW(), INTERVAL 452 HOUR),
   11,
   11,
   1,
   7,
   4,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 454 HOUR),
   DATE_SUB(NOW(), INTERVAL 151 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (324,
   'Creator invites - Goa Off Season Getaway - Creator Residency',
   'email',
   'You are invited: Goa Off Season Getaway - Creator Residency',
   'Budget Rs 70,000 - Rs 190,000 | Mumbai, Delhi, Bengaluru | Apply on Zusty',
   '<h2>Goa Off Season Getaway - Creator Residency</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 70,000 - Rs 190,000 per creator</li><li>Platform: Instagram / bundle x 4</li><li>Cities: Mumbai, Delhi, Bengaluru, Pune, Goa</li><li>Minimum followers: 60,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 Instagram Reel per creator</li><li>1 shared carousel post</li><li>Daily Story sets across 4 nights</li><li>Group content day with the brand photographer</li></ul><p><a href="https://app.zustee.com/campaigns/324">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":324,"campaign_code":"ZC100324","categories":[3,1,4],"cities":["Mumbai","Delhi","Bengaluru","Pune","Goa"],"min_followers":60000,"max_followers":450000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 910 HOUR),
   DATE_SUB(NOW(), INTERVAL 909 HOUR),
   DATE_SUB(NOW(), INTERVAL 908 HOUR),
   11,
   11,
   1,
   7,
   3,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 910 HOUR),
   DATE_SUB(NOW(), INTERVAL 303 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (325,
   'Creator invites - Weekend Ride Essentials - Rider Gear Reels',
   'email',
   '[Draft] Invitation: Weekend Ride Essentials - Rider Gear Reels',
   'Budget Rs 30,000 - Rs 85,000 | Bengaluru, Pune, Delhi | Apply on Zusty',
   '<h2>Weekend Ride Essentials - Rider Gear Reels</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 30,000 - Rs 85,000 per creator</li><li>Platform: Instagram / reel x 2</li><li>Cities: Bengaluru, Pune, Delhi, Chandigarh, Jaipur, Nagpur</li><li>Minimum followers: 30,000</li></ul><p><strong>Deliverables</strong></p><ul><li>2 Instagram Reels on the weekend ride kit</li><li>4 Stories with the gear checklist</li><li>Safety disclaimer in every caption</li></ul><p><a href="https://app.zustee.com/campaigns/325">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":325,"campaign_code":"ZC100325","categories":[14,3],"cities":["Bengaluru","Pune","Delhi","Chandigarh","Jaipur","Nagpur"],"min_followers":30000,"max_followers":300000,"kyc_verified":true}',
   'draft',
   NULL,
   NULL,
   NULL,
   7,
   0,
   0,
   0,
   0,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 70 HOUR),
   DATE_SUB(NOW(), INTERVAL 23 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (326,
   'Creator invites - Small Space Big Style - Rental Makeover Reels',
   'email',
   'You are invited: Small Space Big Style - Rental Makeover Reels',
   'Budget Rs 32,000 - Rs 88,000 | Bengaluru, Mumbai, Pune | Apply on Zusty',
   '<h2>Small Space Big Style - Rental Makeover Reels</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 32,000 - Rs 88,000 per creator</li><li>Platform: Instagram / reel x 2</li><li>Cities: Bengaluru, Mumbai, Pune, Hyderabad, Gurugram, Delhi</li><li>Minimum followers: 30,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 room reveal Reel with before and after</li><li>1 Reel on five no-damage hacks</li><li>5 Stories with shoppable product links</li><li>Before photos shared with the brand</li></ul><p><a href="https://app.zustee.com/campaigns/326">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":326,"campaign_code":"ZC100326","categories":[1,16],"cities":["Bengaluru","Mumbai","Pune","Hyderabad","Gurugram","Delhi"],"min_followers":30000,"max_followers":300000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 166 HOUR),
   DATE_SUB(NOW(), INTERVAL 165 HOUR),
   DATE_SUB(NOW(), INTERVAL 164 HOUR),
   12,
   12,
   1,
   7,
   3,
   1,
   1,
   DATE_SUB(NOW(), INTERVAL 166 HOUR),
   DATE_SUB(NOW(), INTERVAL 55 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (327,
   'Creator invites - Sleep Better in 21 Days - Wellness Series',
   'email',
   'You are invited: Sleep Better in 21 Days - Wellness Series',
   'Budget Rs 80,000 - Rs 210,000 | Mumbai, Delhi, Bengaluru | Apply on Zusty',
   '<h2>Sleep Better in 21 Days - Wellness Series</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 80,000 - Rs 210,000 per creator</li><li>Platform: Youtube / video x 2</li><li>Cities: Mumbai, Delhi, Bengaluru, Pune</li><li>Minimum followers: 60,000</li></ul><p><strong>Deliverables</strong></p><ul><li>2 YouTube videos (7-11 minutes each)</li><li>Sleep tracker screenshots at day 1 and day 21</li><li>2 Instagram Reel cut-downs</li></ul><p><a href="https://app.zustee.com/campaigns/327">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":327,"campaign_code":"ZC100327","categories":[9,5],"cities":["Mumbai","Delhi","Bengaluru","Pune"],"min_followers":60000,"max_followers":500000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 9310 HOUR),
   DATE_SUB(NOW(), INTERVAL 9309 HOUR),
   DATE_SUB(NOW(), INTERVAL 9308 HOUR),
   12,
   12,
   1,
   8,
   4,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 9310 HOUR),
   DATE_SUB(NOW(), INTERVAL 3103 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (328,
   'Creator invites - Wedding Season Chaos - Sketch Series',
   'email',
   'You are invited: Wedding Season Chaos - Sketch Series',
   'Budget Rs 28,000 - Rs 75,000 | Delhi, Jaipur, Lucknow | Apply on Zusty',
   '<h2>Wedding Season Chaos - Sketch Series</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 28,000 - Rs 75,000 per creator</li><li>Platform: Instagram / reel x 1</li><li>Cities: Delhi, Jaipur, Lucknow, Chandigarh, Mumbai</li><li>Minimum followers: 40,000</li></ul><p><strong>Deliverables</strong></p><ul><li>1 Instagram Reel sketch (45-70s)</li><li>3 Stories with the product link</li></ul><p><a href="https://app.zustee.com/campaigns/328">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":328,"campaign_code":"ZC100328","categories":[15,2],"cities":["Delhi","Jaipur","Lucknow","Chandigarh","Mumbai"],"min_followers":40000,"max_followers":400000,"kyc_verified":true}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 5782 HOUR),
   DATE_SUB(NOW(), INTERVAL 5781 HOUR),
   DATE_SUB(NOW(), INTERVAL 5780 HOUR),
   7,
   7,
   0,
   6,
   3,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 5782 HOUR),
   DATE_SUB(NOW(), INTERVAL 1927 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (329,
   'Creator invites - Cold Pressed Juice - UGC Content Library',
   'email',
   '[Draft] Invitation: Cold Pressed Juice - UGC Content Library',
   'Budget Rs 14,000 - Rs 40,000 | Hyderabad, Bengaluru, Mumbai | Apply on Zusty',
   '<h2>Cold Pressed Juice - UGC Content Library</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 14,000 - Rs 40,000 per creator</li><li>Platform: Instagram / ugc x 4</li><li>Cities: Hyderabad, Bengaluru, Mumbai, Pune</li><li>Minimum followers: 3,000</li></ul><p><strong>Deliverables</strong></p><ul><li>4 vertical UGC clips (12-20s each)</li><li>2 hook variants per clip</li><li>Raw files delivered over Drive</li></ul><p><a href="https://app.zustee.com/campaigns/329">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":329,"campaign_code":"ZC100329","categories":[4,5,9],"cities":["Hyderabad","Bengaluru","Mumbai","Pune"],"min_followers":3000,"max_followers":80000,"kyc_verified":true}',
   'draft',
   NULL,
   NULL,
   NULL,
   6,
   0,
   0,
   0,
   0,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 46 HOUR),
   DATE_SUB(NOW(), INTERVAL 15 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`, `status`, `scheduled_at`, `started_at`, `completed_at`, `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`, `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (330,
   'Creator invites - SaaS Founders Speak - LinkedIn Thought Leadership',
   'email',
   '[Draft] Invitation: SaaS Founders Speak - LinkedIn Thought Leadership',
   'Budget Rs 45,000 - Rs 125,000 | Bengaluru, Mumbai, Delhi | Apply on Zusty',
   '<h2>SaaS Founders Speak - LinkedIn Thought Leadership</h2><p>Hi {{creator_name}}, a brand on Zusty has shortlisted your profile for this campaign.</p><ul><li>Budget: Rs 45,000 - Rs 125,000 per creator</li><li>Platform: Linkedin / post x 2</li><li>Cities: Bengaluru, Mumbai, Delhi, Hyderabad, Pune, Chennai</li><li>Minimum followers: 10,000</li></ul><p><strong>Deliverables</strong></p><ul><li>2 long-form LinkedIn posts (900+ words)</li><li>1 comment-thread engagement pass over 48 hours</li><li>No ghostwriting - creator voice only</li></ul><p><a href="https://app.zustee.com/campaigns/330">View the full brief and apply</a></p><p style="font-size:12px;color:#888">You are receiving this because your Zusty profile matches the brief. <a href="https://app.zustee.com/settings/notifications">Manage invite preferences</a>.</p>',
   'creators',
   '{"campaign_id":330,"campaign_code":"ZC100330","categories":[6,12,11],"cities":["Bengaluru","Mumbai","Delhi","Hyderabad","Pune","Chennai"],"min_followers":10000,"max_followers":200000,"kyc_verified":true}',
   'scheduled',
   DATE_ADD(NOW(), INTERVAL 7 DAY),
   NULL,
   NULL,
   8,
   0,
   0,
   0,
   0,
   0,
   1,
   DATE_SUB(NOW(), INTERVAL 118 HOUR),
   DATE_SUB(NOW(), INTERVAL 39 HOUR))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);


-- ---------------------------------------------------------------------
-- 3. campaign_recipients 30001+ - invited creators per blast.
--    Written as INSERT ... SELECT joined to `users` so that
--    `destination` always matches the real seeded creator e-mail and
--    rows for missing users are skipped instead of failing the FK.
-- ---------------------------------------------------------------------

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30001 AS `id`, 301 AS `campaign_id`, 2002 AS `uid`, 'clicked' AS `status`, 'ae1d74b1651261d9e615e1bcba47146f' AS `token`, DATE_SUB(NOW(), INTERVAL 382 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 371 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 360 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30002 AS `id`, 301 AS `campaign_id`, 2007 AS `uid`, 'clicked' AS `status`, '53fa932c6d7fd1f2ac5e49bfd2fee323' AS `token`, DATE_SUB(NOW(), INTERVAL 380 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 362 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 345 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30003 AS `id`, 301 AS `campaign_id`, 2012 AS `uid`, 'opened' AS `status`, '3866a7dbe8b7f42aee7871161c5cf0ed' AS `token`, DATE_SUB(NOW(), INTERVAL 377 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 347 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30004 AS `id`, 301 AS `campaign_id`, 2016 AS `uid`, 'opened' AS `status`, 'd47e10b6e6ba517f4e6984f372b740a6' AS `token`, DATE_SUB(NOW(), INTERVAL 368 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 339 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30005 AS `id`, 301 AS `campaign_id`, 2022 AS `uid`, 'opened' AS `status`, 'e1b42117a29428af970859cf5ea839ac' AS `token`, DATE_SUB(NOW(), INTERVAL 375 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 366 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30006 AS `id`, 301 AS `campaign_id`, 2026 AS `uid`, 'sent' AS `status`, 'f421c0337e7d91ad666d7d8c9b67f4c7' AS `token`, DATE_SUB(NOW(), INTERVAL 368 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30007 AS `id`, 301 AS `campaign_id`, 2031 AS `uid`, 'sent' AS `status`, '0bd4ff1386c971a0b5eb393d1fd72e96' AS `token`, DATE_SUB(NOW(), INTERVAL 374 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30008 AS `id`, 301 AS `campaign_id`, 2036 AS `uid`, 'clicked' AS `status`, 'f5dbc986405ff10da1f7d95912c9022e' AS `token`, DATE_SUB(NOW(), INTERVAL 377 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 367 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 339 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30009 AS `id`, 301 AS `campaign_id`, 2041 AS `uid`, 'opened' AS `status`, '73b879a3d6b96279c18be78327d60e36' AS `token`, DATE_SUB(NOW(), INTERVAL 369 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 357 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30010 AS `id`, 301 AS `campaign_id`, 2001 AS `uid`, 'sent' AS `status`, '9786129ff92a0119df1842f6f8df0b4a' AS `token`, DATE_SUB(NOW(), INTERVAL 367 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30011 AS `id`, 301 AS `campaign_id`, 2006 AS `uid`, 'failed' AS `status`, '52a61e3b81b056b9b500354339bd8d8e' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 552 5.2.2 - recipient mailbox is over quota' AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30012 AS `id`, 302 AS `campaign_id`, 2009 AS `uid`, 'clicked' AS `status`, '6784f71ec74642cfc11f5a6eadfce894' AS `token`, DATE_SUB(NOW(), INTERVAL 248 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 243 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 239 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30013 AS `id`, 302 AS `campaign_id`, 2014 AS `uid`, 'opened' AS `status`, 'be9e4730f4b82c8e41e77b34ce7caf6a' AS `token`, DATE_SUB(NOW(), INTERVAL 261 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 250 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30014 AS `id`, 302 AS `campaign_id`, 2018 AS `uid`, 'opened' AS `status`, 'dda7e2c9120d3e6d558983884268cfc4' AS `token`, DATE_SUB(NOW(), INTERVAL 259 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 256 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30015 AS `id`, 302 AS `campaign_id`, 2024 AS `uid`, 'opened' AS `status`, '2f8a5b27c93ea3b22ca38d68559f18ff' AS `token`, DATE_SUB(NOW(), INTERVAL 250 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 227 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30016 AS `id`, 302 AS `campaign_id`, 2028 AS `uid`, 'sent' AS `status`, '1f0fc59c20e10420eb9756ec1e9560ed' AS `token`, DATE_SUB(NOW(), INTERVAL 258 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30017 AS `id`, 302 AS `campaign_id`, 2033 AS `uid`, 'sent' AS `status`, '787b0f0180b66dd20ac52aa2d202a6c7' AS `token`, DATE_SUB(NOW(), INTERVAL 249 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30018 AS `id`, 302 AS `campaign_id`, 2038 AS `uid`, 'clicked' AS `status`, '392b293feb59f3833f77bfbcc6199a9c' AS `token`, DATE_SUB(NOW(), INTERVAL 245 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 224 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 223 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30019 AS `id`, 302 AS `campaign_id`, 2043 AS `uid`, 'opened' AS `status`, '9b54336b89296494c39861c36841c040' AS `token`, DATE_SUB(NOW(), INTERVAL 262 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 237 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30020 AS `id`, 302 AS `campaign_id`, 2003 AS `uid`, 'sent' AS `status`, '5c35e0eac1fa743405a4a252b021d471' AS `token`, DATE_SUB(NOW(), INTERVAL 244 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30021 AS `id`, 302 AS `campaign_id`, 2008 AS `uid`, 'unsubscribed' AS `status`, '90be5fbaf9c66ce8dd8b62814c81c3f0' AS `token`, DATE_SUB(NOW(), INTERVAL 255 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 232 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30022 AS `id`, 302 AS `campaign_id`, 2015 AS `uid`, 'bounced' AS `status`, '35372b011eaff04aa90f42c887d85f84' AS `token`, DATE_SUB(NOW(), INTERVAL 249 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30023 AS `id`, 302 AS `campaign_id`, 2019 AS `uid`, 'clicked' AS `status`, '71aa1562a82b89f14f59130ee9eb52b5' AS `token`, DATE_SUB(NOW(), INTERVAL 253 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 237 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 236 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30024 AS `id`, 302 AS `campaign_id`, 2025 AS `uid`, 'opened' AS `status`, '54b739b5c1d316701f296cbec58b97ff' AS `token`, DATE_SUB(NOW(), INTERVAL 255 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 241 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30025 AS `id`, 302 AS `campaign_id`, 2029 AS `uid`, 'sent' AS `status`, '65eab59f4b33f38220525cbd712df87c' AS `token`, DATE_SUB(NOW(), INTERVAL 257 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30026 AS `id`, 303 AS `campaign_id`, 2016 AS `uid`, 'opened' AS `status`, 'be32991d43170134a08cd99118e4b146' AS `token`, DATE_SUB(NOW(), INTERVAL 1237 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1217 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30027 AS `id`, 303 AS `campaign_id`, 2020 AS `uid`, 'opened' AS `status`, '37dc53dfec38ca81a96afbd4f91e40f1' AS `token`, DATE_SUB(NOW(), INTERVAL 1241 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1225 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30028 AS `id`, 303 AS `campaign_id`, 2026 AS `uid`, 'opened' AS `status`, '2e107e4035c8e80e75c151a267952040' AS `token`, DATE_SUB(NOW(), INTERVAL 1232 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1212 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30029 AS `id`, 303 AS `campaign_id`, 2030 AS `uid`, 'sent' AS `status`, 'df252d139ab944bc4e39ea3036b2df1e' AS `token`, DATE_SUB(NOW(), INTERVAL 1228 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30030 AS `id`, 303 AS `campaign_id`, 2035 AS `uid`, 'sent' AS `status`, 'a7105b55c9d22bf3ef68e3a8beca414e' AS `token`, DATE_SUB(NOW(), INTERVAL 1234 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30031 AS `id`, 303 AS `campaign_id`, 2040 AS `uid`, 'clicked' AS `status`, '851a4b5fd25354e314def9b9155218f7' AS `token`, DATE_SUB(NOW(), INTERVAL 1244 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1219 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1218 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30032 AS `id`, 303 AS `campaign_id`, 2045 AS `uid`, 'opened' AS `status`, '722808861ac4afc1623bc4b72147ea17' AS `token`, DATE_SUB(NOW(), INTERVAL 1241 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1236 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30033 AS `id`, 303 AS `campaign_id`, 2005 AS `uid`, 'sent' AS `status`, '94835bf43c3bbabcdcdf5b911d13b57d' AS `token`, DATE_SUB(NOW(), INTERVAL 1242 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30034 AS `id`, 303 AS `campaign_id`, 2010 AS `uid`, 'unsubscribed' AS `status`, 'cc14426a1bfc7048f905d626e6d55f3b' AS `token`, DATE_SUB(NOW(), INTERVAL 1246 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1218 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30035 AS `id`, 304 AS `campaign_id`, 2022 AS `uid`, 'opened' AS `status`, 'ab0129ca8ebf9a2a2cfbe504e095e30d' AS `token`, DATE_SUB(NOW(), INTERVAL 1627 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1606 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30036 AS `id`, 304 AS `campaign_id`, 2028 AS `uid`, 'opened' AS `status`, '37313e8b9c6ff4b043966ba22fd905b2' AS `token`, DATE_SUB(NOW(), INTERVAL 1624 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1611 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30037 AS `id`, 304 AS `campaign_id`, 2032 AS `uid`, 'sent' AS `status`, '26bc15158d97e36f446d96fe9c970962' AS `token`, DATE_SUB(NOW(), INTERVAL 1612 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30038 AS `id`, 304 AS `campaign_id`, 2037 AS `uid`, 'sent' AS `status`, 'da3242156c85a69b9c6704553dd37b1e' AS `token`, DATE_SUB(NOW(), INTERVAL 1621 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30039 AS `id`, 304 AS `campaign_id`, 2042 AS `uid`, 'clicked' AS `status`, '093bda3a224f3253c73fe138910f24b5' AS `token`, DATE_SUB(NOW(), INTERVAL 1614 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1600 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1597 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30040 AS `id`, 304 AS `campaign_id`, 2002 AS `uid`, 'opened' AS `status`, '8924065a4b99be23e453022854ef210c' AS `token`, DATE_SUB(NOW(), INTERVAL 1630 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1628 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30041 AS `id`, 304 AS `campaign_id`, 2007 AS `uid`, 'sent' AS `status`, '504254a98f829236c09cd9aaea34c4d3' AS `token`, DATE_SUB(NOW(), INTERVAL 1621 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30042 AS `id`, 304 AS `campaign_id`, 2012 AS `uid`, 'unsubscribed' AS `status`, '33650d981f917b684f6bfc25190efa7c' AS `token`, DATE_SUB(NOW(), INTERVAL 1627 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1622 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30043 AS `id`, 305 AS `campaign_id`, 2030 AS `uid`, 'opened' AS `status`, '26ede2e991cc6128c7f19f35b1393483' AS `token`, DATE_SUB(NOW(), INTERVAL 177 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 155 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30044 AS `id`, 305 AS `campaign_id`, 2034 AS `uid`, 'sent' AS `status`, 'b9b5740c0b76b6417b3adc6f0e0b5645' AS `token`, DATE_SUB(NOW(), INTERVAL 179 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30045 AS `id`, 305 AS `campaign_id`, 2039 AS `uid`, 'sent' AS `status`, '54ab1a01b714c8c6ec94e1d4ebd7dee4' AS `token`, DATE_SUB(NOW(), INTERVAL 173 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30046 AS `id`, 305 AS `campaign_id`, 2044 AS `uid`, 'clicked' AS `status`, 'b1fb8f614699925e1ecb87a7dfaeccea' AS `token`, DATE_SUB(NOW(), INTERVAL 181 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 152 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 151 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30047 AS `id`, 305 AS `campaign_id`, 2004 AS `uid`, 'opened' AS `status`, 'b1bd22012cbc02cd8752a2d8a20843af' AS `token`, DATE_SUB(NOW(), INTERVAL 178 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 165 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30048 AS `id`, 305 AS `campaign_id`, 2009 AS `uid`, 'sent' AS `status`, 'b2df27ca67d2e22dbd9024df6b9ffa99' AS `token`, DATE_SUB(NOW(), INTERVAL 190 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30049 AS `id`, 305 AS `campaign_id`, 2014 AS `uid`, 'unsubscribed' AS `status`, 'f36db4ce25b44edf002c9ad1ee439a00' AS `token`, DATE_SUB(NOW(), INTERVAL 175 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 155 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30050 AS `id`, 305 AS `campaign_id`, 2021 AS `uid`, 'bounced' AS `status`, '9e852f8c3df48acf79f546f4ef005709' AS `token`, DATE_SUB(NOW(), INTERVAL 185 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30051 AS `id`, 305 AS `campaign_id`, 2024 AS `uid`, 'clicked' AS `status`, '2677902cae5615ca1d96b8bb52c523fe' AS `token`, DATE_SUB(NOW(), INTERVAL 189 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 177 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 168 HOUR) AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30052 AS `id`, 306 AS `campaign_id`, 2036 AS `uid`, 'sent' AS `status`, '6d204ae190f3ea7a65ef7ac802fccada' AS `token`, DATE_SUB(NOW(), INTERVAL 134 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30053 AS `id`, 306 AS `campaign_id`, 2041 AS `uid`, 'sent' AS `status`, 'd7259720befdfe2dfeb57567af8c4e56' AS `token`, DATE_SUB(NOW(), INTERVAL 142 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30054 AS `id`, 306 AS `campaign_id`, 2001 AS `uid`, 'clicked' AS `status`, 'f9a50f2202e3a8fb104dfe257c330e12' AS `token`, DATE_SUB(NOW(), INTERVAL 131 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 110 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 106 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30055 AS `id`, 306 AS `campaign_id`, 2006 AS `uid`, 'opened' AS `status`, 'b0ad10e3eba828eff5c0931227c70a1f' AS `token`, DATE_SUB(NOW(), INTERVAL 137 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 128 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30056 AS `id`, 306 AS `campaign_id`, 2011 AS `uid`, 'sent' AS `status`, '3530799ee0a5055c4327ff4dbc89166a' AS `token`, DATE_SUB(NOW(), INTERVAL 132 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30057 AS `id`, 306 AS `campaign_id`, 2016 AS `uid`, 'unsubscribed' AS `status`, 'cf98d3fc8aee4353fe5b199cceac2083' AS `token`, DATE_SUB(NOW(), INTERVAL 139 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 112 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30058 AS `id`, 306 AS `campaign_id`, 2023 AS `uid`, 'bounced' AS `status`, 'c457f3d5cda20e527d3287381625079b' AS `token`, DATE_SUB(NOW(), INTERVAL 126 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30059 AS `id`, 306 AS `campaign_id`, 2026 AS `uid`, 'clicked' AS `status`, '3439a197389616670fc2e014a53c4a23' AS `token`, DATE_SUB(NOW(), INTERVAL 133 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 107 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 106 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30060 AS `id`, 306 AS `campaign_id`, 2033 AS `uid`, 'opened' AS `status`, '4a8007ebd7eac43e58403bfabc4ed0d2' AS `token`, DATE_SUB(NOW(), INTERVAL 128 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 101 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30061 AS `id`, 306 AS `campaign_id`, 2037 AS `uid`, 'sent' AS `status`, '3f92905e47c9e7ff7448fa9f1e969e36' AS `token`, DATE_SUB(NOW(), INTERVAL 131 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30062 AS `id`, 307 AS `campaign_id`, 2043 AS `uid`, 'sent' AS `status`, 'd2d15b49ff5b4d0b66d63005d67a53b7' AS `token`, DATE_SUB(NOW(), INTERVAL 1038 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30063 AS `id`, 307 AS `campaign_id`, 2003 AS `uid`, 'clicked' AS `status`, 'd980958d6576f733a9b70e87d3e4c58a' AS `token`, DATE_SUB(NOW(), INTERVAL 1046 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1018 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1017 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30064 AS `id`, 307 AS `campaign_id`, 2008 AS `uid`, 'opened' AS `status`, '96d6f90d1d45afffa692f9597a6b7b54' AS `token`, DATE_SUB(NOW(), INTERVAL 1048 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1024 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30065 AS `id`, 307 AS `campaign_id`, 2013 AS `uid`, 'sent' AS `status`, '04aa8c7637527a1875b11d7043455c6f' AS `token`, DATE_SUB(NOW(), INTERVAL 1037 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30066 AS `id`, 307 AS `campaign_id`, 2018 AS `uid`, 'unsubscribed' AS `status`, '27dafc98e58adf00de5bacfb0a0696f2' AS `token`, DATE_SUB(NOW(), INTERVAL 1046 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1024 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30067 AS `id`, 307 AS `campaign_id`, 2025 AS `uid`, 'bounced' AS `status`, 'bce7bc900715601a047734ea2690eaa6' AS `token`, DATE_SUB(NOW(), INTERVAL 1054 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30068 AS `id`, 307 AS `campaign_id`, 2028 AS `uid`, 'clicked' AS `status`, '215cf0045d8f6fc9ec47a0cece642381' AS `token`, DATE_SUB(NOW(), INTERVAL 1042 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1033 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1002 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30069 AS `id`, 307 AS `campaign_id`, 2035 AS `uid`, 'opened' AS `status`, 'cdc8f9e3964713c3c966203065868153' AS `token`, DATE_SUB(NOW(), INTERVAL 1054 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1031 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30070 AS `id`, 307 AS `campaign_id`, 2039 AS `uid`, 'sent' AS `status`, '8b1888e1f051aff6368537798b8cb10a' AS `token`, DATE_SUB(NOW(), INTERVAL 1038 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30071 AS `id`, 307 AS `campaign_id`, 2045 AS `uid`, 'clicked' AS `status`, '9ba8c272eb5be10aeec8875a9d25d3e3' AS `token`, DATE_SUB(NOW(), INTERVAL 1052 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1042 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1042 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30072 AS `id`, 307 AS `campaign_id`, 2004 AS `uid`, 'clicked' AS `status`, 'deff037b3955f5489bdcc099736b8e7b' AS `token`, DATE_SUB(NOW(), INTERVAL 1050 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1040 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1031 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30073 AS `id`, 307 AS `campaign_id`, 2009 AS `uid`, 'opened' AS `status`, '0f04fdf65f37749c4423ffd6ca1ac618' AS `token`, DATE_SUB(NOW(), INTERVAL 1039 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1022 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30074 AS `id`, 308 AS `campaign_id`, 2005 AS `uid`, 'clicked' AS `status`, '2700c3692e68d79162ca778fa740e605' AS `token`, DATE_SUB(NOW(), INTERVAL 2300 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2272 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 2271 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30075 AS `id`, 308 AS `campaign_id`, 2010 AS `uid`, 'opened' AS `status`, '3894338ea846d16c3f4d475f76a6c5b5' AS `token`, DATE_SUB(NOW(), INTERVAL 2299 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2273 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30076 AS `id`, 308 AS `campaign_id`, 2015 AS `uid`, 'sent' AS `status`, '5fa0a6a40b72909da307612643fb172b' AS `token`, DATE_SUB(NOW(), INTERVAL 2291 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30077 AS `id`, 308 AS `campaign_id`, 2020 AS `uid`, 'unsubscribed' AS `status`, '8edb289c955db9cd2f9d304d37986459' AS `token`, DATE_SUB(NOW(), INTERVAL 2288 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2265 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30078 AS `id`, 308 AS `campaign_id`, 2027 AS `uid`, 'bounced' AS `status`, '91f370db452ceed428387ceb3c28508f' AS `token`, DATE_SUB(NOW(), INTERVAL 2301 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30079 AS `id`, 308 AS `campaign_id`, 2030 AS `uid`, 'clicked' AS `status`, '92f4e4cec06d2fe6d7257a0b9bf68455' AS `token`, DATE_SUB(NOW(), INTERVAL 2287 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2271 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 2256 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30080 AS `id`, 308 AS `campaign_id`, 2037 AS `uid`, 'opened' AS `status`, 'd01251c9c95d7662d1f2f886a0962e27' AS `token`, DATE_SUB(NOW(), INTERVAL 2299 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2279 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30081 AS `id`, 308 AS `campaign_id`, 2041 AS `uid`, 'sent' AS `status`, '739e8fb492dd6c73ac28c80650c5cd46' AS `token`, DATE_SUB(NOW(), INTERVAL 2299 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30082 AS `id`, 308 AS `campaign_id`, 2002 AS `uid`, 'clicked' AS `status`, '7d9d58ea1f72e2cf817fdc25825af629' AS `token`, DATE_SUB(NOW(), INTERVAL 2291 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2290 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 2272 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30083 AS `id`, 308 AS `campaign_id`, 2006 AS `uid`, 'failed' AS `status`, '6cd78a89747003e6d922a751d61f1b98' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 552 5.2.2 - recipient mailbox is over quota' AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30084 AS `id`, 309 AS `campaign_id`, 2012 AS `uid`, 'opened' AS `status`, '11618e2219dbf7a4d3acee3e74e2ac1c' AS `token`, DATE_SUB(NOW(), INTERVAL 206 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 181 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30085 AS `id`, 309 AS `campaign_id`, 2017 AS `uid`, 'sent' AS `status`, '90901d213fe659b56de35b44a25da46d' AS `token`, DATE_SUB(NOW(), INTERVAL 212 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30086 AS `id`, 309 AS `campaign_id`, 2022 AS `uid`, 'unsubscribed' AS `status`, '27bd29ba7327d02c69177e3f80ec89db' AS `token`, DATE_SUB(NOW(), INTERVAL 201 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 187 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30087 AS `id`, 309 AS `campaign_id`, 2029 AS `uid`, 'bounced' AS `status`, 'cf2f07e3ac3b1aa8e391151d2da43a53' AS `token`, DATE_SUB(NOW(), INTERVAL 213 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30088 AS `id`, 309 AS `campaign_id`, 2032 AS `uid`, 'clicked' AS `status`, 'b2ee959525e57a03e6ecc03e8bfa6b95' AS `token`, DATE_SUB(NOW(), INTERVAL 199 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 183 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 165 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30089 AS `id`, 309 AS `campaign_id`, 2039 AS `uid`, 'opened' AS `status`, '1ed5ee24dd5bb51df6e736a3546e25d3' AS `token`, DATE_SUB(NOW(), INTERVAL 198 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 183 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30090 AS `id`, 309 AS `campaign_id`, 2043 AS `uid`, 'sent' AS `status`, 'bfe0298ddb29ae596b8f6f9d7b77d3e5' AS `token`, DATE_SUB(NOW(), INTERVAL 213 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30091 AS `id`, 310 AS `campaign_id`, 2019 AS `uid`, 'sent' AS `status`, '35dbae8794ea3e19f8ec4d7aa9081f3c' AS `token`, DATE_SUB(NOW(), INTERVAL 786 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30092 AS `id`, 310 AS `campaign_id`, 2024 AS `uid`, 'unsubscribed' AS `status`, '06b5820e6a2adaf93709e7e079d37ca5' AS `token`, DATE_SUB(NOW(), INTERVAL 773 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 746 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30093 AS `id`, 310 AS `campaign_id`, 2031 AS `uid`, 'bounced' AS `status`, '0aa1c8363c820d2940a4aeaf9efe5503' AS `token`, DATE_SUB(NOW(), INTERVAL 773 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30094 AS `id`, 310 AS `campaign_id`, 2034 AS `uid`, 'clicked' AS `status`, '6d24756f17df1c8d4a35906c3d89c5d1' AS `token`, DATE_SUB(NOW(), INTERVAL 775 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 760 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 759 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30095 AS `id`, 310 AS `campaign_id`, 2041 AS `uid`, 'opened' AS `status`, '9ef993a86bcf4d9e36ddc39ff57105f6' AS `token`, DATE_SUB(NOW(), INTERVAL 775 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 765 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30096 AS `id`, 310 AS `campaign_id`, 2045 AS `uid`, 'sent' AS `status`, '189392b0bc6292cf38422da62897bc9c' AS `token`, DATE_SUB(NOW(), INTERVAL 790 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30097 AS `id`, 310 AS `campaign_id`, 2006 AS `uid`, 'clicked' AS `status`, '2b4e4e8e49a4c31b8539f6bf2948a82e' AS `token`, DATE_SUB(NOW(), INTERVAL 784 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 758 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 748 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30098 AS `id`, 310 AS `campaign_id`, 2010 AS `uid`, 'clicked' AS `status`, '1397b67a6b040dabbd18e20acddc9336' AS `token`, DATE_SUB(NOW(), INTERVAL 773 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 753 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 750 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30099 AS `id`, 310 AS `campaign_id`, 2015 AS `uid`, 'opened' AS `status`, '6ba2f66724111ea49e39d9d164fc8ccb' AS `token`, DATE_SUB(NOW(), INTERVAL 778 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 763 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30100 AS `id`, 310 AS `campaign_id`, 2021 AS `uid`, 'opened' AS `status`, '5d3adb08bd51ca125607a4437665db99' AS `token`, DATE_SUB(NOW(), INTERVAL 774 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 753 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30101 AS `id`, 310 AS `campaign_id`, 2025 AS `uid`, 'opened' AS `status`, '1e23a948494b6a9dd19299cbd295a244' AS `token`, DATE_SUB(NOW(), INTERVAL 772 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 764 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30102 AS `id`, 310 AS `campaign_id`, 2032 AS `uid`, 'sent' AS `status`, '9e3d238462210d46bc734b64ac7e4f06' AS `token`, DATE_SUB(NOW(), INTERVAL 776 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30103 AS `id`, 310 AS `campaign_id`, 2036 AS `uid`, 'sent' AS `status`, 'd1edd2e0ab57040a0dd3b9899a1f5b61' AS `token`, DATE_SUB(NOW(), INTERVAL 772 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30104 AS `id`, 310 AS `campaign_id`, 2040 AS `uid`, 'clicked' AS `status`, 'd4e4e94fef454c75fe6df47589eb1a9f' AS `token`, DATE_SUB(NOW(), INTERVAL 783 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 757 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 756 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30105 AS `id`, 310 AS `campaign_id`, 2001 AS `uid`, 'opened' AS `status`, 'e9ebac262ff03dfe8fc76458c9b08a0e' AS `token`, DATE_SUB(NOW(), INTERVAL 790 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 787 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30106 AS `id`, 311 AS `campaign_id`, 2026 AS `uid`, 'unsubscribed' AS `status`, '39bc88482f7a045f5bf0ad5ce0314599' AS `token`, DATE_SUB(NOW(), INTERVAL 7101 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 7096 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30107 AS `id`, 311 AS `campaign_id`, 2033 AS `uid`, 'bounced' AS `status`, 'a134128a4a9833a8d30656490a7b1b93' AS `token`, DATE_SUB(NOW(), INTERVAL 7085 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30108 AS `id`, 311 AS `campaign_id`, 2036 AS `uid`, 'clicked' AS `status`, 'e01ee1349246f32bdd166a2aba993074' AS `token`, DATE_SUB(NOW(), INTERVAL 7093 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 7065 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 7064 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30109 AS `id`, 311 AS `campaign_id`, 2043 AS `uid`, 'opened' AS `status`, '630bd9974b12c647da8a6a60c60e5ac8' AS `token`, DATE_SUB(NOW(), INTERVAL 7087 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 7084 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30110 AS `id`, 311 AS `campaign_id`, 2002 AS `uid`, 'sent' AS `status`, '20b8af0071f5679046ed82b6fef73b80' AS `token`, DATE_SUB(NOW(), INTERVAL 7089 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30111 AS `id`, 311 AS `campaign_id`, 2008 AS `uid`, 'clicked' AS `status`, 'f7615206b279195ada10d4fbb3478940' AS `token`, DATE_SUB(NOW(), INTERVAL 7089 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 7068 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 7057 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30112 AS `id`, 311 AS `campaign_id`, 2012 AS `uid`, 'clicked' AS `status`, 'ddb2d9ce0276fac58bb5274a4cf43830' AS `token`, DATE_SUB(NOW(), INTERVAL 7097 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 7088 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 7087 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30113 AS `id`, 311 AS `campaign_id`, 2017 AS `uid`, 'opened' AS `status`, '840ee89488bc98108c22c4988e67e819' AS `token`, DATE_SUB(NOW(), INTERVAL 7094 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 7084 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30114 AS `id`, 311 AS `campaign_id`, 2023 AS `uid`, 'opened' AS `status`, '59b59b5784733dcb90cc778abfe567d1' AS `token`, DATE_SUB(NOW(), INTERVAL 7088 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 7070 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30115 AS `id`, 311 AS `campaign_id`, 2027 AS `uid`, 'opened' AS `status`, '400bbcfffb4e6d1a660f6681f1fd6b8e' AS `token`, DATE_SUB(NOW(), INTERVAL 7086 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 7076 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30116 AS `id`, 312 AS `campaign_id`, 2035 AS `uid`, 'bounced' AS `status`, '42e05d3953db7d68dfdc948e0bf084bd' AS `token`, DATE_SUB(NOW(), INTERVAL 5086 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30117 AS `id`, 312 AS `campaign_id`, 2038 AS `uid`, 'clicked' AS `status`, '3e95e3616d8ac35845d663a635b64450' AS `token`, DATE_SUB(NOW(), INTERVAL 5077 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5047 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 5046 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30118 AS `id`, 312 AS `campaign_id`, 2045 AS `uid`, 'opened' AS `status`, '7a539efbdf7b03a5d542e158e220b422' AS `token`, DATE_SUB(NOW(), INTERVAL 5074 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5054 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30119 AS `id`, 312 AS `campaign_id`, 2004 AS `uid`, 'sent' AS `status`, 'e4d40e5141dcecf2cf9b04c430795de0' AS `token`, DATE_SUB(NOW(), INTERVAL 5083 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30120 AS `id`, 312 AS `campaign_id`, 2010 AS `uid`, 'clicked' AS `status`, 'ed5a40563fa4b1a39d3ea6ae20c3b48f' AS `token`, DATE_SUB(NOW(), INTERVAL 5070 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5049 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 5037 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30121 AS `id`, 312 AS `campaign_id`, 2014 AS `uid`, 'clicked' AS `status`, '0344c604e0ab3ab68c7addbc8ed9f98f' AS `token`, DATE_SUB(NOW(), INTERVAL 5085 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5070 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 5049 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30122 AS `id`, 312 AS `campaign_id`, 2019 AS `uid`, 'opened' AS `status`, 'b34c2fbfb93f33aa864b6b3d9370cf3f' AS `token`, DATE_SUB(NOW(), INTERVAL 5070 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5050 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30123 AS `id`, 312 AS `campaign_id`, 2025 AS `uid`, 'opened' AS `status`, '80d4fbba455d17f241c44b54750110ac' AS `token`, DATE_SUB(NOW(), INTERVAL 5085 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5057 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30124 AS `id`, 312 AS `campaign_id`, 2028 AS `uid`, 'opened' AS `status`, '64ec377be70a8a1cd3e5eefd3b0ef9db' AS `token`, DATE_SUB(NOW(), INTERVAL 5073 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5059 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30125 AS `id`, 312 AS `campaign_id`, 2036 AS `uid`, 'sent' AS `status`, 'd56d6fda00d546e9c1223cbb3b2780c4' AS `token`, DATE_SUB(NOW(), INTERVAL 5085 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30126 AS `id`, 312 AS `campaign_id`, 2040 AS `uid`, 'sent' AS `status`, 'e894c93bca70c651ef9d0dfbb2e17d5d' AS `token`, DATE_SUB(NOW(), INTERVAL 5072 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30127 AS `id`, 312 AS `campaign_id`, 2044 AS `uid`, 'clicked' AS `status`, '5b4aeb7eff0de9427a3888f332e8f24f' AS `token`, DATE_SUB(NOW(), INTERVAL 5072 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5043 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 5042 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30128 AS `id`, 312 AS `campaign_id`, 2005 AS `uid`, 'opened' AS `status`, 'aade1bb8c7645b325b68fb05cb228b38' AS `token`, DATE_SUB(NOW(), INTERVAL 5082 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5057 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30129 AS `id`, 312 AS `campaign_id`, 2011 AS `uid`, 'sent' AS `status`, '5bb7161418c51613c0e532431b9aaedf' AS `token`, DATE_SUB(NOW(), INTERVAL 5075 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30130 AS `id`, 313 AS `campaign_id`, 2040 AS `uid`, 'clicked' AS `status`, '81296134da6d32246d4be0e0de1d9e66' AS `token`, DATE_SUB(NOW(), INTERVAL 305 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 296 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 295 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30131 AS `id`, 313 AS `campaign_id`, 2002 AS `uid`, 'opened' AS `status`, '22beeab88a5cc5140368dede8ae0d51b' AS `token`, DATE_SUB(NOW(), INTERVAL 295 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 272 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30132 AS `id`, 313 AS `campaign_id`, 2006 AS `uid`, 'sent' AS `status`, '2a895d97cf4cf5f0a6862c1df61609ea' AS `token`, DATE_SUB(NOW(), INTERVAL 299 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30133 AS `id`, 313 AS `campaign_id`, 2012 AS `uid`, 'clicked' AS `status`, 'd2e59309a0338e5b457aa095006fd6e0' AS `token`, DATE_SUB(NOW(), INTERVAL 298 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 296 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 294 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30134 AS `id`, 313 AS `campaign_id`, 2016 AS `uid`, 'clicked' AS `status`, '4288a2ec7398d0f98920602d592bacfd' AS `token`, DATE_SUB(NOW(), INTERVAL 298 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 268 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 262 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30135 AS `id`, 313 AS `campaign_id`, 2021 AS `uid`, 'opened' AS `status`, 'eb0fe5fd756655e2bb1ce607b532fbc5' AS `token`, DATE_SUB(NOW(), INTERVAL 292 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 290 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30136 AS `id`, 313 AS `campaign_id`, 2027 AS `uid`, 'opened' AS `status`, '9006b0cb24386c1004f78bb499514957' AS `token`, DATE_SUB(NOW(), INTERVAL 304 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 282 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30137 AS `id`, 313 AS `campaign_id`, 2030 AS `uid`, 'opened' AS `status`, '47ead718dd09a29b265f6e245cc6acb1' AS `token`, DATE_SUB(NOW(), INTERVAL 306 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 297 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30138 AS `id`, 313 AS `campaign_id`, 2037 AS `uid`, 'sent' AS `status`, '08d79bd39fbca29cc2905660a03b0ccf' AS `token`, DATE_SUB(NOW(), INTERVAL 293 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30139 AS `id`, 313 AS `campaign_id`, 2042 AS `uid`, 'sent' AS `status`, 'de2f7bad53b289f4b22fa6f748ea5ef6' AS `token`, DATE_SUB(NOW(), INTERVAL 294 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30140 AS `id`, 313 AS `campaign_id`, 2001 AS `uid`, 'clicked' AS `status`, 'bfa5877027e48b41bba6a463eb589afd' AS `token`, DATE_SUB(NOW(), INTERVAL 304 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 302 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 281 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30141 AS `id`, 313 AS `campaign_id`, 2007 AS `uid`, 'opened' AS `status`, 'afb4a3b0fa998120c93c06d3a47eaaf5' AS `token`, DATE_SUB(NOW(), INTERVAL 304 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 292 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30142 AS `id`, 313 AS `campaign_id`, 2013 AS `uid`, 'sent' AS `status`, '0410f1fe70685de5d341288070f4456f' AS `token`, DATE_SUB(NOW(), INTERVAL 303 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30143 AS `id`, 313 AS `campaign_id`, 2015 AS `uid`, 'unsubscribed' AS `status`, 'e61c173aecacb2b1d8517a7e521bb44c' AS `token`, DATE_SUB(NOW(), INTERVAL 309 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 300 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30144 AS `id`, 313 AS `campaign_id`, 2022 AS `uid`, 'bounced' AS `status`, 'b0d848b6a5c6c59e4fb7b6f29d40177f' AS `token`, DATE_SUB(NOW(), INTERVAL 309 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30145 AS `id`, 314 AS `campaign_id`, 2004 AS `uid`, 'opened' AS `status`, 'bb4d2fd5c628a2313535cf1b77e64687' AS `token`, DATE_SUB(NOW(), INTERVAL 117 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 91 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30146 AS `id`, 314 AS `campaign_id`, 2008 AS `uid`, 'sent' AS `status`, '4eff2c799e32afef39f151fdf28cf14e' AS `token`, DATE_SUB(NOW(), INTERVAL 107 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30147 AS `id`, 314 AS `campaign_id`, 2014 AS `uid`, 'clicked' AS `status`, '7f67a207d8336971c5c1d230435e7af9' AS `token`, DATE_SUB(NOW(), INTERVAL 107 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 88 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 87 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30148 AS `id`, 314 AS `campaign_id`, 2018 AS `uid`, 'clicked' AS `status`, 'fd2c7c81512b95b6810e8c5f386a4a56' AS `token`, DATE_SUB(NOW(), INTERVAL 114 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 91 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 80 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30149 AS `id`, 314 AS `campaign_id`, 2023 AS `uid`, 'opened' AS `status`, 'bdebdb03f12f7aef2fe4210ea0e98c1d' AS `token`, DATE_SUB(NOW(), INTERVAL 107 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 106 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30150 AS `id`, 314 AS `campaign_id`, 2029 AS `uid`, 'opened' AS `status`, '08b78e15a2df6cdd406780c17f2d8708' AS `token`, DATE_SUB(NOW(), INTERVAL 110 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 97 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30151 AS `id`, 314 AS `campaign_id`, 2032 AS `uid`, 'opened' AS `status`, '1903217028e25517e349791953205865' AS `token`, DATE_SUB(NOW(), INTERVAL 106 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 76 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30152 AS `id`, 314 AS `campaign_id`, 2039 AS `uid`, 'sent' AS `status`, 'e5baddb303278a519c34329933ff9119' AS `token`, DATE_SUB(NOW(), INTERVAL 108 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30153 AS `id`, 314 AS `campaign_id`, 2044 AS `uid`, 'sent' AS `status`, 'd587292b01507bcd298356af59e658e3' AS `token`, DATE_SUB(NOW(), INTERVAL 116 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30154 AS `id`, 314 AS `campaign_id`, 2003 AS `uid`, 'clicked' AS `status`, '24baaff36f643ea10e1745a7f7a7945c' AS `token`, DATE_SUB(NOW(), INTERVAL 115 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 93 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 92 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30155 AS `id`, 314 AS `campaign_id`, 2009 AS `uid`, 'opened' AS `status`, '2f41b78e965934e60fc7ef7cb5d03eb0' AS `token`, DATE_SUB(NOW(), INTERVAL 112 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 85 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30156 AS `id`, 315 AS `campaign_id`, 2010 AS `uid`, 'sent' AS `status`, 'f39ff9259499f28f2328021885d5a9ce' AS `token`, DATE_SUB(NOW(), INTERVAL 1834 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30157 AS `id`, 315 AS `campaign_id`, 2016 AS `uid`, 'clicked' AS `status`, '84cd4499098754f85d69692bbba73d12' AS `token`, DATE_SUB(NOW(), INTERVAL 1839 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1824 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1822 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30158 AS `id`, 315 AS `campaign_id`, 2020 AS `uid`, 'clicked' AS `status`, '0215792dae5cc5c98a66dba79f1a5309' AS `token`, DATE_SUB(NOW(), INTERVAL 1843 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1836 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1835 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30159 AS `id`, 315 AS `campaign_id`, 2025 AS `uid`, 'opened' AS `status`, '144527eb4840f7f24b01a8d1416b4017' AS `token`, DATE_SUB(NOW(), INTERVAL 1828 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1820 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30160 AS `id`, 315 AS `campaign_id`, 2031 AS `uid`, 'opened' AS `status`, '9dd2a1526728bee37b9ecca30b2d3f70' AS `token`, DATE_SUB(NOW(), INTERVAL 1846 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1816 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30161 AS `id`, 315 AS `campaign_id`, 2034 AS `uid`, 'opened' AS `status`, 'e8b97fe58aa419c5b5f80dd5e09c3ff1' AS `token`, DATE_SUB(NOW(), INTERVAL 1843 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1826 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30162 AS `id`, 315 AS `campaign_id`, 2041 AS `uid`, 'sent' AS `status`, '9646b4dd3bab2553b0c1d579b67cacbb' AS `token`, DATE_SUB(NOW(), INTERVAL 1839 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30163 AS `id`, 315 AS `campaign_id`, 2001 AS `uid`, 'sent' AS `status`, 'e073a3da7ed91fb4a55b8ee0ae4c3f53' AS `token`, DATE_SUB(NOW(), INTERVAL 1836 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30164 AS `id`, 315 AS `campaign_id`, 2005 AS `uid`, 'clicked' AS `status`, '33ce5449b194123b6b9efee860769019' AS `token`, DATE_SUB(NOW(), INTERVAL 1835 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1822 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1801 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30165 AS `id`, 315 AS `campaign_id`, 2011 AS `uid`, 'opened' AS `status`, '53fb369424fef5d869394a6e33ce571c' AS `token`, DATE_SUB(NOW(), INTERVAL 1831 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1819 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30166 AS `id`, 315 AS `campaign_id`, 2017 AS `uid`, 'sent' AS `status`, '1483faea132eda25f460124db9e0f163' AS `token`, DATE_SUB(NOW(), INTERVAL 1840 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30167 AS `id`, 315 AS `campaign_id`, 2019 AS `uid`, 'failed' AS `status`, 'b71a4dd80b3d63ba0943612419ef3ebf' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 552 5.2.2 - recipient mailbox is over quota' AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30168 AS `id`, 316 AS `campaign_id`, 2018 AS `uid`, 'clicked' AS `status`, 'a02521e8f4a5371779d464d55518bf7d' AS `token`, DATE_SUB(NOW(), INTERVAL 83 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 57 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 56 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30169 AS `id`, 316 AS `campaign_id`, 2022 AS `uid`, 'clicked' AS `status`, '350adc034de012e2a518c781d50547fc' AS `token`, DATE_SUB(NOW(), INTERVAL 77 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 55 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 54 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30170 AS `id`, 316 AS `campaign_id`, 2027 AS `uid`, 'opened' AS `status`, '6ace8a6c70e08f069be193a8de7020cb' AS `token`, DATE_SUB(NOW(), INTERVAL 85 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 84 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30171 AS `id`, 316 AS `campaign_id`, 2033 AS `uid`, 'opened' AS `status`, '0d601869f08827672fad94ce9837bec2' AS `token`, DATE_SUB(NOW(), INTERVAL 87 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 74 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30172 AS `id`, 316 AS `campaign_id`, 2036 AS `uid`, 'opened' AS `status`, 'aa4b7e2deb1984af4367f05eec97c53b' AS `token`, DATE_SUB(NOW(), INTERVAL 90 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 77 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30173 AS `id`, 316 AS `campaign_id`, 2043 AS `uid`, 'sent' AS `status`, '24d65135f40820125b6e82e207513a9e' AS `token`, DATE_SUB(NOW(), INTERVAL 77 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30174 AS `id`, 316 AS `campaign_id`, 2003 AS `uid`, 'sent' AS `status`, 'b809a7e2cdf2d36bed93a7a81201bcfe' AS `token`, DATE_SUB(NOW(), INTERVAL 92 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30175 AS `id`, 316 AS `campaign_id`, 2007 AS `uid`, 'clicked' AS `status`, '001c5aee5ab584f8fabde4ad79502f58' AS `token`, DATE_SUB(NOW(), INTERVAL 89 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 75 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 66 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30176 AS `id`, 316 AS `campaign_id`, 2013 AS `uid`, 'opened' AS `status`, 'd3e4dbfcf485340c7ff745d27f6f6060' AS `token`, DATE_SUB(NOW(), INTERVAL 77 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 60 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30177 AS `id`, 316 AS `campaign_id`, 2019 AS `uid`, 'sent' AS `status`, 'd606c39280a323624ac1fc271219e8c8' AS `token`, DATE_SUB(NOW(), INTERVAL 79 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30178 AS `id`, 316 AS `campaign_id`, 2021 AS `uid`, 'unsubscribed' AS `status`, 'd044fe3f6f7be17ef5df3eb5a0b91569' AS `token`, DATE_SUB(NOW(), INTERVAL 90 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 85 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30179 AS `id`, 316 AS `campaign_id`, 2028 AS `uid`, 'bounced' AS `status`, '4dcb04205cc0a9d917ced9d3c9f59125' AS `token`, DATE_SUB(NOW(), INTERVAL 90 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30180 AS `id`, 316 AS `campaign_id`, 2034 AS `uid`, 'clicked' AS `status`, '20fe09212ffe463e86fcb8c63adf40c2' AS `token`, DATE_SUB(NOW(), INTERVAL 82 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 72 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 71 HOUR) AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30181 AS `id`, 317 AS `campaign_id`, 2024 AS `uid`, 'pending' AS `status`, '8d4444dd6709cbd9b6318dd4586fedb5' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30182 AS `id`, 317 AS `campaign_id`, 2029 AS `uid`, 'pending' AS `status`, '5fe5d89c23825de62ef3e6010ecfd6ce' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30183 AS `id`, 317 AS `campaign_id`, 2035 AS `uid`, 'pending' AS `status`, '17cc737ed063a9bdb7e27be3db53a2a9' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30184 AS `id`, 317 AS `campaign_id`, 2038 AS `uid`, 'pending' AS `status`, 'c1c6c324a63f134689cdd2a55d4ceb6d' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30185 AS `id`, 317 AS `campaign_id`, 2045 AS `uid`, 'pending' AS `status`, 'a3ee3da7c8dd5926d5c16d27c141225e' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30186 AS `id`, 317 AS `campaign_id`, 2005 AS `uid`, 'pending' AS `status`, '8fe0b781ff662bf469cee5cd9f480c7e' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30187 AS `id`, 317 AS `campaign_id`, 2009 AS `uid`, 'pending' AS `status`, '2a3f24271bbacc1a022de0b787dae409' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30188 AS `id`, 317 AS `campaign_id`, 2015 AS `uid`, 'pending' AS `status`, '4c60cdf2977377f7be935df31d2548f1' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30189 AS `id`, 317 AS `campaign_id`, 2020 AS `uid`, 'pending' AS `status`, 'd18fd2fd022b898586705363d6a6e7e4' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30190 AS `id`, 318 AS `campaign_id`, 2031 AS `uid`, 'opened' AS `status`, 'e87097e3ef5cb61ed6e0065a1321d3a6' AS `token`, DATE_SUB(NOW(), INTERVAL 237 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 220 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30191 AS `id`, 318 AS `campaign_id`, 2037 AS `uid`, 'opened' AS `status`, '0fe9ac3ff95801966d16d303ca1178b4' AS `token`, DATE_SUB(NOW(), INTERVAL 230 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 219 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30192 AS `id`, 318 AS `campaign_id`, 2040 AS `uid`, 'opened' AS `status`, 'a24cd24f6cb3e0582d8ec5f28b355a2b' AS `token`, DATE_SUB(NOW(), INTERVAL 222 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 210 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30193 AS `id`, 318 AS `campaign_id`, 2002 AS `uid`, 'sent' AS `status`, '56fabdb3a8ec4343500c6e426dceae29' AS `token`, DATE_SUB(NOW(), INTERVAL 220 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30194 AS `id`, 318 AS `campaign_id`, 2007 AS `uid`, 'sent' AS `status`, '0d52453dbcf15af00b2a7b7a75dcc853' AS `token`, DATE_SUB(NOW(), INTERVAL 220 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30195 AS `id`, 318 AS `campaign_id`, 2011 AS `uid`, 'clicked' AS `status`, 'e5e9cfb7750ea1eed54d352fc9f5d7aa' AS `token`, DATE_SUB(NOW(), INTERVAL 226 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 207 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 206 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30196 AS `id`, 318 AS `campaign_id`, 2017 AS `uid`, 'opened' AS `status`, 'ed7c46d4a6fd07f651cf145f42b3022f' AS `token`, DATE_SUB(NOW(), INTERVAL 237 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 232 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30197 AS `id`, 319 AS `campaign_id`, 2039 AS `uid`, 'opened' AS `status`, '994c28ccd962acb69e9ded03d815a6e5' AS `token`, DATE_SUB(NOW(), INTERVAL 3608 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 3588 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30198 AS `id`, 319 AS `campaign_id`, 2042 AS `uid`, 'opened' AS `status`, '94e8a5cdee723f040fc17528d5fd619e' AS `token`, DATE_SUB(NOW(), INTERVAL 3615 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 3594 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30199 AS `id`, 319 AS `campaign_id`, 2004 AS `uid`, 'sent' AS `status`, '65bdad556cd23e1651862798d32ca539' AS `token`, DATE_SUB(NOW(), INTERVAL 3604 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30200 AS `id`, 319 AS `campaign_id`, 2009 AS `uid`, 'sent' AS `status`, '17557c7e349ef365860d7e2681e91e80' AS `token`, DATE_SUB(NOW(), INTERVAL 3604 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30201 AS `id`, 319 AS `campaign_id`, 2013 AS `uid`, 'clicked' AS `status`, '54f4000195e0ebc3940d0ee63d31aed0' AS `token`, DATE_SUB(NOW(), INTERVAL 3614 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 3611 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 3601 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30202 AS `id`, 319 AS `campaign_id`, 2019 AS `uid`, 'opened' AS `status`, 'b47dd1daba6a2ebd73d734426c1d805b' AS `token`, DATE_SUB(NOW(), INTERVAL 3622 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 3598 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30203 AS `id`, 319 AS `campaign_id`, 2024 AS `uid`, 'sent' AS `status`, 'b2e9d4333927c579999977f4b41fdfb5' AS `token`, DATE_SUB(NOW(), INTERVAL 3616 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30204 AS `id`, 319 AS `campaign_id`, 2027 AS `uid`, 'unsubscribed' AS `status`, '3ef99c28d8123df2af7970a749dd73b1' AS `token`, DATE_SUB(NOW(), INTERVAL 3609 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 3580 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30205 AS `id`, 320 AS `campaign_id`, 2044 AS `uid`, 'opened' AS `status`, '33aad88424654b546d59baa2f9b8cd54' AS `token`, DATE_SUB(NOW(), INTERVAL 1410 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1398 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30206 AS `id`, 320 AS `campaign_id`, 2006 AS `uid`, 'sent' AS `status`, 'b4bc691b81fd817d1c56f8cd911fbfff' AS `token`, DATE_SUB(NOW(), INTERVAL 1409 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30207 AS `id`, 320 AS `campaign_id`, 2011 AS `uid`, 'sent' AS `status`, '7672b75321690db2f3d04b2bb066d02c' AS `token`, DATE_SUB(NOW(), INTERVAL 1412 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30208 AS `id`, 320 AS `campaign_id`, 2015 AS `uid`, 'clicked' AS `status`, 'daa1d9e7135205dcb3485ed4e42c781c' AS `token`, DATE_SUB(NOW(), INTERVAL 1407 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1399 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1398 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30209 AS `id`, 320 AS `campaign_id`, 2021 AS `uid`, 'opened' AS `status`, '8a8e6e466f0646d179356facad110c14' AS `token`, DATE_SUB(NOW(), INTERVAL 1410 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1403 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30210 AS `id`, 320 AS `campaign_id`, 2026 AS `uid`, 'sent' AS `status`, '79866ee6ac471a1c58d0d2367071c891' AS `token`, DATE_SUB(NOW(), INTERVAL 1412 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30211 AS `id`, 320 AS `campaign_id`, 2029 AS `uid`, 'unsubscribed' AS `status`, '3aaa6a60fec52dc5f7cdfa9dadb8f62f' AS `token`, DATE_SUB(NOW(), INTERVAL 1409 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1396 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30212 AS `id`, 320 AS `campaign_id`, 2036 AS `uid`, 'bounced' AS `status`, '5f6e04bbf896e520c99aaa028017aed8' AS `token`, DATE_SUB(NOW(), INTERVAL 1404 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30213 AS `id`, 320 AS `campaign_id`, 2041 AS `uid`, 'clicked' AS `status`, '00b791d42125749da2ef163964b17ae1' AS `token`, DATE_SUB(NOW(), INTERVAL 1411 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1387 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1374 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30214 AS `id`, 320 AS `campaign_id`, 2001 AS `uid`, 'opened' AS `status`, 'e88f946aa763c22bedda36bac62d5909' AS `token`, DATE_SUB(NOW(), INTERVAL 1402 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1386 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30215 AS `id`, 320 AS `campaign_id`, 2005 AS `uid`, 'sent' AS `status`, '0dc8b51ef92fb33de254ea52149d2f64' AS `token`, DATE_SUB(NOW(), INTERVAL 1407 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30216 AS `id`, 320 AS `campaign_id`, 2009 AS `uid`, 'clicked' AS `status`, 'a7db564adb675e7ba0b67446b12e3bcd' AS `token`, DATE_SUB(NOW(), INTERVAL 1400 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1391 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1390 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30217 AS `id`, 320 AS `campaign_id`, 2014 AS `uid`, 'clicked' AS `status`, 'f543b1d784ae146ed96a4cd7d10e98a0' AS `token`, DATE_SUB(NOW(), INTERVAL 1413 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 1392 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 1392 HOUR) AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30218 AS `id`, 321 AS `campaign_id`, 2008 AS `uid`, 'sent' AS `status`, '56e935b7c84d83836bf2a228139f1c68' AS `token`, DATE_SUB(NOW(), INTERVAL 2820 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30219 AS `id`, 321 AS `campaign_id`, 2013 AS `uid`, 'sent' AS `status`, '4e08b68df1f3ee88b27269f9e4e0a448' AS `token`, DATE_SUB(NOW(), INTERVAL 2829 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30220 AS `id`, 321 AS `campaign_id`, 2017 AS `uid`, 'clicked' AS `status`, 'f9ac582373da894cc94eb851218eb2f4' AS `token`, DATE_SUB(NOW(), INTERVAL 2822 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2817 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 2806 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30221 AS `id`, 321 AS `campaign_id`, 2023 AS `uid`, 'opened' AS `status`, 'd4bbe3add9918994faec39e553794e49' AS `token`, DATE_SUB(NOW(), INTERVAL 2817 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2791 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30222 AS `id`, 321 AS `campaign_id`, 2028 AS `uid`, 'sent' AS `status`, 'c3f97f5b8aa396df49e4a9fce959f16e' AS `token`, DATE_SUB(NOW(), INTERVAL 2823 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30223 AS `id`, 321 AS `campaign_id`, 2031 AS `uid`, 'unsubscribed' AS `status`, '73526245fa29b60f20689f0dd456095c' AS `token`, DATE_SUB(NOW(), INTERVAL 2817 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2799 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30224 AS `id`, 321 AS `campaign_id`, 2038 AS `uid`, 'bounced' AS `status`, 'bbb572d8936fef2885e44f7ca6a39211' AS `token`, DATE_SUB(NOW(), INTERVAL 2818 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30225 AS `id`, 321 AS `campaign_id`, 2043 AS `uid`, 'clicked' AS `status`, '0fa73277b276ed964ee367760a2a818d' AS `token`, DATE_SUB(NOW(), INTERVAL 2823 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2802 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 2801 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30226 AS `id`, 321 AS `campaign_id`, 2003 AS `uid`, 'opened' AS `status`, 'ec3da85baa49cae6253c26e7461ec4fb' AS `token`, DATE_SUB(NOW(), INTERVAL 2819 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 2800 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30227 AS `id`, 322 AS `campaign_id`, 2015 AS `uid`, 'sent' AS `status`, '71184814a132fb37c17e0ae12a83fc0e' AS `token`, DATE_SUB(NOW(), INTERVAL 4163 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30228 AS `id`, 322 AS `campaign_id`, 2019 AS `uid`, 'clicked' AS `status`, '0a45dc71c0eca13f65899c1062e148a5' AS `token`, DATE_SUB(NOW(), INTERVAL 4161 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 4159 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 4127 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30229 AS `id`, 322 AS `campaign_id`, 2025 AS `uid`, 'opened' AS `status`, 'b5b57a26f7a1fcba241cc356f2a76eab' AS `token`, DATE_SUB(NOW(), INTERVAL 4156 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 4130 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30230 AS `id`, 322 AS `campaign_id`, 2030 AS `uid`, 'sent' AS `status`, 'fced8de154c134ce614ce724c29c45ea' AS `token`, DATE_SUB(NOW(), INTERVAL 4165 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30231 AS `id`, 322 AS `campaign_id`, 2033 AS `uid`, 'unsubscribed' AS `status`, '00e930f23cfe224cc171270b1892386c' AS `token`, DATE_SUB(NOW(), INTERVAL 4158 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 4137 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30232 AS `id`, 322 AS `campaign_id`, 2040 AS `uid`, 'bounced' AS `status`, '34b3afa5794be105840ff1e21354f2be' AS `token`, DATE_SUB(NOW(), INTERVAL 4160 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30233 AS `id`, 322 AS `campaign_id`, 2045 AS `uid`, 'clicked' AS `status`, '73f0661bb8d4b5f30c1f800283574b39' AS `token`, DATE_SUB(NOW(), INTERVAL 4160 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 4130 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 4129 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30234 AS `id`, 322 AS `campaign_id`, 2005 AS `uid`, 'opened' AS `status`, '1882835047021c5014945b5356dc95c7' AS `token`, DATE_SUB(NOW(), INTERVAL 4171 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 4143 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30235 AS `id`, 322 AS `campaign_id`, 2009 AS `uid`, 'sent' AS `status`, '08feccf8fdd4e4a32a34dbe00173c6b9' AS `token`, DATE_SUB(NOW(), INTERVAL 4159 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30236 AS `id`, 322 AS `campaign_id`, 2013 AS `uid`, 'clicked' AS `status`, 'a6660732dd7e862d09ff6eed07fc6549' AS `token`, DATE_SUB(NOW(), INTERVAL 4161 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 4157 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 4142 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30237 AS `id`, 322 AS `campaign_id`, 2018 AS `uid`, 'clicked' AS `status`, 'd166624664d5bd79f08bc18629f86979' AS `token`, DATE_SUB(NOW(), INTERVAL 4173 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 4152 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 4145 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30238 AS `id`, 322 AS `campaign_id`, 2024 AS `uid`, 'opened' AS `status`, 'aa0ca92caa789cabf0b35296fde0aa8a' AS `token`, DATE_SUB(NOW(), INTERVAL 4156 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 4147 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30239 AS `id`, 322 AS `campaign_id`, 2028 AS `uid`, 'opened' AS `status`, '53ffc8445005ad132bf04c9a3f85634e' AS `token`, DATE_SUB(NOW(), INTERVAL 4163 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 4162 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30240 AS `id`, 322 AS `campaign_id`, 2034 AS `uid`, 'opened' AS `status`, 'c86f4fe8beffc8eaa441095a2e89e84c' AS `token`, DATE_SUB(NOW(), INTERVAL 4173 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 4168 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30241 AS `id`, 322 AS `campaign_id`, 2041 AS `uid`, 'failed' AS `status`, '7dd52e9c7a6b25c3a22d284a5e1625a1' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 421 4.7.0 - temporary deferral, retry limit reached' AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30242 AS `id`, 323 AS `campaign_id`, 2021 AS `uid`, 'clicked' AS `status`, '5612264c78e22060d1b84851bb982ce9' AS `token`, DATE_SUB(NOW(), INTERVAL 438 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 409 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 408 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30243 AS `id`, 323 AS `campaign_id`, 2027 AS `uid`, 'opened' AS `status`, 'e18c2b97f403f136fd931919f1eb07c1' AS `token`, DATE_SUB(NOW(), INTERVAL 454 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 441 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30244 AS `id`, 323 AS `campaign_id`, 2032 AS `uid`, 'sent' AS `status`, '0748f1561caecc4c23006368162bb5b1' AS `token`, DATE_SUB(NOW(), INTERVAL 443 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30245 AS `id`, 323 AS `campaign_id`, 2035 AS `uid`, 'unsubscribed' AS `status`, '91e84538b95c0343d9f73b53999683b5' AS `token`, DATE_SUB(NOW(), INTERVAL 449 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 431 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30246 AS `id`, 323 AS `campaign_id`, 2042 AS `uid`, 'bounced' AS `status`, 'e1314320261b57fb155477521af26170' AS `token`, DATE_SUB(NOW(), INTERVAL 439 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30247 AS `id`, 323 AS `campaign_id`, 2002 AS `uid`, 'clicked' AS `status`, '3e0c275ea6f3d205b01ffc86941024dc' AS `token`, DATE_SUB(NOW(), INTERVAL 444 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 428 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 416 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30248 AS `id`, 323 AS `campaign_id`, 2007 AS `uid`, 'opened' AS `status`, '296e4f0a663558a113265f65ea9717de' AS `token`, DATE_SUB(NOW(), INTERVAL 442 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 414 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30249 AS `id`, 323 AS `campaign_id`, 2011 AS `uid`, 'sent' AS `status`, 'c34d798e3f9b889b278128b4af722bd4' AS `token`, DATE_SUB(NOW(), INTERVAL 453 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30250 AS `id`, 323 AS `campaign_id`, 2015 AS `uid`, 'clicked' AS `status`, '6d40519e62dad03fee5d3a1b6e76de87' AS `token`, DATE_SUB(NOW(), INTERVAL 454 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 425 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 424 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30251 AS `id`, 323 AS `campaign_id`, 2020 AS `uid`, 'clicked' AS `status`, '3dcac272a361e2c5fa9c7288d1934c10' AS `token`, DATE_SUB(NOW(), INTERVAL 439 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 410 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 409 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30252 AS `id`, 323 AS `campaign_id`, 2026 AS `uid`, 'opened' AS `status`, 'c8752768cf8bfce2eab6929b1427ba9a' AS `token`, DATE_SUB(NOW(), INTERVAL 440 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 437 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30253 AS `id`, 324 AS `campaign_id`, 2029 AS `uid`, 'opened' AS `status`, 'e3bcb44fafd89dedf6c08bd932471320' AS `token`, DATE_SUB(NOW(), INTERVAL 900 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 881 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30254 AS `id`, 324 AS `campaign_id`, 2034 AS `uid`, 'sent' AS `status`, '6228fc7a70110ba73a4d64c35bc0d602' AS `token`, DATE_SUB(NOW(), INTERVAL 903 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30255 AS `id`, 324 AS `campaign_id`, 2037 AS `uid`, 'unsubscribed' AS `status`, '15a8c4c1faa24f84e5bf5a9f7713246d' AS `token`, DATE_SUB(NOW(), INTERVAL 896 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 866 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30256 AS `id`, 324 AS `campaign_id`, 2044 AS `uid`, 'bounced' AS `status`, 'adda3d816b34fc501dfb95255ba02936' AS `token`, DATE_SUB(NOW(), INTERVAL 897 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30257 AS `id`, 324 AS `campaign_id`, 2004 AS `uid`, 'clicked' AS `status`, '03a22df063ddcada848874d0ebd0b55f' AS `token`, DATE_SUB(NOW(), INTERVAL 906 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 896 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 895 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30258 AS `id`, 324 AS `campaign_id`, 2009 AS `uid`, 'opened' AS `status`, '184fc483a1e274f6aa2d4e1435076756' AS `token`, DATE_SUB(NOW(), INTERVAL 892 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 880 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30259 AS `id`, 324 AS `campaign_id`, 2013 AS `uid`, 'sent' AS `status`, 'f6daa0b1b8a7fe43c7730c731f60e80a' AS `token`, DATE_SUB(NOW(), INTERVAL 899 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30260 AS `id`, 324 AS `campaign_id`, 2017 AS `uid`, 'clicked' AS `status`, 'e030fe61be256c22570f0ef193637d62' AS `token`, DATE_SUB(NOW(), INTERVAL 895 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 868 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 865 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30261 AS `id`, 324 AS `campaign_id`, 2022 AS `uid`, 'clicked' AS `status`, '2f96c6b11ba511f11566912ff74e75da' AS `token`, DATE_SUB(NOW(), INTERVAL 901 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 880 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 864 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30262 AS `id`, 324 AS `campaign_id`, 2028 AS `uid`, 'opened' AS `status`, '2607873655ca70d827905bdd053e503b' AS `token`, DATE_SUB(NOW(), INTERVAL 899 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 896 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30263 AS `id`, 324 AS `campaign_id`, 2032 AS `uid`, 'opened' AS `status`, '0f1d0b22d375c34159ed175da37b08ff' AS `token`, DATE_SUB(NOW(), INTERVAL 902 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 878 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30264 AS `id`, 325 AS `campaign_id`, 2036 AS `uid`, 'pending' AS `status`, '08e48a9b83b0617c05d3bd419239fbc5' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30265 AS `id`, 325 AS `campaign_id`, 2039 AS `uid`, 'pending' AS `status`, '9b1d078ba9f6770533c67c8e6d68c74d' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30266 AS `id`, 325 AS `campaign_id`, 2001 AS `uid`, 'pending' AS `status`, 'fd63a65d0be7b217478fc7b8318785da' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30267 AS `id`, 325 AS `campaign_id`, 2006 AS `uid`, 'pending' AS `status`, '46258e330c28fbad6e6bed9601dd8e22' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30268 AS `id`, 325 AS `campaign_id`, 2011 AS `uid`, 'pending' AS `status`, '14e20c5cfe27b080cbce6a2767ef7650' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30269 AS `id`, 325 AS `campaign_id`, 2015 AS `uid`, 'pending' AS `status`, '23c0f2ad5a24b8002b87c3bb6cbfb37f' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30270 AS `id`, 325 AS `campaign_id`, 2019 AS `uid`, 'pending' AS `status`, '13e1793090c170db599dc787a81f59da' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30271 AS `id`, 326 AS `campaign_id`, 2041 AS `uid`, 'unsubscribed' AS `status`, '06fa497cb641d33a1d0c148adf0b97c5' AS `token`, DATE_SUB(NOW(), INTERVAL 149 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 142 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30272 AS `id`, 326 AS `campaign_id`, 2003 AS `uid`, 'bounced' AS `status`, 'f48bb816ade430d58f792f404422d695' AS `token`, DATE_SUB(NOW(), INTERVAL 164 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30273 AS `id`, 326 AS `campaign_id`, 2008 AS `uid`, 'clicked' AS `status`, 'bf29a083d82f0673f791e352e197370b' AS `token`, DATE_SUB(NOW(), INTERVAL 158 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 156 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 128 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30274 AS `id`, 326 AS `campaign_id`, 2013 AS `uid`, 'opened' AS `status`, 'd98d478bf17b97cdd04f7c61b7a55678' AS `token`, DATE_SUB(NOW(), INTERVAL 154 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 136 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30275 AS `id`, 326 AS `campaign_id`, 2017 AS `uid`, 'sent' AS `status`, '28076dc55fffe28c519d4806b5570d65' AS `token`, DATE_SUB(NOW(), INTERVAL 163 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30276 AS `id`, 326 AS `campaign_id`, 2021 AS `uid`, 'clicked' AS `status`, 'b79bd1b96929dc5fb995a3758caed446' AS `token`, DATE_SUB(NOW(), INTERVAL 150 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 122 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 110 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30277 AS `id`, 326 AS `campaign_id`, 2026 AS `uid`, 'clicked' AS `status`, '4c9bd7532df630a995f7757008467da5' AS `token`, DATE_SUB(NOW(), INTERVAL 156 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 131 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 130 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30278 AS `id`, 326 AS `campaign_id`, 2032 AS `uid`, 'opened' AS `status`, '63491038a85adc77f65402f7e31e4186' AS `token`, DATE_SUB(NOW(), INTERVAL 158 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 149 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30279 AS `id`, 326 AS `campaign_id`, 2036 AS `uid`, 'opened' AS `status`, 'e8778e28fe857019954fc99cbaf857e6' AS `token`, DATE_SUB(NOW(), INTERVAL 155 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 137 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30280 AS `id`, 326 AS `campaign_id`, 2042 AS `uid`, 'opened' AS `status`, '53ca176a87b25c2cf18acca38c9cd0fd' AS `token`, DATE_SUB(NOW(), INTERVAL 154 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 130 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30281 AS `id`, 326 AS `campaign_id`, 2004 AS `uid`, 'sent' AS `status`, '567209d6943e5055668f4d523c1325bf' AS `token`, DATE_SUB(NOW(), INTERVAL 156 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30282 AS `id`, 326 AS `campaign_id`, 2007 AS `uid`, 'sent' AS `status`, '50e48141b75e61bb845457e631b05706' AS `token`, DATE_SUB(NOW(), INTERVAL 163 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30283 AS `id`, 327 AS `campaign_id`, 2005 AS `uid`, 'bounced' AS `status`, '374912fdfa6afafca8195521e5c4bc9a' AS `token`, DATE_SUB(NOW(), INTERVAL 9292 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, 'SMTP 550 5.1.1 - address rejected by the receiving server' AS `error_message`
  UNION ALL
  SELECT 30284 AS `id`, 327 AS `campaign_id`, 2010 AS `uid`, 'clicked' AS `status`, '10632820cdb86483b8194d3699739d18' AS `token`, DATE_SUB(NOW(), INTERVAL 9304 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 9279 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 9278 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30285 AS `id`, 327 AS `campaign_id`, 2015 AS `uid`, 'opened' AS `status`, 'd03eb3d2205b737007e74b8faec2f925' AS `token`, DATE_SUB(NOW(), INTERVAL 9301 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 9298 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30286 AS `id`, 327 AS `campaign_id`, 2019 AS `uid`, 'sent' AS `status`, '64484570fa295f663f4fa55a33e47647' AS `token`, DATE_SUB(NOW(), INTERVAL 9292 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30287 AS `id`, 327 AS `campaign_id`, 2023 AS `uid`, 'clicked' AS `status`, 'a65df9c09ea0007ba2a8372abfebc8b7' AS `token`, DATE_SUB(NOW(), INTERVAL 9307 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 9300 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 9284 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30288 AS `id`, 327 AS `campaign_id`, 2028 AS `uid`, 'clicked' AS `status`, '184142c72eeb8db1b1e3693ddb343886' AS `token`, DATE_SUB(NOW(), INTERVAL 9299 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 9291 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 9290 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30289 AS `id`, 327 AS `campaign_id`, 2034 AS `uid`, 'opened' AS `status`, '30995e580614c93533babe1688c979bc' AS `token`, DATE_SUB(NOW(), INTERVAL 9300 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 9290 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30290 AS `id`, 327 AS `campaign_id`, 2038 AS `uid`, 'opened' AS `status`, '495335907b9bae720729284a6f6996d9' AS `token`, DATE_SUB(NOW(), INTERVAL 9308 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 9306 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30291 AS `id`, 327 AS `campaign_id`, 2044 AS `uid`, 'opened' AS `status`, 'af30e299b872319e82c1e9ebce466d5f' AS `token`, DATE_SUB(NOW(), INTERVAL 9300 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 9288 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30292 AS `id`, 327 AS `campaign_id`, 2006 AS `uid`, 'sent' AS `status`, '004c60a366ea7c71b96de6c96bbdaae5' AS `token`, DATE_SUB(NOW(), INTERVAL 9303 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30293 AS `id`, 327 AS `campaign_id`, 2009 AS `uid`, 'sent' AS `status`, '94b4459ad3bfc9df2ba9530dfbf468f4' AS `token`, DATE_SUB(NOW(), INTERVAL 9305 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30294 AS `id`, 327 AS `campaign_id`, 2013 AS `uid`, 'clicked' AS `status`, '89fcf82ca09f7927fbac7f535d5b83c4' AS `token`, DATE_SUB(NOW(), INTERVAL 9301 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 9285 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 9284 HOUR) AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30295 AS `id`, 328 AS `campaign_id`, 2012 AS `uid`, 'clicked' AS `status`, 'ed5e44622dd7b654093f3ee1a7f04f34' AS `token`, DATE_SUB(NOW(), INTERVAL 5773 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5772 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 5755 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30296 AS `id`, 328 AS `campaign_id`, 2017 AS `uid`, 'opened' AS `status`, '8f2d829afbbe7a332e92dc5bd4961a54' AS `token`, DATE_SUB(NOW(), INTERVAL 5765 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5742 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30297 AS `id`, 328 AS `campaign_id`, 2021 AS `uid`, 'sent' AS `status`, '34b59e87dd5d7accd4465e06d0504177' AS `token`, DATE_SUB(NOW(), INTERVAL 5780 HOUR) AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30298 AS `id`, 328 AS `campaign_id`, 2025 AS `uid`, 'clicked' AS `status`, 'afbfc1bcecc833d89d786f4409532ad6' AS `token`, DATE_SUB(NOW(), INTERVAL 5778 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5756 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 5755 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30299 AS `id`, 328 AS `campaign_id`, 2030 AS `uid`, 'clicked' AS `status`, 'a9a1bf16d5d9b3f52c82a390b7d1a60d' AS `token`, DATE_SUB(NOW(), INTERVAL 5780 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5775 HOUR) AS `opened_at`, DATE_SUB(NOW(), INTERVAL 5754 HOUR) AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30300 AS `id`, 328 AS `campaign_id`, 2036 AS `uid`, 'opened' AS `status`, '9cd0e350fe128066b1c2309682348655' AS `token`, DATE_SUB(NOW(), INTERVAL 5770 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5741 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30301 AS `id`, 328 AS `campaign_id`, 2040 AS `uid`, 'opened' AS `status`, '1dc038c052f25e11a46293bd6807293e' AS `token`, DATE_SUB(NOW(), INTERVAL 5782 HOUR) AS `sent_at`, DATE_SUB(NOW(), INTERVAL 5781 HOUR) AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30302 AS `id`, 329 AS `campaign_id`, 2019 AS `uid`, 'pending' AS `status`, '9a771b9a83c441a7d0c34b382da45246' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30303 AS `id`, 329 AS `campaign_id`, 2023 AS `uid`, 'pending' AS `status`, '69a7efa158877f2d1df23f368f41b685' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30304 AS `id`, 329 AS `campaign_id`, 2027 AS `uid`, 'pending' AS `status`, 'd18976735f638661545fdc316f230db4' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30305 AS `id`, 329 AS `campaign_id`, 2032 AS `uid`, 'pending' AS `status`, 'e73c69b1b7f704210f7c82e26f64a35c' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30306 AS `id`, 329 AS `campaign_id`, 2038 AS `uid`, 'pending' AS `status`, 'de8cbf6f0ee428b74de8ef443a12ea0f' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30307 AS `id`, 329 AS `campaign_id`, 2042 AS `uid`, 'pending' AS `status`, 'f5ddbd9d4533a888d76b9718928fb4f4' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;

INSERT IGNORE INTO `campaign_recipients`
  (`id`, `campaign_id`, `user_id`, `destination`, `status`, `tracking_token`, `sent_at`, `opened_at`, `clicked_at`, `error_message`)
SELECT r.`id`, r.`campaign_id`, u.`id`, u.`email`, r.`status`, r.`token`,
       r.`sent_at`, r.`opened_at`, r.`clicked_at`, r.`error_message`
FROM (
  SELECT 30308 AS `id`, 330 AS `campaign_id`, 2025 AS `uid`, 'pending' AS `status`, '40021cfddadedac5454e68cd98ceefb3' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30309 AS `id`, 330 AS `campaign_id`, 2029 AS `uid`, 'pending' AS `status`, 'cf354b68db1af0d5fcb92efb4e55720e' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30310 AS `id`, 330 AS `campaign_id`, 2034 AS `uid`, 'pending' AS `status`, '591626123fc5e752df606e5901e9b0ff' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30311 AS `id`, 330 AS `campaign_id`, 2040 AS `uid`, 'pending' AS `status`, '360ea82f9729a7e362c990222bf57502' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30312 AS `id`, 330 AS `campaign_id`, 2044 AS `uid`, 'pending' AS `status`, '61be0b1e75149491d41d8ffb280ec7e3' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30313 AS `id`, 330 AS `campaign_id`, 2005 AS `uid`, 'pending' AS `status`, '0c239ae122b7b01a4c4c171651c9b823' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30314 AS `id`, 330 AS `campaign_id`, 2011 AS `uid`, 'pending' AS `status`, '7cef59b6d98d7c4fdb96c75a1fcc3322' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
  UNION ALL
  SELECT 30315 AS `id`, 330 AS `campaign_id`, 2015 AS `uid`, 'pending' AS `status`, '4d4f47aec6dcd17972536402814bc070' AS `token`, NULL AS `sent_at`, NULL AS `opened_at`, NULL AS `clicked_at`, NULL AS `error_message`
) AS r
JOIN `users` u ON u.`id` = r.`uid` AND u.`email` IS NOT NULL;


-- =====================================================================
-- Summary: 30 campaigns (301-330), 30 invite blasts (marketing_campaigns
-- 301-330), 315 campaign_recipients rows (30001-30315).
-- =====================================================================
