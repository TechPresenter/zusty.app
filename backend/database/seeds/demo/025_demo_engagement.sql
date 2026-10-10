/* ============================================================================
 * 025_demo_engagement.sql — Zusty demo data: social & communication layer
 * ----------------------------------------------------------------------------
 * Seeds the engagement surface the app home screen, creator profile, inbox and
 * notification centre read from.
 *
 * Tables and id ranges owned by this file
 *   reviews                 701 - 760   (brand_to_creator 701-745, creator_to_brand 746-760)
 *   favorites               composite pk (brand user 1001-1020 x creator 101-145)
 *   follows                 composite pk (user 1001-1020 / 2001-2045 x creator 101-145)
 *   notifications          2101 - 2300
 *   conversations           801 - 830
 *   conversation_participants  composite pk on conversations 801-830
 *   messages                901 - 1280
 *   message_receipts        composite pk on messages 901-1280
 *   referrals                  1 - 12
 *   search_histories        3001 - 3110
 *   popular_searches          31 - 70  (plus hit counts refreshed on curated 1-8)
 *
 * Depends on (seeded by earlier files, referenced never re-inserted)
 *   users     brands 1001-1020, creators 2001-2045
 *   creators  101-145            (creators.user_id = 2000 + (id - 100))
 *   bookings  401-480
 *   notification_templates  — every notifications.type below is an existing
 *                             notification_templates.code, verified against the
 *                             live table. No invented types.
 *
 * Design notes
 *   - Reviews are derived with INSERT ... SELECT against bookings where
 *     status = 'completed', so booking / reviewer / reviewee / creator ids are
 *     always internally consistent no matter which bookings the booking seeder
 *     marked complete.
 *   - Conversations, participants and messages are derived from the same
 *     deterministic ROW_NUMBER() ordering over bookings 401-480, so sender ids
 *     always match the thread participants.
 *   - Message bodies come from five negotiation scripts with {brand},
 *     {creator}, {city} and rate placeholders resolved at insert time from the
 *     real joined rows, so every thread reads as its own conversation.
 *   - Every statement is INSERT IGNORE / ON DUPLICATE KEY UPDATE and every
 *     UPDATE is idempotent, so the whole file is safely re-runnable.
 * ========================================================================== */


/* ==========================================================================
 * 1. REVIEWS  (701 - 760)
 * ========================================================================== */

/* -- 1a. brand -> creator, ids 701-745 ------------------------------------ */
INSERT IGNORE INTO reviews
    (id, booking_id, direction, reviewer_id, reviewee_id, creator_id,
     rating, quality_rating, communication_rating, timeliness_rating,
     title, comment, media_urls, reply, replied_at,
     is_published, is_flagged, helpful_count, created_at, updated_at)
SELECT
    700 + t.rn,
    t.bid,
    'brand_to_creator',
    t.brand_user_id,
    t.creator_user_id,
    t.creator_id,
    t.rating,
    LEAST(5, GREATEST(1, t.rating + IF(t.rn % 4 = 0, -1, 0))),
    LEAST(5, GREATEST(1, t.rating + IF(t.rn % 5 = 0, -1, IF(t.rn % 7 = 0 AND t.rating < 5, 1, 0)))),
    LEAST(5, GREATEST(1, t.rating + IF(t.rn % 3 = 0, -1, 0))),
    CASE t.rating
        WHEN 5 THEN ELT(1 + (t.rn % 4),
                'Brilliant collaboration',
                'Exceeded every expectation',
                'Studio quality on a creator budget',
                'Would book again immediately')
        WHEN 4 THEN ELT(1 + (t.rn % 3),
                'Good work, one small delay',
                'Strong content, minor notes',
                'Happy with the outcome')
        WHEN 3 THEN ELT(1 + (t.rn % 2),
                'Average, needed follow ups',
                'Fine, but not memorable')
        ELSE ELT(1 + (t.rn % 2),
                'Missed the deadline and the brief',
                'Below the portfolio standard')
    END,
    CASE t.rating
        WHEN 5 THEN ELT(1 + (t.rn % 8),
            'Outstanding from start to finish. The reel was exactly on brief, the lighting was clean and the final cut landed a full day before the deadline. Engagement was close to three times what our in-house content usually pulls.',
            'Genuinely one of the smoothest collaborations we have run this year. Clear communication throughout, drafts shared on the promised dates and both rounds of feedback were taken without any friction.',
            'The storytelling was excellent. Instead of a flat product mention we got a real routine our audience could relate to, and the comments were full of people asking where to buy.',
            'Very professional. Shared a shot list before the shoot, confirmed the hook line with us and handed over the raw footage along with the final cut. Already planning to book again for the festive campaign.',
            'Turnaround was quick. The first draft came in within four days and the post went live exactly on the agreed slot. Footage quality was close to studio standard on a fraction of the budget.',
            'Fantastic grasp of our brand tone. We barely had to give feedback at all. The carousel design, the caption and the disclosure tag all matched our guidelines on the first pass.',
            'Delivered above the scope. We paid for one reel and received a second alternate cut because the other angle looked good and she wanted us to have options. That kind of ownership is rare.',
            'Saves and shares went through the roof and we saw a clear spike in website sessions the same evening the reel went up. Worth every rupee and the whole process was completely hassle free.')
        WHEN 4 THEN ELT(1 + (t.rn % 6),
            'Good content and a genuinely easy person to work with. The first draft needed two rounds on the caption, but everything after that moved smoothly.',
            'Happy with the final output. Delivery slipped by two days past the agreed date, which we had to absorb internally, but the reel itself performed well.',
            'Solid work. The video quality and the edit were both strong. We would have liked a little more product visibility in the opening three seconds.',
            'Professional and quick to reply on chat. The story set was the highlight, the main post felt a little safe compared to her usual feed.',
            'Delivered on time and stuck to the brief. Audio mixing on the reel could have been tighter, but the visuals carried it comfortably.',
            'Very pleasant to coordinate with and open to feedback. We will work together again, just with a tighter brief on the hook next time.')
        WHEN 3 THEN ELT(1 + (t.rn % 4),
            'The content was acceptable but not memorable. It took three rounds of revisions to get the product framing right, and replies over the weekend were slow.',
            'Average outcome. The reel was delivered late and engagement came in well below what the profile numbers suggested we would get.',
            'Okay experience. The creative itself was fine, but we had to keep following up for the draft and the upload happened a day after the agreed slot.',
            'Mixed. The photography was good, the video edit felt rushed. Communication stayed polite but we chased updates more than we would like to.')
        ELSE ELT(1 + (t.rn % 2),
            'Disappointing. The deliverable came in six days late, the hook we had signed off on was dropped without telling us, and the paid partnership tag was missing on the first upload.',
            'Not what we expected from the portfolio. Two rounds of revision notes were ignored, the product name was mispronounced in the voiceover and follow ups took days to get answered.')
    END,
    CASE WHEN t.rn % 6 = 0
         THEN CONCAT('["https://picsum.photos/seed/zreview-', t.rn, '-a/900/900","https://picsum.photos/seed/zreview-', t.rn, '-b/900/900"]')
         ELSE NULL END,
    CASE WHEN t.rn % 3 = 0 THEN
        CASE WHEN t.rating <= 3 THEN
            ELT(1 + (t.rn % 2),
                CONCAT('Thank you for the honest feedback, ', COALESCE(bu.company_name, bu.name, 'team'), '. The delay was on me and I have moved to a two day draft turnaround since, precisely so this does not repeat.'),
                CONCAT('Apologies again for the back and forth, ', COALESCE(bu.company_name, bu.name, 'team'), '. I have rebuilt my shoot calendar and tightened my revision process. I would like a chance to make the next one right.'))
        ELSE
            ELT(1 + (t.rn % 3),
                CONCAT('Thank you so much, ', COALESCE(bu.company_name, bu.name, 'team'), '! It was a joy shooting this one. The brief was clear and the product genuinely fit my routine. Looking forward to the next drop.'),
                CONCAT('Really appreciate this, ', COALESCE(bu.company_name, bu.name, 'team'), '. Thank you for trusting my call on the hook line, that is honestly what made the reel work.'),
                CONCAT('Thank you for the kind words, ', COALESCE(bu.company_name, bu.name, 'team'), '. And thank you for approving the draft so quickly, that is what let us hit the launch window.'))
        END
    ELSE NULL END,
    CASE WHEN t.rn % 3 = 0
         THEN LEAST(NOW(), DATE_ADD(COALESCE(t.completed_at, DATE_SUB(NOW(), INTERVAL 70 DAY)), INTERVAL (5 + t.rn % 7) DAY))
         ELSE NULL END,
    1,
    0,
    (t.rn * 3) % 17,
    LEAST(NOW(), DATE_ADD(COALESCE(t.completed_at, DATE_SUB(NOW(), INTERVAL 72 DAY)), INTERVAL (2 + t.rn % 6) DAY)),
    LEAST(NOW(), DATE_ADD(COALESCE(t.completed_at, DATE_SUB(NOW(), INTERVAL 72 DAY)), INTERVAL (2 + t.rn % 6) DAY))
FROM (
    SELECT s.*,
           CASE
               WHEN s.rn % 23 = 9 THEN 2
               WHEN s.rn % 7  = 4 THEN 3
               WHEN s.rn % 3  = 2 THEN 4
               ELSE 5
           END AS rating
    FROM (
        SELECT b.id            AS bid,
               b.brand_user_id AS brand_user_id,
               b.creator_user_id AS creator_user_id,
               b.creator_id    AS creator_id,
               b.completed_at  AS completed_at,
               ROW_NUMBER() OVER (ORDER BY b.id) AS rn
        FROM bookings b
        WHERE b.status = 'completed'
          AND b.deleted_at IS NULL
          AND b.id BETWEEN 401 AND 480
    ) s
) t
LEFT JOIN users bu ON bu.id = t.brand_user_id
WHERE t.rn <= 45;


/* -- 1b. creator -> brand, ids 746-760 ------------------------------------ */
INSERT IGNORE INTO reviews
    (id, booking_id, direction, reviewer_id, reviewee_id, creator_id,
     rating, quality_rating, communication_rating, timeliness_rating,
     title, comment, is_published, is_flagged, helpful_count, created_at, updated_at)
SELECT
    745 + t.rn,
    t.bid,
    'creator_to_brand',
    t.creator_user_id,
    t.brand_user_id,
    NULL,
    t.rating,
    t.rating,
    LEAST(5, GREATEST(1, t.rating + IF(t.rn % 4 = 0, -1, 0))),
    LEAST(5, GREATEST(1, t.rating + IF(t.rn % 5 = 0, -1, 0))),
    ELT(1 + (t.rn % 5),
        'Professional brand, prompt payout',
        'Clear brief, real creative freedom',
        'Smooth from brief to payment',
        'Good campaign, approvals were slow',
        'Would happily collaborate again'),
    ELT(1 + (t.rn % 5),
        'The brief was clear from day one, the product reached me well before the shoot date and the escrow was released the same week the campaign went live. Very easy brand to work with.',
        'Great team to collaborate with. They gave me full freedom on the hook and only asked for factual corrections on the specifications. Payout came through without a single reminder.',
        'Smooth booking overall. Approvals took a little longer than promised over the long weekend, but everything else was handled professionally.',
        'Genuinely enjoyed this campaign. The moodboard was detailed, the shipping was tracked end to end and nobody asked me to soften my honest opinion in the video.',
        'Good experience. My only ask for next time would be to share the shot list before the shoot date rather than after it, so we can plan the day better.'),
    1,
    0,
    (t.rn * 5) % 11,
    LEAST(NOW(), DATE_ADD(COALESCE(t.completed_at, DATE_SUB(NOW(), INTERVAL 68 DAY)), INTERVAL (3 + t.rn % 5) DAY)),
    LEAST(NOW(), DATE_ADD(COALESCE(t.completed_at, DATE_SUB(NOW(), INTERVAL 68 DAY)), INTERVAL (3 + t.rn % 5) DAY))
FROM (
    SELECT s.*,
           CASE
               WHEN s.rn % 9 = 5 THEN 3
               WHEN s.rn % 4 = 1 THEN 4
               ELSE 5
           END AS rating
    FROM (
        SELECT b.id              AS bid,
               b.brand_user_id   AS brand_user_id,
               b.creator_user_id AS creator_user_id,
               b.completed_at    AS completed_at,
               ROW_NUMBER() OVER (ORDER BY b.id) AS rn
        FROM bookings b
        WHERE b.status = 'completed'
          AND b.deleted_at IS NULL
          AND b.id BETWEEN 401 AND 480
    ) s
) t
WHERE t.rn <= 15;


/* -- 1c. keep bookings + creators consistent with the reviews just written - */
UPDATE bookings b
  JOIN reviews r ON r.booking_id = b.id AND r.direction = 'brand_to_creator'
   SET b.brand_reviewed = 1
 WHERE b.id BETWEEN 401 AND 480;

UPDATE bookings b
  JOIN reviews r ON r.booking_id = b.id AND r.direction = 'creator_to_brand'
   SET b.creator_reviewed = 1
 WHERE b.id BETWEEN 401 AND 480;

UPDATE creators c
  JOIN (
        SELECT r.creator_id, COUNT(*) AS n, AVG(r.rating) AS avg_rating
          FROM reviews r
         WHERE r.direction = 'brand_to_creator'
           AND r.is_published = 1
           AND r.deleted_at IS NULL
           AND r.creator_id IS NOT NULL
         GROUP BY r.creator_id
       ) x ON x.creator_id = c.id
   SET c.rating_avg   = ROUND(x.avg_rating, 2),
       c.rating_count = x.n
 WHERE c.id BETWEEN 101 AND 145;


/* ==========================================================================
 * 2. FAVORITES — brands 1001-1020 shortlisting creators 101-145
 *    Deterministic hash spread: 5-10 saved creators per brand, ~148 rows,
 *    weighted towards the higher profile creators (101-110).
 * ========================================================================== */
INSERT IGNORE INTO favorites (user_id, creator_id, created_at)
SELECT u.id,
       c.id,
       DATE_SUB(NOW(), INTERVAL (4 + ((u.id * 17 + c.id * 29) % 330)) DAY)
FROM users u
JOIN creators c
  ON c.id BETWEEN 101 AND 145
 AND c.deleted_at IS NULL
WHERE u.id BETWEEN 1001 AND 1020
  AND u.account_type = 'brand'
  AND u.deleted_at IS NULL
  AND ((u.id * 41 + c.id * 23) % 100) <
      CASE WHEN c.id <= 110 THEN 26
           WHEN c.id <= 128 THEN 17
           ELSE 10 END;

/* ==========================================================================
 * 3. FOLLOWS — brands and creators following creators (~395 rows),
 *    heavily concentrated on creators 101-108, thinning out towards 145.
 * ========================================================================== */
