-- ---------------------------------------------------------------------
-- Taxonomy seed.
--
-- Each block below is a list that used to be a `const` map in the Flutter
-- source. Values are preserved exactly, because the API and existing rows
-- already store them — only the labels are now editable.
--
-- Idempotent: re-running updates labels and ordering without duplicating.
-- ---------------------------------------------------------------------

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `sort_order`) VALUES
  ('creator_sort',     'Creator sort orders',   'Sort options on Search, Explore and the filter sheet.',      1, 0, 10),
  ('platform',         'Social platforms',      'Platform filter, campaign briefs and booking requests.',     1, 0, 20),
  ('language',         'Languages',             'Creator languages and the Language filter.',                 0, 1, 30),
  ('gender',           'Gender',                'Creator gender filter and campaign targeting.',              0, 0, 40),
  ('age_group',        'Age groups',            'Creator age band filter and campaign targeting.',            0, 0, 50),
  ('experience_level', 'Experience levels',     'Creator experience filter.',                                 0, 0, 60),
  ('response_time',    'Response times',        'How quickly a creator replies. Ranked, fastest first.',      1, 0, 70),
  ('rating_filter',    'Rating thresholds',     'Minimum-rating chips in the filter sheet.',                  0, 0, 80),
  ('engagement_filter','Engagement thresholds', 'Minimum engagement-rate chips in the filter sheet.',         0, 0, 90),
  ('campaign_type',    'Campaign types',        'Type of collaboration on a booking request.',                0, 0, 100),
  ('deliverable_type', 'Deliverable types',     'What a creator produces. Used by campaigns and bookings.',   1, 0, 110),
  ('portfolio_kind',   'Portfolio tabs',        'Tabs on a creator gallery.',                                 1, 0, 120),
  ('industry',         'Industries',            'Brand industry on the business profile.',                    0, 0, 130),
  ('company_type',     'Company types',         'Legal entity type on business KYC.',                         1, 0, 140),
  ('company_size',     'Company sizes',         'Team size band on the business profile.',                    0, 0, 150),
  ('support_topic',    'Support topics',        'Browse-by-topic tiles on the Support screen.',               0, 0, 160),
  ('faq_category',     'FAQ categories',        'Category filter on the FAQ screen.',                         0, 0, 170),
  ('ticket_category',  'Ticket categories',     'Category chosen when opening a support ticket.',             1, 0, 180),
  ('topup_preset',     'Wallet top-up presets', 'Quick-amount chips in the wallet top-up sheet.',             0, 0, 190),
  ('booking_status',   'Booking statuses',      'Labels and tones for the booking state machine.',            1, 0, 200)
ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `description` = VALUES(`description`),
  `is_system`   = VALUES(`is_system`),
  `sort_order`  = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Terms. `@t` resolves the parent id so each block stays readable.
-- ---------------------------------------------------------------------

-- Creator sort ---------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'creator_sort');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `icon`, `is_default`, `sort_order`) VALUES
  (@t, 'relevance',  'Relevance',            'auto_awesome',   1, 10),
  (@t, 'rating',     'Rating',               'star',           0, 20),
  (@t, 'popular',    'Popularity',           'trending_up',    0, 30),
  (@t, 'price_low',  'Price: low to high',   'arrow_upward',   0, 40),
  (@t, 'price_high', 'Price: high to low',   'arrow_downward', 0, 50),
  (@t, 'newest',     'Newest',               'fiber_new',      0, 60)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`), `sort_order` = VALUES(`sort_order`);

-- Platforms ------------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'platform');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `icon`, `color_hex`, `is_default`, `sort_order`) VALUES
  (@t, 'instagram', 'Instagram',   'photo_camera',    '#E1306C', 1, 10),
  (@t, 'youtube',   'YouTube',     'play_circle',     '#FF0000', 0, 20),
  (@t, 'facebook',  'Facebook',    'thumb_up',        '#1877F2', 0, 30),
  (@t, 'twitter',   'X (Twitter)', 'tag',             '#000000', 0, 40),
  (@t, 'linkedin',  'LinkedIn',    'work',            '#0A66C2', 0, 50),
  (@t, 'snapchat',  'Snapchat',    'chat',            '#FFFC00', 0, 60),
  (@t, 'pinterest', 'Pinterest',   'push_pin',        '#BD081C', 0, 70),
  (@t, 'tiktok',    'TikTok',      'music_note',      '#000000', 0, 80),
  (@t, 'multi',     'Multiple',    'apps',            NULL,      0, 90)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`), `color_hex` = VALUES(`color_hex`), `sort_order` = VALUES(`sort_order`);

