-- =====================================================================================
-- 021_demo_creator_depth.sql
--
-- Seeds "creator depth" demo data for the Zusty marketplace: the sales surface of a
-- creator profile (packages), the proof-of-work surface (portfolio), the booking
-- surface (availability) and the two analytics surfaces (daily rollups + raw views).
--
-- Depends on : creators 101-145 (creators.user_id = 2000 + (id - 100)) seeded earlier,
--              brand users 1001-1020, categories 1-16.
--
-- Tables and id ranges
--   creator_packages        ids 1101-1300   (2-5 per creator, exactly 200 rows)
--   creator_portfolio       ids 1301-1600   (3-8 per creator, exactly 300 rows)
--   creator_availability    ids 10100-14559 (id = 10000 + (creator_id-100)*100 + dayN,
--                                            ~25 of the next 60 days per creator)
--   creator_analytics_daily composite PK (creator_id, stat_date) - no surrogate id.
--                                        90 days x top-20 creators by reach.
--   creator_profile_views   ids 5001-5400   (400 rows over the last 60 days)
--
--   creator_availability and creator_profile_views are not listed in the global id
--   allocation table because no other agent writes to them; the ranges above are
--   reserved by this file and are disjoint from every other range in the seed set.
--
-- Design notes
--   * Prices are NOT hardcoded. Each package price is derived at apply time from the
--     creator's own price_min..price_max band (with a follower-tier fallback: nano
--     3k-15k, micro 15k-75k, mid 75k-300k, macro 300k-1.2L..12L) so the row can never
--     fall outside the band the creators seed established. Platform commission 15%.
--   * Package and portfolio copy is matched to the creator's real primary_category_id
--     at apply time through a 10-pack content library (LIFE / STYLE / TRAVEL / FOOD /
--     FIT / TECH / CREATIVE / KNOW / PARENT / AUTO), so a finance creator never ends up
--     with a makeup portfolio.
--   * Engagement counts scale with the creator's followers_total; analytics trend
--     upward over the 90-day window with weekday seasonality and bounded noise.
--   * Every statement is INSERT IGNORE and every id is deterministic, so the whole
--     file is safely re-runnable.
--   * Images use deterministic picsum seeds: zpf-<creator_id>-<slot>.
-- =====================================================================================


-- -------------------------------------------------------------------------------------
-- 1. creator_packages  (ids 1101-1300)
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO `creator_packages`
  (`id`, `creator_id`, `title`, `description`, `platform`, `deliverable_type`, `quantity`,
   `price`, `currency`, `turnaround_days`, `revisions`, `includes`, `is_active`,
   `sort_order`, `created_at`, `updated_at`)
SELECT
  1100 + ROW_NUMBER() OVER (ORDER BY cx.cid, sl.n),
  cx.cid,
  p.title,
  CONCAT(p.description, ' Shoot base: ', COALESCE(NULLIF(cx.city, ''), 'Mumbai'), '.'),
  p.platform,
  p.deliverable_type,
  p.quantity,
  LEAST(cx.fmax, GREATEST(cx.fmin, ROUND((cx.fmin + (cx.fmax - cx.fmin) * p.band) / 500) * 500)),
  'INR',
  p.tdays,
  p.revs,
  p.incl,
  1,
  sl.n,
  DATE_SUB(NOW(), INTERVAL (250 - sl.n * 9 - MOD(cx.cid, 40)) DAY),
  DATE_SUB(NOW(), INTERVAL MOD(cx.cid * 7 + sl.n, 25) DAY)