INSERT IGNORE INTO follows (follower_id, creator_id, created_at)
SELECT u.id,
       c.id,
       DATE_SUB(NOW(), INTERVAL (3 + ((u.id * 13 + c.id * 7) % 400)) DAY)
FROM users u
JOIN creators c
  ON c.id BETWEEN 101 AND 145
 AND c.deleted_at IS NULL
 AND c.user_id <> u.id
WHERE u.deleted_at IS NULL
  AND (u.id BETWEEN 1001 AND 1020 OR u.id BETWEEN 2001 AND 2045)
  AND ((u.id * 31 + c.id * 17) % 100) <
      CASE WHEN c.id <= 108 THEN 30
           WHEN c.id <= 118 THEN 18
           WHEN c.id <= 130 THEN 10
           ELSE 5 END;

UPDATE creators c
   SET c.platform_followers = (SELECT COUNT(*) FROM follows f WHERE f.creator_id = c.id)
 WHERE c.id BETWEEN 101 AND 145;


/* ==========================================================================
 * 4. CONVERSATIONS (801-830) + PARTICIPANTS
 *    Derived from the first 30 bookings in id order so the two participants
 *    are always the real brand and creator on that booking. 801-826 are
 *    booking threads, 827-830 are pre-booking direct enquiries.
 * ========================================================================== */
INSERT IGNORE INTO conversations
    (id, uuid, type, booking_id, subject, messages_count, is_archived, is_locked, created_at, updated_at)
SELECT
    800 + s.rn,
    CONCAT('cafe0000-0000-4000-8000-', LPAD(800 + s.rn, 12, '0')),
    IF(s.rn <= 26, 'booking', 'direct'),
    IF(s.rn <= 26, s.bid, NULL),
    LEFT(COALESCE(s.btitle, 'Collaboration enquiry'), 191),
    0,
    IF(s.rn % 13 = 0, 1, 0),
    0,
    DATE_SUB(COALESCE(s.bcreated, DATE_SUB(NOW(), INTERVAL 95 DAY)), INTERVAL 2 DAY),
    DATE_SUB(COALESCE(s.bcreated, DATE_SUB(NOW(), INTERVAL 95 DAY)), INTERVAL 2 DAY)
FROM (
    SELECT b.id AS bid, b.title AS btitle, b.created_at AS bcreated,
           ROW_NUMBER() OVER (ORDER BY b.id) AS rn
    FROM bookings b
    WHERE b.deleted_at IS NULL AND b.id BETWEEN 401 AND 480
) s
WHERE s.rn <= 30;

INSERT IGNORE INTO conversation_participants
    (conversation_id, user_id, role, unread_count, is_muted, is_typing, joined_at)
SELECT 800 + s.rn, s.brand_user_id, 'brand', 0, 0, 0,
       DATE_SUB(COALESCE(s.bcreated, DATE_SUB(NOW(), INTERVAL 95 DAY)), INTERVAL 2 DAY)
FROM (
    SELECT b.brand_user_id, b.creator_user_id, b.created_at AS bcreated,
           ROW_NUMBER() OVER (ORDER BY b.id) AS rn
    FROM bookings b
    WHERE b.deleted_at IS NULL AND b.id BETWEEN 401 AND 480
) s
WHERE s.rn <= 30;

INSERT IGNORE INTO conversation_participants
    (conversation_id, user_id, role, unread_count, is_muted, is_typing, joined_at)
SELECT 800 + s.rn, s.creator_user_id, 'creator', 0, IF(s.rn % 11 = 0, 1, 0), 0,
       DATE_SUB(COALESCE(s.bcreated, DATE_SUB(NOW(), INTERVAL 95 DAY)), INTERVAL 2 DAY)
FROM (
    SELECT b.brand_user_id, b.creator_user_id, b.created_at AS bcreated,
           ROW_NUMBER() OVER (ORDER BY b.id) AS rn
    FROM bookings b
    WHERE b.deleted_at IS NULL AND b.id BETWEEN 401 AND 480
) s
WHERE s.rn <= 30;


/* ==========================================================================
 * 5. MESSAGES (901-1280)
 *    Five negotiation scripts of 25 turns each. Thread n takes the first
 *    `cnt` turns of script (1 + conversation_id mod 5). Placeholders are
 *    resolved from the real brand, creator, city and agreed rate.
 * ========================================================================== */
INSERT IGNORE INTO messages
    (id, uuid, conversation_id, sender_id, sender_type, type, body,
     media_url, thumbnail_url, media_mime, media_size, media_width, media_height,
     metadata, delivered_count, read_count, is_edited, created_at)
SELECT
    k.base + q.seq,
    CONCAT('beef0000-0000-4000-8000-', LPAD(k.base + q.seq, 12, '0')),
    k.cid,
    IF(sc.who = 'b', cpb.user_id, cpc.user_id),
    'user',
    sc.mtype,
    REPLACE(
      REPLACE(
        REPLACE(
          REPLACE(
            REPLACE(
              REPLACE(sc.body, '{brand}',   COALESCE(bu.company_name, bu.name, 'the brand team')),
            '{creator}', COALESCE(cr.display_name, cu.name, 'there')),
          '{city}',   COALESCE(cr.city, 'Mumbai')),
        '{amt}',  FORMAT(COALESCE(bk.amt, 45000), 0)),
      '{amt2}', FORMAT(ROUND(COALESCE(bk.amt, 45000) * 0.85), 0)),
    '{amt3}', FORMAT(ROUND(COALESCE(bk.amt, 45000) * 1.25), 0)),
    CASE WHEN sc.mtype = 'image'
         THEN CONCAT('https://picsum.photos/seed/zchat-', k.base + q.seq, '/720/1280') END,
    CASE WHEN sc.mtype = 'image'
         THEN CONCAT('https://picsum.photos/seed/zchat-', k.base + q.seq, '/180/320') END,
    CASE WHEN sc.mtype = 'image' THEN 'image/jpeg' END,
    CASE WHEN sc.mtype = 'image' THEN 214000 + ((k.base + q.seq) * 137) % 380000 END,
    CASE WHEN sc.mtype = 'image' THEN 720 END,
    CASE WHEN sc.mtype = 'image' THEN 1280 END,
    CASE WHEN sc.mtype = 'offer'
         THEN CONCAT('{"offer_amount":', ROUND(COALESCE(bk.amt, 45000), 2),
                     ',"currency":"INR","expires_in_hours":48,"revisions":1,"booking_id":',
                     COALESCE(bk.bid, 0), '}') END,
    0,
    0,
    0,
    LEAST(
      DATE_SUB(NOW(), INTERVAL 20 MINUTE),
      DATE_ADD(
        DATE_SUB(COALESCE(bk.bcreated, DATE_SUB(NOW(), INTERVAL 95 DAY)), INTERVAL 2 DAY),
        INTERVAL ((q.seq - 1) * (43 + (k.cid % 9) * 11) + ((q.seq * 7) % 31)) MINUTE))
