-- =====================================================================================
-- 027_demo_analytics_ops.sql  —  Zustee demo analytics + operational history
-- =====================================================================================
-- Fills the eight tables the admin dashboards read for charts, traffic breakdowns,
-- outbound-messaging history and the personal widgets (todo / sticky notes /
-- announcements). Without these rows every panel on /admin and /admin/analytics
-- renders as an empty state.
--
-- Tables and id ranges owned by this file
--   analytics_daily          PK stat_date — one row per day for the last 365 days
--   visit_stats              PK (stat_date, dimension, value) — last 90 days x 49 series
--   user_devices             ids 101-160  (60 devices across 59 seeded users)
--   marketing_campaigns      ids 401-412  (ids 301-330 belong to 022_demo_campaigns)
--   notification_broadcasts  ids 1-8
--   admin_announcements      ids 1-6
--   admin_tasks              ids 1-12
--   admin_notes              ids 1-6
--
-- Depends on (never re-inserted, only read/referenced)
--   admins 1 (role_id 1)      roles 1-7
--   users  brands 1001-1020, creators 2001-2045
--   bookings 401-480, payments 501-580, platform_earnings, refunds,
--   withdrawal_requests, wallet_transactions, messages, reviews,
--   search_histories, creator_profile_views, support_tickets
--
-- ---------------------------------------------------------------------------
-- DERIVED vs SYNTHESISED  (analytics_daily)
-- ---------------------------------------------------------------------------
-- DERIVED — computed with a correlated subquery against the transactional table,
-- using the EXACT same expression `CronRunner::rollupAnalytics()` uses, so the
-- nightly cron re-running over these dates reproduces the identical number and
-- the dashboard totals reconcile with the source tables:
--
--   users_new           users.created_at            (account_type <> 'guest')
--   brands_new          users.created_at            (account_type = 'brand')
--   creators_new        users.created_at            (account_type = 'creator')
--   bookings_created    bookings.created_at
--   bookings_accepted   bookings.accepted_at
--   bookings_completed  bookings.completed_at
--   bookings_cancelled  bookings.cancelled_at
--   gmv                 SUM(bookings.total_amount)      BY paid_at
--   revenue             SUM(platform_earnings.total)    BY earned_on
--   refunds             SUM(refunds.amount)             BY processed_at, status='completed'
--   payouts             SUM(withdrawal_requests.net_amount) BY processed_at, status='completed'
--   messages_sent       messages.created_at
--   reviews_posted      reviews.created_at
--
--   These are genuinely spiky — 80 bookings and 61 payments across 365 days means
--   most days really are zero. That is the truth of the transactional tables and it
--   is deliberately NOT smoothed, because a GMV chart that disagrees with
--   /admin/bookings is worse than a sparse one.
--
-- DERIVED WITH A FLOOR — real source exists but only covers part of the window, so
-- the row takes GREATEST(<derived>, <synthesised>). The stored value is therefore
-- never LESS than the recorded raw events:
--
--   searches        >= COUNT(search_histories) on that date
--   profile_views   >= COUNT(creator_profile_views) on that date
--   tickets_opened  >= COUNT(support_tickets) on that date  (support_tickets is
--                      seeded by 026_demo_cms_support.sql; the floor picks it up
--                      whichever order the two files are applied in)
--
-- SYNTHESISED — no usable source in this install:
--
--   users_active    the cron reads users.last_seen_at, which the login flow only
--                   ever writes "now" — every seeded row lands in the last 6 days,
--                   so 359 of 365 days would be 0. Modelled instead as
--                   ROUND(registered_users_to_date * 0.45 * seasonality), which
--                   still grows off a REAL signal (the cumulative user count) and
--                   is hard-capped at the number of accounts that existed that day.
--                   searches / profile_views / tickets_opened scale off the same
--                   index BEFORE the cap, so the festive peak is not clipped.
--   wallet_topups   the cron reads payments WHERE purpose='wallet_topup', and the
--                   finance seed (024) modelled top-ups as wallet_transactions
--                   ledger entries instead — there are 0 matching payment rows.
--                   Derived here from wallet_transactions (category='deposit',
--                   status='completed') so it reconciles with the wallet ledger
--                   the finance panel shows. Sums to the same 51,00,000 INR.
--
-- SHAPE OF THE SYNTHESISED SERIES
--   trend      cumulative registered users (real staircase) x a 0.80 -> 1.20 ramp
--   weekly     Sun 0.66, Sat 0.73, Fri 0.95, Mon 1.09, Wed 1.06, Tue/Thu 1.02
--   festive    a triangular bump peaking on 25 Oct (Navratri -> Diwali -> Bhai Dooj),
--              +85% at the peak, tapering to 0 over +/- 35 days. Anchored per-year
--              so the bump lands correctly whichever 365-day window NOW() produces.
--   jitter     deterministic, +/-10% from (age * 37) % 23 — no RAND(), so a re-run
--              over the same dates writes the same numbers.
--
-- ---------------------------------------------------------------------------
-- visit_stats — a note on "page/route" visits
-- ---------------------------------------------------------------------------
-- The table CANNOT hold page/screen routes: `dimension` is
-- ENUM('device','browser','os','country','city','language','platform','source')
-- and the PK is (stat_date, dimension, value). There is no 'page' / 'route' /
-- 'screen' member, and WidgetService::statsByDimension() hard-rejects anything
-- outside that list. So this file seeds all eight REAL dimensions for the last
-- 90 days instead. Route-level analytics would need a schema change.
--
-- Idempotent: analytics_daily / visit_stats upsert every metric column (so the two
-- placeholder analytics_daily rows the cron already wrote get real values), the
-- id-keyed tables use ON DUPLICATE KEY UPDATE `id` = VALUES(`id`). Safe to re-run.
-- Money is INR, GST 18%, platform commission 15% — inherited from the source rows.
-- =====================================================================================

SET NAMES utf8mb4;


-- =====================================================================================
-- 1. analytics_daily — 365 daily rows, every numeric column populated
-- =====================================================================================
-- The day spine is built from a digit cross-join (0-399, filtered to 0-364) rather
-- than a helper table, so the file has no external dependency.

INSERT INTO `analytics_daily`
  (`stat_date`, `users_new`, `brands_new`, `creators_new`, `users_active`,
   `bookings_created`, `bookings_accepted`, `bookings_completed`, `bookings_cancelled`,
   `gmv`, `revenue`, `refunds`, `payouts`, `wallet_topups`,
   `messages_sent`, `reviews_posted`, `searches`, `profile_views`, `tickets_opened`,
   `computed_at`)
SELECT
    d.`stat_date`,

    -- ---- derived: registrations -------------------------------------------------
    (SELECT COUNT(*) FROM `users` u
      WHERE DATE(u.`created_at`) = d.`stat_date` AND u.`account_type` <> 'guest'),
    (SELECT COUNT(*) FROM `users` u
      WHERE DATE(u.`created_at`) = d.`stat_date` AND u.`account_type` = 'brand'),
    (SELECT COUNT(*) FROM `users` u
      WHERE DATE(u.`created_at`) = d.`stat_date` AND u.`account_type` = 'creator'),

    -- ---- synthesised: daily actives (capped by the accounts that existed) --------
    d.`users_active`,

    -- ---- derived: booking funnel ------------------------------------------------
    (SELECT COUNT(*) FROM `bookings` b WHERE DATE(b.`created_at`)   = d.`stat_date`),
    (SELECT COUNT(*) FROM `bookings` b WHERE DATE(b.`accepted_at`)  = d.`stat_date`),
    (SELECT COUNT(*) FROM `bookings` b WHERE DATE(b.`completed_at`) = d.`stat_date`),
    (SELECT COUNT(*) FROM `bookings` b WHERE DATE(b.`cancelled_at`) = d.`stat_date`),

    -- ---- derived: money ---------------------------------------------------------
    (SELECT COALESCE(SUM(b.`total_amount`), 0) FROM `bookings` b
      WHERE DATE(b.`paid_at`) = d.`stat_date`),
    (SELECT COALESCE(SUM(pe.`total`), 0) FROM `platform_earnings` pe
      WHERE pe.`earned_on` = d.`stat_date`),
    (SELECT COALESCE(SUM(r.`amount`), 0) FROM `refunds` r
      WHERE DATE(r.`processed_at`) = d.`stat_date` AND r.`status` = 'completed'),
    (SELECT COALESCE(SUM(w.`net_amount`), 0) FROM `withdrawal_requests` w
      WHERE DATE(w.`processed_at`) = d.`stat_date` AND w.`status` = 'completed'),
    -- wallet top-ups live in the ledger, not in `payments`, in this install
    (SELECT COALESCE(SUM(wt.`amount`), 0) FROM `wallet_transactions` wt
      WHERE DATE(wt.`created_at`) = d.`stat_date`
        AND wt.`category` = 'deposit' AND wt.`status` = 'completed'),

    -- ---- derived: engagement ----------------------------------------------------
    (SELECT COUNT(*) FROM `messages` m WHERE DATE(m.`created_at`) = d.`stat_date`),
    (SELECT COUNT(*) FROM `reviews`  rv WHERE DATE(rv.`created_at`) = d.`stat_date`),

    -- ---- floored: searches / profile views / tickets ----------------------------
    GREATEST(
        (SELECT COUNT(*) FROM `search_histories` sh WHERE DATE(sh.`created_at`) = d.`stat_date`),
        ROUND(d.`base_activity` * 2.90 * d.`f_jitter2`)
    ),
    GREATEST(
        (SELECT COUNT(*) FROM `creator_profile_views` cpv WHERE cpv.`viewed_on` = d.`stat_date`),
        ROUND(d.`base_activity` * 4.60 * d.`f_jitter3`)
    ),
    GREATEST(
        (SELECT COUNT(*) FROM `support_tickets` st WHERE DATE(st.`created_at`) = d.`stat_date`),
        ROUND(d.`base_activity` * 0.055)
    ),

    NOW()