FROM (
    SELECT c.id AS cid, c.handle, c.city, c.followers_total AS ft,
      CASE COALESCE(c.primary_category_id, 1)
        WHEN 2 THEN 'STYLE'    WHEN 7 THEN 'STYLE'
        WHEN 3 THEN 'TRAVEL'
        WHEN 4 THEN 'FOOD'
        WHEN 5 THEN 'FIT'      WHEN 9 THEN 'FIT'
        WHEN 6 THEN 'TECH'     WHEN 8 THEN 'TECH'
        WHEN 10 THEN 'CREATIVE' WHEN 15 THEN 'CREATIVE' WHEN 16 THEN 'CREATIVE'
        WHEN 11 THEN 'KNOW'    WHEN 12 THEN 'KNOW'
        WHEN 13 THEN 'PARENT'
        WHEN 14 THEN 'AUTO'
        ELSE 'LIFE'
      END AS pack,
      CASE WHEN c.price_max > 0 THEN LEAST(c.price_min, c.price_max)
           WHEN c.followers_total < 50000 THEN 3000
           WHEN c.followers_total < 250000 THEN 15000
           WHEN c.followers_total < 1000000 THEN 75000
           ELSE 300000 END AS fmin,
      CASE WHEN c.price_max > 0 THEN c.price_max
           WHEN c.followers_total < 50000 THEN 15000
           WHEN c.followers_total < 250000 THEN 75000
           WHEN c.followers_total < 1000000 THEN 300000
           ELSE 1200000 END AS fmax
    FROM `creators` c
    WHERE c.id BETWEEN 101 AND 145 AND c.deleted_at IS NULL
  ) cx
  JOIN (
    SELECT 101 AS kid, 5 AS pkg_cnt, 8 AS port_cnt UNION ALL SELECT 102,5,8 UNION ALL
    SELECT 103,4,7 UNION ALL SELECT 104,5,8 UNION ALL SELECT 105,5,6 UNION ALL
    SELECT 106,5,8 UNION ALL SELECT 107,4,7 UNION ALL SELECT 108,5,8 UNION ALL
    SELECT 109,5,8 UNION ALL SELECT 110,3,5 UNION ALL SELECT 111,5,8 UNION ALL
    SELECT 112,4,7 UNION ALL SELECT 113,5,8 UNION ALL SELECT 114,5,6 UNION ALL
    SELECT 115,5,8 UNION ALL SELECT 116,4,7 UNION ALL SELECT 117,5,8 UNION ALL
    SELECT 118,5,8 UNION ALL SELECT 119,5,4 UNION ALL SELECT 120,2,6 UNION ALL
    SELECT 121,5,7 UNION ALL SELECT 122,5,8 UNION ALL SELECT 123,4,6 UNION ALL
    SELECT 124,5,8 UNION ALL SELECT 125,5,8 UNION ALL SELECT 126,5,5 UNION ALL
    SELECT 127,4,7 UNION ALL SELECT 128,5,8 UNION ALL SELECT 129,3,6 UNION ALL
    SELECT 130,5,6 UNION ALL SELECT 131,5,7 UNION ALL SELECT 132,4,8 UNION ALL
    SELECT 133,5,4 UNION ALL SELECT 134,5,8 UNION ALL SELECT 135,5,6 UNION ALL
    SELECT 136,4,7 UNION ALL SELECT 137,5,8 UNION ALL SELECT 138,2,3 UNION ALL
    SELECT 139,4,7 UNION ALL SELECT 140,4,6 UNION ALL SELECT 141,5,5 UNION ALL
    SELECT 142,5,6 UNION ALL SELECT 143,3,4 UNION ALL SELECT 144,3,6 UNION ALL
    SELECT 145,4,3
  ) k ON k.kid = cx.cid
  JOIN (
    SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
  ) sl ON sl.n < k.pkg_cnt
  JOIN (
    /* ---------------- LIFE ---------------- */
    SELECT 'LIFE' AS pack, 0 AS slot,
      'Instagram Reel - Single Deliverable' AS title,
      'One scripted 30-45 second Reel built around a hook-first narrative, shot and edited in-house with trending audio matched to your brand tone.' AS description,
      'instagram' AS platform, 'reel' AS deliverable_type, 1 AS quantity, 0.10 AS band,
      5 AS tdays, 2 AS revs,
      '["Concept and script approval","1 x 30-45s Reel","Story reshare on publish day","7-day organic usage rights"]' AS incl
    UNION ALL SELECT 'LIFE',1,'3-Post Story Series',
      'A three-frame Story arc that takes the audience from problem to product to link tap, published across a single high-traffic evening window.',
      'instagram','story',3,0.22,4,1,
      '["3 x 15s Stories","Link sticker to product page","Brand mention and tag","24-hour highlight save"]'
    UNION ALL SELECT 'LIFE',2,'Feed Post + Reel Combo',
      'A polished carousel feed post paired with a companion Reel so the campaign lands on both the grid and the discovery tab.',
      'instagram','bundle',2,0.45,7,2,
      '["1 x feed carousel","1 x companion Reel","2 Stories with product tag","30-day paid usage rights"]'
    UNION ALL SELECT 'LIFE',3,'YouTube Integration - 60s Brand Segment',
      'A 60 to 90 second integrated brand segment inside a regular long-form upload, written to sit naturally in the episode rather than read like an ad.',
      'youtube','video',1,0.72,12,1,
      '["Dedicated 60-90s brand segment","Pinned comment with offer code","Description link and timestamps","Community post on launch day"]'
    UNION ALL SELECT 'LIFE',4,'Full Lifestyle Campaign Bundle',
      'A month-long always-on bundle: two Reels, one carousel, six Stories and a whitelisted cut your paid media team can run as an ad.',
      'multi','bundle',4,0.95,18,3,
      '["2 x Reels","1 x carousel","6 x Stories","Whitelisted ad cut","90-day paid usage rights"]'

    /* ---------------- STYLE (fashion + beauty) ---------------- */
    UNION ALL SELECT 'STYLE',0,'Styling Reel - Outfit of the Day',
      'A single transition-led styling Reel featuring your piece across two looks, shot in natural light with a clean product close-up.',
      'instagram','reel',1,0.11,5,2,
      '["Look direction and styling","1 x 30-45s transition Reel","Product close-up insert","7-day organic usage rights"]'
    UNION ALL SELECT 'STYLE',1,'3-Post Story Series - Try-On Haul',
      'Three Stories covering unboxing, fit-and-fabric commentary and a sizing guide, closed with a link sticker to the exact SKU.',
      'instagram','story',3,0.22,4,1,
      '["3 x 15s Stories","Sizing and fit commentary","Link sticker to the exact SKU","24-hour highlight save"]'
    UNION ALL SELECT 'STYLE',2,'Lookbook Post + Reel Combo',
      'A four-slide lookbook carousel plus a companion Reel, delivered with flat-lay stills your team can reuse on the product page.',
      'instagram','bundle',2,0.46,8,2,
      '["4-slide lookbook carousel","1 x companion Reel","5 flat-lay stills for your PDP","30-day paid usage rights"]'
    UNION ALL SELECT 'STYLE',3,'YouTube Integration - Wardrobe Feature',
      'Your label featured inside a wardrobe or haul video with an on-camera styling breakdown and a pinned discount code.',
      'youtube','video',1,0.72,12,1,
      '["Dedicated wardrobe segment","On-camera styling breakdown","Pinned discount code","Description link and timestamps"]'
    UNION ALL SELECT 'STYLE',4,'Product Unboxing Video + Reel Bundle',
      'A dedicated unboxing film plus two cut-down Reels and ten UGC stills licensed for your ads and marketplace listings.',
      'multi','bundle',4,0.95,16,3,
      '["1 x unboxing film","2 x Reel cut-downs","10 UGC stills","Marketplace listing rights","90-day paid usage rights"]'

    /* ---------------- TRAVEL ---------------- */
    UNION ALL SELECT 'TRAVEL',0,'Destination Reel - Single Deliverable',
      'One cinematic 30-45 second destination Reel with sweeping establishing shots and a location tag on your property.',
      'instagram','reel',1,0.12,7,2,
      '["Location recce and shot list","1 x cinematic 30-45s Reel","Geo-tag on your property","7-day organic usage rights"]'
    UNION ALL SELECT 'TRAVEL',1,'3-Post Story Series - Stay Walkthrough',
      'Three Stories walking through arrival, room reveal and the property highlight, each carrying a booking link sticker.',
      'instagram','story',3,0.23,5,1,
      '["3 x 15s Stories","Booking link sticker","Property geo-tag","Permanent highlight save"]'
    UNION ALL SELECT 'TRAVEL',2,'Travel Feature Post + Reel Combo',
      'A feature carousel with a written itinerary plus a companion Reel, both geo-tagged and saved to a permanent highlight.',
      'instagram','bundle',2,0.46,9,2,
      '["Feature carousel with itinerary","1 x companion Reel","6 destination stills","30-day paid usage rights"]'
    UNION ALL SELECT 'TRAVEL',3,'YouTube Integration - Property Feature',
      'A two to three minute property feature inside a destination vlog, including room tour, food and a candid guest verdict.',
      'youtube','video',1,0.74,14,1,
      '["2-3 minute property feature","Room tour and food coverage","Booking link in description","Pinned comment with offer code"]'
    UNION ALL SELECT 'TRAVEL',4,'48-Hour Destination Campaign Bundle',
      'A two-night hosted shoot delivering two Reels, one carousel, twelve Stories and a raw footage folder for your own edits.',
      'multi','bundle',4,0.96,21,3,
      '["2 x Reels","1 x carousel","12 x Stories","Raw footage handover","90-day paid usage rights"]'

    /* ---------------- FOOD ---------------- */
    UNION ALL SELECT 'FOOD',0,'Recipe Reel - Single Deliverable',
      'A top-down recipe Reel built around your product as the hero ingredient, with on-screen steps in Hindi and English.',
      'instagram','reel',1,0.11,6,2,
      '["Recipe development","1 x top-down 30-45s Reel","Bilingual on-screen steps","7-day organic usage rights"]'
    UNION ALL SELECT 'FOOD',1,'3-Post Story Series - Restaurant Visit',
      'Three Stories covering the arrival, the signature dish and an honest first-bite verdict, tagged to your outlet location.',
      'instagram','story',3,0.22,4,1,
      '["3 x 15s Stories","Outlet location tag","Menu and pricing frame","24-hour highlight save"]'
    UNION ALL SELECT 'FOOD',2,'Food Feature Post + Reel Combo',
      'A styled feed post with the full recipe in the caption plus a companion Reel, delivered with five plated stills.',
      'instagram','bundle',2,0.45,8,2,
      '["Styled feed post","Full recipe in caption","1 x companion Reel","5 plated stills","30-day paid usage rights"]'
    UNION ALL SELECT 'FOOD',3,'YouTube Integration - Recipe Film',
      'A long-form recipe film built end to end around your product, with an ingredient card and a pinned offer code.',
      'youtube','video',1,0.73,14,1,
      '["Long-form recipe film","On-screen ingredient card","Pinned comment with offer code","Description link and timestamps"]'
    UNION ALL SELECT 'FOOD',4,'Product Unboxing Video + Recipe Bundle',
      'An unboxing and taste-test film plus two recipe Reels and eight food stills licensed for packaging and ad use.',
      'multi','bundle',4,0.95,18,3,
      '["1 x unboxing and taste test","2 x recipe Reels","8 food stills","Packaging and ad usage rights"]'

    /* ---------------- FIT (fitness + health) ---------------- */
    UNION ALL SELECT 'FIT',0,'Workout Reel - Single Deliverable',
      'One 30-45 second training Reel demonstrating three movements with your gear in frame and a form-cue voiceover.',
      'instagram','reel',1,0.10,5,2,
      '["Movement selection and script","1 x 30-45s training Reel","Form-cue voiceover","7-day organic usage rights"]'
    UNION ALL SELECT 'FIT',1,'3-Post Story Series - Day-in-the-Life',
      'Three Stories tracking a training day from pre-workout to recovery, placing your product at the moment of actual use.',
      'instagram','story',3,0.21,4,1,
      '["3 x 15s Stories","Product placed at point of use","Link sticker to product page","24-hour highlight save"]'
    UNION ALL SELECT 'FIT',2,'Transformation Post + Reel Combo',
      'A results-led carousel with honest before-and-after context plus a companion Reel breaking down the routine.',
      'instagram','bundle',2,0.44,8,2,
      '["Results carousel with context","1 x routine breakdown Reel","2 Stories with product tag","30-day paid usage rights"]'
    UNION ALL SELECT 'FIT',3,'YouTube Integration - Routine Breakdown',
      'A dedicated segment inside a training or nutrition video explaining how your product fits an actual weekly plan.',
      'youtube','video',1,0.71,12,1,
      '["Dedicated 90s brand segment","Weekly plan walkthrough","Pinned comment with offer code","Description link and timestamps"]'
    UNION ALL SELECT 'FIT',4,'30-Day Wellness Campaign Bundle',
      'A month-long tracked challenge: two Reels, weekly Story check-ins, a results carousel and a testimonial cut for your ads.',
      'multi','bundle',4,0.94,30,3,
      '["2 x Reels","4 weekly Story check-ins","1 x results carousel","Testimonial ad cut","90-day paid usage rights"]'

    /* ---------------- TECH (tech + gaming) ---------------- */
    UNION ALL SELECT 'TECH',0,'Tech Reel - First Impressions',
      'A fast-cut first impressions Reel covering design, one standout feature and a straight-talking verdict in under 45 seconds.',
      'instagram','reel',1,0.11,6,2,
      '["Unit collection and setup","1 x first impressions Reel","Product close-up inserts","7-day organic usage rights"]'
    UNION ALL SELECT 'TECH',1,'3-Post Story Series - Setup Walkthrough',
      'Three Stories taking the audience through unboxing, setup and the first-run experience with a link to the product page.',
      'instagram','story',3,0.22,4,1,
      '["3 x 15s Stories","Unboxing and setup coverage","Link sticker to product page","24-hour highlight save"]'
    UNION ALL SELECT 'TECH',2,'Spec Breakdown Post + Reel Combo',
      'A spec-comparison carousel against two rivals in the same price band plus a companion Reel with the short verdict.',
      'instagram','bundle',2,0.46,8,2,
      '["Spec comparison carousel","1 x verdict Reel","2 Stories with product tag","30-day paid usage rights"]'
    UNION ALL SELECT 'TECH',3,'YouTube Integration - Dedicated Review',
      'A full long-form review after a week of real usage, with benchmark notes and an honest buy or skip call at the end.',
      'youtube','video',1,0.76,15,1,
      '["Full long-form review","Benchmark and battery testing","Pinned comment with offer code","Description link and timestamps"]'
    UNION ALL SELECT 'TECH',4,'Product Unboxing Video - Long Form + Shorts',
      'A dedicated unboxing and review upload plus three Shorts and a Reel cut-down, with 90-day paid usage rights.',
      'multi','bundle',4,0.96,20,3,
      '["1 x long-form unboxing and review","3 x YouTube Shorts","1 x Reel cut-down","90-day paid usage rights"]'

    /* ---------------- CREATIVE (music + comedy + art) ---------------- */
    UNION ALL SELECT 'CREATIVE',0,'Original Reel - Sound and Concept',
      'An original concept Reel written, performed and scored in-house, with your brand woven into the punchline rather than bolted on.',
      'instagram','reel',1,0.12,7,2,
      '["Original concept and script","1 x performed Reel","Original audio stem","7-day organic usage rights"]'
    UNION ALL SELECT 'CREATIVE',1,'3-Post Story Series - Behind the Scenes',
      'Three Stories from the making of the shoot, giving the audience process access and closing on a link sticker.',
      'instagram','story',3,0.22,4,1,
      '["3 x 15s Stories","Behind-the-scenes process access","Link sticker to product page","Permanent highlight save"]'
    UNION ALL SELECT 'CREATIVE',2,'Concept Post + Reel Combo',
      'A concept-led feed post plus a companion Reel, delivered with the project stills and the original audio stem.',
      'instagram','bundle',2,0.45,9,2,
      '["Concept-led feed post","1 x companion Reel","Project stills handover","Original audio stem","30-day paid usage rights"]'
    UNION ALL SELECT 'CREATIVE',3,'YouTube Integration - Sponsored Segment',
      'A scripted sponsored segment inside a regular upload, performed in the same voice the channel is subscribed for.',
      'youtube','video',1,0.72,13,1,
      '["Scripted sponsored segment","In-voice performance","Pinned comment with offer code","Description link and timestamps"]'
    UNION ALL SELECT 'CREATIVE',4,'Live Session + Content Bundle',
      'A 45-minute co-hosted live session plus a promo Reel, three Stories and a highlights cut-down after the stream.',
      'multi','live',4,0.94,20,2,
      '["45-minute co-hosted live","1 x promo Reel","3 x Stories","Highlights cut-down","60-day paid usage rights"]'

    /* ---------------- KNOW (education + finance) ---------------- */
    UNION ALL SELECT 'KNOW',0,'Explainer Reel - 60 Seconds',
      'A single explainer Reel that breaks one idea down to a clear takeaway, with on-screen text designed for silent viewing.',
      'instagram','reel',1,0.10,6,2,
      '["Script and fact check","1 x 60s explainer Reel","Silent-viewing on-screen text","7-day organic usage rights"]'
    UNION ALL SELECT 'KNOW',1,'3-Post Story Series - Q&A Takeover',
      'Three Stories running an audience question box on the topic, with your platform positioned as the practical answer.',
      'instagram','story',3,0.21,4,1,
      '["3 x 15s Stories","Audience question box","Link sticker to sign-up page","24-hour highlight save"]'
    UNION ALL SELECT 'KNOW',2,'Carousel Post + Reel Combo',
      'A ten-slide teaching carousel plus a companion Reel, both built to be saved and shared rather than just liked.',
      'instagram','bundle',2,0.44,8,2,
      '["10-slide teaching carousel","1 x companion Reel","2 Stories with link sticker","30-day paid usage rights"]'
    UNION ALL SELECT 'KNOW',3,'YouTube Integration - Deep-Dive Segment',
      'A three to four minute deep-dive segment inside a long-form video, with an on-screen walkthrough of your product.',
      'youtube','video',1,0.73,14,1,
      '["3-4 minute deep-dive segment","On-screen product walkthrough","Pinned comment with offer code","Description link and timestamps"]'
    UNION ALL SELECT 'KNOW',4,'Webinar + Content Campaign Bundle',
      'A co-hosted webinar with pre-promotion Reels, three Stories, a recap carousel and the recording licensed for your funnel.',
      'multi','bundle',4,0.95,25,3,
      '["Co-hosted 60-minute webinar","2 x promo Reels","3 x Stories","Recap carousel","Recording licensed for your funnel"]'

    /* ---------------- PARENT ---------------- */
    UNION ALL SELECT 'PARENT',0,'Family Reel - Single Deliverable',
      'A warm slice-of-life Reel showing your product inside a real family routine, shot with the children consented and comfortable.',
      'instagram','reel',1,0.10,6,2,
      '["Routine-led concept","1 x 30-45s family Reel","Product placed at point of use","7-day organic usage rights"]'
    UNION ALL SELECT 'PARENT',1,'3-Post Story Series - Morning Routine',
      'Three Stories following a school-morning routine, placing your product at the point where it actually saves time.',
      'instagram','story',3,0.21,4,1,
      '["3 x 15s Stories","School-morning routine coverage","Link sticker to product page","24-hour highlight save"]'
    UNION ALL SELECT 'PARENT',2,'Honest Review Post + Reel Combo',
      'A long-form honest review post after two weeks of real use plus a companion Reel carrying the short verdict.',
      'instagram','bundle',2,0.44,10,2,
      '["Two-week real-use trial","Long-form review post","1 x verdict Reel","30-day paid usage rights"]'
    UNION ALL SELECT 'PARENT',3,'YouTube Integration - Family Vlog Feature',
      'Your product featured across a family vlog with a dedicated segment covering value, safety and repeat-buy intent.',
      'youtube','video',1,0.71,13,1,
      '["Dedicated vlog segment","Value and safety commentary","Pinned comment with offer code","Description link and timestamps"]'
    UNION ALL SELECT 'PARENT',4,'Product Unboxing Video + UGC Pack',
      'An unboxing and two-week trial film plus a pack of twelve UGC clips and stills licensed for your performance ads.',
      'multi','ugc',4,0.94,20,3,
      '["1 x unboxing and trial film","12 UGC clips and stills","Performance ad usage rights","90-day paid usage rights"]'

    /* ---------------- AUTO ---------------- */
    UNION ALL SELECT 'AUTO',0,'Ride Reel - Single Deliverable',
      'A single cinematic ride Reel with rolling shots, cabin detail and a sharp one-line verdict on how it actually drives.',
      'instagram','reel',1,0.12,7,2,
      '["Rolling shots and cabin detail","1 x cinematic 30-45s Reel","Verdict voiceover","7-day organic usage rights"]'
    UNION ALL SELECT 'AUTO',1,'3-Post Story Series - Showroom Visit',
      'Three Stories from the showroom floor covering the variant walkaround, on-road pricing and the booking link.',
      'instagram','story',3,0.23,5,1,
      '["3 x 15s Stories","Variant walkaround","On-road pricing frame","Booking link sticker"]'
    UNION ALL SELECT 'AUTO',2,'First Drive Post + Reel Combo',
      'A first-drive carousel with mileage and ride-quality notes plus a companion Reel shot on city and highway stretches.',
      'instagram','bundle',2,0.46,9,2,
      '["First-drive carousel","Mileage and ride-quality notes","1 x companion Reel","30-day paid usage rights"]'
    UNION ALL SELECT 'AUTO',3,'YouTube Integration - Full Road Test',
      'A complete road test upload covering performance, comfort, features and a value verdict against the direct rivals.',
      'youtube','video',1,0.78,16,1,
      '["Complete road test upload","Rival comparison and value verdict","Dealer link in description","Pinned comment with offer code"]'
    UNION ALL SELECT 'AUTO',4,'Launch Week Campaign Bundle',
      'Launch-week coverage: a teaser Reel, a first-drive video, eight Stories and a live Q&A on delivery timelines and variants.',
      'multi','bundle',4,0.96,22,3,
      '["1 x teaser Reel","1 x first-drive video","8 x Stories","Live Q&A session","90-day paid usage rights"]'
  ) p ON p.pack = cx.pack AND p.slot = sl.n;