FROM (
              SELECT 801 AS cid, 14 AS cnt,  900 AS base
    UNION ALL SELECT 802,  9,  914
    UNION ALL SELECT 803, 22,  923
    UNION ALL SELECT 804,  7,  945
    UNION ALL SELECT 805, 16,  952
    UNION ALL SELECT 806, 11,  968
    UNION ALL SELECT 807, 25,  979
    UNION ALL SELECT 808,  6, 1004
    UNION ALL SELECT 809, 18, 1010
    UNION ALL SELECT 810, 12, 1028
    UNION ALL SELECT 811,  8, 1040
    UNION ALL SELECT 812, 20, 1048
    UNION ALL SELECT 813, 10, 1068
    UNION ALL SELECT 814, 15, 1078
    UNION ALL SELECT 815,  5, 1093
    UNION ALL SELECT 816, 13, 1098
    UNION ALL SELECT 817,  9, 1111
    UNION ALL SELECT 818, 24, 1120
    UNION ALL SELECT 819, 11, 1144
    UNION ALL SELECT 820,  7, 1155
    UNION ALL SELECT 821, 17, 1162
    UNION ALL SELECT 822,  6, 1179
    UNION ALL SELECT 823, 12, 1185
    UNION ALL SELECT 824, 19, 1197
    UNION ALL SELECT 825,  8, 1216
    UNION ALL SELECT 826, 14, 1224
    UNION ALL SELECT 827, 10, 1238
    UNION ALL SELECT 828, 16, 1248
    UNION ALL SELECT 829,  7, 1264
    UNION ALL SELECT 830,  9, 1271
) k
JOIN (
              SELECT  1 AS seq
    UNION ALL SELECT  2 UNION ALL SELECT  3 UNION ALL SELECT  4 UNION ALL SELECT  5
    UNION ALL SELECT  6 UNION ALL SELECT  7 UNION ALL SELECT  8 UNION ALL SELECT  9
    UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13
    UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17
    UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20 UNION ALL SELECT 21
    UNION ALL SELECT 22 UNION ALL SELECT 23 UNION ALL SELECT 24 UNION ALL SELECT 25
) q ON q.seq <= k.cnt
JOIN (
    /* ---- script 1 : beauty / skincare launch reel ---------------------- */
              SELECT 1 AS scr,  1 AS seq, 'b' AS who, 'text' AS mtype, 'Hi {creator}, this is {brand}. We have been following your skincare series for a while and would love to work with you on the launch reel for our new vitamin C range.' AS body
    UNION ALL SELECT 1,  2, 'c', 'text', 'Hi {brand}, thank you so much for reaching out. I would love to hear more. Could you share the brief and the timeline you are working with?'
    UNION ALL SELECT 1,  3, 'b', 'text', 'Of course. One Instagram reel of 30 to 45 seconds plus two stories with a link sticker. Shot at your place, natural light, no studio needed. We go live in the first week of next month.'
    UNION ALL SELECT 1,  4, 'c', 'text', 'Noted. My rate for a reel plus two stories is INR {amt}, inclusive of one round of revisions. That is for organic usage on my handle.'
    UNION ALL SELECT 1,  5, 'b', 'text', 'That works for us. Can you also include 30 days of paid usage on Meta ads within the same rate if we lock the dates today?'
    UNION ALL SELECT 1,  6, 'c', 'text', 'Yes, I can include 30 days of paid usage at this rate. I will need the product at least five days before the shoot day though.'
    UNION ALL SELECT 1,  7, 'b', 'text', 'The courier goes out tomorrow from our Mumbai warehouse, you should have it in two days. I will drop the tracking link here.'
    UNION ALL SELECT 1,  8, 'b', 'image', 'Sharing the moodboard we are working from, mostly warm tones and a morning routine framing.'
    UNION ALL SELECT 1,  9, 'c', 'text', 'This is helpful, thank you. I will keep the same palette. I usually shoot at the bathroom counter and on the balcony, both fit this direction well.'
    UNION ALL SELECT 1, 10, 'b', 'offer', 'Sending across the formal offer on the platform so we can lock it in.'
    UNION ALL SELECT 1, 11, 'c', 'text', 'Offer accepted. The booking is confirmed from my side.'
    UNION ALL SELECT 1, 12, 'b', 'text', 'Payment is done and sitting in escrow. It releases to your Zusty wallet the moment we mark the campaign complete.'
    UNION ALL SELECT 1, 13, 'c', 'text', 'Perfect. Shoot is planned for Saturday morning and I will have the draft with you by Tuesday.'
    UNION ALL SELECT 1, 14, 'c', 'image', 'Quick behind the scenes frame from today. Wanted to check whether the background shade works for you.'
    UNION ALL SELECT 1, 15, 'b', 'text', 'The background is lovely. Only one ask, please keep the label facing the camera while you apply the serum.'
    UNION ALL SELECT 1, 16, 'c', 'text', 'Understood. Also confirming the hook line: three weeks, one serum, and the difference is on my face. Does that clear your legal check?'
    UNION ALL SELECT 1, 17, 'b', 'text', 'Legal is fine with that as long as we do not use the phrase clinically proven anywhere. Please keep the paid partnership tag on.'
    UNION ALL SELECT 1, 18, 'c', 'text', 'Paid partnership tag will be on both the reel and the stories. Sending the draft tonight.'
    UNION ALL SELECT 1, 19, 'c', 'text', 'Draft link is in your inbox. It is 38 seconds, and the two stories are cut from the same footage.'
    UNION ALL SELECT 1, 20, 'b', 'text', 'Watched it twice, it is really good. Two small notes: trim the intro by about a second, and can the pack shot hold a beat longer at the end?'
    UNION ALL SELECT 1, 21, 'c', 'text', 'Both done. Uploading version two now.'
    UNION ALL SELECT 1, 22, 'b', 'text', 'Approved. Please go live on Friday between 7 and 9 pm, that is consistently our best window.'
    UNION ALL SELECT 1, 23, 'c', 'text', 'Posted at 7:20 pm. Stories are up as well with the link sticker.'
    UNION ALL SELECT 1, 24, 'b', 'text', 'Numbers look great. 41k views on day one and the link clicks are well above our benchmark. Thank you {creator}.'
    UNION ALL SELECT 1, 25, 'c', 'text', 'So glad it worked out. Marking the deliverables as submitted on the booking. I would love to do the next drop with you.'

    /* ---- script 2 : fashion / festive edit ------------------------------ */
    UNION ALL SELECT 2,  1, 'b', 'text', 'Hello {creator}, {brand} here. We are putting together our festive edit and your styling feed is exactly the aesthetic we have in mind.'
    UNION ALL SELECT 2,  2, 'c', 'text', 'Hi, thank you for thinking of me. What are you looking at in terms of deliverables?'
    UNION ALL SELECT 2,  3, 'b', 'text', 'Two reels and one carousel post, styled around three outfits from the new collection. The budget we have set aside is around INR {amt2}.'
    UNION ALL SELECT 2,  4, 'c', 'text', 'For three looks with two reels and a carousel I would normally quote higher, but I do like this collection. Can we do INR {amt} if you cover travel within {city}?'
    UNION ALL SELECT 2,  5, 'b', 'text', 'We can cover travel within {city}. Styling assistant we would rather you arrange, so let us fold a flat amount for that into the same number.'
    UNION ALL SELECT 2,  6, 'c', 'text', 'That is fair. Then we are aligned on both scope and rate.'
    UNION ALL SELECT 2,  7, 'b', 'text', 'Great. Please share your sizes so we can courier the pieces this week.'
    UNION ALL SELECT 2,  8, 'c', 'text', 'Small in tops and 28 in bottoms. The shipping address is the one saved on my Zusty profile.'
    UNION ALL SELECT 2,  9, 'b', 'image', 'These are the three looks we have shortlisted for you.'
    UNION ALL SELECT 2, 10, 'c', 'text', 'Look two is my favourite. For look three, can I swap the belt for something from my own wardrobe? It will sit better on camera.'
    UNION ALL SELECT 2, 11, 'b', 'text', 'Absolutely, styling calls are yours as long as the garment stays clearly visible.'
    UNION ALL SELECT 2, 12, 'b', 'offer', 'Formal offer sent. Delivery window is 12 days from acceptance.'
    UNION ALL SELECT 2, 13, 'c', 'text', 'Accepted. Twelve days is comfortable for me.'
    UNION ALL SELECT 2, 14, 'c', 'text', 'Package arrived today and everything fits well. Shooting on Sunday at the location we discussed.'
    UNION ALL SELECT 2, 15, 'c', 'image', 'One frame from the shoot for your approval before I cut the reel.'
    UNION ALL SELECT 2, 16, 'b', 'text', 'This is beautiful. Please make sure the price tag is not visible in the opening shot.'
    UNION ALL SELECT 2, 17, 'c', 'text', 'Already fixed in the edit. Drafts with you by Wednesday evening.'
    UNION ALL SELECT 2, 18, 'c', 'text', 'Both reels and the carousel are in the shared drive. Captions are in the same doc.'
    UNION ALL SELECT 2, 19, 'b', 'text', 'Reels are approved. On the carousel, slide four, could you use the alternate frame where the dupatta is fully open?'
    UNION ALL SELECT 2, 20, 'c', 'text', 'Changed. I also tweaked the caption to use the collection name exactly as you wrote it.'
    UNION ALL SELECT 2, 21, 'b', 'text', 'Perfect. Go live as per the calendar: reel one Thursday, carousel Saturday, reel two next Tuesday.'
    UNION ALL SELECT 2, 22, 'c', 'text', 'All three are scheduled. I will send the links as each one goes up.'
    UNION ALL SELECT 2, 23, 'c', 'text', 'Reel one is live. Saves are already at 1.2k in three hours.'
    UNION ALL SELECT 2, 24, 'b', 'text', 'Very strong start. Our site traffic from Instagram has roughly doubled today.'
    UNION ALL SELECT 2, 25, 'b', 'text', 'All deliverables verified, marking the booking complete. The payout should reflect in your wallet within 48 hours.'

    /* ---- script 3 : tech / long form review ---------------------------- */
    UNION ALL SELECT 3,  1, 'b', 'text', 'Hi {creator}, we are {brand}. We are launching a mid range earbud next month and we want a genuine long form review rather than an ad read.'
    UNION ALL SELECT 3,  2, 'c', 'text', 'Hello. I do review audio gear, but I only take briefs where the opinion stays mine, including the criticism.'
    UNION ALL SELECT 3,  3, 'b', 'text', 'That is exactly what we are after. No script approval, only a factual check on the specifications before you publish.'
    UNION ALL SELECT 3,  4, 'c', 'text', 'Good. My rate for a dedicated 8 to 10 minute review is INR {amt3}, and that includes a community post and one short.'
    UNION ALL SELECT 3,  5, 'b', 'text', 'That is above what we budgeted. Would you consider INR {amt2} if we drop the short from the scope?'
    UNION ALL SELECT 3,  6, 'c', 'text', 'I can drop the short, but the community post stays because it is what drives traffic to the video. Meet me in the middle and we have a deal.'
    UNION ALL SELECT 3,  7, 'b', 'text', 'Let me check internally and come back to you today.'
    UNION ALL SELECT 3,  8, 'b', 'text', 'Approved at INR {amt}. Sending the offer now.'
    UNION ALL SELECT 3,  9, 'b', 'offer', 'Offer sent with the revised amount and a 21 day delivery window.'
    UNION ALL SELECT 3, 10, 'c', 'text', 'Accepted. Please send two units, one for the teardown and one for daily use.'
    UNION ALL SELECT 3, 11, 'b', 'text', 'Two units are on the way to your {city} address, expect them in about three days.'
    UNION ALL SELECT 3, 12, 'c', 'text', 'Received. First impressions are positive, the case hinge feels far more solid than the price suggests.'
    UNION ALL SELECT 3, 13, 'c', 'text', 'One issue though. The companion app crashes on Android 15 when I change the equaliser preset. Is there a build I should be testing instead?'
    UNION ALL SELECT 3, 14, 'b', 'text', 'Good catch, thank you. Our team is pushing a fix this week and I will share the beta link so you test the shipping experience.'
    UNION ALL SELECT 3, 15, 'b', 'text', 'Beta is live now, please update through the Play Store beta channel.'
    UNION ALL SELECT 3, 16, 'c', 'text', 'Updated and the crash is gone. Equaliser presets behave exactly as documented now.'
    UNION ALL SELECT 3, 17, 'c', 'text', 'Recording this weekend. Fair warning, the review will call out the average call quality on windy days.'
    UNION ALL SELECT 3, 18, 'b', 'text', 'Understood and accepted. Honest is what we asked for and honest is what our buyers trust.'
    UNION ALL SELECT 3, 19, 'c', 'text', 'Video is 9 minutes 40 seconds. Sending the private link for a specification check only.'
    UNION ALL SELECT 3, 20, 'b', 'text', 'Specifications are all correct except the battery figure. It is 7 hours on the buds, not 6.'
    UNION ALL SELECT 3, 21, 'c', 'text', 'Corrected in the on screen text and in the description. Publishing Friday at 6 pm.'
    UNION ALL SELECT 3, 22, 'c', 'text', 'Live now, and the community post is up as well.'
    UNION ALL SELECT 3, 23, 'b', 'text', '62k views in 24 hours and the comments are asking where to buy. We are sending you a discount code to pin.'
    UNION ALL SELECT 3, 24, 'c', 'text', 'Code is pinned and added to the description.'
    UNION ALL SELECT 3, 25, 'b', 'text', 'Wonderful work. Closing the booking, and we would like to book you again for the over ear launch.'

    /* ---- script 4 : food / restaurant tasting --------------------------- */
    UNION ALL SELECT 4,  1, 'b', 'text', 'Namaste {creator}, this is the team at {brand}. We are opening our second outlet in {city} and we want a food creator to cover the tasting.'
    UNION ALL SELECT 4,  2, 'c', 'text', 'Hi, congratulations on the new outlet. I do covered tastings, usually one reel plus a story set on the day itself.'
    UNION ALL SELECT 4,  3, 'b', 'text', 'That is exactly what we need. A reel of 45 to 60 seconds and five stories, all posted on the day.'
    UNION ALL SELECT 4,  4, 'c', 'text', 'Works for me. My rate for the reel and stories is INR {amt}, and everything carries the paid partnership tag.'
    UNION ALL SELECT 4,  5, 'b', 'text', 'That works for us. The tasting is on the 14th at 1 pm and we will hold a table for two.'
    UNION ALL SELECT 4,  6, 'c', 'text', 'Perfect, I will bring my camera person. Could you share the menu in advance so I can plan the shots?'
    UNION ALL SELECT 4,  7, 'b', 'image', 'Here is the tasting menu, eight courses with two desserts.'
    UNION ALL SELECT 4,  8, 'c', 'text', 'The kebab platter and the phirni will shoot beautifully. Can we plate the platter fresh right before the shot?'
    UNION ALL SELECT 4,  9, 'b', 'text', 'Yes, chef will plate on your call. We also have a window seat with very good afternoon light.'
    UNION ALL SELECT 4, 10, 'b', 'offer', 'Sending the booking offer, delivery within 7 days of the tasting.'
    UNION ALL SELECT 4, 11, 'c', 'text', 'Accepted. See you on the 14th.'
    UNION ALL SELECT 4, 12, 'c', 'text', 'The tasting was excellent, the kebabs especially. Stories are already up from today.'
    UNION ALL SELECT 4, 13, 'c', 'image', 'One still from the shoot. This is the frame I want to open the reel with.'
    UNION ALL SELECT 4, 14, 'b', 'text', 'Gorgeous. Please tag the location as the new outlet and not the flagship one.'
    UNION ALL SELECT 4, 15, 'c', 'text', 'Location tag corrected on all the stories. Reel draft by Thursday.'
    UNION ALL SELECT 4, 16, 'c', 'text', 'Draft is ready. 52 seconds, opening on the kebab close up.'
    UNION ALL SELECT 4, 17, 'b', 'text', 'We love it. One request, our name is pronounced with the stress on the first syllable. Could you redo that one voiceover line?'
    UNION ALL SELECT 4, 18, 'c', 'text', 'Of course, redoing the voiceover today. Small thing, but worth getting right.'
    UNION ALL SELECT 4, 19, 'c', 'text', 'New audio is in, sending version two.'
    UNION ALL SELECT 4, 20, 'b', 'text', 'Approved. Please publish on Saturday at 12 noon.'
    UNION ALL SELECT 4, 21, 'c', 'text', 'Live, with the paid partnership tag on the reel and the stories.'
    UNION ALL SELECT 4, 22, 'b', 'text', 'Thank you. We did 40 covers on Sunday and at least a dozen guests mentioned the reel.'
    UNION ALL SELECT 4, 23, 'b', 'text', 'One thought for next time, could you do a follow up story when our weekend brunch launches?'
    UNION ALL SELECT 4, 24, 'c', 'text', 'Happy to. Send me the dates and we will work out a small add on.'
    UNION ALL SELECT 4, 25, 'b', 'text', 'Will do. Marking this booking complete, thank you {creator}.'

    /* ---- script 5 : fitness / three month association ------------------ */
    UNION ALL SELECT 5,  1, 'b', 'text', 'Hi {creator}, {brand} here. We make plant protein and we are looking for a fitness creator in {city} for a three month association.'
    UNION ALL SELECT 5,  2, 'c', 'text', 'Hello. A three month association is interesting. What does the deliverable split look like per month?'
    UNION ALL SELECT 5,  3, 'b', 'text', 'One reel and four stories a month, plus a mention in your workout posts wherever it fits naturally.'
    UNION ALL SELECT 5,  4, 'c', 'text', 'For a three month lock in I would quote INR {amt3} per month. Organic usage only, ads would be a separate conversation.'
    UNION ALL SELECT 5,  5, 'b', 'text', 'That is a little above our band. If we commit to all three months upfront, can you do better on the monthly rate?'
    UNION ALL SELECT 5,  6, 'c', 'text', 'If the full amount goes into escrow upfront I can bring it down to INR {amt} per month. I have been burned by month to month deals before.'
    UNION ALL SELECT 5,  7, 'b', 'text', 'That is reasonable. Let me confirm the budget with our founder and revert today.'
    UNION ALL SELECT 5,  8, 'b', 'text', 'Founder is on board. We will fund all three months into escrow at the start.'
    UNION ALL SELECT 5,  9, 'c', 'text', 'Then we have a deal. Please send the product for the first month, chocolate and unflavoured if you have both.'
    UNION ALL SELECT 5, 10, 'b', 'text', 'Both are going out today from our Bengaluru warehouse.'
    UNION ALL SELECT 5, 11, 'b', 'offer', 'Sending the offer for month one so we can start. The remaining two months follow the same terms.'
    UNION ALL SELECT 5, 12, 'c', 'text', 'Accepted. I will open with a taste and mixability test story, because that is what my audience always asks about first.'
    UNION ALL SELECT 5, 13, 'c', 'text', 'Product arrived. Mixability is genuinely good, no clumping with cold water, which is rare for a plant protein.'
    UNION ALL SELECT 5, 14, 'c', 'image', 'Story frame from this morning, shot right after my session.'
    UNION ALL SELECT 5, 15, 'b', 'text', 'Love this. Could you also mention the 24 grams per scoop figure? That number performs consistently well for us.'
    UNION ALL SELECT 5, 16, 'c', 'text', 'Added to the on screen text. The month one reel goes out next week.'
    UNION ALL SELECT 5, 17, 'c', 'text', 'Reel draft is ready. It is a full day of eating format with the shake at post workout.'
    UNION ALL SELECT 5, 18, 'b', 'text', 'Approved with no changes. This is exactly the tone we were hoping for.'
    UNION ALL SELECT 5, 19, 'c', 'text', 'Published. Comments are mostly about the taste and I am replying to them through the day.'
    UNION ALL SELECT 5, 20, 'b', 'text', 'Month one numbers are strong, 28k reach and 340 saves. Sending the month two brief shortly.'
    UNION ALL SELECT 5, 21, 'b', 'text', 'For month two we want to push the recovery angle ahead of the marathon season.'
    UNION ALL SELECT 5, 22, 'c', 'text', 'That fits my calendar nicely. I am training for the half marathon anyway, so it will be honest content.'
    UNION ALL SELECT 5, 23, 'b', 'text', 'Even better. Same deliverable split, and we will release month two from escrow this week.'
    UNION ALL SELECT 5, 24, 'c', 'text', 'Received the month two release, thank you. Starting the shoot on Sunday.'
    UNION ALL SELECT 5, 25, 'b', 'text', 'Great. Keeping the same rhythm for month three, I will send that brief at the end of this month.'
) sc ON sc.scr = 1 + (k.cid % 5) AND sc.seq = q.seq
JOIN conversation_participants cpb ON cpb.conversation_id = k.cid AND cpb.role = 'brand'
JOIN conversation_participants cpc ON cpc.conversation_id = k.cid AND cpc.role = 'creator'
JOIN users bu ON bu.id = cpb.user_id
JOIN users cu ON cu.id = cpc.user_id
LEFT JOIN (
    SELECT 800 + ROW_NUMBER() OVER (ORDER BY b.id) AS cid2,
           b.id AS bid, b.amount AS amt, b.creator_id AS crid, b.created_at AS bcreated
    FROM bookings b
    WHERE b.deleted_at IS NULL AND b.id BETWEEN 401 AND 480
) bk ON bk.cid2 = k.cid
LEFT JOIN creators cr ON cr.id = bk.crid;


/* ==========================================================================
 * 6. MESSAGE RECEIPTS — one row per recipient per message.
 *    Threads whose id is divisible by 3 keep their last three messages unread.
 * ========================================================================== */
INSERT IGNORE INTO message_receipts (message_id, user_id, delivered_at, read_at)
SELECT
    m.id,
    p.user_id,
    LEAST(NOW(), DATE_ADD(m.created_at, INTERVAL (1 + m.id % 3) MINUTE)),
    CASE
        WHEN m.conversation_id % 3 = 0
             AND m.id > (SELECT MAX(x.id) - 3 FROM messages x WHERE x.conversation_id = m.conversation_id)
        THEN NULL
        ELSE LEAST(NOW(), DATE_ADD(m.created_at, INTERVAL (4 + (m.id * 7) % 55) MINUTE))
    END
FROM messages m
JOIN conversation_participants p
  ON p.conversation_id = m.conversation_id
 AND p.user_id <> m.sender_id