FROM (
    SELECT x.`stat_date`,
           x.`f_jitter2`,
           x.`f_jitter3`,
           -- uncapped activity index; searches / views / tickets scale off this so the
           -- festive peak is not clipped away by the head-count cap below
           x.`cum_users` * 0.45 * x.`f_season` AS `base_activity`,
           -- daily actives can never exceed the accounts that existed that day
           LEAST(x.`cum_users`, GREATEST(3, ROUND(x.`cum_users` * 0.45 * x.`f_season`))) AS `users_active`
    FROM (
        SELECT s.`stat_date`,
               (SELECT COUNT(*) FROM `users` u
                 WHERE DATE(u.`created_at`) <= s.`stat_date` AND u.`account_type` <> 'guest') AS `cum_users`,
               ROUND(
                   -- upward ramp across the window (0.80 a year ago -> 1.20 today)
                   (0.80 + (364 - s.`age`) * 0.0011)
                   -- weekly seasonality: weekends clearly lower
                   * CASE DAYOFWEEK(s.`stat_date`)
                       WHEN 1 THEN 0.66   -- Sunday
                       WHEN 7 THEN 0.73   -- Saturday
                       WHEN 6 THEN 0.95   -- Friday
                       WHEN 2 THEN 1.09   -- Monday
                       WHEN 4 THEN 1.06   -- Wednesday
                       ELSE 1.02          -- Tuesday / Thursday
                     END
                   -- festive bump: triangular, peaks 25 Oct, +85%, +/-35 day taper
                   * (1 + 0.85 * (GREATEST(0, 35 - LEAST(35,
                        ABS(DATEDIFF(s.`stat_date`, CONCAT(YEAR(s.`stat_date`), '-10-25'))))) / 35))
                   -- deterministic jitter, +/-10%
                   * (1 + (CAST((s.`age` * 37) % 23 AS SIGNED) - 11) / 110)
               , 4) AS `f_season`,
               ROUND(1 + (CAST((s.`age` * 53) % 19 AS SIGNED) - 9) / 95, 4)  AS `f_jitter2`,
               ROUND(1 + (CAST((s.`age` * 71) % 17 AS SIGNED) - 8) / 100, 4) AS `f_jitter3`
        FROM (
            SELECT DATE(DATE_SUB(NOW(), INTERVAL g.`n` DAY)) AS `stat_date`, g.`n` AS `age`
            FROM (
                SELECT (a.`n` + b.`n` * 10 + c.`n` * 100) AS `n`
                FROM (SELECT 0 AS `n` UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                      UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
                      UNION ALL SELECT 8 UNION ALL SELECT 9) a
                CROSS JOIN
                     (SELECT 0 AS `n` UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                      UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
                      UNION ALL SELECT 8 UNION ALL SELECT 9) b
                CROSS JOIN
                     (SELECT 0 AS `n` UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) c
            ) g
            WHERE g.`n` <= 364
        ) s
    ) x
) d
ON DUPLICATE KEY UPDATE
    `users_new`          = VALUES(`users_new`),
    `brands_new`         = VALUES(`brands_new`),
    `creators_new`       = VALUES(`creators_new`),
    `users_active`       = VALUES(`users_active`),
    `bookings_created`   = VALUES(`bookings_created`),
    `bookings_accepted`  = VALUES(`bookings_accepted`),
    `bookings_completed` = VALUES(`bookings_completed`),
    `bookings_cancelled` = VALUES(`bookings_cancelled`),
    `gmv`                = VALUES(`gmv`),
    `revenue`            = VALUES(`revenue`),
    `refunds`            = VALUES(`refunds`),
    `payouts`            = VALUES(`payouts`),
    `wallet_topups`      = VALUES(`wallet_topups`),
    `messages_sent`      = VALUES(`messages_sent`),
    `reviews_posted`     = VALUES(`reviews_posted`),
    `searches`           = VALUES(`searches`),
    `profile_views`      = VALUES(`profile_views`),
    `tickets_opened`     = VALUES(`tickets_opened`),
    `computed_at`        = VALUES(`computed_at`);


-- =====================================================================================
-- 2. visit_stats — last 90 days across all eight real dimensions
-- =====================================================================================
-- Daily traffic index grows ~760 -> ~1310 visits/day across the window and carries the
-- same weekday seasonality as analytics_daily. Each dimension's shares sum to 1.00, so
-- every dimension totals the same daily visit count — which is what a real
-- session-level rollup produces.

INSERT INTO `visit_stats` (`stat_date`, `dimension`, `value`, `visits`, `unique_users`)
SELECT
    d.`stat_date`,
    v.`dimension`,
    v.`value`,
    GREATEST(1, ROUND(d.`daily_visits` * v.`share`)),
    GREATEST(1, ROUND(d.`daily_visits` * v.`share` * 0.62))