-- -------------------------------------------------------------------------------------
-- 2. creator_portfolio  (ids 1301-1600)
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO `creator_portfolio`
  (`id`, `creator_id`, `media_kind`, `title`, `caption`, `media_url`, `thumbnail_url`,
   `external_url`, `source_platform`, `aspect_ratio`, `duration_secs`, `views_count`,
   `likes_count`, `comments_count`, `published_at`, `is_featured`, `is_active`,
   `sort_order`, `created_at`, `updated_at`)
SELECT
  1300 + ROW_NUMBER() OVER (ORDER BY q.cid, q.slot),
  q.cid,
  q.media_kind,
  q.title,
  q.caption,
  q.media_url,
  q.thumbnail_url,
  q.external_url,
  q.source_platform,
  q.aspect_ratio,
  q.duration_secs,
  q.views,
  ROUND(q.views * q.like_ratio),
  ROUND(q.views * q.like_ratio * 0.031),
  q.published_at,
  IF(q.slot < 2, 1, 0),
  1,
  q.slot,
  DATE_ADD(q.published_at, INTERVAL 2 DAY),
  DATE_SUB(NOW(), INTERVAL MOD(q.cid * 3 + q.slot, 30) DAY)
FROM (
  SELECT
    cx.cid,
    sl.n AS slot,
    f.media_kind,
    f.title,
    f.caption,
    CONCAT('https://picsum.photos/seed/zpf-', cx.cid, '-', sl.n, '/', f.media_dims) AS media_url,
    CONCAT('https://picsum.photos/seed/zpf-', cx.cid, '-', sl.n, '/', f.thumb_dims) AS thumbnail_url,
    CASE f.source_platform
      WHEN 'instagram' THEN CONCAT('https://www.instagram.com/',
        IF(f.media_kind = 'reel', 'reel', 'p'), '/',
        SUBSTRING(MD5(CONCAT(cx.handle, '-ig-', sl.n)), 1, 11), '/')
      WHEN 'youtube' THEN CONCAT('https://www.youtube.com/watch?v=',
        SUBSTRING(MD5(CONCAT(cx.handle, '-yt-', sl.n)), 1, 11))
      ELSE NULL
    END AS external_url,
    f.source_platform,
    f.aspect_ratio,
    f.duration_secs,
    f.like_ratio,
    GREATEST(180, ROUND(GREATEST(cx.ft, 4000) * f.view_ratio
      * ((85 + MOD(CRC32(CONCAT('pv', cx.cid, '-', sl.n)), 62)) / 100))) AS views,
    DATE_SUB(NOW(), INTERVAL (25 + sl.n * 34
      + MOD(CRC32(CONCAT('pd', cx.cid, '-', sl.n)), 18)) DAY) AS published_at
  FROM (
      SELECT c.id AS cid, c.handle, c.followers_total AS ft,
        CASE COALESCE(c.primary_category_id, 1)
          WHEN 2 THEN 'STYLE'    WHEN 7 THEN 'STYLE'
          WHEN 3 THEN 'TRAVEL'
          WHEN 4 THEN 'FOOD'
          WHEN 5 THEN 'FIT'      WHEN 9 THEN 'FIT'
          WHEN 6 THEN 'TECH'     WHEN 8 THEN 'TECH'
          WHEN 10 THEN 'CREATIVE' WHEN 15 THEN 'CREATIVE' WHEN 16 THEN 'CREATIVE'
          WHEN 11 THEN 'KNOW'    WHEN 12 THEN 'KNOW'
          WHEN 13 THEN 'PARENT'
          WHEN 14 THEN 'AUTO'
          ELSE 'LIFE'
        END AS pack
      FROM `creators` c
      WHERE c.id BETWEEN 101 AND 145 AND c.deleted_at IS NULL
    ) cx
    JOIN (
      SELECT 101 AS kid, 8 AS port_cnt UNION ALL SELECT 102,8 UNION ALL SELECT 103,7 UNION ALL
      SELECT 104,8 UNION ALL SELECT 105,6 UNION ALL SELECT 106,8 UNION ALL SELECT 107,7 UNION ALL
      SELECT 108,8 UNION ALL SELECT 109,8 UNION ALL SELECT 110,5 UNION ALL SELECT 111,8 UNION ALL
      SELECT 112,7 UNION ALL SELECT 113,8 UNION ALL SELECT 114,6 UNION ALL SELECT 115,8 UNION ALL
      SELECT 116,7 UNION ALL SELECT 117,8 UNION ALL SELECT 118,8 UNION ALL SELECT 119,4 UNION ALL
      SELECT 120,6 UNION ALL SELECT 121,7 UNION ALL SELECT 122,8 UNION ALL SELECT 123,6 UNION ALL
      SELECT 124,8 UNION ALL SELECT 125,8 UNION ALL SELECT 126,5 UNION ALL SELECT 127,7 UNION ALL
      SELECT 128,8 UNION ALL SELECT 129,6 UNION ALL SELECT 130,6 UNION ALL SELECT 131,7 UNION ALL
      SELECT 132,8 UNION ALL SELECT 133,4 UNION ALL SELECT 134,8 UNION ALL SELECT 135,6 UNION ALL
      SELECT 136,7 UNION ALL SELECT 137,8 UNION ALL SELECT 138,3 UNION ALL SELECT 139,7 UNION ALL
      SELECT 140,6 UNION ALL SELECT 141,5 UNION ALL SELECT 142,6 UNION ALL SELECT 143,4 UNION ALL
      SELECT 144,6 UNION ALL SELECT 145,3
    ) k ON k.kid = cx.cid
    JOIN (
      SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
      SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
    ) sl ON sl.n < k.port_cnt
    JOIN (
      /* pack, slot, media_kind, title, caption, source_platform, aspect_ratio,
         duration_secs, media_dims, thumb_dims, view_ratio, like_ratio            */

      /* ---------------- LIFE ---------------- */
      SELECT 'LIFE' AS pack, 0 AS slot, 'reel' AS media_kind,
        'Sunday Reset with Bloom Skincare' AS title,
        'My whole Sunday reset in thirty seconds. The Bloom Skincare night oil is the one step I never skip, even at 1am after a shoot.' AS caption,
        'instagram' AS source_platform, '9:16' AS aspect_ratio, 32 AS duration_secs,
        '1080/1920' AS media_dims, '540/960' AS thumb_dims,
        1.35 AS view_ratio, 0.062 AS like_ratio
      UNION ALL SELECT 'LIFE',1,'reel','Small Flat, Big Corner Makeover',
        'Turned the deadest corner of a 1BHK into the spot I actually work from. Everything you see here came in under fifteen thousand rupees.',
        'instagram','9:16',45,'1080/1920','540/960',0.95,0.058
      UNION ALL SELECT 'LIFE',2,'post','Morning Pages and Filter Coffee',
        'Six months of writing three pages before touching my phone. Genuinely the only habit that stuck this year. Cup courtesy the family filter.',
        'instagram','4:5',NULL,'1080/1350','540/675',0.42,0.075
      UNION ALL SELECT 'LIFE',3,'video','A Real Week in My Life - Mumbai Edition',
        'Seven days, three shoots and one very delayed local train. The unedited version of what this job actually looks like.',
        'youtube','16:9',742,'1280/720','640/360',0.22,0.048
      UNION ALL SELECT 'LIFE',4,'photo','Balcony Garden, Two Years In',
        'Started with two money plants and a lot of misplaced confidence. This is what patience and one good monsoon look like.',
        'upload','4:5',NULL,'1080/1350','540/675',0.28,0.082
      UNION ALL SELECT 'LIFE',5,'reel','Things I Stopped Buying This Year',
        'Five purchases I have completely stopped making and what I do instead. Number three saved me the most money by far.',
        'instagram','9:16',28,'1080/1920','540/960',0.70,0.060
      UNION ALL SELECT 'LIFE',6,'post','The Sunday Grocery Haul',
        'What sixteen hundred rupees at the local mandi actually buys. Sharing the full list because so many of you asked for it.',
        'instagram','1:1',NULL,'1080/1080','540/540',0.35,0.070
      UNION ALL SELECT 'LIFE',7,'video','Setting Up My New Home - Full Tour',
        'Two months of hunting for furniture across the city, and the full tour of how the place finally came together.',
        'youtube','16:9',1104,'1280/720','640/360',0.18,0.045

      /* ---------------- STYLE ---------------- */
      UNION ALL SELECT 'STYLE',0,'reel','Three Ways to Wear One Kurta',
        'One handloom kurta styled three completely different ways - office, sangeet and a Sunday brunch. Save this for wedding season.',
        'instagram','9:16',34,'1080/1920','540/960',1.42,0.064
      UNION ALL SELECT 'STYLE',1,'reel','Sale Haul: What I Kept, What Went Back',
        'Ordered eleven pieces in the end-of-season sale. Being brutally honest about the four that actually made the cut.',
        'instagram','9:16',48,'1080/1920','540/960',0.98,0.059
      UNION ALL SELECT 'STYLE',2,'post','Handloom Season Lookbook',
        'Shot this whole set with weavers from Bhagalpur. Every piece is handwoven and every drape is a little imperfect on purpose.',
        'instagram','4:5',NULL,'1080/1350','540/675',0.44,0.078
      UNION ALL SELECT 'STYLE',3,'video','Building a 20-Piece Capsule Wardrobe',
        'The full breakdown of the twenty pieces I actually wear, with links and honest sizing notes for Indian body types.',
        'youtube','16:9',884,'1280/720','640/360',0.23,0.049
      UNION ALL SELECT 'STYLE',4,'photo','Wedding Guest, Minus the Panic',
        'The exact look I wore to a Jaipur wedding last week. Lehenga borrowed, jewellery my mother''s, and shoes that were finally comfortable.',
        'upload','4:5',NULL,'1080/1350','540/675',0.30,0.085
      UNION ALL SELECT 'STYLE',5,'reel','Styling One Blazer for a Week',
        'Five days, one oversized blazer, zero repeats. Proof that a single well-structured piece does most of the work for you.',
        'instagram','9:16',30,'1080/1920','540/960',0.74,0.061
      UNION ALL SELECT 'STYLE',6,'post','Skin Prep Before the Base',
        'The ten minutes before makeup matter more than the makeup. My full prep routine with the Bloom Skincare serum layered under everything.',
        'instagram','1:1',NULL,'1080/1080','540/540',0.38,0.072
      UNION ALL SELECT 'STYLE',7,'video','Six Months With the Same Five Products',
        'No new launches and nothing gifted. Just what genuinely survived six months in my routine and what quietly got shelved.',
        'youtube','16:9',652,'1280/720','640/360',0.19,0.046

      /* ---------------- TRAVEL ---------------- */
      UNION ALL SELECT 'TRAVEL',0,'reel','Sunrise at Hampi',
        'Left Bengaluru at midnight to catch this. Twelve hours of driving for four minutes of light, and completely worth it.',
        'instagram','9:16',36,'1080/1920','540/960',1.48,0.066
      UNION ALL SELECT 'TRAVEL',1,'reel','Coorg in the Monsoon',
        'Everything is louder here in the monsoon. Full route, the stay and the exact viewpoint are all tagged in the caption.',
        'instagram','9:16',42,'1080/1920','540/960',1.02,0.060
      UNION ALL SELECT 'TRAVEL',2,'post','48 Hours in Udaipur',
        'A two-day itinerary that skips every queue. Lake Pichola at 6am is the version of this city nobody bothers to post.',
        'instagram','4:5',NULL,'1080/1350','540/675',0.46,0.077
      UNION ALL SELECT 'TRAVEL',3,'video','Spiti Valley Road Trip - Part One',
        'Nine days, two thousand kilometres and one very tired Thar. Part one covers Shimla to Kaza, including where we broke down.',
        'youtube','16:9',1428,'1280/720','640/360',0.25,0.051
      UNION ALL SELECT 'TRAVEL',4,'photo','The Blue Doors of Jodhpur',
        'Walked the old city for six hours waiting for the light to hit these doors right. Shot on a phone, no edits beyond a crop.',
        'upload','4:5',NULL,'1080/1350','540/675',0.31,0.086
      UNION ALL SELECT 'TRAVEL',5,'reel','The Cheapest Way to Do the Andamans',
        'Did eight days for under forty thousand including flights. Sharing every single booking hack that actually worked.',
        'instagram','9:16',33,'1080/1920','540/960',0.82,0.063
      UNION ALL SELECT 'TRAVEL',6,'post','Where I Actually Stayed in Goa',
        'Skipping the beach clubs this time. Three homestays in south Goa that together cost less than one night in Baga.',
        'instagram','1:1',NULL,'1080/1080','540/540',0.37,0.071
      UNION ALL SELECT 'TRAVEL',7,'video','Meghalaya - The Complete Ten-Day Guide',
        'Living root bridges, the cleanest village in Asia and a great deal of rain. The full ten-day guide with real costs.',
        'youtube','16:9',1266,'1280/720','640/360',0.20,0.047

      /* ---------------- FOOD ---------------- */
      UNION ALL SELECT 'FOOD',0,'reel','60-Second Butter Chicken Masala',
        'The base my mother makes every Sunday, timed and measured properly for the first time. No cream, only patience.',
        'instagram','9:16',60,'1080/1920','540/960',1.40,0.065
      UNION ALL SELECT 'FOOD',1,'reel','Street Food Crawl: Old Delhi',
        'Six stops between Chandni Chowk and Jama Masjid, ranked. The last one is not the famous one, and that is exactly the point.',
        'instagram','9:16',52,'1080/1920','540/960',1.05,0.061
      UNION ALL SELECT 'FOOD',2,'post','Filter Coffee, Properly Explained',
        'Coffee to chicory ratio, decoction time and why your first pour is always weak. Everything I learned from my grandmother.',
        'instagram','4:5',NULL,'1080/1350','540/675',0.43,0.076
      UNION ALL SELECT 'FOOD',3,'video','South Indian Breakfast Thali From Scratch',
        'Idli, sambar, three chutneys and a podi, all made in one morning. The full timeline so you can actually replicate it.',
        'youtube','16:9',968,'1280/720','640/360',0.24,0.050
      UNION ALL SELECT 'FOOD',4,'photo','Sunday Table',
        'Everything on this table came from the Saturday farmers market and nothing here took more than forty minutes to cook.',
        'upload','4:5',NULL,'1080/1350','540/675',0.29,0.083
      UNION ALL SELECT 'FOOD',5,'reel','Five Dals, Five States',
        'Same lentil, completely different results. Which one you grew up with says everything about where you are from.',
        'instagram','9:16',38,'1080/1920','540/960',0.76,0.062
      UNION ALL SELECT 'FOOD',6,'post','The Honest Restaurant Review',
        'Went back three times before writing this. Great biryani, average service, and yes it is worth the wait on a weekday.',
        'instagram','1:1',NULL,'1080/1080','540/540',0.36,0.069
      UNION ALL SELECT 'FOOD',7,'video','Sourdough in Indian Weather',
        'Humidity ruins more starters than bad flour ever does. Everything I changed to make it work in a Chennai kitchen.',
        'youtube','16:9',714,'1280/720','640/360',0.18,0.045

      /* ---------------- FIT ---------------- */
      UNION ALL SELECT 'FIT',0,'reel','Three Moves for a Dead Lower Back',
        'If you sit for ten hours a day, start here. Two minutes, no equipment, and do it before your first meeting.',
        'instagram','9:16',31,'1080/1920','540/960',1.30,0.063
      UNION ALL SELECT 'FIT',1,'reel','What 100g of Protein Actually Looks Like',
        'Vegetarian, Indian kitchen, no imported powders. Laid the whole day out so you can see the real portion sizes.',
        'instagram','9:16',44,'1080/1920','540/960',0.92,0.058
      UNION ALL SELECT 'FIT',2,'post','Year Two of Training, Honestly',
        'No transformation photo drama. Just what actually changed in strength, sleep and how I handle a genuinely bad week.',
        'instagram','4:5',NULL,'1080/1350','540/675',0.41,0.074
      UNION ALL SELECT 'FIT',3,'video','Beginner Strength Plan - Full 12 Weeks',
        'The exact three-day split I give every beginner, with progression, deload weeks and the mistakes I made myself.',
        'youtube','16:9',1032,'1280/720','640/360',0.22,0.048
      UNION ALL SELECT 'FIT',4,'photo','6am, Marine Drive',
        'The run I do four times a week. The city is a completely different place before the traffic wakes up.',
        'upload','4:5',NULL,'1080/1350','540/675',0.27,0.081
      UNION ALL SELECT 'FIT',5,'reel','Stop Doing This on Squat Day',
        'Three form errors I see in every gym in the country, and the single cue that fixes each one immediately.',
        'instagram','9:16',29,'1080/1920','540/960',0.72,0.059
      UNION ALL SELECT 'FIT',6,'post','My Actual Supplement Stack',
        'Four things, all boring, all backed by something. Everything else I have tried and quietly stopped buying.',
        'instagram','1:1',NULL,'1080/1080','540/540',0.34,0.068
      UNION ALL SELECT 'FIT',7,'video','Recovery, Sleep and Why You Are Always Tired',
        'A full breakdown of recovery for people training around a nine-to-six job, with a sleep protocol that is actually realistic.',
        'youtube','16:9',826,'1280/720','640/360',0.17,0.044

      /* ---------------- TECH ---------------- */
      UNION ALL SELECT 'TECH',0,'reel','This Phone Under 25K Surprised Me',
        'Two weeks of daily use and the camera is doing things phones twice the price still struggle with. Full verdict on the channel.',
        'instagram','9:16',35,'1080/1920','540/960',1.38,0.061
      UNION ALL SELECT 'TECH',1,'reel','My Desk Setup for This Year',
        'Everything on this desk in forty-five seconds, including the two cheap things that made the biggest difference.',
        'instagram','9:16',45,'1080/1920','540/960',0.94,0.057
      UNION ALL SELECT 'TECH',2,'post','Battery Life, Tested Properly',
        'Ran the same five-hour loop on four phones. The results are not remotely what the spec sheets suggest.',
        'instagram','4:5',NULL,'1080/1350','540/675',0.40,0.073
      UNION ALL SELECT 'TECH',3,'video','Full Review: Ninety Days Later',
        'Most reviews go up in three days. This one is after three months of actual use, including what started annoying me.',
        'youtube','16:9',1188,'1280/720','640/360',0.26,0.052
      UNION ALL SELECT 'TECH',4,'photo','The Home Lab',
        'Two mini PCs, a NAS and far too many cables. Everything that runs quietly in the background behind the videos.',
        'upload','4:5',NULL,'1080/1350','540/675',0.26,0.079
      UNION ALL SELECT 'TECH',5,'reel','Three Settings to Change Right Now',
        'Turn these off the day you unbox any Android phone. Battery life and privacy both improve immediately.',
        'instagram','9:16',27,'1080/1920','540/960',0.78,0.060
      UNION ALL SELECT 'TECH',6,'post','Which Laptop for Engineering Students',
        'Three price bands, three clear picks, and the one spec people keep badly overpaying for every single year.',
        'instagram','1:1',NULL,'1080/1080','540/540',0.33,0.067
      UNION ALL SELECT 'TECH',7,'video','Building a 60K Gaming PC',
        'Full part list, live assembly and benchmarks at 1080p, priced with Indian retail rather than international MSRP.',
        'youtube','16:9',1512,'1280/720','640/360',0.21,0.047

      /* ---------------- CREATIVE ---------------- */
      UNION ALL SELECT 'CREATIVE',0,'reel','Original Sound: Monsoon Loop',
        'Made this from a field recording of rain on a tin roof in Alibaug. Free for anyone to use, just tag me if you do.',
        'instagram','9:16',30,'1080/1920','540/960',1.55,0.068
      UNION ALL SELECT 'CREATIVE',1,'reel','When the Family Group Gets Serious',
        'Every Indian family has these four people. Tag the one who forwards good morning images at five in the morning.',
        'instagram','9:16',41,'1080/1920','540/960',1.20,0.064
      UNION ALL SELECT 'CREATIVE',2,'post','Process: From Sketch to Mural',
        'Four weeks, one wall in Bandra and roughly ninety cups of chai. Swipe across for the sketch it started as.',
        'instagram','4:5',NULL,'1080/1350','540/675',0.45,0.079
      UNION ALL SELECT 'CREATIVE',3,'video','Writing a Song in One Sitting',
        'Start to finish with no cuts, including the twenty minutes where absolutely nothing worked. Chords are in the description.',
        'youtube','16:9',1344,'1280/720','640/360',0.23,0.050
      UNION ALL SELECT 'CREATIVE',4,'photo','Studio, 2am',
        'The only hour the city is quiet enough to record in. Most of what you have heard this year was made in this room.',
        'upload','4:5',NULL,'1080/1350','540/675',0.32,0.087
      UNION ALL SELECT 'CREATIVE',5,'reel','Recreating a 90s Bollywood Frame',
        'One light, one bedsheet and a great deal of colour grading. Guess the film before the reveal at the end.',
        'instagram','9:16',37,'1080/1920','540/960',0.88,0.065
      UNION ALL SELECT 'CREATIVE',6,'post','Type Design for Indian Scripts',
        'Spent a year on this Devanagari face. Sharing the details that took by far the longest to get right.',
        'instagram','1:1',NULL,'1080/1080','540/540',0.35,0.070
      UNION ALL SELECT 'CREATIVE',7,'video','Behind the Scenes of the Diwali Campaign',
        'Three cities, eleven days and a crew of six. The full making-of, including everything that went wrong on day two.',
        'youtube','16:9',978,'1280/720','640/360',0.19,0.046

      /* ---------------- KNOW ---------------- */
      UNION ALL SELECT 'KNOW',0,'reel','The 50-30-20 Rule, Indian Salary Edition',
        'It runs beautifully on a spreadsheet and falls apart on a sixty thousand rupee salary in Mumbai. Here is what to do instead.',
        'instagram','9:16',58,'1080/1920','540/960',1.25,0.059
      UNION ALL SELECT 'KNOW',1,'reel','Old Tax Regime or New - 60 Seconds',
        'One question decides it for most salaried people. Answer it before you file your March declaration.',
        'instagram','9:16',56,'1080/1920','540/960',0.90,0.055
      UNION ALL SELECT 'KNOW',2,'post','How Compounding Actually Feels',
        'Ten years of SIP data plotted honestly. The first four years are deeply boring and that is precisely the point.',
        'instagram','4:5',NULL,'1080/1350','540/675',0.48,0.080
      UNION ALL SELECT 'KNOW',3,'video','Your First Job Offer, Decoded',
        'CTC versus in-hand, what gratuity really means, and the three clauses worth negotiating before you sign anything.',
        'youtube','16:9',1092,'1280/720','640/360',0.24,0.049
      UNION ALL SELECT 'KNOW',4,'photo','Whiteboard Sunday',
        'One concept, one whiteboard and no slides. This is how I explain risk to anyone who asks me at a family function.',
        'upload','4:5',NULL,'1080/1350','540/675',0.25,0.076
      UNION ALL SELECT 'KNOW',5,'reel','Three Free Certifications Worth Doing',
        'All three are genuinely recognised by hiring managers here, and all three take under six weeks to finish.',
        'instagram','9:16',49,'1080/1920','540/960',0.80,0.057
      UNION ALL SELECT 'KNOW',6,'post','Emergency Fund: The Real Number',
        'Six months of expenses is the standard advice. For freelancers in India the honest answer is closer to nine.',
        'instagram','1:1',NULL,'1080/1080','540/540',0.39,0.072
      UNION ALL SELECT 'KNOW',7,'video','Reading a Balance Sheet in 20 Minutes',
        'No finance background needed. We go line by line through a real listed Indian company and its last annual report.',
        'youtube','16:9',1236,'1280/720','640/360',0.20,0.046

      /* ---------------- PARENT ---------------- */
      UNION ALL SELECT 'PARENT',0,'reel','School Mornings Without the Shouting',
        'The night-before routine that cut our morning chaos in half. It took three weeks to stick and was worth every one.',
        'instagram','9:16',33,'1080/1920','540/960',1.28,0.062
      UNION ALL SELECT 'PARENT',1,'reel','Lunchboxes That Come Back Empty',
        'Five boxes, five days, all packed in under twelve minutes. Number four is the only one she never trades away.',
        'instagram','9:16',46,'1080/1920','540/960',0.96,0.059
      UNION ALL SELECT 'PARENT',2,'post','Two Weeks With the New Stroller',
        'Honest notes after real use on Indian footpaths, which is a completely different test from a showroom floor.',
        'instagram','4:5',NULL,'1080/1350','540/675',0.42,0.075
      UNION ALL SELECT 'PARENT',3,'video','Our Bedtime Routine, Start to Finish',
        'The full ninety minutes from dinner to lights out, including the part where nothing at all goes to plan.',
        'youtube','16:9',864,'1280/720','640/360',0.21,0.047
      UNION ALL SELECT 'PARENT',4,'photo','First Day of Class One',
        'The uniform is one size too big entirely on purpose. Some traditions are non-negotiable in this house.',
        'upload','4:5',NULL,'1080/1350','540/675',0.30,0.084
      UNION ALL SELECT 'PARENT',5,'reel','Screen Time Rules We Actually Follow',
        'Three rules, no negotiating, and the one exception we allow. Six months in and it is still holding up.',
        'instagram','9:16',32,'1080/1920','540/960',0.75,0.060
      UNION ALL SELECT 'PARENT',6,'post','What Nobody Tells You About Year One',
        'The honest version. Nobody mentions how much of it is admin and how little of it is actual instinct.',
        'instagram','1:1',NULL,'1080/1080','540/540',0.36,0.071
      UNION ALL SELECT 'PARENT',7,'video','Weekend Meal Prep for a Family of Four',
        'Three hours on a Sunday that buys back an hour every weekday. Full menu and container list in the description.',
        'youtube','16:9',936,'1280/720','640/360',0.18,0.045

      /* ---------------- AUTO ---------------- */
      UNION ALL SELECT 'AUTO',0,'reel','First Ride: 400cc in City Traffic',
        'Twenty minutes through Bengaluru peak hour is a better test than any track day. Heat management is the real story here.',
        'instagram','9:16',34,'1080/1920','540/960',1.32,0.060
      UNION ALL SELECT 'AUTO',1,'reel','The Cheapest Car Worth Buying Now',
        'Under eight lakh on-road, five stars on safety, and it is not the one everyone in the comments recommends.',
        'instagram','9:16',47,'1080/1920','540/960',0.93,0.056
      UNION ALL SELECT 'AUTO',2,'post','Ownership Report: 40,000 km',
        'Two years, four states and one very expensive clutch job. The full running cost breakdown is in the caption.',
        'instagram','4:5',NULL,'1080/1350','540/675',0.41,0.072
      UNION ALL SELECT 'AUTO',3,'video','Full Road Test - Highway and City',
        'Six hundred kilometres across two days. Real mileage, real ride quality and an honest verdict against the direct rival.',
        'youtube','16:9',1356,'1280/720','640/360',0.25,0.051
      UNION ALL SELECT 'AUTO',4,'photo','Sunrise Run on the Old Highway',
        'Left at four just to get the empty road. This stretch is the reason I bought this bike in the first place.',
        'upload','4:5',NULL,'1080/1350','540/675',0.28,0.080
      UNION ALL SELECT 'AUTO',5,'reel','Three Checks Before You Buy Used',
        'Skip these and you inherit somebody else''s problem. The second one takes about thirty seconds to do.',
        'instagram','9:16',31,'1080/1920','540/960',0.77,0.058
      UNION ALL SELECT 'AUTO',6,'post','EV After One Year in Delhi',
        'Charging, real range in winter and the true cost per kilometre. Sharing all of it, including what I got wrong.',
        'instagram','1:1',NULL,'1080/1080','540/540',0.34,0.068
      UNION ALL SELECT 'AUTO',7,'video','Modifying a Classic 350 - Full Build',
        'Eight weeks, one workshop in Pune and a complete build sheet with every part and every price listed.',
        'youtube','16:9',1104,'1280/720','640/360',0.19,0.046
    ) f ON f.pack = cx.pack AND f.slot = sl.n
) q;