WHERE m.conversation_id BETWEEN 801 AND 830
  AND m.id BETWEEN 901 AND 1300;

UPDATE messages m
  JOIN (
        SELECT r.message_id,
               SUM(r.delivered_at IS NOT NULL) AS d,
               SUM(r.read_at IS NOT NULL)      AS rd
          FROM message_receipts r
         WHERE r.message_id BETWEEN 901 AND 1300
         GROUP BY r.message_id
       ) z ON z.message_id = m.id
   SET m.delivered_count = z.d,
       m.read_count      = z.rd
 WHERE m.id BETWEEN 901 AND 1300;

UPDATE conversation_participants p
  JOIN (
        SELECT m.conversation_id, r.user_id, COUNT(*) AS n
          FROM message_receipts r
          JOIN messages m ON m.id = r.message_id
         WHERE r.read_at IS NULL
           AND m.conversation_id BETWEEN 801 AND 830
         GROUP BY m.conversation_id, r.user_id
       ) x ON x.conversation_id = p.conversation_id AND x.user_id = p.user_id
   SET p.unread_count = LEAST(x.n, 65535)
 WHERE p.conversation_id BETWEEN 801 AND 830;

UPDATE conversation_participants p
  JOIN (
        SELECT m.conversation_id, r.user_id,
               MAX(m.id)      AS mx,
               MAX(r.read_at) AS rt
          FROM message_receipts r
          JOIN messages m ON m.id = r.message_id
         WHERE r.read_at IS NOT NULL
           AND m.conversation_id BETWEEN 801 AND 830
         GROUP BY m.conversation_id, r.user_id
       ) y ON y.conversation_id = p.conversation_id AND y.user_id = p.user_id
   SET p.last_read_message_id = y.mx,
       p.last_read_at         = y.rt
 WHERE p.conversation_id BETWEEN 801 AND 830;

UPDATE conversations c
  JOIN (
        SELECT m.conversation_id, COUNT(*) AS n, MAX(m.id) AS mx
          FROM messages m
         WHERE m.conversation_id BETWEEN 801 AND 830
           AND m.deleted_at IS NULL
         GROUP BY m.conversation_id
       ) a ON a.conversation_id = c.id
  JOIN messages lm ON lm.id = a.mx
   SET c.messages_count    = a.n,
       c.last_message_id   = a.mx,
       c.last_message_text = LEFT(COALESCE(lm.body, '[attachment]'), 255),
       c.last_message_at   = lm.created_at,
       c.updated_at        = lm.created_at
 WHERE c.id BETWEEN 801 AND 830;


/* ==========================================================================
 * 7. NOTIFICATIONS (2101-2300)
 *    Every `type` below is an existing notification_templates.code.
 *    {who} is resolved from the real counterparty user row at insert time.
 *    action_type stays inside the notifications enum
 *    (none|booking|creator|chat|wallet|payment|url|review|ticket) — note the
 *    template table stores its own action verbs such as open_kyc which are NOT
 *    valid here, so kyc.* rows use 'none'.
 * ========================================================================== */
INSERT IGNORE INTO notifications
    (id, user_id, type, category, title, body, image_url, icon,
     action_type, action_value, data, is_read, read_at, sent_push, sent_email, created_at)
SELECT
    n.id,
    n.uid,
    n.ntype,
    n.cat,
    REPLACE(n.ttl, '{who}', COALESCE(ru.company_name, ru.name, 'A Zusty partner')),
    REPLACE(n.bdy, '{who}', COALESCE(ru.company_name, ru.name, 'A Zusty partner')),
    CASE WHEN n.id % 7 = 0
         THEN CONCAT('https://picsum.photos/seed/znotif-', n.id, '/240/240') END,
    n.icon,
    n.atype,
    n.aval,
    CASE n.atype
        WHEN 'booking' THEN CONCAT('{"booking_id":', n.aval, '}')
        WHEN 'creator' THEN CONCAT('{"creator_id":', n.aval, '}')
        WHEN 'chat'    THEN CONCAT('{"conversation_id":', n.aval, '}')
        WHEN 'payment' THEN CONCAT('{"payment_id":', n.aval, '}')
        WHEN 'wallet'  THEN CONCAT('{"transaction_id":', n.aval, '}')
        WHEN 'review'  THEN CONCAT('{"review_id":', n.aval, '}')
        WHEN 'ticket'  THEN CONCAT('{"ticket_id":', n.aval, '}')
        WHEN 'url'     THEN CONCAT('{"url":', JSON_QUOTE(n.aval), '}')
        ELSE NULL
    END,
    n.rd,
    CASE WHEN n.rd = 1
         THEN LEAST(NOW(), DATE_ADD(DATE_SUB(NOW(), INTERVAL n.dago DAY), INTERVAL (2 + n.id % 40) HOUR))
         END,
    IF(n.id % 5 <> 0, 1, 0),
    IF(n.cat IN ('payment','wallet','account'), 1, 0),
    DATE_ADD(DATE_SUB(NOW(), INTERVAL n.dago DAY), INTERVAL (n.id % 640) MINUTE)