FROM (
    SELECT s.`stat_date`,
           ROUND(
               (760 + (89 - s.`age`) * 6.2)
               * CASE DAYOFWEEK(s.`stat_date`)
                   WHEN 1 THEN 0.66 WHEN 7 THEN 0.73 WHEN 6 THEN 0.95
                   WHEN 2 THEN 1.09 WHEN 4 THEN 1.06 ELSE 1.02 END
               -- same festive curve as analytics_daily, so the two agree if the
               -- 90-day window happens to cover October
               * (1 + 0.85 * (GREATEST(0, 35 - LEAST(35,
                    ABS(DATEDIFF(s.`stat_date`, CONCAT(YEAR(s.`stat_date`), '-10-25'))))) / 35))
               * (1 + (CAST((s.`age` * 37) % 23 AS SIGNED) - 11) / 110)
           ) AS `daily_visits`
    FROM (
        SELECT DATE(DATE_SUB(NOW(), INTERVAL g.`n` DAY)) AS `stat_date`, g.`n` AS `age`
        FROM (
            SELECT (a.`n` + b.`n` * 10) AS `n`
            FROM (SELECT 0 AS `n` UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                  UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
                  UNION ALL SELECT 8 UNION ALL SELECT 9) a
            CROSS JOIN
                 (SELECT 0 AS `n` UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                  UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
                  UNION ALL SELECT 8) b
        ) g
        WHERE g.`n` <= 89
    ) s
) d
CROSS JOIN (
    -- device (3)
              SELECT 'device'   AS `dimension`, 'Mobile'            AS `value`, 0.780 AS `share`
    UNION ALL SELECT 'device',   'Desktop',            0.170
    UNION ALL SELECT 'device',   'Tablet',             0.050
    -- browser (6)
    UNION ALL SELECT 'browser',  'Chrome',             0.490
    UNION ALL SELECT 'browser',  'Safari',             0.240
    UNION ALL SELECT 'browser',  'Edge',               0.090
    UNION ALL SELECT 'browser',  'Samsung Internet',   0.080
    UNION ALL SELECT 'browser',  'Firefox',            0.050
    UNION ALL SELECT 'browser',  'Opera',              0.050
    -- os (5)
    UNION ALL SELECT 'os',       'Android',            0.580
    UNION ALL SELECT 'os',       'iOS',                0.240
    UNION ALL SELECT 'os',       'Windows',            0.130
    UNION ALL SELECT 'os',       'macOS',              0.040
    UNION ALL SELECT 'os',       'Linux',              0.010
    -- country (6)
    UNION ALL SELECT 'country',  'India',              0.880
    UNION ALL SELECT 'country',  'United Arab Emirates', 0.040
    UNION ALL SELECT 'country',  'United States',      0.030
    UNION ALL SELECT 'country',  'United Kingdom',     0.020
    UNION ALL SELECT 'country',  'Singapore',          0.020
    UNION ALL SELECT 'country',  'Canada',             0.010
    -- city (11)
    UNION ALL SELECT 'city',     'Mumbai',             0.190
    UNION ALL SELECT 'city',     'Delhi',              0.150
    UNION ALL SELECT 'city',     'Bengaluru',          0.140
    UNION ALL SELECT 'city',     'Hyderabad',          0.100
    UNION ALL SELECT 'city',     'Pune',               0.090
    UNION ALL SELECT 'city',     'Chennai',            0.080
    UNION ALL SELECT 'city',     'Kolkata',            0.070
    UNION ALL SELECT 'city',     'Ahmedabad',          0.060
    UNION ALL SELECT 'city',     'Jaipur',             0.050
    UNION ALL SELECT 'city',     'Lucknow',            0.040
    UNION ALL SELECT 'city',     'Indore',             0.030
    -- language (6)
    UNION ALL SELECT 'language', 'en-IN',              0.620
    UNION ALL SELECT 'language', 'hi-IN',              0.210
    UNION ALL SELECT 'language', 'mr-IN',              0.060
    UNION ALL SELECT 'language', 'ta-IN',              0.050
    UNION ALL SELECT 'language', 'te-IN',              0.040
    UNION ALL SELECT 'language', 'bn-IN',              0.020
    -- platform (4)
    UNION ALL SELECT 'platform', 'android',            0.550
    UNION ALL SELECT 'platform', 'ios',                0.230
    UNION ALL SELECT 'platform', 'web',                0.200
    UNION ALL SELECT 'platform', 'admin',              0.020
    -- source (8)
    UNION ALL SELECT 'source',   'direct',             0.260
    UNION ALL SELECT 'source',   'organic_search',     0.210
    UNION ALL SELECT 'source',   'instagram',          0.190
    UNION ALL SELECT 'source',   'whatsapp',           0.110
    UNION ALL SELECT 'source',   'google_ads',         0.090
    UNION ALL SELECT 'source',   'meta_ads',           0.060
    UNION ALL SELECT 'source',   'referral',           0.050
    UNION ALL SELECT 'source',   'email',              0.030
) v
-- The trailing WHERE is required: without it MariaDB parses the `ON` of
-- `ON DUPLICATE KEY UPDATE` as the join condition of the CROSS JOIN above.
WHERE v.`share` > 0
ON DUPLICATE KEY UPDATE
    `visits`       = VALUES(`visits`),
    `unique_users` = VALUES(`unique_users`);


-- =====================================================================================
-- 3. user_devices — ids 101-160
-- =====================================================================================
-- 60 registered devices: 21 across the 20 brand users (1001-1020, user 1001 has both
-- a phone and a browser) and 39 across creator users 2001-2039.
-- 36 android / 17 ios / 7 web (a browser "device" has no FCM handset token but does
-- carry a web-push registration id). Handset models are the phones that actually sell
-- in India. push_enabled is 0 for the handful of users who muted notifications, and
-- those rows also carry an older app_version and a stale last_active_at.
-- ids 1-30 in this table belong to the integration-test fixtures and are left alone.

INSERT INTO `user_devices`
  (`id`, `user_id`, `device_id`, `fcm_token`, `platform`, `device_model`, `os_version`,
   `app_version`, `locale`, `push_enabled`, `last_active_at`, `created_at`)