-- -------------------------------------------------------------------------------------
-- 3. creator_availability  (ids 10000 + (creator_id - 100) * 100 + dayOffset)
--
--    Next 60 days. Roughly 42% of days are published per creator (~25 rows each), which
--    is how a real creator calendar looks: some days open, some fully booked, Sundays
--    off, and occasional blocked stretches for travel or edit weeks.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO `creator_availability`
  (`id`, `creator_id`, `available_on`, `is_available`, `slots`, `max_bookings`,
   `booked_count`, `note`, `created_at`, `updated_at`)
SELECT
  q.aid,
  q.cid,
  q.dt,
  CASE WHEN q.is_sunday = 1 THEN 0 WHEN q.role >= 86 THEN 0 ELSE 1 END,
  CASE
    WHEN q.is_sunday = 1 OR q.role >= 86 THEN NULL
    WHEN MOD(q.role, 3) = 0 THEN '[{"from":"10:00","to":"13:00"},{"from":"15:00","to":"18:00"}]'
    WHEN MOD(q.role, 3) = 1 THEN '[{"from":"09:00","to":"12:00"}]'
    ELSE '[{"from":"11:00","to":"14:00"},{"from":"16:00","to":"20:00"}]'
  END,
  CASE WHEN q.is_sunday = 1 OR q.role >= 86 THEN 1 ELSE 1 + MOD(q.role, 3) END,
  CASE
    WHEN q.is_sunday = 1 OR q.role >= 86 THEN 0
    WHEN q.role >= 66 THEN 1 + MOD(q.role, 3)
    WHEN q.role >= 50 THEN 1
    ELSE 0
  END,
  CASE
    WHEN q.is_sunday = 1 THEN 'Weekly off - no shoots on Sundays'
    WHEN q.role >= 86 THEN ELT(1 + MOD(q.role, 4),
      'Blocked - travelling for a brand shoot',
      'Blocked - personal leave',
      'Blocked - edit week, no new bookings',
      'Blocked - festival break with family')
    WHEN q.role >= 66 THEN 'Fully booked - shoot day'
    WHEN q.role >= 50 THEN 'One slot left - shared shoot day'
    ELSE NULL
  END,
  DATE_SUB(NOW(), INTERVAL MOD(q.cid + q.n, 20) DAY),
  DATE_SUB(NOW(), INTERVAL MOD(q.cid * 3 + q.n, 9) DAY)