FROM (
    /* id , recipient , counterparty , type , category , icon , action_type , action_value , title , body , is_read , days_ago */
              SELECT 2101 AS id, 1001 AS uid, 2003 AS ref, 'booking.accepted' AS ntype, 'booking' AS cat, 'check_circle' AS icon, 'booking' AS atype, '403' AS aval, 'Booking accepted' AS ttl, '{who} accepted your request for the monsoon skincare reel. Complete the payment to lock the shoot dates.' AS bdy, 1 AS rd, 71 AS dago
    UNION ALL SELECT 2102, 1001, 2009, 'booking.delivered', 'booking', 'task_alt', 'booking', '407', 'Deliverables submitted', '{who} has submitted the reel and two stories for review. You have 72 hours to approve or request changes.', 1, 64
    UNION ALL SELECT 2103, 1001, 2014, 'message.new', 'message', 'chat_bubble', 'chat', '805', 'New message', '{who}: Draft link is in your inbox. It is 38 seconds and the two stories are cut from the same footage.', 1, 60
    UNION ALL SELECT 2104, 1002, 2005, 'booking.requested', 'booking', 'calendar_today', 'booking', '412', 'Request sent to creator', 'Your booking request has reached {who}. Most creators respond within 12 hours.', 1, 88
    UNION ALL SELECT 2105, 1002, 2005, 'booking.countered', 'booking', 'swap_horiz', 'booking', '412', 'Counter offer received', '{who} sent a counter offer with a revised rate and a 12 day delivery window. Review it before it expires.', 1, 86
    UNION ALL SELECT 2106, 1002, 2021, 'booking.confirmed', 'booking', 'verified', 'booking', '418', 'Booking confirmed!', 'Your collaboration with {who} is confirmed. The amount is now held in escrow until the deliverables are approved.', 1, 79
    UNION ALL SELECT 2107, 1003, 2011, 'booking.completed', 'booking', 'task_alt', 'review', '705', 'Campaign completed', 'The campaign with {who} is closed. Leave a review to help other brands discover great creators.', 0, 22
    UNION ALL SELECT 2108, 1003, 2027, 'booking.rejected', 'booking', 'cancel', 'booking', '424', 'Booking declined', '{who} is unavailable for the requested dates. Browse similar creators in the same category and city.', 1, 55
    UNION ALL SELECT 2109, 1003, 2004, 'message.new', 'message', 'chat_bubble', 'chat', '812', 'New message', '{who}: Package arrived today and everything fits well. Shooting on Sunday at the location we discussed.', 1, 48
    UNION ALL SELECT 2110, 1004, 2016, 'booking.accepted', 'booking', 'check_circle', 'booking', '429', 'Booking accepted', '{who} accepted your festive edit brief. Payment link is active for the next 24 hours.', 1, 74
    UNION ALL SELECT 2111, 1004, 2016, 'booking.delivered', 'booking', 'task_alt', 'booking', '429', 'Deliverables submitted', 'Two reels and one carousel from {who} are ready for your review.', 1, 62
    UNION ALL SELECT 2112, 1004, 2032, 'booking.expiring', 'booking', 'schedule', 'booking', '433', 'Request expiring soon', 'Your request to {who} expires in 6 hours. Send a nudge or pick another creator.', 1, 41
    UNION ALL SELECT 2113, 1005, 2007, 'booking.confirmed', 'booking', 'verified', 'booking', '437', 'Booking confirmed!', 'All set with {who}. Shoot date is locked and the brief has been shared with the creator.', 1, 68
    UNION ALL SELECT 2114, 1005, 2007, 'message.new', 'message', 'chat_bubble', 'chat', '817', 'New message', '{who}: Draft is ready. 52 seconds, opening on the kebab close up.', 0, 12
    UNION ALL SELECT 2115, 1005, 2019, 'booking.cancelled', 'booking', 'event_busy', 'booking', '441', 'Booking cancelled', '{who} cancelled the booking due to a scheduling clash. Your escrow amount is being refunded in full.', 1, 37
    UNION ALL SELECT 2116, 1006, 2024, 'booking.accepted', 'booking', 'check_circle', 'booking', '445', 'Booking accepted', '{who} is on board for the launch campaign. Deliverables are due in 14 days.', 1, 58
    UNION ALL SELECT 2117, 1006, 2024, 'booking.delivered', 'booking', 'task_alt', 'booking', '445', 'Deliverables submitted', '{who} uploaded the long form review and the community post link.', 1, 44
    UNION ALL SELECT 2118, 1006, 2031, 'booking.requested', 'booking', 'calendar_today', 'booking', '449', 'Request sent to creator', 'Your request is with {who}. We will notify you the moment there is a response.', 1, 33
    UNION ALL SELECT 2119, 1007, 2012, 'booking.countered', 'booking', 'swap_horiz', 'booking', '452', 'Counter offer received', '{who} would like to revise the scope to two reels instead of three. Tap to review the counter.', 0, 9
    UNION ALL SELECT 2120, 1007, 2012, 'message.new', 'message', 'chat_bubble', 'chat', '821', 'New message', '{who}: I can drop the short, but the community post stays because it is what drives traffic to the video.', 0, 8
    UNION ALL SELECT 2121, 1007, 2038, 'booking.completed', 'booking', 'task_alt', 'review', '712', 'Campaign completed', 'Your campaign with {who} wrapped up. Tell us how it went.', 1, 51
    UNION ALL SELECT 2122, 1008, 2002, 'booking.confirmed', 'booking', 'verified', 'booking', '456', 'Booking confirmed!', '{who} confirmed the three month association. Month one deliverables start next week.', 1, 65
    UNION ALL SELECT 2123, 1008, 2002, 'booking.delivered', 'booking', 'task_alt', 'booking', '456', 'Deliverables submitted', 'Month one reel from {who} is ready for approval.', 1, 39
    UNION ALL SELECT 2124, 1008, 2029, 'booking.rejected', 'booking', 'cancel', 'booking', '460', 'Booking declined', '{who} has paused new collaborations this month. We have shortlisted four similar creators for you.', 1, 29
    UNION ALL SELECT 2125, 1009, 2035, 'booking.accepted', 'booking', 'check_circle', 'booking', '463', 'Booking accepted', '{who} accepted your brief for the Diwali gifting campaign.', 1, 47
    UNION ALL SELECT 2126, 1009, 2035, 'message.new', 'message', 'chat_bubble', 'chat', '825', 'New message', '{who}: Location tag corrected on all the stories. Reel draft by Thursday.', 1, 43
    UNION ALL SELECT 2127, 1009, 2018, 'booking.expiring', 'booking', 'schedule', 'booking', '467', 'Request expiring soon', 'Only 12 hours left on your request to {who} before it auto expires.', 0, 4
    UNION ALL SELECT 2128, 1010, 2006, 'booking.confirmed', 'booking', 'verified', 'booking', '470', 'Booking confirmed!', 'Confirmed with {who}. The amount is safely in escrow until you approve the deliverables.', 1, 56
    UNION ALL SELECT 2129, 1010, 2006, 'booking.completed', 'booking', 'task_alt', 'review', '718', 'Campaign completed', 'Everything is signed off with {who}. A short review takes 30 seconds and really helps creators.', 0, 15
    UNION ALL SELECT 2130, 1010, 2040, 'booking.requested', 'booking', 'calendar_today', 'booking', '473', 'Request sent to creator', 'Request delivered to {who} along with your brief and moodboard.', 1, 26
    UNION ALL SELECT 2131, 1011, 2013, 'booking.accepted', 'booking', 'check_circle', 'booking', '476', 'Booking accepted', '{who} accepted the brief. Please complete payment within 24 hours to keep the slot.', 1, 61
    UNION ALL SELECT 2132, 1011, 2013, 'booking.delivered', 'booking', 'task_alt', 'booking', '476', 'Deliverables submitted', 'Deliverables from {who} are in. Approve them to release the escrow payout.', 0, 6
    UNION ALL SELECT 2133, 1012, 2022, 'booking.countered', 'booking', 'swap_horiz', 'booking', '479', 'Counter offer received', '{who} proposed a revised rate for the same scope. Accept, counter again or decline.', 1, 34
    UNION ALL SELECT 2134, 1012, 2022, 'booking.confirmed', 'booking', 'verified', 'booking', '479', 'Booking confirmed!', 'Deal closed with {who}. The shoot is scheduled and the brief has been acknowledged.', 1, 31
    UNION ALL SELECT 2135, 1013, 2043, 'booking.accepted', 'booking', 'check_circle', 'booking', '405', 'Booking accepted', '{who} accepted your request for a product unboxing video.', 1, 77
    UNION ALL SELECT 2136, 1013, 2043, 'booking.completed', 'booking', 'task_alt', 'review', '724', 'Campaign completed', 'Campaign with {who} is complete. Your invoice is available in Billing.', 1, 53
    UNION ALL SELECT 2137, 1014, 2010, 'booking.requested', 'booking', 'calendar_today', 'booking', '409', 'Request sent to creator', 'Your brief has reached {who}. Average response time on this profile is 6 hours.', 1, 82
    UNION ALL SELECT 2138, 1014, 2010, 'booking.accepted', 'booking', 'check_circle', 'booking', '409', 'Booking accepted', '{who} is in. Deliverables are due 10 days from today.', 1, 81
    UNION ALL SELECT 2139, 1015, 2026, 'booking.cancelled', 'booking', 'event_busy', 'booking', '414', 'Booking cancelled', 'You cancelled the booking with {who}. A full refund has been initiated to the original payment method.', 1, 45
    UNION ALL SELECT 2140, 1015, 2026, 'message.new', 'message', 'chat_bubble', 'chat', '829', 'New message', '{who}: Happy to. Send me the dates and we will work out a small add on.', 0, 3
    UNION ALL SELECT 2141, 2003, 1001, 'booking.requested', 'booking', 'calendar_today', 'booking', '403', 'New booking request', '{who} wants to book you for a skincare launch reel. Respond within 48 hours to keep your response rate high.', 1, 72
    UNION ALL SELECT 2142, 2003, 1001, 'payment.success', 'payment', 'check_circle', 'booking', '403', 'Payment received in escrow', '{who} has paid for your booking. The amount is held safely and releases the moment the deliverables are approved.', 1, 70
    UNION ALL SELECT 2143, 2003, 1001, 'wallet.earning', 'wallet', 'payments', 'wallet', '1712', 'You have been paid', 'Your payout for the campaign with {who} has been credited to your Zusty wallet after platform commission and GST.', 1, 62
    UNION ALL SELECT 2144, 2005, 1002, 'booking.requested', 'booking', 'calendar_today', 'booking', '412', 'New booking request', '{who} sent you a brief for a festive styling campaign. Tap to view the scope and the offered rate.', 1, 88
    UNION ALL SELECT 2145, 2005, 1002, 'message.new', 'message', 'chat_bubble', 'chat', '802', 'New message', '{who}: We can cover travel within the city. Styling assistant we would rather you arrange.', 1, 85
    UNION ALL SELECT 2146, 2005, 1002, 'booking.confirmed', 'booking', 'verified', 'booking', '412', 'Booking confirmed!', 'Your collaboration with {who} is confirmed and the payment is in escrow.', 1, 79
    UNION ALL SELECT 2147, 2007, 1005, 'booking.requested', 'booking', 'calendar_today', 'booking', '437', 'New booking request', '{who} would like you to cover their new outlet tasting. The proposed date is in nine days.', 1, 69
    UNION ALL SELECT 2148, 2007, 1005, 'review.received', 'review', 'star', 'review', '702', 'New review', '{who} left you a 5 star review. Your profile rating has been updated.', 1, 50
    UNION ALL SELECT 2149, 2007, 1005, 'wallet.earning', 'wallet', 'payments', 'wallet', '1728', 'You have been paid', 'Escrow released for the tasting campaign with {who}. Funds are now withdrawable.', 1, 49
    UNION ALL SELECT 2150, 2009, 1001, 'booking.confirmed', 'booking', 'verified', 'booking', '407', 'Booking confirmed!', 'All confirmed with {who}. Your delivery window opens today.', 1, 66
    UNION ALL SELECT 2151, 2009, 1001, 'booking.completed', 'booking', 'review', 'review', '703', 'Campaign completed', 'Your work for {who} was approved. Leave a review for the brand while it is fresh.', 1, 63
    UNION ALL SELECT 2152, 2011, 1003, 'booking.requested', 'booking', 'calendar_today', 'booking', '421', 'New booking request', '{who} is interested in a two reel package. Their brief is attached to the request.', 1, 57
    UNION ALL SELECT 2153, 2011, 1003, 'review.received', 'review', 'star', 'review', '705', 'New review', '{who} rated your collaboration 5 stars and mentioned your turnaround time.', 0, 21
    UNION ALL SELECT 2154, 2012, 1007, 'booking.requested', 'booking', 'calendar_today', 'booking', '452', 'New booking request', '{who} wants a long form review of their new earbuds. No script approval required.', 0, 10
    UNION ALL SELECT 2155, 2012, 1007, 'message.new', 'message', 'chat_bubble', 'chat', '821', 'New message', '{who}: Approved at the revised amount. Sending the offer now.', 0, 8
    UNION ALL SELECT 2156, 2013, 1011, 'payment.success', 'payment', 'check_circle', 'booking', '476', 'Payment received in escrow', 'Escrow funded by {who}. You can begin production right away.', 1, 60
    UNION ALL SELECT 2157, 2013, 1011, 'wallet.earning', 'wallet', 'payments', 'wallet', '1745', 'You have been paid', 'Your payout has landed in your wallet. Withdrawals to your linked bank usually settle in 24 to 48 hours.', 0, 5
    UNION ALL SELECT 2158, 2014, 1001, 'message.new', 'message', 'chat_bubble', 'chat', '805', 'New message', '{who}: Legal is fine with that as long as we do not use the phrase clinically proven anywhere.', 1, 59
    UNION ALL SELECT 2159, 2014, 1001, 'review.received', 'review', 'star', 'review', '707', 'New review', '{who} left a 4 star review with a note about the caption revisions.', 1, 46
    UNION ALL SELECT 2160, 2016, 1004, 'booking.requested', 'booking', 'calendar_today', 'booking', '429', 'New booking request', '{who} shared a festive edit brief with three looks. Respond to keep your response time badge.', 1, 76
    UNION ALL SELECT 2161, 2016, 1004, 'booking.completed', 'booking', 'review', 'review', '709', 'Campaign completed', 'The festive edit with {who} is signed off. Your earnings have been released.', 1, 54
    UNION ALL SELECT 2162, 2019, 1005, 'booking.cancelled', 'booking', 'event_busy', 'booking', '441', 'Booking cancelled', 'The booking with {who} was cancelled. No penalty has been applied to your profile.', 1, 37
    UNION ALL SELECT 2163, 2021, 1002, 'booking.confirmed', 'booking', 'verified', 'booking', '418', 'Booking confirmed!', 'Confirmed with {who}. Deliverables are due in 12 days.', 1, 78
    UNION ALL SELECT 2164, 2021, 1002, 'review.received', 'review', 'star', 'review', '711', 'New review', '{who} rated you 5 stars: studio quality on a creator budget.', 1, 52
    UNION ALL SELECT 2165, 2022, 1012, 'booking.countered', 'booking', 'swap_horiz', 'booking', '479', 'Counter offer sent', 'Your counter offer has been sent to {who}. They have 48 hours to respond.', 1, 34
    UNION ALL SELECT 2166, 2024, 1006, 'booking.requested', 'booking', 'calendar_today', 'booking', '445', 'New booking request', '{who} would like a dedicated review video plus a community post.', 1, 59
    UNION ALL SELECT 2167, 2024, 1006, 'wallet.earning', 'wallet', 'payments', 'wallet', '1761', 'You have been paid', 'Payout released for the review campaign with {who}.', 1, 42
    UNION ALL SELECT 2168, 2026, 1015, 'message.new', 'message', 'chat_bubble', 'chat', '829', 'New message', '{who}: One thought for next time, could you do a follow up story when our weekend brunch launches?', 0, 3
    UNION ALL SELECT 2169, 2027, 1003, 'booking.expiring', 'booking', 'schedule', 'booking', '424', 'Request expiring soon', 'The request from {who} expires in 4 hours. Declining is better for your stats than letting it lapse.', 1, 56
    UNION ALL SELECT 2170, 2029, 1008, 'booking.requested', 'booking', 'calendar_today', 'booking', '460', 'New booking request', '{who} sent a collaboration request for a protein launch.', 1, 30
    UNION ALL SELECT 2171, 2031, 1006, 'booking.requested', 'booking', 'calendar_today', 'booking', '449', 'New booking request', '{who} wants to book your UGC package for a three video bundle.', 1, 33
    UNION ALL SELECT 2172, 2032, 1004, 'booking.expiring', 'booking', 'schedule', 'booking', '433', 'Request expiring soon', 'Six hours left to respond to {who}.', 0, 4
    UNION ALL SELECT 2173, 2035, 1009, 'booking.confirmed', 'booking', 'verified', 'booking', '463', 'Booking confirmed!', 'Diwali gifting campaign with {who} is confirmed.', 1, 46
    UNION ALL SELECT 2174, 2035, 1009, 'review.received', 'review', 'star', 'review', '714', 'New review', '{who} left you a 5 star review and asked to work together again.', 1, 40
    UNION ALL SELECT 2175, 2038, 1007, 'booking.completed', 'booking', 'review', 'review', '716', 'Campaign completed', 'Campaign with {who} closed successfully. Rate the brand to help other creators.', 1, 51
    UNION ALL SELECT 2176, 2040, 1010, 'booking.requested', 'booking', 'calendar_today', 'booking', '473', 'New booking request', '{who} has shared a brief with a moodboard and reference reels.', 1, 26
    UNION ALL SELECT 2177, 2043, 1013, 'booking.completed', 'booking', 'review', 'review', '720', 'Campaign completed', 'Unboxing campaign with {who} is complete and your payout has been released.', 1, 52
    UNION ALL SELECT 2178, 2010, 1014, 'booking.accepted', 'booking', 'check_circle', 'booking', '409', 'Booking accepted', 'You accepted the brief from {who}. Deliverables are due in 10 days.', 1, 81
    UNION ALL SELECT 2179, 2002, 1008, 'booking.confirmed', 'booking', 'verified', 'booking', '456', 'Booking confirmed!', 'Three month association with {who} is live. Month one starts next week.', 1, 64
    UNION ALL SELECT 2180, 2006, 1010, 'review.received', 'review', 'star', 'review', '722', 'New review', '{who} rated you 4 stars and left detailed feedback on the hook.', 0, 14
    UNION ALL SELECT 2181, 1001, 2003, 'payment.success', 'payment', 'check_circle', 'payment', '503', 'Payment successful', 'INR 47,200 including GST has been charged to your card ending 4412 and moved into escrow.', 1, 70
    UNION ALL SELECT 2182, 1002, 2005, 'payment.success', 'payment', 'check_circle', 'payment', '509', 'Payment successful', 'Your UPI payment went through. The invoice is available under Billing.', 1, 78
    UNION ALL SELECT 2183, 1003, 2011, 'payment.failed', 'payment', 'error', 'payment', '514', 'Payment failed', 'Your card was declined by the issuing bank. No amount has been deducted. Try another method to keep the booking.', 1, 57
    UNION ALL SELECT 2184, 1003, 2011, 'payment.success', 'payment', 'check_circle', 'payment', '515', 'Payment successful', 'Retry succeeded on netbanking. The booking is now confirmed.', 1, 57
    UNION ALL SELECT 2185, 1004, 2016, 'payment.success', 'payment', 'check_circle', 'payment', '521', 'Payment successful', 'Payment captured. GST at 18 percent on the platform fee is included in your invoice.', 1, 74
    UNION ALL SELECT 2186, 1005, 2019, 'payment.refunded', 'payment', 'refresh', 'wallet', '1774', 'Refund processed', 'Your refund for the cancelled booking has been initiated and should reflect in 5 to 7 working days.', 1, 36
    UNION ALL SELECT 2187, 1006, 2024, 'payment.success', 'payment', 'check_circle', 'payment', '528', 'Payment successful', 'Escrow funded for the earbud review campaign.', 1, 58
    UNION ALL SELECT 2188, 1007, 2038, 'payment.success', 'payment', 'check_circle', 'payment', '534', 'Payment successful', 'Payment received. You can now chat with the creator and share the detailed brief.', 1, 52
    UNION ALL SELECT 2189, 1008, 2002, 'payment.success', 'payment', 'check_circle', 'payment', '540', 'Payment successful', 'All three months have been funded into escrow as agreed.', 1, 65
    UNION ALL SELECT 2190, 1009, 2035, 'payment.failed', 'payment', 'error', 'payment', '545', 'Payment failed', 'The transaction timed out at the bank page. Please retry, the creator slot is held for another 6 hours.', 0, 11
    UNION ALL SELECT 2191, 1010, 2006, 'payment.success', 'payment', 'check_circle', 'payment', '549', 'Payment successful', 'Payment of INR 1,18,000 completed. Invoice ZINV-0621 has been emailed to your billing contact.', 1, 56
    UNION ALL SELECT 2192, 1011, 2013, 'payment.success', 'payment', 'check_circle', 'payment', '554', 'Payment successful', 'Card payment captured successfully.', 1, 61
    UNION ALL SELECT 2193, 1012, 2022, 'payment.refunded', 'payment', 'refresh', 'wallet', '1781', 'Refund processed', 'A partial refund of INR 12,000 has been credited for the deliverable that was dropped from scope.', 1, 28
    UNION ALL SELECT 2194, 1013, 2043, 'payment.success', 'payment', 'check_circle', 'payment', '560', 'Payment successful', 'Payment successful. Your booking is confirmed.', 1, 77
    UNION ALL SELECT 2195, 1014, 2010, 'wallet.debited', 'wallet', 'arrow_upward', 'wallet', '1788', 'Wallet debited', 'INR 35,400 was debited from your wallet balance towards the booking with {who}.', 1, 80
    UNION ALL SELECT 2196, 1015, 2026, 'wallet.credited', 'wallet', 'arrow_downward', 'wallet', '1793', 'Refund credited', 'INR 50,000 from a cancelled booking has been refunded to your Zusty wallet.', 1, 44
    UNION ALL SELECT 2197, 1016, 2015, 'payment.success', 'payment', 'check_circle', 'payment', '566', 'Payment successful', 'Payment captured for the UGC bundle.', 1, 35
    UNION ALL SELECT 2198, 1017, 2020, 'wallet.credited', 'wallet', 'arrow_downward', 'wallet', '1801', 'Refund credited', 'A refund has been credited to your wallet. Your available balance has been updated.', 1, 23
    UNION ALL SELECT 2199, 1018, 2033, 'payment.success', 'payment', 'check_circle', 'payment', '571', 'Payment successful', 'Payment complete. The creator has been notified and can start production.', 0, 7
    UNION ALL SELECT 2200, 1019, 2041, 'payment.failed', 'payment', 'error', 'payment', '575', 'Payment failed', 'Payment could not be completed because the daily UPI limit was exceeded. Try a card or netbanking instead.', 0, 2
    UNION ALL SELECT 2201, 2003, 1001, 'wallet.credited', 'wallet', 'arrow_downward', 'wallet', '1712', 'Money added', 'INR 38,250 has been credited to your wallet after 15 percent platform commission and applicable GST.', 1, 62
    UNION ALL SELECT 2202, 2003, 1001, 'withdrawal.approved', 'wallet', 'account_balance', 'wallet', '1955', 'Withdrawal approved', 'Your withdrawal of INR 35,000 has been approved and sent to your HDFC account ending 8841.', 1, 58
    UNION ALL SELECT 2203, 2005, 1002, 'wallet.earning', 'wallet', 'payments', 'wallet', '1719', 'You have been paid', 'Escrow released for the festive edit campaign.', 1, 71
    UNION ALL SELECT 2204, 2005, 1002, 'withdrawal.approved', 'wallet', 'account_balance', 'wallet', '1957', 'Withdrawal approved', 'INR 60,000 is on its way to your bank account. UTR will appear in the transaction detail once settled.', 1, 68
    UNION ALL SELECT 2205, 2007, 1005, 'withdrawal.rejected', 'wallet', 'error', 'wallet', '1959', 'Withdrawal rejected', 'Your withdrawal was rejected because the beneficiary name did not match your PAN. Update the bank account and retry.', 1, 47
    UNION ALL SELECT 2206, 2007, 1005, 'withdrawal.approved', 'wallet', 'account_balance', 'wallet', '1960', 'Withdrawal approved', 'Resubmitted withdrawal approved. Funds will settle within 24 hours.', 1, 45
    UNION ALL SELECT 2207, 2009, 1001, 'wallet.earning', 'wallet', 'payments', 'wallet', '1725', 'You have been paid', 'Your earnings for the reel and story set have been released from escrow.', 1, 61
    UNION ALL SELECT 2208, 2011, 1003, 'wallet.credited', 'wallet', 'arrow_downward', 'wallet', '1731', 'Money added', 'Payout credited. Your withdrawable balance is now updated in the wallet.', 1, 50
    UNION ALL SELECT 2209, 2012, 1007, 'wallet.earning', 'wallet', 'payments', 'wallet', '1737', 'You have been paid', 'Payout for the earbud review has been credited to your wallet.', 0, 6
    UNION ALL SELECT 2210, 2013, 1011, 'withdrawal.approved', 'wallet', 'account_balance', 'wallet', '1962', 'Withdrawal approved', 'INR 42,000 has been transferred to your ICICI account.', 1, 39
    UNION ALL SELECT 2211, 2016, 1004, 'wallet.earning', 'wallet', 'payments', 'wallet', '1743', 'You have been paid', 'Escrow released after the brand approved all three deliverables.', 1, 53
    UNION ALL SELECT 2212, 2021, 1002, 'wallet.credited', 'wallet', 'arrow_downward', 'wallet', '1749', 'Money added', 'Campaign earnings credited to your Zusty wallet.', 1, 51
    UNION ALL SELECT 2213, 2022, 1012, 'withdrawal.rejected', 'wallet', 'error', 'wallet', '1964', 'Withdrawal rejected', 'Withdrawal could not be processed because your KYC is pending. Complete verification to unlock payouts.', 0, 17
    UNION ALL SELECT 2214, 2024, 1006, 'wallet.earning', 'wallet', 'payments', 'wallet', '1755', 'You have been paid', 'Payout released. Commission and GST breakdown is available in the transaction detail.', 1, 41
    UNION ALL SELECT 2215, 2026, 1015, 'wallet.credited', 'wallet', 'arrow_downward', 'wallet', '1767', 'Money added', 'Referral bonus and campaign earnings have been credited together this week.', 1, 32
    UNION ALL SELECT 2216, 2029, 1008, 'wallet.earning', 'wallet', 'payments', 'wallet', '1773', 'You have been paid', 'Month one payout for the protein association is now in your wallet.', 1, 27
    UNION ALL SELECT 2217, 2031, 1006, 'withdrawal.approved', 'wallet', 'account_balance', 'wallet', '1966', 'Withdrawal approved', 'Your payout request has been approved and queued for the next settlement cycle.', 1, 24
    UNION ALL SELECT 2218, 2035, 1009, 'wallet.earning', 'wallet', 'payments', 'wallet', '1779', 'You have been paid', 'Diwali campaign payout released from escrow.', 1, 38
    UNION ALL SELECT 2219, 2038, 1007, 'wallet.credited', 'wallet', 'arrow_downward', 'wallet', '1785', 'Money added', 'Your wallet has been credited. You can withdraw any amount above INR 1,000.', 1, 49
    UNION ALL SELECT 2220, 2043, 1013, 'wallet.earning', 'wallet', 'payments', 'wallet', '1791', 'You have been paid', 'Unboxing campaign payout credited after commission.', 1, 51
    UNION ALL SELECT 2221, 1001, 2003, 'message.new', 'message', 'chat_bubble', 'chat', '801', 'New message', '{who}: Shoot is planned for Saturday morning and I will have the draft with you by Tuesday.', 1, 67
    UNION ALL SELECT 2222, 1002, 2005, 'message.new', 'message', 'chat_bubble', 'chat', '802', 'New message', '{who}: Look two is my favourite. Can I swap the belt for something from my own wardrobe?', 1, 84
    UNION ALL SELECT 2223, 1003, 2011, 'message.new', 'message', 'chat_bubble', 'chat', '803', 'New message', '{who}: Recording this weekend. Fair warning, the review will call out the average call quality.', 1, 55
    UNION ALL SELECT 2224, 1004, 2016, 'message.new', 'message', 'chat_bubble', 'chat', '804', 'New message', '{who}: Both reels and the carousel are in the shared drive. Captions are in the same doc.', 1, 63
    UNION ALL SELECT 2225, 1006, 2024, 'message.new', 'message', 'chat_bubble', 'chat', '806', 'New message', '{who}: Updated and the crash is gone. Equaliser presets behave exactly as documented now.', 1, 45
    UNION ALL SELECT 2226, 1007, 2038, 'message.new', 'message', 'chat_bubble', 'chat', '807', 'New message', '{who}: Corrected in the on screen text and in the description. Publishing Friday at 6 pm.', 0, 9
    UNION ALL SELECT 2227, 1008, 2002, 'message.new', 'message', 'chat_bubble', 'chat', '808', 'New message', '{who}: Product arrived. Mixability is genuinely good, no clumping with cold water.', 1, 43
    UNION ALL SELECT 2228, 1009, 2035, 'message.new', 'message', 'chat_bubble', 'chat', '809', 'New message', '{who}: The tasting was excellent, the kebabs especially. Stories are already up from today.', 1, 44
    UNION ALL SELECT 2229, 1010, 2006, 'message.new', 'message', 'chat_bubble', 'chat', '810', 'New message', '{who}: Draft link is ready for your review whenever you have five minutes.', 0, 13
    UNION ALL SELECT 2230, 1011, 2013, 'message.new', 'message', 'chat_bubble', 'chat', '811', 'New message', '{who}: Posted at 7:20 pm. Stories are up as well with the link sticker.', 1, 57
    UNION ALL SELECT 2231, 1012, 2022, 'message.new', 'message', 'chat_bubble', 'chat', '813', 'New message', '{who}: That is fair. Then we are aligned on both scope and rate.', 1, 33
    UNION ALL SELECT 2232, 1013, 2043, 'message.new', 'message', 'chat_bubble', 'chat', '814', 'New message', '{who}: New audio is in, sending version two.', 1, 54
    UNION ALL SELECT 2233, 1014, 2010, 'message.new', 'message', 'chat_bubble', 'chat', '815', 'New message', '{who}: Accepted. Twelve days is comfortable for me.', 1, 80
    UNION ALL SELECT 2234, 1016, 2015, 'message.new', 'message', 'chat_bubble', 'chat', '816', 'New message', '{who}: Sharing three reference reels so we are aligned on the pacing.', 0, 5
    UNION ALL SELECT 2235, 1017, 2020, 'message.new', 'message', 'chat_bubble', 'chat', '818', 'New message', '{who}: Can we shift the shoot to Sunday? Saturday has a heavy rain forecast.', 0, 4
    UNION ALL SELECT 2236, 1018, 2033, 'message.new', 'message', 'chat_bubble', 'chat', '819', 'New message', '{who}: Sizes shared. The shipping address is the one saved on my Zusty profile.', 1, 25
    UNION ALL SELECT 2237, 1019, 2041, 'message.new', 'message', 'chat_bubble', 'chat', '820', 'New message', '{who}: Sounds good. I will send the shot list before Friday.', 0, 2
    UNION ALL SELECT 2238, 1020, 2044, 'message.new', 'message', 'chat_bubble', 'chat', '822', 'New message', '{who}: Thank you for the brief, it is one of the clearest I have received.', 1, 19
    UNION ALL SELECT 2239, 2015, 1016, 'message.new', 'message', 'chat_bubble', 'chat', '816', 'New message', '{who}: We are flexible on the publish date, whatever fits your calendar best.', 1, 5
    UNION ALL SELECT 2240, 2020, 1017, 'message.new', 'message', 'chat_bubble', 'chat', '818', 'New message', '{who}: Sunday works. We will move the courier pickup accordingly.', 0, 4
    UNION ALL SELECT 2241, 2033, 1018, 'message.new', 'message', 'chat_bubble', 'chat', '819', 'New message', '{who}: Pieces are dispatched, tracking link is in the chat above.', 1, 24
    UNION ALL SELECT 2242, 2041, 1019, 'message.new', 'message', 'chat_bubble', 'chat', '820', 'New message', '{who}: Perfect, looking forward to seeing the first cut.', 0, 2
    UNION ALL SELECT 2243, 2044, 1020, 'message.new', 'message', 'chat_bubble', 'chat', '822', 'New message', '{who}: Happy to hear that. Let us lock the dates today.', 1, 18
    UNION ALL SELECT 2244, 2045, 1020, 'message.new', 'message', 'chat_bubble', 'chat', '823', 'New message', '{who}: Could you also add two stories to the package? We can revise the rate.', 0, 6
    UNION ALL SELECT 2245, 2004, 1003, 'message.new', 'message', 'chat_bubble', 'chat', '824', 'New message', '{who}: Approved. Please publish on Saturday at 12 noon.', 1, 47
    UNION ALL SELECT 2246, 2003, 1001, 'review.received', 'review', 'star', 'review', '701', 'New review', '{who} rated your collaboration 5 stars: brilliant collaboration.', 1, 60
    UNION ALL SELECT 2247, 2009, 1001, 'review.received', 'review', 'star', 'review', '704', 'New review', 'You have a new 4 star review from {who}.', 1, 58
    UNION ALL SELECT 2248, 2016, 1004, 'review.received', 'review', 'star', 'review', '706', 'New review', '{who} left a review mentioning your turnaround and communication.', 1, 53
    UNION ALL SELECT 2249, 2024, 1006, 'review.received', 'review', 'star', 'review', '708', 'New review', '{who} rated you 5 stars for the review video.', 1, 43
    UNION ALL SELECT 2250, 2002, 1008, 'review.received', 'review', 'star', 'review', '710', 'New review', 'A new review from {who} is live on your profile.', 0, 16
    UNION ALL SELECT 2251, 2013, 1011, 'review.received', 'review', 'star', 'review', '713', 'New review', '{who} gave you 5 stars and called out the studio quality footage.', 1, 48
    UNION ALL SELECT 2252, 2022, 1012, 'review.received', 'review', 'star', 'review', '715', 'New review', '{who} left a 3 star review with feedback on the revision rounds. Consider replying.', 0, 20
    UNION ALL SELECT 2253, 2035, 1009, 'review.received', 'review', 'star', 'review', '717', 'New review', 'New 5 star review from {who}.', 1, 39
    UNION ALL SELECT 2254, 2043, 1013, 'review.received', 'review', 'star', 'review', '719', 'New review', '{who} rated the unboxing campaign 4 stars.', 1, 50
    UNION ALL SELECT 2255, 1001, 2003, 'review.received', 'review', 'star', 'review', '746', 'New review', '{who} reviewed your brand: clear brief, real creative freedom.', 1, 59
    UNION ALL SELECT 2256, 1002, 2005, 'review.received', 'review', 'star', 'review', '747', 'New review', '{who} rated working with you 5 stars.', 1, 70
    UNION ALL SELECT 2257, 1005, 2007, 'review.received', 'review', 'star', 'review', '748', 'New review', '{who} left a review about your approval turnaround.', 1, 46
    UNION ALL SELECT 2258, 1006, 2024, 'review.received', 'review', 'star', 'review', '749', 'New review', '{who} rated your brand 5 stars: smooth from brief to payment.', 0, 18
    UNION ALL SELECT 2259, 1008, 2002, 'review.received', 'review', 'star', 'review', '750', 'New review', 'A creator has reviewed your brand. Reviews improve your visibility to top creators.', 1, 42
    UNION ALL SELECT 2260, 1011, 2013, 'review.received', 'review', 'star', 'review', '751', 'New review', '{who} left a 4 star review mentioning slower approvals over the weekend.', 1, 36
    UNION ALL SELECT 2261, 2004, 1001, 'creator.approved', 'account', 'verified', 'creator', '104', 'Your profile is live', 'Congratulations, your creator profile has been approved and is now discoverable in search.', 1, 89
    UNION ALL SELECT 2262, 2018, 1001, 'creator.approved', 'account', 'verified', 'creator', '118', 'Your profile is live', 'Your profile passed review. Add at least three portfolio items to improve your ranking.', 1, 87
    UNION ALL SELECT 2263, 2030, 1001, 'creator.rejected', 'account', 'info', 'creator', '130', 'Profile needs changes', 'Your profile could not be approved yet. The linked Instagram handle does not match the name on your ID.', 1, 86
    UNION ALL SELECT 2264, 2030, 1001, 'creator.approved', 'account', 'verified', 'creator', '130', 'Your profile is live', 'Thank you for the correction, your profile is approved and live.', 1, 84
    UNION ALL SELECT 2265, 2037, 1001, 'creator.approved', 'account', 'verified', 'creator', '137', 'Your profile is live', 'Your creator profile is approved. Set your packages to start receiving booking requests.', 1, 90
    UNION ALL SELECT 2266, 2003, 1001, 'kyc.verified', 'account', 'verified_user', 'none', NULL, 'Identity verified', 'Your identity has been verified. Payouts and higher booking limits are now unlocked.', 1, 89
    UNION ALL SELECT 2267, 2005, 1001, 'kyc.submitted', 'account', 'verified_user', 'none', NULL, 'Verification submitted', 'We have received your documents. Verification usually completes within 24 to 48 hours.', 1, 88
    UNION ALL SELECT 2268, 2005, 1001, 'kyc.approved', 'account', 'verified', 'none', NULL, 'You are verified', 'Your KYC has been approved. The verified badge is now visible on your profile.', 1, 87
    UNION ALL SELECT 2269, 2012, 1001, 'kyc.under_review', 'account', 'hourglass_top', 'none', NULL, 'Verification under review', 'Your documents are with our compliance team. We will notify you as soon as there is an update.', 1, 87
    UNION ALL SELECT 2270, 2012, 1001, 'kyc.info_required', 'account', 'upload_file', 'none', NULL, 'More information needed', 'The address on your utility bill is not readable. Please upload a clearer copy to continue.', 0, 19
    UNION ALL SELECT 2271, 2022, 1001, 'kyc.rejected', 'account', 'gpp_bad', 'none', NULL, 'Verification needs attention', 'The PAN details did not match our records. Re-check the name and number and submit again.', 1, 62
    UNION ALL SELECT 2272, 2028, 1001, 'kyc.expiring', 'account', 'schedule', 'none', NULL, 'Verification expires soon', 'Your verification expires in 30 days. Re-verify now so your payouts are never interrupted.', 0, 8
    UNION ALL SELECT 2273, 2034, 1001, 'kyc.expired', 'account', 'event_busy', 'none', NULL, 'Verification expired', 'Your KYC has expired. Withdrawals are paused until you complete verification again.', 0, 3
    UNION ALL SELECT 2274, 1013, 2003, 'kyc.verified', 'account', 'verified_user', 'none', NULL, 'Identity verified', 'Your business verification is complete. GST invoices will now be generated automatically.', 1, 85
    UNION ALL SELECT 2275, 1017, 2003, 'kyc.under_review', 'account', 'hourglass_top', 'none', NULL, 'Verification under review', 'Your GST certificate is being verified. This usually takes one working day.', 1, 29
    UNION ALL SELECT 2276, 1001, 2003, 'account.welcome', 'account', 'celebration', 'url', 'https://zuste.com/help/getting-started-brands', 'Welcome to Zusty', 'Welcome aboard. Complete your business profile and browse over 4,000 verified creators across 16 categories.', 1, 90
    UNION ALL SELECT 2277, 1012, 2003, 'account.welcome', 'account', 'celebration', 'url', 'https://zuste.com/help/getting-started-brands', 'Welcome to Zusty', 'Welcome to Zusty. Your first campaign brief takes about four minutes to set up.', 1, 89
    UNION ALL SELECT 2278, 2019, 1001, 'account.welcome', 'account', 'celebration', 'url', 'https://zuste.com/help/getting-started-creators', 'Welcome to Zusty', 'Welcome. Add your packages and connect your social accounts to appear in brand searches.', 1, 88
    UNION ALL SELECT 2279, 2036, 1001, 'account.welcome', 'account', 'celebration', 'url', 'https://zuste.com/help/getting-started-creators', 'Welcome to Zusty', 'Welcome to Zusty. Creators with a complete profile receive around three times more requests.', 1, 86
    UNION ALL SELECT 2280, 1005, 2007, 'referral.rewarded', 'promo', 'person_add', 'wallet', '1806', 'Referral reward credited', 'Your referral completed their first paid booking. INR 1,000 has been credited to your wallet.', 1, 66
    UNION ALL SELECT 2281, 1009, 2035, 'referral.rewarded', 'promo', 'person_add', 'wallet', '1810', 'Referral reward credited', 'Referral bonus credited. Share your code again to keep earning.', 1, 40
    UNION ALL SELECT 2282, 2007, 1005, 'referral.rewarded', 'promo', 'person_add', 'wallet', '1814', 'Referral reward credited', 'INR 500 signup bonus has been added to your wallet from the referral code you used.', 1, 65
    UNION ALL SELECT 2283, 2026, 1015, 'referral.rewarded', 'promo', 'person_add', 'wallet', '1818', 'Referral reward credited', 'Your friend joined and completed a booking. Reward credited.', 0, 12
    UNION ALL SELECT 2284, 1016, 2015, 'referral.rewarded', 'promo', 'person_add', 'wallet', '1822', 'Referral reward credited', 'Referral reward of INR 1,000 credited to your Zusty wallet.', 1, 21
    UNION ALL SELECT 2285, 2040, 1010, 'referral.rewarded', 'promo', 'person_add', 'wallet', '1826', 'Referral reward credited', 'Referral bonus credited after your first completed campaign.', 1, 31
    UNION ALL SELECT 2286, 1001, 2003, 'ticket.replied', 'system', 'support_agent', 'ticket', '1602', 'Support replied', 'Our team has responded to your query about GST on the platform fee.', 1, 52
    UNION ALL SELECT 2287, 1004, 2016, 'ticket.replied', 'system', 'support_agent', 'ticket', '1605', 'Support replied', 'We have replied about the delayed refund. Please check the ticket for the reference number.', 1, 34
    UNION ALL SELECT 2288, 2007, 1005, 'ticket.replied', 'system', 'support_agent', 'ticket', '1608', 'Support replied', 'Support has answered your question about the withdrawal rejection.', 1, 46
    UNION ALL SELECT 2289, 2013, 1011, 'ticket.replied', 'system', 'support_agent', 'ticket', '1611', 'Support replied', 'A support agent has replied to your ticket about bank account verification.', 0, 10
    UNION ALL SELECT 2290, 1010, 2006, 'ticket.replied', 'system', 'support_agent', 'ticket', '1614', 'Support replied', 'Your invoice correction request has been resolved. The revised invoice is available in Billing.', 1, 27
    UNION ALL SELECT 2291, 2024, 1006, 'ticket.replied', 'system', 'support_agent', 'ticket', '1617', 'Support replied', 'We have shared the escrow release timeline on your ticket.', 1, 23
    UNION ALL SELECT 2292, 1018, 2033, 'ticket.replied', 'system', 'support_agent', 'ticket', '1619', 'Support replied', 'Support replied regarding the campaign brief template you requested.', 0, 5
    UNION ALL SELECT 2293, 1020, 2044, 'booking.expiring', 'booking', 'schedule', 'booking', '478', 'Request expiring soon', 'Your request to {who} expires tomorrow morning.', 0, 1
    UNION ALL SELECT 2294, 2045, 1020, 'booking.requested', 'booking', 'calendar_today', 'booking', '480', 'New booking request', '{who} sent you a new collaboration request. Respond soon to protect your response rate.', 0, 1
    UNION ALL SELECT 2295, 1016, 2015, 'booking.accepted', 'booking', 'check_circle', 'booking', '415', 'Booking accepted', '{who} accepted your request. Complete payment to move into production.', 0, 2
    UNION ALL SELECT 2296, 1017, 2020, 'booking.confirmed', 'booking', 'verified', 'booking', '419', 'Booking confirmed!', 'Your booking with {who} is confirmed. Chat is now open for briefing.', 1, 20
    UNION ALL SELECT 2297, 1018, 2033, 'booking.delivered', 'booking', 'task_alt', 'booking', '423', 'Deliverables submitted', '{who} submitted the deliverables. Approve within 72 hours or they auto approve.', 0, 3
    UNION ALL SELECT 2298, 1019, 2041, 'booking.cancelled', 'booking', 'event_busy', 'booking', '427', 'Booking cancelled', 'The booking with {who} was cancelled before payment. No amount was charged.', 1, 13
    UNION ALL SELECT 2299, 2044, 1020, 'booking.confirmed', 'booking', 'verified', 'booking', '478', 'Booking confirmed!', 'Confirmed with {who}. The brief and moodboard are in the chat.', 0, 1
    UNION ALL SELECT 2300, 2015, 1016, 'booking.completed', 'booking', 'review', 'review', '752', 'Campaign completed', 'Campaign with {who} is complete. Your payout has been released from escrow.', 0, 2
) n
LEFT JOIN users ru ON ru.id = n.ref
JOIN users tu ON tu.id = n.uid;