-- Languages ------------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'language');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `is_default`, `sort_order`) VALUES
  (@t, 'English',   'English',   1, 10),
  (@t, 'Hindi',     'Hindi',     0, 20),
  (@t, 'Marathi',   'Marathi',   0, 30),
  (@t, 'Tamil',     'Tamil',     0, 40),
  (@t, 'Telugu',    'Telugu',    0, 50),
  (@t, 'Kannada',   'Kannada',   0, 60),
  (@t, 'Bengali',   'Bengali',   0, 70),
  (@t, 'Gujarati',  'Gujarati',  0, 80),
  (@t, 'Punjabi',   'Punjabi',   0, 90),
  (@t, 'Malayalam', 'Malayalam', 0, 100)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Gender ---------------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'gender');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `sort_order`) VALUES
  (@t, 'female',     'Female',     10),
  (@t, 'male',       'Male',       20),
  (@t, 'non_binary', 'Non-binary', 30)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Age groups -----------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'age_group');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `metadata`, `sort_order`) VALUES
  (@t, '18-24', '18 – 24', '{"min":18,"max":24}', 10),
  (@t, '25-34', '25 – 34', '{"min":25,"max":34}', 20),
  (@t, '35-44', '35 – 44', '{"min":35,"max":44}', 30),
  (@t, '45+',   '45+',     '{"min":45}',          40)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `metadata` = VALUES(`metadata`), `sort_order` = VALUES(`sort_order`);

-- Experience -----------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'experience_level');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `sort_order`) VALUES
  (@t, 'new',          'New (under 1 yr)', 10),
  (@t, 'intermediate', '1 – 3 years',      20),
  (@t, 'experienced',  '3 – 5 years',      30),
  (@t, 'expert',       '5+ years',         40)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Response time. `rank` drives the "within 6h also matches within 1h" logic,
-- so the comparison stays correct even if an admin reorders the list.
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'response_time');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `metadata`, `sort_order`) VALUES
  (@t, 'within_1h',  'Within 1 hour',  '{"rank":0,"hours":1}',  10),
  (@t, 'within_6h',  'Within 6 hours', '{"rank":1,"hours":6}',  20),
  (@t, 'within_24h', 'Within 24 hours','{"rank":2,"hours":24}', 30),
  (@t, 'within_48h', 'Within 2 days',  '{"rank":3,"hours":48}', 40)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `metadata` = VALUES(`metadata`), `sort_order` = VALUES(`sort_order`);