FROM (
  SELECT
    10000 + (c.id - 100) * 100 + d.n AS aid,
    c.id AS cid,
    d.n AS n,
    DATE_ADD(CURDATE(), INTERVAL d.n DAY) AS dt,
    IF(DAYOFWEEK(DATE_ADD(CURDATE(), INTERVAL d.n DAY)) = 1, 1, 0) AS is_sunday,
    MOD(CRC32(CONCAT('role-', c.id, ':', d.n)), 100) AS role
  FROM `creators` c
  CROSS JOIN (
    SELECT a.n + b.n * 10 AS n
    FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
          SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL
          SELECT 8 UNION ALL SELECT 9) a
    CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
                SELECT 4 UNION ALL SELECT 5) b
  ) d
  WHERE c.id BETWEEN 101 AND 145
    AND c.deleted_at IS NULL
    AND MOD(CRC32(CONCAT('pick-', c.id, ':', d.n)), 100) < 42
) q;


-- -------------------------------------------------------------------------------------
-- 4. creator_analytics_daily  (composite PK: creator_id + stat_date)
--
--    Last 90 days for the 20 creators with the highest reach. Values are not flat
--    random: profile views ramp from ~55% to 100% of the creator's ceiling across the
--    window (an upward trend), dip on Saturdays and Sundays, and carry +/-13% noise.
--    Booking requests derive from views (with ~30% of days drawing none at all),
--    accepted derive from requests, and a completion only lands on the minority of
--    days where an earlier booking is actually delivered - so a creator books a
--    handful of jobs a month rather than one every day. Caps: <=4 requests, <=3
--    accepted, <=2 completed per day.
--    Earnings are completed x the creator's mid-band price net of the
--    15% platform commission. Rating is only recorded on days with a completion.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO `creator_analytics_daily`
  (`creator_id`, `stat_date`, `profile_views`, `booking_requests`, `bookings_accepted`,
   `bookings_completed`, `earnings`, `new_followers`, `avg_rating`, `computed_at`)