/* ==========================================================================
 * 8. REFERRALS (1-12) — mixed status, INR rewards
 * ========================================================================== */
INSERT IGNORE INTO referrals
    (id, referrer_user_id, referred_user_id, code, referrer_reward, referred_reward,
     status, qualified_at, rewarded_at, created_at)
SELECT r.id, r.referrer, r.referred, r.code, r.rr, r.dr, r.st,
       CASE WHEN r.st IN ('qualified','rewarded') THEN DATE_SUB(NOW(), INTERVAL r.qd DAY) END,
       CASE WHEN r.st = 'rewarded' THEN DATE_SUB(NOW(), INTERVAL r.wd DAY) END,
       DATE_SUB(NOW(), INTERVAL r.cd DAY)
FROM (
              SELECT  1 AS id, 1001 AS referrer, 1012 AS referred, 'ZBRND1001' AS code, 1000.00 AS rr, 500.00 AS dr, 'rewarded'  AS st, 118 AS qd, 116 AS wd, 132 AS cd
    UNION ALL SELECT  2, 1005, 1017, 'ZBRND1005', 1000.00, 500.00, 'rewarded',   66,  64,  84
    UNION ALL SELECT  3, 1009, 1019, 'ZBRND1009', 1000.00, 500.00, 'qualified',  40,   0,  58
    UNION ALL SELECT  4, 1016, 1020, 'ZBRND1016',  750.00, 500.00, 'pending',     0,   0,  21
    UNION ALL SELECT  5, 2003, 2019, 'ZCRTR2003', 1000.00, 500.00, 'rewarded',   64,  62,  92
    UNION ALL SELECT  6, 2007, 2026, 'ZCRTR2007', 1000.00, 500.00, 'rewarded',   47,  45,  75
    UNION ALL SELECT  7, 2005, 2036, 'ZCRTR2005', 1000.00, 500.00, 'qualified',  30,   0,  54
    UNION ALL SELECT  8, 2013, 2039, 'ZCRTR2013',  750.00, 250.00, 'pending',     0,   0,  17
    UNION ALL SELECT  9, 2016, 2042, 'ZCRTR2016',  750.00, 250.00, 'pending',     0,   0,   9
    UNION ALL SELECT 10, 2024, 2030, 'ZCRTR2024', 1000.00, 500.00, 'void',        0,   0, 101
    UNION ALL SELECT 11, 1010, 2040, 'ZBRND1010', 1000.00, 500.00, 'rewarded',   31,  29,  49
    UNION ALL SELECT 12, 2035, 2045, 'ZCRTR2035',  750.00, 250.00, 'void',        0,   0,  38
) r
JOIN users u1 ON u1.id = r.referrer
JOIN users u2 ON u2.id = r.referred;