-- Rating thresholds ----------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'rating_filter');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `icon`, `sort_order`) VALUES
  (@t, '4.5', '4.5+', 'star', 10),
  (@t, '4.0', '4.0+', 'star', 20),
  (@t, '3.5', '3.5+', 'star', 30),
  (@t, '3.0', '3.0+', 'star', 40)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Engagement thresholds ------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'engagement_filter');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `sort_order`) VALUES
  (@t, '1', '1%+', 10),
  (@t, '2', '2%+', 20),
  (@t, '3', '3%+', 30),
  (@t, '5', '5%+', 40),
  (@t, '8', '8%+', 50)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Campaign types -------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'campaign_type');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `is_default`, `sort_order`) VALUES
  (@t, 'sponsored_content', 'Sponsored content', 1, 10),
  (@t, 'product_seeding',   'Product seeding',   0, 20),
  (@t, 'brand_ambassador',  'Brand ambassador',  0, 30),
  (@t, 'event_coverage',    'Event coverage',    0, 40),
  (@t, 'giveaway',          'Giveaway',          0, 50),
  (@t, 'affiliate',         'Affiliate',         0, 60)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Deliverables ---------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'deliverable_type');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `icon`, `is_default`, `sort_order`) VALUES
  (@t, 'post',   'Feed post', 'grid_on',              1, 10),
  (@t, 'reel',   'Reel',      'movie_creation',       0, 20),
  (@t, 'story',  'Story',     'amp_stories',          0, 30),
  (@t, 'video',  'Video',     'play_circle_outline',  0, 40),
  (@t, 'short',  'Short',     'bolt',                 0, 50),
  (@t, 'live',   'Live',      'sensors',              0, 60),
  (@t, 'ugc',    'UGC',       'video_camera_back',    0, 70),
  (@t, 'blog',   'Blog',      'article',              0, 80),
  (@t, 'review', 'Review',    'rate_review',          0, 90),
  (@t, 'bundle', 'Bundle',    'inventory_2',          0, 100)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`), `sort_order` = VALUES(`sort_order`);

-- Portfolio tabs -------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'portfolio_kind');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `is_default`, `sort_order`) VALUES
  (@t, 'post',  'Posts',  1, 10),
  (@t, 'reel',  'Reels',  0, 20),
  (@t, 'photo', 'Photos', 0, 30),
  (@t, 'video', 'Videos', 0, 40)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Industries -----------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'industry');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `sort_order`) VALUES
  (@t, 'beauty_skincare',  'Beauty & Skincare',    10),
  (@t, 'fashion_apparel',  'Fashion & Apparel',    20),
  (@t, 'food_beverage',    'Food & Beverage',      30),
  (@t, 'health_fitness',   'Health & Fitness',     40),
  (@t, 'technology',       'Technology',           50),
  (@t, 'travel',           'Travel & Hospitality', 60),
  (@t, 'finance',          'Finance',              70),
  (@t, 'education',        'Education',            80),
  (@t, 'home_living',      'Home & Living',        90),
  (@t, 'automotive',       'Automotive',          100),
  (@t, 'gaming',           'Gaming',              110),
  (@t, 'other',            'Other',               120)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Company types --------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'company_type');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `sort_order`) VALUES
  (@t, 'sole_proprietorship', 'Sole proprietorship', 10),
  (@t, 'partnership',         'Partnership',         20),
  (@t, 'llp',                 'LLP',                 30),
  (@t, 'private_limited',     'Private limited',     40),
  (@t, 'public_limited',      'Public limited',      50),
  (@t, 'opc',                 'One person company',  60),
  (@t, 'trust',               'Trust',               70),
  (@t, 'society',             'Society',             80),
  (@t, 'ngo',                 'NGO',                 90),
  (@t, 'other',               'Other',              100)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Company sizes --------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'company_size');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `is_default`, `sort_order`) VALUES
  (@t, '1-10',    '1–10',    1, 10),
  (@t, '11-50',   '11–50',   0, 20),
  (@t, '51-200',  '51–200',  0, 30),
  (@t, '201-500', '201–500', 0, 40),
  (@t, '500+',    '500+',    0, 50)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Support topics -------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'support_topic');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `icon`, `sort_order`) VALUES
  (@t, 'bookings',        'Bookings',        'calendar_month',   10),
  (@t, 'payments',        'Payments',        'payments',         20),
  (@t, 'payouts',         'Payouts',         'account_balance',  30),
  (@t, 'verification',    'Verification',    'verified_user',    40),
  (@t, 'disputes',        'Disputes',        'gavel',            50),
  (@t, 'getting_started', 'Getting started', 'rocket_launch',    60)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`), `sort_order` = VALUES(`sort_order`);