VALUES
  (101, 1001, 'zd-brand-1001-a71f', 'fMx7Qa2LTAy1:APA91bH8kZustee1001aQwErTyUiOpAsDfGhJkLzXcVbNm0192837465',  'android', 'Samsung Galaxy S24',        'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 388 DAY)),
  (102, 1001, 'zd-brand-1001-web',  'wPk3Ns9RVBz4:APA91bWebZustee1001nMbVcXzAsDfGhJkLpOiUyTrEwQ7564839201', 'web',     'Chrome on Windows',         'Windows 11', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 370 DAY)),
  (103, 1002, 'zd-brand-1002-c93b', 'kLp8Vd4XQCe6:APA91bT2rZustee1002zXcVbNmAsDfGhJkLqWeRtYuIoP4738291056', 'ios',     'iPhone 15 Pro',             'iOS 17.5',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 3 DAY),   DATE_SUB(NOW(), INTERVAL 362 DAY)),
  (104, 1003, 'zd-brand-1003-e55d', 'nRt5Yh1JKDf9:APA91bU9wZustee1003mNbVcXzLkJhGfDsAqWeRtYuIoP8264910375', 'android', 'OnePlus 12R',               'Android 14', '2.4.0',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 5 DAY),   DATE_SUB(NOW(), INTERVAL 344 DAY)),
  (105, 1004, 'zd-brand-1004-h20a', 'bGh2Uj6MNEg3:APA91bK4tZustee1004qAzWsXeDcRfVtGbYhNuJmIkOlP1029384756', 'ios',     'iPhone 14',                 'iOS 17.4',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 331 DAY)),
  (106, 1005, 'zd-brand-1005-j17c', 'cVb9Ik3OPFh5:APA91bL6yZustee1005plOkNiJbHuVgYcFtXdRsEzAwQ5647382910', 'android', 'Xiaomi Redmi Note 13 Pro+', 'Android 14', '2.4.1',  'hi-IN', 1, DATE_SUB(NOW(), INTERVAL 4 DAY),   DATE_SUB(NOW(), INTERVAL 318 DAY)),
  (107, 1006, 'zd-brand-1006-m84e', 'dNm4Ol7QRGj8:APA91bM1uZustee1006okMjNhBgVfCdXsZaQwErTyUiOp9182736450', 'web',     'Chrome on macOS',           'macOS 14.5', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 7 DAY),   DATE_SUB(NOW(), INTERVAL 306 DAY)),
  (108, 1007, 'zd-brand-1007-p62f', 'eBv6Pl2STHk1:APA91bN3iZustee1007ijNhYgTfRdEsWaQzXcVbNmKlOp3746192805', 'android', 'Google Pixel 8a',           'Android 15', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 292 DAY)),
  (109, 1008, 'zd-brand-1008-r39g', 'fCx8Qm5TUIl4:APA91bP7oZustee1008uhBgVfCdXsZaQwErTyUiOpLkJh1938475620', 'ios',     'iPhone 13',                 'iOS 16.7',   '2.3.6',  'en-IN', 0, DATE_SUB(NOW(), INTERVAL 26 DAY),  DATE_SUB(NOW(), INTERVAL 281 DAY)),
  (110, 1009, 'zd-brand-1009-t08h', 'gZq1Rn8UVJm7:APA91bQ5aZustee1009ygVfCdXsZaQwErTyUiOpLkJhNm2938475610', 'android', 'vivo V40 Pro',              'Android 14', '2.4.0',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 6 DAY),   DATE_SUB(NOW(), INTERVAL 268 DAY)),
  (111, 1010, 'zd-brand-1010-v55i', 'hWe3Sp1WXKn9:APA91bR2sZustee1010tfCdXsZaQwErTyUiOpLkJhNmBv8475639201', 'android', 'Samsung Galaxy A55',        'Android 14', '2.4.1',  'mr-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 254 DAY)),
  (112, 1011, 'zd-brand-1011-x71j', 'iQr5Tq4XYLo2:APA91bS8dZustee1011reXsZaQwErTyUiOpLkJhNmBvCx1938475620', 'ios',     'iPhone 15',                 'iOS 17.5',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 3 DAY),   DATE_SUB(NOW(), INTERVAL 241 DAY)),
  (113, 1012, 'zd-brand-1012-z26k', 'jTy7Ur6YZMp5:APA91bV4fZustee1012wdZaQwErTyUiOpLkJhNmBvCxDs5610293847', 'web',     'Edge on Windows',           'Windows 11', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 9 DAY),   DATE_SUB(NOW(), INTERVAL 229 DAY)),
  (114, 1013, 'zd-brand-1013-b93l', 'kUi9Vs8ZANq7:APA91bW6gZustee1013qsAaQwErTyUiOpLkJhNmBvCxDsEf2847561093', 'android', 'realme GT 6',               'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 214 DAY)),
  (115, 1014, 'zd-brand-1014-d41m', 'lYo2Wt1BCOr9:APA91bX9hZustee1014zaQwErTyUiOpLkJhNmBvCxDsEfGh7561093284', 'android', 'Motorola Edge 50 Pro',      'Android 14', '2.4.0',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 11 DAY),  DATE_SUB(NOW(), INTERVAL 198 DAY)),
  (116, 1015, 'zd-brand-1015-f77n', 'mIp4Xu3CDPs1:APA91bY1jZustee1015xsWeRtYuIoPaSdFgHjKlZxCvBn0938475612', 'ios',     'iPhone 15 Pro Max',         'iOS 17.5',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 183 DAY)),
  (117, 1016, 'zd-brand-1016-h12o', 'nOq6Yv5DEQt3:APA91bZ3kZustee1016cdRfTgYhUjIkOlPaSdFgHjKlZx4756102938', 'android', 'iQOO Neo 9 Pro',            'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 4 DAY),   DATE_SUB(NOW(), INTERVAL 167 DAY)),
  (118, 1017, 'zd-brand-1017-k58p', 'oAr8Zw7EFRu5:APA91bA5lZustee1017vfTgYhUjIkOlPaSdFgHjKlZxCv6102938475', 'android', 'Nothing Phone (2a)',        'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 8 DAY),   DATE_SUB(NOW(), INTERVAL 152 DAY)),
  (119, 1018, 'zd-brand-1018-n34q', 'pBs1Ax9GHSv7:APA91bB7mZustee1018bgYhUjIkOlPaSdFgHjKlZxCvBn9384756102', 'web',     'Safari on macOS',           'macOS 15.0', '2.4.1',  'en-IN', 0, DATE_SUB(NOW(), INTERVAL 19 DAY),  DATE_SUB(NOW(), INTERVAL 138 DAY)),
  (120, 1019, 'zd-brand-1019-q90r', 'qCt3By2HITw9:APA91bC9nZustee1019nhUjIkOlPaSdFgHjKlZxCvBnMq3847561029', 'ios',     'iPhone 16',                 'iOS 18.0',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 121 DAY)),
  (121, 1020, 'zd-brand-1020-s46s', 'rDu5Cz4IJUx2:APA91bD2pZustee1020mjIkOlPaSdFgHjKlZxCvBnMqWe1029384756', 'android', 'Samsung Galaxy S23 FE',     'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 3 DAY),   DATE_SUB(NOW(), INTERVAL 104 DAY)),

  (122, 2001, 'zd-creator-2001-a11', 'sEv7Da6JKVy4:APA91bE4qZustee2001kOlPaSdFgHjKlZxCvBnMqWeRt8475610293', 'android', 'Xiaomi Redmi Note 13',      'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 396 DAY)),
  (123, 2002, 'zd-creator-2002-b22', 'tFw9Eb8KLWz6:APA91bF6rZustee2002lPaSdFgHjKlZxCvBnMqWeRtYu5610293847', 'ios',     'iPhone 13 mini',            'iOS 17.3',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 389 DAY)),
  (124, 2003, 'zd-creator-2003-c33', 'uGx2Fc1LMXa8:APA91bG8sZustee2003paSdFgHjKlZxCvBnMqWeRtYuIo2938475610', 'android', 'Samsung Galaxy M35 5G',     'Android 14', '2.4.1',  'hi-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 381 DAY)),
  (125, 2004, 'zd-creator-2004-d44', 'vHy4Gd3MNYb1:APA91bH1tZustee2004asDfGhJkLzXcVbNmQwErTyUi7561029384', 'android', 'realme narzo 70 Pro',       'Android 14', '2.4.0',  'hi-IN', 1, DATE_SUB(NOW(), INTERVAL 6 DAY),   DATE_SUB(NOW(), INTERVAL 374 DAY)),
  (126, 2005, 'zd-creator-2005-e55', 'wIz6He5NOZc3:APA91bI3uZustee2005sdFgHjKlZxCvBnMqWeRtYuIoPa0293847561', 'ios',     'iPhone 15',                 'iOS 17.5',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 366 DAY)),
  (127, 2006, 'zd-creator-2006-f66', 'xJa8If7OPAd5:APA91bJ5vZustee2006dfGhJkLzXcVbNmQwErTyUiOpAs4756102938', 'android', 'OnePlus Nord CE 4',         'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 358 DAY)),
  (128, 2007, 'zd-creator-2007-g77', 'yKb1Jg9PQBe7:APA91bK7wZustee2007fgHjKlZxCvBnMqWeRtYuIoPaSd6102938475', 'android', 'vivo T3 5G',                'Android 14', '2.4.1',  'mr-IN', 1, DATE_SUB(NOW(), INTERVAL 4 DAY),   DATE_SUB(NOW(), INTERVAL 349 DAY)),
  (129, 2008, 'zd-creator-2008-h88', 'zLc3Kh2QRCf9:APA91bL9xZustee2008ghJkLzXcVbNmQwErTyUiOpAsDf9384756102', 'ios',     'iPhone 14 Pro',             'iOS 17.5',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 341 DAY)),
  (130, 2009, 'zd-creator-2009-i99', 'aMd5Li4RSDg2:APA91bM2yZustee2009hjKlZxCvBnMqWeRtYuIoPaSdFg3847561029', 'android', 'Google Pixel 7a',           'Android 15', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 3 DAY),   DATE_SUB(NOW(), INTERVAL 333 DAY)),
  (131, 2010, 'zd-creator-2010-j10', 'bNe7Mj6STEh4:APA91bN4zZustee2010jkLzXcVbNmQwErTyUiOpAsDfGh1029384756', 'web',     'Chrome on Windows',         'Windows 11', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 12 DAY),  DATE_SUB(NOW(), INTERVAL 325 DAY)),
  (132, 2011, 'zd-creator-2011-k11', 'cOf9Nk8TUFi6:APA91bO6aZustee2011klZxCvBnMqWeRtYuIoPaSdFgHj8475610293', 'android', 'Samsung Galaxy S24 Ultra',  'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 316 DAY)),
  (133, 2012, 'zd-creator-2012-l12', 'dPg2Ol1UVGj8:APA91bP8bZustee2012lzXcVbNmQwErTyUiOpAsDfGhJk5610293847', 'android', 'Xiaomi 14 Civi',            'Android 14', '2.4.1',  'ta-IN', 1, DATE_SUB(NOW(), INTERVAL 5 DAY),   DATE_SUB(NOW(), INTERVAL 308 DAY)),
  (134, 2013, 'zd-creator-2013-m13', 'eQh4Pm3VWHk1:APA91bQ1cZustee2013zxCvBnMqWeRtYuIoPaSdFgHjKl2938475610', 'ios',     'iPhone 15 Pro',             'iOS 18.0',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 299 DAY)),
  (135, 2014, 'zd-creator-2014-n14', 'fRi6Qn5WXIl3:APA91bR3dZustee2014xcVbNmQwErTyUiOpAsDfGhJkLz7561029384', 'android', 'POCO X6 Pro',               'Android 14', '2.4.0',  'hi-IN', 1, DATE_SUB(NOW(), INTERVAL 9 DAY),   DATE_SUB(NOW(), INTERVAL 291 DAY)),
  (136, 2015, 'zd-creator-2015-o15', 'gSj8Ro7XYJm5:APA91bS5eZustee2015cvBnMqWeRtYuIoPaSdFgHjKlZx0293847561', 'android', 'Samsung Galaxy A35 5G',     'Android 14', '2.4.1',  'te-IN', 1, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 282 DAY)),
  (137, 2016, 'zd-creator-2016-p16', 'hTk1Sp9YZKn7:APA91bT7fZustee2016vbNmQwErTyUiOpAsDfGhJkLzXc4756102938', 'ios',     'iPhone 12',                 'iOS 16.6',   '2.3.6',  'en-IN', 0, DATE_SUB(NOW(), INTERVAL 41 DAY),  DATE_SUB(NOW(), INTERVAL 274 DAY)),
  (138, 2017, 'zd-creator-2017-q17', 'iUl3Tq2ZANo9:APA91bU9gZustee2017bnMqWeRtYuIoPaSdFgHjKlZxCv6102938475', 'android', 'OnePlus 12',                'Android 15', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 265 DAY)),
  (139, 2018, 'zd-creator-2018-r18', 'jVm5Ur4ABOp2:APA91bV2hZustee2018nmQwErTyUiOpAsDfGhJkLzXcVb9384756102', 'android', 'Motorola Edge 50 Fusion',   'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 7 DAY),   DATE_SUB(NOW(), INTERVAL 257 DAY)),
  (140, 2019, 'zd-creator-2019-s19', 'kWn7Vs6BCPq4:APA91bW4iZustee2019mqWeRtYuIoPaSdFgHjKlZxCvBn3847561029', 'web',     'Chrome on Android',         'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 15 DAY),  DATE_SUB(NOW(), INTERVAL 248 DAY)),
  (141, 2020, 'zd-creator-2020-t20', 'lXo9Wt8CDQr6:APA91bX6jZustee2020qwErTyUiOpAsDfGhJkLzXcVbNm1029384756', 'android', 'iQOO Z9s',                  'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 240 DAY)),
  (142, 2021, 'zd-creator-2021-u21', 'mYp2Xu1DERs8:APA91bY8kZustee2021weRtYuIoPaSdFgHjKlZxCvBnMq8475610293', 'ios',     'iPhone 14',                 'iOS 17.4',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 231 DAY)),
  (143, 2022, 'zd-creator-2022-v22', 'nZq4Yv3EFSt1:APA91bZ1lZustee2022erTyUiOpAsDfGhJkLzXcVbNmQw5610293847', 'android', 'Samsung Galaxy F55',        'Android 14', '2.4.0',  'bn-IN', 1, DATE_SUB(NOW(), INTERVAL 10 DAY),  DATE_SUB(NOW(), INTERVAL 223 DAY)),
  (144, 2023, 'zd-creator-2023-w23', 'oAr6Zw5FGTu3:APA91bA3mZustee2023rtYuIoPaSdFgHjKlZxCvBnMqWe2938475610', 'android', 'realme 12 Pro+',            'Android 14', '2.4.1',  'hi-IN', 1, DATE_SUB(NOW(), INTERVAL 3 DAY),   DATE_SUB(NOW(), INTERVAL 214 DAY)),
  (145, 2024, 'zd-creator-2024-x24', 'pBs8Ax7GHUv5:APA91bB5nZustee2024tyUiOpAsDfGhJkLzXcVbNmQwEr7561029384', 'ios',     'iPhone 15 Plus',            'iOS 17.5',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 206 DAY)),
  (146, 2025, 'zd-creator-2025-y25', 'qCt1By9HIVw7:APA91bC7oZustee2025yuIoPaSdFgHjKlZxCvBnMqWeRt0293847561', 'android', 'Nothing Phone (2)',         'Android 15', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 4 DAY),   DATE_SUB(NOW(), INTERVAL 197 DAY)),
  (147, 2026, 'zd-creator-2026-z26', 'rDu3Cz2IJWx9:APA91bD9pZustee2026uiOpAsDfGhJkLzXcVbNmQwErTy4756102938', 'android', 'Xiaomi Redmi 13 5G',        'Android 14', '2.4.1',  'ta-IN', 1, DATE_SUB(NOW(), INTERVAL 6 DAY),   DATE_SUB(NOW(), INTERVAL 189 DAY)),
  (148, 2027, 'zd-creator-2027-a27', 'sEv5Da4JKXy2:APA91bE2qZustee2027ioPaSdFgHjKlZxCvBnMqWeRtYu6102938475', 'ios',     'iPhone 16 Pro',             'iOS 18.1',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 180 DAY)),
  (149, 2028, 'zd-creator-2028-b28', 'tFw7Eb6KLYz4:APA91bF4rZustee2028opAsDfGhJkLzXcVbNmQwErTyUi9384756102', 'android', 'vivo Y200 Pro',             'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 172 DAY)),
  (150, 2029, 'zd-creator-2029-c29', 'uGx9Fc8LMZa6:APA91bG6sZustee2029pasDfGhJkLzXcVbNmQwErTyUiOp3847561029', 'web',     'Firefox on Windows',        'Windows 10', '2.4.1',  'en-IN', 0, DATE_SUB(NOW(), INTERVAL 33 DAY),  DATE_SUB(NOW(), INTERVAL 163 DAY)),
  (151, 2030, 'zd-creator-2030-d30', 'vHy2Gd1MNAb8:APA91bH8tZustee2030asdFgHjKlZxCvBnMqWeRtYuIoPa1029384756', 'android', 'Samsung Galaxy S23',        'Android 14', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 155 DAY)),
  (152, 2031, 'zd-creator-2031-e31', 'wIz4He3NOBc1:APA91bI1uZustee2031sdfGhJkLzXcVbNmQwErTyUiOpAs8475610293', 'android', 'OPPO Reno 12 Pro',          'Android 14', '2.4.1',  'mr-IN', 1, DATE_SUB(NOW(), INTERVAL 5 DAY),   DATE_SUB(NOW(), INTERVAL 146 DAY)),
  (153, 2032, 'zd-creator-2032-f32', 'xJa6If5OPCd3:APA91bJ3vZustee2032dfgHjKlZxCvBnMqWeRtYuIoPaSd5610293847', 'ios',     'iPhone 13',                 'iOS 17.2',   '2.4.0',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 8 DAY),   DATE_SUB(NOW(), INTERVAL 138 DAY)),
  (154, 2033, 'zd-creator-2033-g33', 'yKb8Jg7PQDe5:APA91bK5wZustee2033fghJkLzXcVbNmQwErTyUiOpAsDfGh2938475610', 'android', 'Lava Agni 3',               'Android 14', '2.4.1',  'hi-IN', 1, DATE_SUB(NOW(), INTERVAL 3 DAY),   DATE_SUB(NOW(), INTERVAL 129 DAY)),
  (155, 2034, 'zd-creator-2034-h34', 'zLc1Kh9QREf7:APA91bL7xZustee2034ghjKlZxCvBnMqWeRtYuIoPaSdFg7561029384', 'android', 'POCO M6 Pro',               'Android 14', '2.4.1',  'te-IN', 1, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 121 DAY)),
  (156, 2035, 'zd-creator-2035-i35', 'aMd3Li2RSFg9:APA91bM9yZustee2035hjkLzXcVbNmQwErTyUiOpAsDfGhJk0293847561', 'ios',     'iPhone 15 Pro',             'iOS 18.0',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 112 DAY)),
  (157, 2036, 'zd-creator-2036-j36', 'bNe5Mj4STGh2:APA91bN2zZustee2036jklZxCvBnMqWeRtYuIoPaSdFgHjKl4756102938', 'android', 'Samsung Galaxy A16 5G',     'Android 15', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 4 DAY),   DATE_SUB(NOW(), INTERVAL 96 DAY)),
  (158, 2037, 'zd-creator-2037-k37', 'cOf7Nk6TUHi4:APA91bO4aZustee2037klzXcVbNmQwErTyUiOpAsDfGhJkLz6102938475', 'android', 'Xiaomi Redmi Note 14 Pro',  'Android 15', '2.4.1',  'hi-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 74 DAY)),
  (159, 2038, 'zd-creator-2038-l38', 'dPg9Ol8UVIj6:APA91bP6bZustee2038lzxCvBnMqWeRtYuIoPaSdFgHjKlZx9384756102', 'ios',     'iPhone 16 Plus',            'iOS 18.1',   '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 2 DAY),   DATE_SUB(NOW(), INTERVAL 51 DAY)),
  (160, 2039, 'zd-creator-2039-m39', 'eQh2Pm1VWJk8:APA91bQ8cZustee2039zxcVbNmQwErTyUiOpAsDfGhJkLzXc3847561029', 'android', 'OnePlus Nord 4',            'Android 15', '2.4.1',  'en-IN', 1, DATE_SUB(NOW(), INTERVAL 1 DAY),   DATE_SUB(NOW(), INTERVAL 28 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);


-- =====================================================================================
-- 4. marketing_campaigns — ids 401-412
-- =====================================================================================
-- Platform-side blasts (ids 301-330 are the per-brand creator-invite blasts that
-- 022_demo_campaigns owns). Delivery maths holds on every finished send:
--   sent_count + failed_count = recipients_count
--   opened_count <= sent_count, clicked_count <= opened_count,
--   unsubscribed_count <= opened_count
-- The unfinished rows deliberately break the first identity, because that is what a
-- half-delivered blast looks like: 410 is 'sending' (6 of 9 out so far) and 411 is
-- 'scheduled' (47 queued, 0 sent). 412 is a 'draft' with zero everything.
-- Audience sizes track the seeded base (22 brands, 47 creators, 69 total).

INSERT INTO `marketing_campaigns`
  (`id`, `name`, `channel`, `subject`, `preheader`, `content`, `audience`, `audience_filter`,
   `status`, `scheduled_at`, `started_at`, `completed_at`,
   `recipients_count`, `sent_count`, `failed_count`, `opened_count`, `clicked_count`,
   `unsubscribed_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (401,
   'Festive Season 2025 - Brand Booking Push',
   'email',
   'Diwali briefs close in 10 days - lock your creators now',
   'Festive inventory moves fast. Nano and micro creators in Mumbai and Delhi are already 60% booked.',
   '<h2>Diwali is 3 weeks away. Your creators are not.</h2><p>Hi {{name}},</p><p>Every October we watch the same thing happen: brands finalise their festive brief in the last week and then fight over the same twelve creators. Reels booked in the first week of October went live at an average of 41% lower cost than the ones booked after Dussehra.</p><p>We have opened festive availability for 145 approved creators across Fashion, Beauty, Food and Home. Filter by city, follower tier and turnaround time, send an offer, and the creator has 48 hours to accept.</p><p><a href="https://zustee.com/brand/creators?festive=1">Browse festive-ready creators</a></p><p>Payments stay in escrow until you approve the deliverable. GST invoice is generated automatically.</p><p>— Team Zusty</p>',
   'brands', NULL,
   'sent',
   DATE_SUB(NOW(), INTERVAL 297 DAY), DATE_SUB(NOW(), INTERVAL 297 DAY), DATE_SUB(NOW(), INTERVAL 297 DAY),
   22, 22, 0, 17, 9, 0, 1,
   DATE_SUB(NOW(), INTERVAL 300 DAY), DATE_SUB(NOW(), INTERVAL 297 DAY)),

  (402,
   'Creator Onboarding - Complete Your KYC',
   'email',
   'Finish your verification to take bookings',
   'An approved verification lets you accept bookings and withdraw your earnings.',
   '<h2>Finish your verification</h2><p>Hi {{name}},</p><p>Your Zusty profile is live, but you need an approved verification before you can accept bookings or withdraw your earnings. In the app, open Profile → Verification and add:</p><ul><li>Your PAN</li><li>A masked copy of your Aadhaar (only the last four digits visible)</li></ul><p>A person on our team reviews every submission, and the blue tick appears only after that approval.</p><p>— Team Zusty</p>',
   'creators', NULL,
   'sent',
   DATE_SUB(NOW(), INTERVAL 243 DAY), DATE_SUB(NOW(), INTERVAL 243 DAY), DATE_SUB(NOW(), INTERVAL 243 DAY),
   47, 45, 2, 31, 19, 1, 1,
   DATE_SUB(NOW(), INTERVAL 245 DAY), DATE_SUB(NOW(), INTERVAL 243 DAY)),

  (403,
   'New Year Creator Rate Card Refresh',
   'email',
   'Update your 2026 rate card before brands start budgeting',
   'Brands lock annual influencer budgets in January. Stale pricing costs you briefs.',
   '<h2>Refresh your rate card for 2026</h2><p>Hi {{name}},</p><p>January is when brand teams finalise the year''s influencer budget. If your packages still carry last year''s pricing, you are either leaving money on the table or pricing yourself out.</p><p>Median rates on Zusty moved this way over the last twelve months: Instagram Reels +18%, Stories bundle +11%, YouTube integration +24%.</p><p><a href="https://zustee.com/creator/packages">Update your packages</a></p><p>Reminder: the platform commission is 15% and is deducted from your payout, so quote the number you want brands to see.</p><p>— Team Zusty</p>',
   'verified_creators', '{"kyc_status":"verified","min_rating":4.0}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 205 DAY), DATE_SUB(NOW(), INTERVAL 205 DAY), DATE_SUB(NOW(), INTERVAL 205 DAY),
   34, 34, 0, 26, 14, 0, 1,
   DATE_SUB(NOW(), INTERVAL 208 DAY), DATE_SUB(NOW(), INTERVAL 205 DAY)),

  (404,
   'Payout Delay Notice - Bank Holiday Batch',
   'sms',
   NULL, NULL,
   'Zusty: Payout batches on 14-15 Mar are delayed by RBI bank holidays. Settlements credit by 17 Mar. Track status in the app under Wallet > Withdrawals.',
   'creators', NULL,
   'sent',
   DATE_SUB(NOW(), INTERVAL 142 DAY), DATE_SUB(NOW(), INTERVAL 142 DAY), DATE_SUB(NOW(), INTERVAL 142 DAY),
   47, 46, 1, 46, 12, 0, 1,
   DATE_SUB(NOW(), INTERVAL 143 DAY), DATE_SUB(NOW(), INTERVAL 142 DAY)),

  (405,
   'Brand Re-engagement - Dormant 60 Days',
   'email',
   'Your last campaign delivered 3.2x. Here is what changed since.',
   'New: multi-creator briefs, side-by-side comparison and consolidated GST invoicing.',
   '<h2>It has been a while</h2><p>Hi {{name}},</p><p>Your last brief on Zusty closed two months ago. Three things shipped since then that are worth two minutes of your time:</p><ul><li><strong>Multi-creator briefs</strong> — post one brief, receive applications, shortlist five, book three.</li><li><strong>Side-by-side comparison</strong> — engagement rate, past delivery record and price band on one screen.</li><li><strong>Consolidated GST invoicing</strong> — one invoice per month per brand instead of one per booking.</li></ul><p><a href="https://zustee.com/brand/campaigns/create">Post a brief</a></p><p>— Team Zusty</p>',
   'custom', '{"account_type":"brand","last_booking_before_days":60}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 118 DAY), DATE_SUB(NOW(), INTERVAL 118 DAY), DATE_SUB(NOW(), INTERVAL 118 DAY),
   14, 13, 1, 8, 4, 1, 1,
   DATE_SUB(NOW(), INTERVAL 120 DAY), DATE_SUB(NOW(), INTERVAL 118 DAY)),

  (406,
   'IPL 2026 Sports and Snacking Brief Alert',
   'whatsapp',
   NULL, NULL,
   'Zusty | 9 sports and snacking brands are hiring creators for IPL week 1. Reels, watch-party stories and match-day UGC. Budgets INR 8,000 - INR 1,20,000. Apply before Friday: https://zustee.com/creator/briefs',
   'creators', '{"categories":["fitness","food","lifestyle"]}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 96 DAY), DATE_SUB(NOW(), INTERVAL 96 DAY), DATE_SUB(NOW(), INTERVAL 96 DAY),
   31, 30, 1, 27, 18, 0, 1,
   DATE_SUB(NOW(), INTERVAL 97 DAY), DATE_SUB(NOW(), INTERVAL 96 DAY)),

  (407,
   'Monsoon Beauty Edit - Creator Call',
   'email',
   'Skincare brands are booking monsoon creators this week',
   'Six beauty brands, 40+ open slots, average ticket INR 32,000.',
   '<h2>Monsoon beauty briefs are live</h2><p>Hi {{name}},</p><p>Six skincare and haircare brands opened monsoon briefs on Zusty this week. They are looking for honest routine reels, humidity-proof makeup demos and 7-day-use reviews.</p><p>Average agreed fee on similar briefs last season: INR 32,000. Escrow is funded before you start shooting.</p><p><a href="https://zustee.com/creator/briefs?category=beauty">See open beauty briefs</a></p><p>— Team Zusty</p>',
   'creators', '{"categories":["beauty"]}',
   'sent',
   DATE_SUB(NOW(), INTERVAL 61 DAY), DATE_SUB(NOW(), INTERVAL 61 DAY), DATE_SUB(NOW(), INTERVAL 61 DAY),
   26, 26, 0, 20, 11, 0, 1,
   DATE_SUB(NOW(), INTERVAL 63 DAY), DATE_SUB(NOW(), INTERVAL 61 DAY)),

  (408,
   'App v2.4 Release - What Is New',
   'push',
   NULL, NULL,
   'Zusty 2.4 is here: in-chat offers, deliverable revisions with timestamps, and UPI payouts under two minutes. Update from the Play Store or App Store.',
   'all', NULL,
   'sent',
   DATE_SUB(NOW(), INTERVAL 44 DAY), DATE_SUB(NOW(), INTERVAL 44 DAY), DATE_SUB(NOW(), INTERVAL 44 DAY),
   60, 57, 3, 41, 22, 0, 1,
   DATE_SUB(NOW(), INTERVAL 45 DAY), DATE_SUB(NOW(), INTERVAL 44 DAY)),

  (409,
   'Newsletter - Creator Economy Report H1 2026',
   'email',
   'What 480 bookings taught us about Indian influencer pricing',
   'Median rates by city, category and follower tier. Free download, no form.',
   '<h2>The Zusty H1 2026 pricing report</h2><p>Hi {{name}},</p><p>We looked at every booking that completed on Zusty in the first half of 2026 and pulled out the numbers brands and creators keep asking us for:</p><ul><li>Median Reel rate by follower tier and city</li><li>Which categories negotiate hardest (Food, then Tech)</li><li>Average time from offer to acceptance: 31 hours</li><li>Completion rate by brief clarity score</li></ul><p><a href="https://zustee.com/reports/creator-economy-h1-2026">Read the report</a> — no email gate, it is a public page.</p><p>— Team Zusty</p>',
   'subscribers', NULL,
   'sent',
   DATE_SUB(NOW(), INTERVAL 27 DAY), DATE_SUB(NOW(), INTERVAL 27 DAY), DATE_SUB(NOW(), INTERVAL 27 DAY),
   58, 55, 3, 38, 21, 2, 1,
   DATE_SUB(NOW(), INTERVAL 29 DAY), DATE_SUB(NOW(), INTERVAL 27 DAY)),

  (410,
   'Abandoned Checkout Recovery - Brands',
   'email',
   'Your booking is still waiting for payment',
   'The creator has accepted. Escrow funding closes the loop.',
   '<h2>One step left on your booking</h2><p>Hi {{name}},</p><p>The creator accepted your offer, but the booking is still in <strong>Awaiting payment</strong>. Work does not start until escrow is funded, and the acceptance lapses after 72 hours.</p><p>You can pay by UPI, card or netbanking. The amount sits in escrow and is released to the creator only after you approve the deliverable.</p><p><a href="https://zustee.com/brand/bookings">Fund the booking</a></p><p>— Team Zusty</p>',
   'custom', '{"account_type":"brand","booking_status":"awaiting_payment"}',
   'sending',
   DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY), NULL,
   9, 6, 0, 3, 1, 0, 1,
   DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),

  (411,
   'Independence Day Creator Campaign Blast',
   'email',
   'Har Ghar Tiranga briefs open Monday',
   'Patriotic content briefs from 11 D2C brands. Shortlisting starts 8 Aug.',
   '<h2>Independence Day briefs open Monday</h2><p>Hi {{name}},</p><p>Eleven D2C brands are running Har Ghar Tiranga campaigns this year and want creators booked before the 8th. Formats: Reels, Stories, and one long-form YouTube integration per brand.</p><p>Applications open Monday 9 AM IST. Verified creators get a 24-hour head start.</p><p><a href="https://zustee.com/creator/briefs">Set a reminder</a></p><p>— Team Zusty</p>',
   'creators', NULL,
   'scheduled',
   DATE_ADD(NOW(), INTERVAL 4 DAY), NULL, NULL,
   47, 0, 0, 0, 0, 0, 1,
   DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),

  (412,
   'Q3 Brand NPS Survey',
   'email',
   'Two questions about your last booking',
   'Thirty seconds. It decides what we build next quarter.',
   '<h2>How did your last booking go?</h2><p>Hi {{name}},</p><p>Two questions, thirty seconds, and it genuinely decides the next quarter''s roadmap.</p><ol><li>How likely are you to recommend Zusty to another brand? (0-10)</li><li>What is the single thing we should fix first?</li></ol><p><a href="https://zustee.com/survey/brand-nps-q3">Answer the survey</a></p><p>— Team Zusty</p>',
   'brands', NULL,
   'draft',
   NULL, NULL, NULL,
   0, 0, 0, 0, 0, 0, 1,
   DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);


-- =====================================================================================
-- 5. notification_broadcasts — ids 1-8
-- =====================================================================================
-- `channels` is a JSON array constrained to in_app / push / email (the set
-- EngagementController::broadcast() intersects against). `action_type` values are
-- taken from the app-side allow-list: none, booking, creator, chat, wallet, url, screen.
-- delivered_count <= recipients_count always.

INSERT INTO `notification_broadcasts`
  (`id`, `title`, `body`, `image_url`, `audience`, `audience_filter`, `channels`,
   `action_type`, `action_value`, `status`, `scheduled_at`, `sent_at`,
   `recipients_count`, `delivered_count`, `created_by`, `created_at`, `updated_at`)
VALUES
  (1,
   'Diwali briefs are live',
   'Fourteen brands opened festive briefs today across Fashion, Beauty and Home. Budgets from INR 12,000 to INR 2,40,000. Applications close on Dussehra.',
   'https://picsum.photos/seed/zuste-diwali-briefs/1080/540',
   'creators', NULL, '["in_app","push"]',
   'screen', '/creator/briefs', 'sent',
   NULL, DATE_SUB(NOW(), INTERVAL 294 DAY), 47, 44, 1,
   DATE_SUB(NOW(), INTERVAL 294 DAY), DATE_SUB(NOW(), INTERVAL 294 DAY)),

  (2,
   'Escrow now releases in 24 hours',
   'Once you approve a deliverable, the creator payout leaves escrow within 24 hours instead of 72. No action needed on your side.',
   NULL,
   'all', NULL, '["in_app","push","email"]',
   'none', NULL, 'sent',
   NULL, DATE_SUB(NOW(), INTERVAL 221 DAY), 69, 66, 1,
   DATE_SUB(NOW(), INTERVAL 221 DAY), DATE_SUB(NOW(), INTERVAL 221 DAY)),

  (3,
   'Scheduled maintenance tonight',
   'Zusty will be read-only from 1:30 AM to 2:15 AM IST while we migrate the payments database. Bookings and chat stay visible; new payments will fail during the window.',
   NULL,
   'all', NULL, '["in_app","push"]',
   'none', NULL, 'sent',
   DATE_SUB(NOW(), INTERVAL 168 DAY), DATE_SUB(NOW(), INTERVAL 168 DAY), 69, 69, 1,
   DATE_SUB(NOW(), INTERVAL 169 DAY), DATE_SUB(NOW(), INTERVAL 168 DAY)),

  (4,
   'Your verification is approved',
   'Your verification is approved. You can now accept bookings and withdraw your earnings.',
   'https://picsum.photos/seed/zuste-verified-badge/1080/540',
   'verified_creators', '{"kyc_status":"verified"}', '["in_app","push","email"]',
   'creator', 'self', 'sent',
   NULL, DATE_SUB(NOW(), INTERVAL 133 DAY), 34, 33, 1,
   DATE_SUB(NOW(), INTERVAL 133 DAY), DATE_SUB(NOW(), INTERVAL 133 DAY)),

  (5,
   'UPI payouts are here',
   'Withdraw to any UPI ID and get credited in under two minutes on working days. Add your VPA under Wallet > Payout methods.',
   NULL,
   'creators', NULL, '["in_app","push"]',
   'wallet', NULL, 'sent',
   NULL, DATE_SUB(NOW(), INTERVAL 78 DAY), 47, 45, 1,
   DATE_SUB(NOW(), INTERVAL 78 DAY), DATE_SUB(NOW(), INTERVAL 78 DAY)),

  (6,
   'Monsoon Beauty Edit - 40 open slots',
   'Six skincare brands are booking creators for the monsoon edit. Average agreed fee INR 32,000. Escrow funded before you shoot.',
   'https://picsum.photos/seed/zuste-monsoon-beauty/1080/540',
   'creators', '{"categories":["beauty"]}', '["in_app","push","email"]',
   'screen', '/creator/briefs?category=beauty', 'sent',
   NULL, DATE_SUB(NOW(), INTERVAL 60 DAY), 26, 25, 1,
   DATE_SUB(NOW(), INTERVAL 60 DAY), DATE_SUB(NOW(), INTERVAL 60 DAY)),

  (7,
   'Rate your last collaboration',
   'You have two completed bookings without a review. Ratings decide search ranking for creators and trust scores for brands - it takes twenty seconds.',
   NULL,
   'all', NULL, '["in_app"]',
   'booking', NULL, 'sent',
   NULL, DATE_SUB(NOW(), INTERVAL 21 DAY), 69, 63, 1,
   DATE_SUB(NOW(), INTERVAL 21 DAY), DATE_SUB(NOW(), INTERVAL 21 DAY)),

  (8,
   'Independence Day briefs open Monday',
   'Eleven D2C brands are running Har Ghar Tiranga campaigns. Applications open Monday 9 AM IST, with a 24-hour head start for verified creators.',
   'https://picsum.photos/seed/zuste-independence-briefs/1080/540',
   'creators', NULL, '["in_app","push"]',
   'screen', '/creator/briefs', 'scheduled',
   DATE_ADD(NOW(), INTERVAL 4 DAY), NULL, 47, 0, 1,
   DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);


-- =====================================================================================
-- 6. admin_announcements — ids 1-6
-- =====================================================================================
-- WidgetService::announcements() shows rows where is_active = 1, the date window
-- covers NOW(), and role_id is NULL or matches the viewing admin's role. Admin 1 is
-- role_id 1 (Super Admin), so ids 1, 2, 4 and 6 render on its dashboard today.
-- Roles: 1 Super Admin, 2 Admin, 3 Manager, 4 Finance, 5 Moderator, 6 Content Manager, 7 Support.

INSERT INTO `admin_announcements`
  (`id`, `title`, `body`, `severity`, `role_id`, `starts_at`, `ends_at`, `is_active`,
   `created_by`, `created_at`)
VALUES
  (1,
   'Festive surge staffing - extended moderation hours',
   'Creator applications are running roughly 3x normal volume through the festive window. Moderation and KYC review shifts are extended to 10 PM IST on weekdays. Anything sitting in the queue past 18 hours gets escalated to the duty manager automatically.',
   'warning', NULL,
   DATE_SUB(NOW(), INTERVAL 9 DAY), DATE_ADD(NOW(), INTERVAL 21 DAY), 1, 1,
   DATE_SUB(NOW(), INTERVAL 9 DAY)),

  (2,
   'Cashfree is now the primary payment gateway',
   'All new booking payments route through Cashfree. Razorpay stays enabled only for settling orders created before the cutover - do not create new Razorpay orders manually. Webhook failures now surface under System > Integration logs instead of the old payments log.',
   'info', NULL,
   DATE_SUB(NOW(), INTERVAL 34 DAY), NULL, 1, 1,
   DATE_SUB(NOW(), INTERVAL 34 DAY)),

  (3,
   'Payout batch cutoff moved to 4 PM IST',
   'The daily payout batch now closes at 4 PM IST instead of 6 PM so NEFT clears the same working day. Withdrawal approvals after 4 PM roll into the next batch. Please clear the approval queue before lunch.',
   'info', 4,
   DATE_SUB(NOW(), INTERVAL 52 DAY), NULL, 1, 1,
   DATE_SUB(NOW(), INTERVAL 52 DAY)),

  (4,
   'Do not approve KYC without a live selfie match',
   'Three fraudulent creator accounts were caught last week using borrowed PAN cards. Every KYC approval now needs the selfie check to pass alongside the document. If the selfie is missing, move the case to Pending user - do not approve on documents alone.',
   'critical', NULL,
   DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(NOW(), INTERVAL 40 DAY), 1, 1,
   DATE_SUB(NOW(), INTERVAL 5 DAY)),

  (5,
   'Quarter closed - GST filings submitted',
   'All Q1 FY27 GST returns are filed and the platform earnings ledger is reconciled to the last rupee. Invoices 601 to 660 are locked and can no longer be edited. Raise a credit note instead if a correction is needed.',
   'success', NULL,
   DATE_SUB(NOW(), INTERVAL 96 DAY), DATE_SUB(NOW(), INTERVAL 66 DAY), 1, 1,
   DATE_SUB(NOW(), INTERVAL 96 DAY)),

  (6,
   'App v2.4 rollout starts Monday',
   'Version 2.4 ships to 10% of Android users on Monday and to everyone by Thursday. Watch the crash rate widget on the dashboard - anything above 0.4% and we halt the rollout. iOS follows one week later after review.',
   'info', NULL,
   DATE_ADD(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 1, 1,
   DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);


-- =====================================================================================
-- 7. admin_tasks — ids 1-12  (todo-list widget for admin 1)
-- =====================================================================================
-- Widget orders by is_done, sort_order, id DESC and takes 12. Open items carry the
-- low sort_order numbers so they surface first; completed ones keep completed_at set.

INSERT INTO `admin_tasks`
  (`id`, `admin_id`, `title`, `is_done`, `priority`, `due_on`, `completed_at`, `sort_order`, `created_at`)
VALUES
  (1,  1, 'Clear the KYC review queue before the festive intake opens',            0, 'high',   DATE_ADD(NOW(), INTERVAL 2 DAY),  NULL, 1,  DATE_SUB(NOW(), INTERVAL 6 DAY)),
  (2,  1, 'Approve pending creator withdrawals for this week''s payout batch',      0, 'high',   DATE(NOW()),                      NULL, 2,  DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (3,  1, 'Resolve the two disputed bookings flagged by the escrow report',        0, 'high',   DATE_ADD(NOW(), INTERVAL 1 DAY),  NULL, 3,  DATE_SUB(NOW(), INTERVAL 4 DAY)),
  (4,  1, 'Review the Independence Day broadcast copy before Monday 9 AM',         0, 'normal', DATE_ADD(NOW(), INTERVAL 3 DAY),  NULL, 4,  DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (5,  1, 'Refresh the homepage featured-creator shelf for the monsoon edit',      0, 'normal', DATE_ADD(NOW(), INTERVAL 5 DAY),  NULL, 5,  DATE_SUB(NOW(), INTERVAL 5 DAY)),
  (6,  1, 'Audit the 15% commission split on the three counter-offered bookings',  0, 'normal', DATE_ADD(NOW(), INTERVAL 7 DAY),  NULL, 6,  DATE_SUB(NOW(), INTERVAL 8 DAY)),
  (7,  1, 'Reply to the pending brand GST invoice queries in Support',             0, 'normal', DATE_ADD(NOW(), INTERVAL 4 DAY),  NULL, 7,  DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (8,  1, 'Draft the Q3 brand NPS survey email and hand it to Content',            0, 'low',    DATE_ADD(NOW(), INTERVAL 12 DAY), NULL, 8,  DATE_SUB(NOW(), INTERVAL 6 DAY)),
  (9,  1, 'Archive last season''s festive banners from the CMS',                   0, 'low',    DATE_ADD(NOW(), INTERVAL 18 DAY), NULL, 9,  DATE_SUB(NOW(), INTERVAL 10 DAY)),
  (10, 1, 'Verify the Cashfree webhook signature after the gateway cutover',       1, 'high',   DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 31 DAY), 10, DATE_SUB(NOW(), INTERVAL 36 DAY)),
  (11, 1, 'Publish the H1 2026 creator economy report page',                       1, 'normal', DATE_SUB(NOW(), INTERVAL 26 DAY), DATE_SUB(NOW(), INTERVAL 27 DAY), 11, DATE_SUB(NOW(), INTERVAL 33 DAY)),
  (12, 1, 'Reconcile the March settlement batch against platform earnings',        1, 'high',   DATE_SUB(NOW(), INTERVAL 62 DAY), DATE_SUB(NOW(), INTERVAL 62 DAY), 12, DATE_SUB(NOW(), INTERVAL 70 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);


-- =====================================================================================
-- 8. admin_notes — ids 1-6  (sticky-notes widget for admin 1)
-- =====================================================================================
-- Widget orders by is_pinned DESC, id DESC and takes 6. `color` is a 7-char hex.

INSERT INTO `admin_notes`
  (`id`, `admin_id`, `body`, `color`, `is_pinned`, `created_at`, `updated_at`)
VALUES
  (1, 1,
   'Escrow release SLA: 24h after brand approval. If a payout is older than that on the finance dashboard, it is stuck on a KYC hold - check kyc_profiles before raising it with the gateway.',
   '#FFD9DE', 1, DATE_SUB(NOW(), INTERVAL 41 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY)),
  (2, 1,
   'Commission is 15% of the agreed fee and comes out of the creator payout. GST 18% is charged on top to the brand. Never quote a brand a number that already has GST folded in - the invoice will not match.',
   '#DDF3E4', 1, DATE_SUB(NOW(), INTERVAL 88 DAY), DATE_SUB(NOW(), INTERVAL 88 DAY)),
  (3, 1,
   'Festive intake plan: open creator applications 1 Oct, freeze new category approvals 15 Oct, extend moderation to 10 PM through Bhai Dooj. Ping the Support lead a week before.',
   '#FFF3C4', 0, DATE_SUB(NOW(), INTERVAL 24 DAY), DATE_SUB(NOW(), INTERVAL 24 DAY)),
  (4, 1,
   'Fraud pattern seen twice: brand posts a high-budget brief, accepts the creator, then asks to move payment off-platform over chat. Auto-flag any conversation containing a UPI handle before escrow is funded.',
   '#E5E0FF', 0, DATE_SUB(NOW(), INTERVAL 15 DAY), DATE_SUB(NOW(), INTERVAL 7 DAY)),
  (5, 1,
   'Do not delete media folder 3 (brand logo originals). The CDN still serves the 1200px variants from it and the campaign cards will fall back to placeholders.',
   '#D8ECFF', 0, DATE_SUB(NOW(), INTERVAL 57 DAY), DATE_SUB(NOW(), INTERVAL 57 DAY)),
  (6, 1,
   'Payout batch cutoff is 4 PM IST. Anything approved after that clears the next working day. Bank holidays: check the RBI calendar before promising a date to a creator on Support.',
   '#FFE4CC', 0, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);


-- =====================================================================================
-- End of 027_demo_analytics_ops.sql
-- =====================================================================================