/* mirror the relationship on the user row where nothing has claimed it yet */
UPDATE users u
  JOIN referrals rf ON rf.referred_user_id = u.id AND rf.id BETWEEN 1 AND 12
   SET u.referred_by = rf.referrer_user_id
 WHERE u.referred_by IS NULL
   AND rf.status <> 'void';


/* ==========================================================================
 * 9. SEARCH HISTORIES (3001-3110)
 * ========================================================================== */
INSERT IGNORE INTO search_histories (id, user_id, term, filters, result_count, created_at)
SELECT s.id, s.uid, s.term, s.filters, s.rc, DATE_SUB(NOW(), INTERVAL s.dago DAY)
FROM (
              SELECT 3001 AS id, 1001 AS uid, 'instagram fashion mumbai' AS term, '{"platform":"instagram","category":"fashion","city":"Mumbai"}' AS filters, 128 AS rc, 96 AS dago
    UNION ALL SELECT 3002, 1001, 'skincare creators under 50k budget', '{"category":"beauty","price_max":50000}', 64, 94
    UNION ALL SELECT 3003, 1001, 'beauty reel creators', '{"category":"beauty","deliverable":"reel"}', 211, 92
    UNION ALL SELECT 3004, 1001, 'nano influencers mumbai', '{"city":"Mumbai","tier":"nano"}', 340, 88
    UNION ALL SELECT 3005, 1001, 'vitamin c serum ugc', '{"deliverable":"ugc"}', 47, 71
    UNION ALL SELECT 3006, 1002, 'festive fashion creators delhi', '{"category":"fashion","city":"Delhi"}', 96, 90
    UNION ALL SELECT 3007, 1002, 'saree styling reels', '{"category":"fashion","deliverable":"reel"}', 58, 89
    UNION ALL SELECT 3008, 1002, 'ethnic wear haul', '{"category":"fashion"}', 73, 87
    UNION ALL SELECT 3009, 1002, 'micro influencers 15k to 75k', '{"price_min":15000,"price_max":75000}', 402, 80
    UNION ALL SELECT 3010, 1002, 'carousel post creators', '{"deliverable":"post"}', 189, 62
    UNION ALL SELECT 3011, 1003, 'tech reviewers youtube', '{"platform":"youtube","category":"tech"}', 44, 84
    UNION ALL SELECT 3012, 1003, 'earbuds review long form', '{"platform":"youtube","deliverable":"video"}', 22, 83
    UNION ALL SELECT 3013, 1003, 'gadget unboxing bengaluru', '{"category":"tech","city":"Bengaluru"}', 31, 79
    UNION ALL SELECT 3014, 1003, 'tech creators above 500k subscribers', '{"category":"tech","followers_min":500000}', 18, 66
    UNION ALL SELECT 3015, 1003, 'hindi tech channels', '{"category":"tech","language":"Hindi"}', 39, 54
    UNION ALL SELECT 3016, 1004, 'food bloggers hyderabad', '{"category":"food","city":"Hyderabad"}', 67, 78
    UNION ALL SELECT 3017, 1004, 'restaurant tasting creators', '{"category":"food"}', 142, 77
    UNION ALL SELECT 3018, 1004, 'biryani reel', '{"category":"food","deliverable":"reel"}', 29, 74
    UNION ALL SELECT 3019, 1004, 'cafe review creators pune', '{"category":"food","city":"Pune"}', 41, 60
    UNION ALL SELECT 3020, 1004, 'south indian food creators', '{"category":"food"}', 88, 44
    UNION ALL SELECT 3021, 1005, 'fitness trainers bengaluru', '{"category":"fitness","city":"Bengaluru"}', 55, 82
    UNION ALL SELECT 3022, 1005, 'plant protein creators', '{"category":"fitness"}', 34, 81
    UNION ALL SELECT 3023, 1005, 'marathon runners india', '{"category":"fitness"}', 26, 68
    UNION ALL SELECT 3024, 1005, 'home workout reels', '{"category":"fitness","deliverable":"reel"}', 112, 57
    UNION ALL SELECT 3025, 1005, 'gym creators available this month', '{"category":"fitness","availability":"available"}', 63, 33
    UNION ALL SELECT 3026, 1006, 'travel vloggers goa', '{"category":"travel","city":"Goa"}', 37, 76
    UNION ALL SELECT 3027, 1006, 'himalaya trek creators', '{"category":"travel"}', 21, 75
    UNION ALL SELECT 3028, 1006, 'travel youtube long form', '{"category":"travel","platform":"youtube"}', 48, 61
    UNION ALL SELECT 3029, 1006, 'budget travel india', '{"category":"travel"}', 94, 43
    UNION ALL SELECT 3030, 1006, 'kerala backwaters reels', '{"category":"travel","city":"Kochi"}', 12, 24
    UNION ALL SELECT 3031, 1007, 'gaming streamers hindi', '{"category":"gaming","language":"Hindi"}', 59, 73
    UNION ALL SELECT 3032, 1007, 'bgmi creators', '{"category":"gaming"}', 83, 72
    UNION ALL SELECT 3033, 1007, 'gaming setup review', '{"category":"gaming","deliverable":"video"}', 27, 55
    UNION ALL SELECT 3034, 1007, 'esports creators delhi', '{"category":"gaming","city":"Delhi"}', 19, 30
    UNION ALL SELECT 3035, 1008, 'parenting creators mumbai', '{"category":"parenting","city":"Mumbai"}', 46, 70
    UNION ALL SELECT 3036, 1008, 'baby care ugc', '{"category":"parenting","deliverable":"ugc"}', 33, 69
    UNION ALL SELECT 3037, 1008, 'mom influencers', '{"category":"parenting"}', 121, 51
    UNION ALL SELECT 3038, 1008, 'kids nutrition creators', '{"category":"parenting"}', 24, 28
    UNION ALL SELECT 3039, 1009, 'finance creators youtube', '{"category":"finance","platform":"youtube"}', 52, 67
    UNION ALL SELECT 3040, 1009, 'mutual funds explainer', '{"category":"finance"}', 30, 65
    UNION ALL SELECT 3041, 1009, 'personal finance hindi', '{"category":"finance","language":"Hindi"}', 41, 47
    UNION ALL SELECT 3042, 1009, 'stock market creators', '{"category":"finance"}', 38, 26
    UNION ALL SELECT 3043, 1010, 'automobile reviewers', '{"category":"automobile"}', 44, 64
    UNION ALL SELECT 3044, 1010, 'ev review creators', '{"category":"automobile"}', 17, 63
    UNION ALL SELECT 3045, 1010, 'bike vloggers chennai', '{"category":"automobile","city":"Chennai"}', 23, 45
    UNION ALL SELECT 3046, 1010, 'car detailing shorts', '{"category":"automobile","deliverable":"short"}', 15, 22
    UNION ALL SELECT 3047, 1011, 'comedy creators instagram', '{"category":"comedy","platform":"instagram"}', 154, 59
    UNION ALL SELECT 3048, 1011, 'sketch comedy reels', '{"category":"comedy","deliverable":"reel"}', 98, 58
    UNION ALL SELECT 3049, 1011, 'stand up comedians india', '{"category":"comedy"}', 62, 36
    UNION ALL SELECT 3050, 1011, 'brand integration comedy', '{"category":"comedy"}', 71, 14
    UNION ALL SELECT 3051, 1012, 'lifestyle creators pune', '{"category":"lifestyle","city":"Pune"}', 86, 56
    UNION ALL SELECT 3052, 1012, 'home decor creators', '{"category":"lifestyle"}', 57, 55
    UNION ALL SELECT 3053, 1012, 'minimal aesthetic feed', '{"category":"lifestyle"}', 42, 35
    UNION ALL SELECT 3054, 1012, 'lifestyle creators available', '{"category":"lifestyle","availability":"available"}', 130, 11
    UNION ALL SELECT 3055, 1013, 'unboxing creators', '{"deliverable":"video"}', 76, 53
    UNION ALL SELECT 3056, 1013, 'amazon product ugc', '{"deliverable":"ugc"}', 145, 52
    UNION ALL SELECT 3057, 1013, 'verified creators only', '{"is_verified":true}', 289, 31
    UNION ALL SELECT 3058, 1013, 'creators with 4.5 plus rating', '{"rating_min":4.5}', 176, 10
    UNION ALL SELECT 3059, 1014, 'education creators', '{"category":"education"}', 64, 50
    UNION ALL SELECT 3060, 1014, 'upsc coaching creators', '{"category":"education"}', 29, 49
    UNION ALL SELECT 3061, 1014, 'edtech collab lucknow', '{"category":"education","city":"Lucknow"}', 11, 27
    UNION ALL SELECT 3062, 1014, 'study with me reels', '{"category":"education","deliverable":"reel"}', 35, 8
    UNION ALL SELECT 3063, 1015, 'health and wellness creators', '{"category":"health-wellness"}', 92, 48
    UNION ALL SELECT 3064, 1015, 'ayurveda creators kochi', '{"category":"health-wellness","city":"Kochi"}', 14, 46
    UNION ALL SELECT 3065, 1015, 'yoga instructors instagram', '{"category":"health-wellness","platform":"instagram"}', 61, 25
    UNION ALL SELECT 3066, 1015, 'mental health creators', '{"category":"health-wellness"}', 38, 7
    UNION ALL SELECT 3067, 1016, 'art and design creators', '{"category":"art-design"}', 47, 42
    UNION ALL SELECT 3068, 1016, 'illustrators jaipur', '{"category":"art-design","city":"Jaipur"}', 13, 41
    UNION ALL SELECT 3069, 1016, 'calligraphy reels', '{"category":"art-design","deliverable":"reel"}', 19, 20
    UNION ALL SELECT 3070, 1016, 'packaging design creators', '{"category":"art-design"}', 16, 6
    UNION ALL SELECT 3071, 1017, 'music creators independent', '{"category":"music"}', 55, 40
    UNION ALL SELECT 3072, 1017, 'indie singers mumbai', '{"category":"music","city":"Mumbai"}', 32, 39
    UNION ALL SELECT 3073, 1017, 'punjabi music creators', '{"category":"music"}', 44, 19
    UNION ALL SELECT 3074, 1017, 'reels audio collab', '{"category":"music","deliverable":"reel"}', 87, 5
    UNION ALL SELECT 3075, 1018, 'macro influencers india', '{"tier":"macro"}', 78, 38
    UNION ALL SELECT 3076, 1018, 'creators above 1 million followers', '{"followers_min":1000000}', 41, 37
    UNION ALL SELECT 3077, 1018, 'multi platform creators', '{"platform":"multi"}', 133, 18
    UNION ALL SELECT 3078, 1018, 'brand ambassadors long term', '{}', 210, 4
    UNION ALL SELECT 3079, 1019, 'creators in ahmedabad', '{"city":"Ahmedabad"}', 68, 34
    UNION ALL SELECT 3080, 1019, 'gujarati content creators', '{"language":"Gujarati"}', 51, 33
    UNION ALL SELECT 3081, 1019, 'diwali campaign creators', '{}', 265, 16
    UNION ALL SELECT 3082, 1019, 'fast turnaround creators', '{"delivery_days_max":7}', 97, 3
    UNION ALL SELECT 3083, 1020, 'kolkata lifestyle creators', '{"category":"lifestyle","city":"Kolkata"}', 43, 32
    UNION ALL SELECT 3084, 1020, 'bengali creators', '{"language":"Bengali"}', 36, 29
    UNION ALL SELECT 3085, 1020, 'durga puja campaign', '{"city":"Kolkata"}', 21, 15
    UNION ALL SELECT 3086, 1020, 'creators under 20000', '{"price_max":20000}', 312, 2
    UNION ALL SELECT 3087, 2003, 'skincare brands hiring', '{"type":"campaign","category":"beauty"}', 24, 60
    UNION ALL SELECT 3088, 2003, 'open campaigns mumbai', '{"type":"campaign","city":"Mumbai"}', 31, 45
    UNION ALL SELECT 3089, 2005, 'fashion campaigns', '{"type":"campaign","category":"fashion"}', 28, 57
    UNION ALL SELECT 3090, 2005, 'paid collaborations this month', '{"type":"campaign"}', 52, 23
    UNION ALL SELECT 3091, 2007, 'restaurant collaborations', '{"type":"campaign","category":"food"}', 17, 43
    UNION ALL SELECT 3092, 2007, 'food brands hyderabad', '{"type":"campaign","city":"Hyderabad"}', 9, 12
    UNION ALL SELECT 3093, 2011, 'tech brand campaigns', '{"type":"campaign","category":"tech"}', 14, 41
    UNION ALL SELECT 3094, 2013, 'beauty brand briefs', '{"type":"campaign","category":"beauty"}', 22, 30
    UNION ALL SELECT 3095, 2016, 'ethnic wear brands', '{"type":"campaign","category":"fashion"}', 19, 26
    UNION ALL SELECT 3096, 2024, 'audio brand collaborations', '{"type":"campaign","category":"tech"}', 11, 21
    UNION ALL SELECT 3097, 2035, 'festive campaigns', '{"type":"campaign"}', 46, 13
    UNION ALL SELECT 3098, 2043, 'ugc only campaigns', '{"type":"campaign","deliverable":"ugc"}', 33, 9
    UNION ALL SELECT 3099, NULL, 'instagram fashion mumbai', '{"platform":"instagram","category":"fashion","city":"Mumbai"}', 128, 6
    UNION ALL SELECT 3100, NULL, 'travel vlogger goa', '{"category":"travel","city":"Goa"}', 37, 6
    UNION ALL SELECT 3101, NULL, 'fitness trainer delhi', '{"category":"fitness","city":"Delhi"}', 49, 5
    UNION ALL SELECT 3102, NULL, 'food blogger chennai', '{"category":"food","city":"Chennai"}', 40, 5
    UNION ALL SELECT 3103, NULL, 'tech reviewer bengaluru', '{"category":"tech","city":"Bengaluru"}', 31, 4
    UNION ALL SELECT 3104, NULL, 'beauty creator hyderabad', '{"category":"beauty","city":"Hyderabad"}', 27, 4
    UNION ALL SELECT 3105, NULL, 'lifestyle blogger pune', '{"category":"lifestyle","city":"Pune"}', 86, 3
    UNION ALL SELECT 3106, NULL, 'comedy creator instagram', '{"category":"comedy","platform":"instagram"}', 154, 3
    UNION ALL SELECT 3107, NULL, 'youtube shorts creators', '{"platform":"youtube","deliverable":"short"}', 203, 2
    UNION ALL SELECT 3108, NULL, 'creators under 10000', '{"price_max":10000}', 178, 2
    UNION ALL SELECT 3109, NULL, 'verified creators jaipur', '{"city":"Jaipur","is_verified":true}', 22, 1
    UNION ALL SELECT 3110, NULL, 'instagram reel creators chandigarh', '{"platform":"instagram","city":"Chandigarh","deliverable":"reel"}', 18, 1
) s
LEFT JOIN users su ON su.id = s.uid
WHERE s.uid IS NULL OR su.id IS NOT NULL;