SELECT
  q4.cid,
  q4.d,
  q4.pv,
  q4.br,
  q4.ba,
  q4.bc,
  ROUND(q4.bc * q4.avg_price * 0.85, 2),
  FLOOR(q4.pv * (10 + MOD(CRC32(CONCAT('nf-', q4.cid, '-', q4.n)), 30)) / 100)
    + MOD(CRC32(CONCAT('nf2-', q4.cid, '-', q4.n)), 5),
  IF(q4.bc > 0,
     LEAST(5.00, 4.10 + MOD(CRC32(CONCAT('ar-', q4.cid, '-', q4.n)), 80) / 100),
     0.00),
  LEAST(NOW(), TIMESTAMP(q4.d, '23:45:00'))
FROM (
  SELECT q3.cid, q3.n, q3.d, q3.avg_price, q3.pv, q3.br, q3.ba,
         IF(MOD(CRC32(CONCAT('done-', q3.cid, '-', q3.n)), 100) < 82, 0, LEAST(2, q3.ba)) AS bc
  FROM (
    SELECT q2.cid, q2.n, q2.d, q2.avg_price, q2.pv, q2.br,
           LEAST(3, ROUND(q2.br * (40 + MOD(CRC32(CONCAT('ba-', q2.cid, '-', q2.n)), 45)) / 100)) AS ba
    FROM (
      SELECT q1.cid, q1.n, q1.d, q1.avg_price, q1.pv,
             IF(MOD(CRC32(CONCAT('gate-', q1.cid, '-', q1.n)), 100) < 30, 0,
                LEAST(4, 1 + FLOOR(q1.pv * (1 + MOD(CRC32(CONCAT('br-', q1.cid, '-', q1.n)), 3)) / 200))) AS br
      FROM (
        SELECT
          t.cid,
          nn.n,
          DATE_SUB(CURDATE(), INTERVAL nn.n DAY) AS d,
          t.avg_price,
          GREATEST(3, ROUND(
              (8 + LEAST(420, t.ft / 8000))
            * (0.55 + 0.45 * (90 - nn.n) / 90)
            * (CASE WHEN DAYOFWEEK(DATE_SUB(CURDATE(), INTERVAL nn.n DAY)) IN (1, 7)
                    THEN 0.74 ELSE 1.08 END)
            * ((87 + MOD(CRC32(CONCAT('pv-', t.cid, '-', nn.n)), 27)) / 100)
          )) AS pv
        FROM (
          SELECT c.id AS cid, c.followers_total AS ft,
            ((CASE WHEN c.price_max > 0 THEN LEAST(c.price_min, c.price_max)
                   WHEN c.followers_total < 50000 THEN 3000
                   WHEN c.followers_total < 250000 THEN 15000
                   WHEN c.followers_total < 1000000 THEN 75000
                   ELSE 300000 END)
             + (CASE WHEN c.price_max > 0 THEN c.price_max
                     WHEN c.followers_total < 50000 THEN 15000
                     WHEN c.followers_total < 250000 THEN 75000
                     WHEN c.followers_total < 1000000 THEN 300000
                     ELSE 1200000 END)) / 2 AS avg_price
          FROM `creators` c
          WHERE c.id BETWEEN 101 AND 145 AND c.deleted_at IS NULL
          ORDER BY c.followers_total DESC, c.id ASC
          LIMIT 20
        ) t
        CROSS JOIN (
          SELECT a.n + b.n * 10 AS n
          FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
                SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL
                SELECT 8 UNION ALL SELECT 9) a
          CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
                      SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL
                      SELECT 8) b
        ) nn
      ) q1
    ) q2
  ) q3
) q4;