-- FAQ categories -------------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'faq_category');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `sort_order`) VALUES
  (@t, 'getting_started', 'Getting started', 10),
  (@t, 'bookings',        'Bookings',        20),
  (@t, 'payments',        'Payments',        30),
  (@t, 'payouts',         'Payouts',         40),
  (@t, 'verification',    'Verification',    50),
  (@t, 'disputes',        'Disputes',        60)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Ticket categories ----------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'ticket_category');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `is_default`, `sort_order`) VALUES
  (@t, 'general',         'General inquiry', 1, 10),
  (@t, 'booking_dispute', 'Booking dispute', 0, 20),
  (@t, 'payment',         'Payment issue',   0, 30),
  (@t, 'account',         'Account',         0, 40),
  (@t, 'kyc',             'Verification',    0, 50)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);

-- Wallet top-up presets ------------------------------------------------
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'topup_preset');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `metadata`, `sort_order`) VALUES
  (@t, '1000',  '₹1,000',  '{"amount":1000}',  10),
  (@t, '5000',  '₹5,000',  '{"amount":5000}',  20),
  (@t, '10000', '₹10,000', '{"amount":10000}', 30),
  (@t, '25000', '₹25,000', '{"amount":25000}', 40)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `metadata` = VALUES(`metadata`), `sort_order` = VALUES(`sort_order`);

-- Booking statuses. `tone` drives the status chip colour in the apps.
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'booking_status');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `metadata`, `sort_order`) VALUES
  (@t, 'pending',          'Pending',          '{"tone":"warning"}', 10),
  (@t, 'countered',        'Counter offer',    '{"tone":"warning"}', 20),
  (@t, 'accepted',         'Accepted',         '{"tone":"info"}',    30),
  (@t, 'awaiting_payment', 'Awaiting payment', '{"tone":"warning"}', 40),
  (@t, 'confirmed',        'Confirmed',        '{"tone":"info"}',    50),
  (@t, 'in_progress',      'In progress',      '{"tone":"info"}',    60),
  (@t, 'delivered',        'Delivered',        '{"tone":"info"}',    70),
  (@t, 'completed',        'Completed',        '{"tone":"success"}', 80),
  (@t, 'rejected',         'Declined',         '{"tone":"error"}',   90),
  (@t, 'cancelled',        'Cancelled',        '{"tone":"error"}',  100),
  (@t, 'expired',          'Expired',          '{"tone":"error"}',  110),
  (@t, 'disputed',         'Disputed',         '{"tone":"error"}',  120),
  (@t, 'refunded',         'Refunded',         '{"tone":"error"}',  130)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `metadata` = VALUES(`metadata`), `sort_order` = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Numeric bounds that travel with the taxonomies rather than in code.
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `sort_order`) VALUES
  ('discovery', 'max_budget_filter',    '200000',  'integer', 'Budget filter ceiling',    'Upper bound of the price range slider.',            1, 10),
  ('discovery', 'max_follower_filter',  '1000000', 'integer', 'Follower filter ceiling',  'Upper bound of the follower range slider.',         1, 20),
  ('discovery', 'default_page_size',    '20',      'integer', 'Results per page',         'How many creators a search page returns.',          1, 30),
  ('discovery', 'trending_searches',    '["Skincare creators","Fitness Mumbai","Travel vloggers","Fashion under 25k","Tech reviewers"]', 'json', 'Trending searches', 'Suggested searches on the Search screen.', 1, 40)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`);

-- ---------------------------------------------------------------------
-- Permissions for the taxonomy admin module.
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`group_name`, `name`, `slug`, `description`, `sort_order`) VALUES
  ('Content', 'View taxonomies',   'taxonomies.view',   'See the option lists the apps render.',        10),
  ('Content', 'Manage taxonomies', 'taxonomies.manage', 'Add, edit, reorder and disable list options.', 20)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, p.`id` FROM `permissions` p WHERE p.`slug` LIKE 'taxonomies.%'
ON DUPLICATE KEY UPDATE `role_id` = `role_id`;