/* ==========================================================================
 * 10. POPULAR SEARCHES
 *     10a refreshes hit counts on the curated terms already seeded (ids 1-8),
 *     10b adds the organic trending terms at ids 31-70.
 * ========================================================================== */
INSERT INTO popular_searches (term, hits, is_curated, is_active, last_hit_at) VALUES
    ('Fashion Influencer', 4820, 1, 1, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    ('Travel Vlogger',     2410, 1, 1, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
    ('Lifestyle Blogger',  3960, 1, 1, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
    ('Fitness Trainer',    2180, 1, 1, DATE_SUB(NOW(), INTERVAL 9 HOUR)),
    ('Food Blogger',       3540, 1, 1, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
    ('Tech Reviewer',      1970, 1, 1, DATE_SUB(NOW(), INTERVAL 7 HOUR)),
    ('Beauty Creator',     4110, 1, 1, DATE_SUB(NOW(), INTERVAL 1 HOUR))
ON DUPLICATE KEY UPDATE
    hits        = VALUES(hits),
    is_active   = VALUES(is_active),
    last_hit_at = VALUES(last_hit_at);

INSERT INTO popular_searches (id, term, hits, is_curated, is_active, last_hit_at) VALUES
    (31, 'instagram fashion mumbai',        1842, 0, 1, DATE_SUB(NOW(), INTERVAL 40 MINUTE)),
    (32, 'skincare creators',               1615, 0, 1, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (33, 'ugc creators india',              1497, 0, 1, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
    (34, 'micro influencers delhi',         1288, 0, 1, DATE_SUB(NOW(), INTERVAL 4 HOUR)),
    (35, 'food bloggers hyderabad',         1104, 0, 1, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
    (36, 'youtube tech reviewers',           986, 0, 1, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
    (37, 'reel creators under 20000',        942, 0, 1, DATE_SUB(NOW(), INTERVAL 8 HOUR)),
    (38, 'fitness creators bengaluru',       901, 0, 1, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
    (39, 'ethnic wear haul',                 867, 0, 1, DATE_SUB(NOW(), INTERVAL 12 HOUR)),
    (40, 'diwali campaign creators',         845, 0, 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (41, 'nano influencers mumbai',          812, 0, 1, DATE_SUB(NOW(), INTERVAL 9 HOUR)),
    (42, 'comedy creators instagram',        788, 0, 1, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
    (43, 'travel creators goa',              744, 0, 1, DATE_SUB(NOW(), INTERVAL 14 HOUR)),
    (44, 'gaming streamers hindi',           702, 0, 1, DATE_SUB(NOW(), INTERVAL 7 HOUR)),
    (45, 'parenting creators',               681, 0, 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (46, 'finance creators youtube',         659, 0, 1, DATE_SUB(NOW(), INTERVAL 11 HOUR)),
    (47, 'beauty reel creators',             634, 0, 1, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
    (48, 'automobile reviewers',             588, 0, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (49, 'verified creators only',           571, 0, 1, DATE_SUB(NOW(), INTERVAL 6 HOUR)),
    (50, 'creators in pune',                 547, 0, 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (51, 'home decor creators',              522, 0, 1, DATE_SUB(NOW(), INTERVAL 15 HOUR)),
    (52, 'restaurant tasting creators',      498, 0, 1, DATE_SUB(NOW(), INTERVAL 5 HOUR)),
    (53, 'saree styling reels',              476, 0, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (54, 'south indian food creators',       451, 0, 1, DATE_SUB(NOW(), INTERVAL 18 HOUR)),
    (55, 'creators in ahmedabad',            428, 0, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (56, 'health and wellness creators',     407, 0, 1, DATE_SUB(NOW(), INTERVAL 10 HOUR)),
    (57, 'education creators',               389, 0, 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
    (58, 'illustrators jaipur',              361, 0, 1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
    (59, 'indie music creators',             344, 0, 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (60, 'creators in kolkata',              328, 0, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (61, 'macro influencers india',          311, 0, 1, DATE_SUB(NOW(), INTERVAL 16 HOUR)),
    (62, 'youtube shorts creators',          297, 0, 1, DATE_SUB(NOW(), INTERVAL 7 HOUR)),
    (63, 'fast turnaround creators',         276, 0, 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
    (64, 'ev review creators',               254, 0, 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
    (65, 'yoga instructors instagram',       238, 0, 1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
    (66, 'creators in chandigarh',           219, 0, 1, DATE_SUB(NOW(), INTERVAL 6 DAY)),
    (67, 'ayurveda creators kochi',          186, 0, 1, DATE_SUB(NOW(), INTERVAL 8 DAY)),
    (68, 'bike vloggers chennai',            164, 0, 1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
    (69, 'study with me reels',              141, 0, 1, DATE_SUB(NOW(), INTERVAL 9 DAY)),
    (70, 'packaging design creators',         97, 0, 0, DATE_SUB(NOW(), INTERVAL 21 DAY))
ON DUPLICATE KEY UPDATE
    hits        = VALUES(hits),
    is_active   = VALUES(is_active),
    last_hit_at = VALUES(last_hit_at);

/* ========================= end 025_demo_engagement.sql ==================== */