-- -------------------------------------------------------------------------------------
-- 5. creator_profile_views  (ids 5001-5400)
--
--    400 raw profile-view events across the last 60 days, aimed at the 20 highest-reach
--    creators. Roughly 62% are attributed to brand users 1001-1020 (resolved through a
--    lookup so the row degrades to anonymous if that user is absent) and the rest are
--    logged out. Source mix is weighted towards search and category browsing.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO `creator_profile_views`
  (`id`, `creator_id`, `viewer_id`, `source`, `viewed_on`, `created_at`)
SELECT
  5000 + (q.rn - 1) * 20 + q.n + 1,
  q.cid,
  IF(q.anon < 62,
     (SELECT u.id FROM `users` u
       WHERE u.id = 1001 + MOD(CRC32(CONCAT('bu-', q.cid, '-', q.n)), 20)),
     NULL),
  CASE
    WHEN q.src < 34 THEN 'search'
    WHEN q.src < 54 THEN 'category'
    WHEN q.src < 72 THEN 'home'
    WHEN q.src < 84 THEN 'direct'
    WHEN q.src < 93 THEN 'explore'
    ELSE 'share'
  END,
  q.vd,
  LEAST(NOW(),
        TIMESTAMP(q.vd)
          + INTERVAL (8 + MOD(CRC32(CONCAT('hh-', q.cid, '-', q.n)), 14)) HOUR
          + INTERVAL MOD(CRC32(CONCAT('mm-', q.cid, '-', q.n)), 60) MINUTE)
FROM (
  SELECT
    t.rn,
    t.cid,
    nn.n,
    MOD(CRC32(CONCAT('anon-', t.cid, '-', nn.n)), 100) AS anon,
    MOD(CRC32(CONCAT('src-', t.cid, '-', nn.n)), 100) AS src,
    DATE_SUB(CURDATE(), INTERVAL MOD(CRC32(CONCAT('day-', t.cid, '-', nn.n)), 60) DAY) AS vd
  FROM (
    SELECT c.id AS cid,
           ROW_NUMBER() OVER (ORDER BY c.followers_total DESC, c.id ASC) AS rn
    FROM `creators` c
    WHERE c.id BETWEEN 101 AND 145 AND c.deleted_at IS NULL
    ORDER BY c.followers_total DESC, c.id ASC
    LIMIT 20
  ) t
  CROSS JOIN (
    SELECT a.n + b.n * 10 AS n
    FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
          SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL
          SELECT 8 UNION ALL SELECT 9) a
    CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1) b
  ) nn
) q;


-- =====================================================================================
-- End of 021_demo_creator_depth.sql
-- =====================================================================================
