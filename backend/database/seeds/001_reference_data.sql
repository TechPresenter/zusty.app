-- =====================================================================
-- Zusty Influencer Marketplace — Seed 001 (reference data)
--
-- Idempotent: every statement uses INSERT ... ON DUPLICATE KEY UPDATE or
-- INSERT IGNORE, so re-running is safe.
--
-- NOTE: no administrator account is created here on purpose — shipping a
-- default admin password in a SQL file is a security anti-pattern.
-- Create the first Super Admin with:  php backend/scripts/install.php
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Roles
-- ---------------------------------------------------------------------
INSERT INTO `roles` (`id`, `name`, `slug`, `description`, `level`, `is_system`) VALUES
  (1, 'Super Admin',      'super-admin',      'Unrestricted access to every module and setting.',              100, 1),
  (2, 'Admin',            'admin',            'Full operational access; cannot manage roles or system config.', 90, 1),
  (3, 'Manager',          'manager',          'Oversees users, creators, bookings and reporting.',              70, 1),
  (4, 'Finance',          'finance',          'Payments, wallets, withdrawals, invoices and settlements.',      60, 1),
  (5, 'Moderator',        'moderator',        'Reviews creator profiles, content, reviews and abuse reports.',  50, 1),
  (6, 'Content Manager',  'content-manager',  'CMS pages, blogs, banners, FAQs and media library.',             40, 1),
  (7, 'Support',          'support',          'Support tickets and read-only access to user records.',          30, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`),
  `level` = VALUES(`level`), `is_system` = VALUES(`is_system`);

-- ---------------------------------------------------------------------
-- Permissions (slug format: module.action)
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`group_name`, `name`, `slug`, `description`, `sort_order`) VALUES
  ('Dashboard',    'View dashboard',          'dashboard.view',        'Open the admin dashboard and KPI widgets.',        10),
  ('Dashboard',    'View analytics',          'analytics.view',        'Access charts, funnels and cohort reports.',       20),

  ('Users',        'List users',              'users.view',            'Browse and search app users.',                     30),
  ('Users',        'Create user',             'users.create',          'Create a user account manually.',                  31),
  ('Users',        'Edit user',               'users.edit',            'Update a user profile or status.',                 32),
  ('Users',        'Delete user',             'users.delete',          'Soft-delete or purge a user account.',             33),
  ('Users',        'Impersonate user',        'users.impersonate',     'Issue a support token to act as a user.',          34),
  ('Users',        'Review KYC',              'users.kyc',             'Approve or reject KYC submissions.',               35),

  ('Creators',     'List creators',           'creators.view',         'Browse creator profiles.',                         40),
  ('Creators',     'Edit creator',            'creators.edit',         'Update creator profile data and pricing.',         41),
  ('Creators',     'Approve creator',         'creators.approve',      'Approve, reject or suspend a creator profile.',    42),
  ('Creators',     'Verify creator',          'creators.verify',       'Grant or revoke the verified badge.',              43),
  ('Creators',     'Feature creator',         'creators.feature',      'Pin a creator to featured placements.',            44),
  ('Creators',     'Delete creator',          'creators.delete',       'Remove a creator profile.',                        45),

  ('Categories',   'List categories',         'categories.view',       'Browse the category tree.',                        50),
  ('Categories',   'Manage categories',       'categories.manage',     'Create, edit, reorder and delete categories.',     51),

  ('Bookings',     'List bookings',           'bookings.view',         'Browse all bookings and campaigns.',               60),
  ('Bookings',     'Edit booking',            'bookings.edit',         'Amend booking details or status.',                 61),
  ('Bookings',     'Cancel booking',          'bookings.cancel',       'Force-cancel a booking.',                          62),
  ('Bookings',     'Resolve dispute',         'bookings.dispute',      'Arbitrate and settle booking disputes.',           63),

  ('Payments',     'List payments',           'payments.view',         'Browse gateway payments and invoices.',            70),
  ('Payments',     'Issue refund',            'payments.refund',       'Initiate a full or partial refund.',               71),
  ('Payments',     'Manage gateways',         'payments.gateways',     'Configure Razorpay / Stripe credentials.',         72),

  ('Wallet',       'View wallets',            'wallet.view',           'Inspect user wallets and the ledger.',             80),
  ('Wallet',       'Adjust wallet',           'wallet.adjust',         'Post a manual credit or debit adjustment.',        81),
  ('Wallet',       'Approve withdrawals',     'wallet.withdrawals',    'Approve, reject and settle payout requests.',      82),

  ('Reviews',      'List reviews',            'reviews.view',          'Browse ratings and written reviews.',              90),
  ('Reviews',      'Moderate reviews',        'reviews.moderate',      'Publish, hide or delete a review.',                91),

  ('Reports',      'View reports',            'reports.view',          'Access abuse and content reports.',                100),
  ('Reports',      'Handle reports',          'reports.handle',        'Resolve or dismiss a report.',                     101),

  ('CMS',          'View content',            'cms.view',              'Browse pages, blogs, banners and FAQs.',           110),
  ('CMS',          'Manage pages',            'cms.pages',             'Create and edit static pages.',                    111),
  ('CMS',          'Manage blogs',            'cms.blogs',             'Author and publish blog posts.',                   112),
  ('CMS',          'Manage banners',          'cms.banners',           'Manage promotional banners.',                      113),
  ('CMS',          'Manage FAQs',             'cms.faqs',              'Manage the FAQ knowledge base.',                   114),
  ('CMS',          'Manage testimonials',     'cms.testimonials',      'Manage homepage testimonials.',                    115),
  ('CMS',          'Manage media',            'cms.media',             'Upload and delete media library assets.',          116),

  ('Notifications','View notifications',      'notifications.view',    'Browse notification history.',                     120),
  ('Notifications','Send broadcast',          'notifications.send',    'Compose and send push/email broadcasts.',          121),
  ('Notifications','Manage templates',        'notifications.templates','Edit notification templates.',                    122),

  ('Support',      'View tickets',            'support.view',          'Browse support tickets.',                          130),
  ('Support',      'Reply to tickets',        'support.reply',         'Respond to and resolve tickets.',                  131),
  ('Support',      'Assign tickets',          'support.assign',        'Route tickets between agents.',                    132),

  ('Access',       'View admins',             'admins.view',           'Browse admin accounts.',                           140),
  ('Access',       'Manage admins',           'admins.manage',         'Create, edit and disable admin accounts.',         141),
  ('Access',       'Manage roles',            'roles.manage',          'Create roles and assign permissions.',             142),

  ('System',       'View activity log',       'logs.activity',         'Read the audit trail.',                            150),
  ('System',       'View system log',         'logs.system',           'Read application error logs.',                     151),
  ('System',       'View settings',           'settings.view',         'Open the settings screens.',                       152),
  ('System',       'Manage settings',         'settings.manage',       'Change platform configuration.',                   153),
  ('System',       'Manage security',         'settings.security',     'Change security and rate-limit policy.',           154),
  ('System',       'Run backups',             'system.backup',         'Create and download backups.',                     155),
  ('System',       'Restore backup',          'system.restore',        'Restore the database from a backup.',              156),
  ('System',       'Optimize database',       'system.optimize',       'Run OPTIMIZE / ANALYZE maintenance.',               157),
  ('System',       'Run jobs',                'system.jobs',           'Trigger scheduled jobs manually.',                 158),

  ('Export',       'Export data',             'export.data',           'Download CSV / Excel exports.',                    160)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `group_name` = VALUES(`group_name`),
  `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Role → permission matrix
-- ---------------------------------------------------------------------

-- Super Admin: everything.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

-- Admin: everything except role/admin management and destructive system ops.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions`
WHERE `slug` NOT IN ('roles.manage', 'admins.manage', 'system.restore', 'settings.security', 'users.impersonate');

-- Manager: operations without finance mutations or system config.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions` WHERE `slug` IN (
  'dashboard.view','analytics.view',
  'users.view','users.edit','users.kyc',
  'creators.view','creators.edit','creators.approve','creators.verify','creators.feature',
  'categories.view','categories.manage',
  'bookings.view','bookings.edit','bookings.cancel','bookings.dispute',
  'payments.view','wallet.view',
  'reviews.view','reviews.moderate',
  'reports.view','reports.handle',
  'cms.view','notifications.view','notifications.send',
  'support.view','support.reply','support.assign',
  'logs.activity','settings.view','export.data'
);

-- Finance.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, `id` FROM `permissions` WHERE `slug` IN (
  'dashboard.view','analytics.view',
  'users.view','creators.view',
  'bookings.view',
  'payments.view','payments.refund',
  'wallet.view','wallet.adjust','wallet.withdrawals',
  'logs.activity','settings.view','export.data'
);

-- Moderator.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 5, `id` FROM `permissions` WHERE `slug` IN (
  'dashboard.view',
  'users.view','creators.view','creators.approve','creators.verify',
  'bookings.view',
  'reviews.view','reviews.moderate',
  'reports.view','reports.handle',
  'cms.view','cms.media',
  'logs.activity'
);

-- Content Manager.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 6, `id` FROM `permissions` WHERE `slug` IN (
  'dashboard.view',
  'categories.view','categories.manage',
  'cms.view','cms.pages','cms.blogs','cms.banners','cms.faqs','cms.testimonials','cms.media',
  'notifications.view','notifications.templates',
  'export.data'
);

-- Support.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 7, `id` FROM `permissions` WHERE `slug` IN (
  'dashboard.view',
  'users.view','creators.view','bookings.view','payments.view','wallet.view',
  'support.view','support.reply',
  'reports.view',
  'notifications.view'
);

-- ---------------------------------------------------------------------
-- Platform settings
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  -- General
  ('general','app_name','Zusty','string','Application name','Shown in emails, invoices and the admin header.',1,0,1),
  ('general','app_tagline','Shoot. Connect. Grow.','string','Tagline','Brand tagline used on onboarding.',1,0,2),
  ('general','support_email','support@zusty.app','string','Support email','Reply-to address for outbound mail.',1,0,3),
  ('general','support_phone','+91 90000 00000','string','Support phone','Displayed on the Help & Support screen.',1,0,4),
  ('general','company_name','Zuste Technologies Pvt. Ltd.','string','Legal entity','Printed on invoices.',1,0,5),
  ('general','company_address','Mumbai, Maharashtra, India','text','Registered address','Printed on invoices.',1,0,6),
  ('general','company_gstin','','string','GSTIN','Company GST identification number.',0,0,7),
  ('general','default_currency','INR','string','Default currency','ISO-4217 code.',1,0,8),
  ('general','currency_symbol','₹','string','Currency symbol','Rendered in the apps.',1,0,9),
  ('general','default_locale','en','string','Default locale','Fallback language.',1,0,10),
  ('general','timezone','Asia/Kolkata','string','Timezone','Server timezone for all timestamps.',1,0,11),
  ('general','maintenance_mode','0','boolean','Maintenance mode','Blocks the public API with HTTP 503.',1,0,12),
  ('general','maintenance_message','We are performing scheduled maintenance. Please try again shortly.','text','Maintenance message','Shown by the apps while in maintenance.',1,0,13),

  -- Branding
  ('branding','logo_url','/uploads/branding/zusty-logo.png','file','Logo','Primary wordmark.',1,0,1),
  ('branding','logo_dark_url','/uploads/branding/zusty-logo-dark.png','file','Logo (dark)','Wordmark for dark surfaces.',1,0,2),
  ('branding','favicon_url','/uploads/branding/zusty-favicon.png','file','Favicon','Browser icon.',1,0,3),
  ('branding','primary_color','#B80049','string','Primary colour','Brand pink from the design system.',1,0,4),
  ('branding','secondary_color','#632CE5','string','Secondary colour','Brand violet.',1,0,5),
  ('branding','tertiary_color','#006A34','string','Tertiary colour','Success green.',1,0,6),

  -- Booking rules
  ('booking','request_expiry_hours','72','integer','Request expiry (hours)','Unanswered requests auto-expire after this window.',1,0,1),
  ('booking','payment_window_hours','48','integer','Payment window (hours)','Time a brand has to pay after acceptance.',1,0,2),
  ('booking','default_delivery_days','7','integer','Default delivery days','Used when a package does not specify a turnaround.',1,0,3),
  ('booking','auto_complete_days','7','integer','Auto-complete after (days)','Delivered work auto-completes if the brand does not respond.',1,0,4),
  ('booking','free_cancellation_days','3','integer','Free cancellation window (days)','Full refund if cancelled this far before delivery.',1,0,5),
  ('booking','late_cancellation_fee_percent','25','decimal','Late cancellation fee (%)','Retained from the brand on late cancellation.',1,0,6),
  ('booking','min_booking_amount','1000','decimal','Minimum booking amount','Lowest permitted booking value.',1,0,7),
  ('booking','max_booking_amount','2000000','decimal','Maximum booking amount','Highest permitted booking value.',1,0,8),
  ('booking','allow_counter_offers','1','boolean','Allow counter offers','Enables the negotiation flow.',1,0,9),
  ('booking','max_counter_offers','3','integer','Max counter offers','Negotiation rounds per booking.',1,0,10),

  -- Commission & fees
  ('commission','platform_fee_percent','10','decimal','Platform fee (%)','Charged to the brand on top of the creator fee.',1,0,1),
  ('commission','creator_commission_percent','10','decimal','Creator commission (%)','Deducted from the creator payout.',1,0,2),
  ('commission','tax_percent','18','decimal','GST on fees (%)','Tax applied to the platform fee.',1,0,3),
  ('commission','tax_label','GST','string','Tax label','Shown on invoices.',1,0,4),
  ('commission','withdrawal_fee_percent','0','decimal','Withdrawal fee (%)','Deducted from payout requests.',1,0,5),
  ('commission','withdrawal_fee_flat','0','decimal','Withdrawal fee (flat)','Flat fee per payout.',1,0,6),

  -- Wallet
  ('wallet','enabled','1','boolean','Wallet enabled','Master switch for wallet features.',1,0,1),
  ('wallet','min_topup','500','decimal','Minimum top-up','Smallest wallet deposit.',1,0,2),
  ('wallet','max_topup','500000','decimal','Maximum top-up','Largest single deposit.',1,0,3),
  ('wallet','min_withdrawal','1000','decimal','Minimum withdrawal','Smallest payout request.',1,0,4),
  ('wallet','max_withdrawal','200000','decimal','Maximum withdrawal','Largest single payout request.',1,0,5),
  ('wallet','withdrawal_hold_days','3','integer','Payout hold (days)','Earnings mature before becoming withdrawable.',1,0,6),
  ('wallet','require_kyc_for_withdrawal','1','boolean','Require KYC for payouts','Blocks payouts until KYC is verified.',1,0,7),

  -- Referral
  ('referral','enabled','1','boolean','Referrals enabled','Master switch for Invite & Earn.',1,0,1),
  ('referral','referrer_reward','200','decimal','Referrer reward','Wallet credit for the inviter.',1,0,2),
  ('referral','referred_reward','200','decimal','Invitee reward','Wallet credit for the new user.',1,0,3),
  ('referral','qualify_on','first_paid_booking','string','Qualification event','When the reward is released.',1,0,4),
  ('referral','share_base_url','https://zusty.app/invite/','string','Invite link base','Prefix for referral share links.',1,0,5),

  -- Payments
  ('payment','razorpay_enabled','0','boolean','Razorpay enabled','Enable the Razorpay gateway.',1,0,1),
  ('payment','razorpay_key_id','','string','Razorpay key id','Public key id.',1,0,2),
  ('payment','razorpay_key_secret','','secret','Razorpay key secret','Stored encrypted.',0,1,3),
  ('payment','razorpay_webhook_secret','','secret','Razorpay webhook secret','Used to verify webhook signatures.',0,1,4),
  ('payment','stripe_enabled','0','boolean','Stripe enabled','Enable the Stripe gateway.',1,0,5),
  ('payment','stripe_publishable_key','','string','Stripe publishable key','Public key.',1,0,6),
  ('payment','stripe_secret_key','','secret','Stripe secret key','Stored encrypted.',0,1,7),
  ('payment','stripe_webhook_secret','','secret','Stripe webhook secret','Used to verify webhook signatures.',0,1,8),
  ('payment','allow_wallet_payment','1','boolean','Allow wallet payment','Let brands pay from wallet balance.',1,0,9),
  ('payment','allowed_methods','["card","upi","netbanking","wallet"]','json','Allowed methods','Payment methods offered at checkout.',1,0,10),

  -- Firebase
  ('firebase','project_id','','string','Firebase project id','Used to validate ID tokens.',1,0,1),
  ('firebase','web_api_key','','string','Web API key','Firebase web configuration.',1,0,2),
  ('firebase','service_account_json','','secret','Service account JSON','Server credentials for FCM v1. Stored encrypted.',0,1,3),
  ('firebase','fcm_enabled','1','boolean','Push notifications enabled','Master switch for FCM delivery.',1,0,4),
  ('firebase','storage_bucket','','string','Storage bucket','Firebase Storage bucket name.',1,0,5),
  ('firebase','firestore_chat_mirror','1','boolean','Mirror chat to Firestore','Enables the realtime chat transport.',1,0,6),

  -- Mail
  ('mail','driver','smtp','string','Mail driver','smtp | mail | log',0,0,1),
  ('mail','host','smtp.hostinger.com','string','SMTP host','Outgoing mail server.',0,0,2),
  ('mail','port','465','integer','SMTP port','587 for TLS, 465 for SSL.',0,0,3),
  ('mail','encryption','ssl','string','Encryption','tls | ssl | none',0,0,4),
  ('mail','username','','string','SMTP username','Usually the full mailbox address.',0,0,5),
  ('mail','password','','secret','SMTP password','Stored encrypted.',0,1,6),
  ('mail','from_address','no-reply@zusty.app','string','From address','Envelope sender.',0,0,7),
  ('mail','from_name','Zusty','string','From name','Display name on outgoing mail.',0,0,8),

  -- SMS
  ('sms','enabled','0','boolean','SMS enabled','Master switch for SMS delivery.',0,0,1),
  ('sms','provider','msg91','string','Provider','msg91 | twilio | textlocal',0,0,2),
  ('sms','api_key','','secret','API key','Stored encrypted.',0,1,3),
  ('sms','sender_id','ZUSTEP','string','Sender id','6-character DLT-approved header.',0,0,4),
  ('sms','otp_template_id','','string','OTP template id','DLT template id for OTP messages.',0,0,5),

  -- Security
  ('security','jwt_access_ttl','3600','integer','Access token TTL (s)','Lifetime of a JWT access token.',0,0,1),
  ('security','jwt_refresh_ttl','2592000','integer','Refresh token TTL (s)','Lifetime of a refresh token (30 days).',0,0,2),
  ('security','otp_ttl','300','integer','OTP validity (s)','How long an OTP stays valid.',0,0,3),
  ('security','otp_length','6','integer','OTP length','Number of digits.',0,0,4),
  ('security','max_login_attempts','5','integer','Max login attempts','Before the account is temporarily locked.',0,0,5),
  ('security','lockout_minutes','15','integer','Lockout duration (min)','How long an account stays locked.',0,0,6),
  ('security','api_rate_limit','120','integer','API rate limit','Requests per window per identity.',0,0,7),
  ('security','api_rate_window','60','integer','Rate limit window (s)','Sliding window size.',0,0,8),
  ('security','password_min_length','8','integer','Minimum password length','Enforced on registration and reset.',1,0,9),
  ('security','force_https','1','boolean','Force HTTPS','Redirect and set HSTS.',0,0,10),
  ('security','allowed_origins','["https://zusty.app"]','json','CORS origins','Origins allowed to call the API.',0,0,11),

  -- Uploads
  ('upload','max_image_mb','5','integer','Max image size (MB)','Per-file limit for images.',1,0,1),
  ('upload','max_video_mb','50','integer','Max video size (MB)','Per-file limit for video.',1,0,2),
  ('upload','max_document_mb','10','integer','Max document size (MB)','Per-file limit for KYC documents.',1,0,3),
  ('upload','allowed_image_types','["jpg","jpeg","png","webp"]','json','Allowed image types','Whitelisted image extensions.',1,0,4),
  ('upload','allowed_video_types','["mp4","mov","webm"]','json','Allowed video types','Whitelisted video extensions.',1,0,5),
  ('upload','allowed_document_types','["pdf","jpg","jpeg","png"]','json','Allowed document types','Whitelisted KYC extensions.',1,0,6),
  ('upload','image_quality','82','integer','Image quality','JPEG/WebP re-encode quality.',0,0,7),
  ('upload','image_max_dimension','2048','integer','Max dimension (px)','Images are downscaled to fit this box.',0,0,8),

  -- App / release control
  ('app','android_min_version','1.0.0','string','Android minimum version','Below this the app forces an update.',1,0,1),
  ('app','android_latest_version','1.0.0','string','Android latest version','Latest published build.',1,0,2),
  ('app','ios_min_version','1.0.0','string','iOS minimum version','Below this the app forces an update.',1,0,3),
  ('app','ios_latest_version','1.0.0','string','iOS latest version','Latest published build.',1,0,4),
  ('app','android_store_url','https://play.google.com/store/apps/details?id=com.zuste.zuste','string','Play Store URL','Update link.',1,0,5),
  ('app','ios_store_url','https://apps.apple.com/app/id0000000000','string','App Store URL','Update link.',1,0,6),
  ('app','force_update','0','boolean','Force update','Blocks the app until updated.',1,0,7),
  ('app','creator_signup_enabled','1','boolean','Creator signup enabled','Allows new creator registrations.',1,0,8),
  ('app','brand_signup_enabled','1','boolean','Brand signup enabled','Allows new brand registrations.',1,0,9),

  -- SEO
  ('seo','site_title','Zusty — Book verified creators for your brand','string','Site title','Default HTML title.',1,0,1),
  ('seo','site_description','Zusty connects brands with verified influencers. Browse creators, book campaigns and pay securely.','text','Meta description','Default meta description.',1,0,2),
  ('seo','site_keywords','influencer marketing, creator marketplace, brand collaboration, book influencers India','string','Meta keywords','Comma separated.',1,0,3),
  ('seo','og_image','/uploads/branding/og-image.png','file','Open Graph image','Social share preview.',1,0,4),
  ('seo','twitter_handle','@zusteapp','string','Twitter handle','Used in Twitter cards.',1,0,5),
  ('seo','canonical_base','https://zusty.app','string','Canonical base URL','Root for canonical links.',1,0,6),

  -- Social links
  ('social','instagram','https://instagram.com/zusteapp','string','Instagram','Footer link.',1,0,1),
  ('social','youtube','https://youtube.com/@zusteapp','string','YouTube','Footer link.',1,0,2),
  ('social','linkedin','https://linkedin.com/company/zuste','string','LinkedIn','Footer link.',1,0,3),
  ('social','twitter','https://twitter.com/zusteapp','string','X / Twitter','Footer link.',1,0,4),
  ('social','facebook','https://facebook.com/zusteapp','string','Facebook','Footer link.',1,0,5)
ON DUPLICATE KEY UPDATE
  `type` = VALUES(`type`), `label` = VALUES(`label`),
  `description` = VALUES(`description`), `is_public` = VALUES(`is_public`),
  `is_encrypted` = VALUES(`is_encrypted`), `sort_order` = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Categories (mirrors the Categories screen of the reference design)
-- ---------------------------------------------------------------------
INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `icon`, `color_hex`, `is_featured`, `is_active`, `sort_order`) VALUES
  (1,  'Lifestyle',        'lifestyle',        'Everyday living, routines, home and personal style.',        'auto_awesome',      '#B80049', 1, 1, 1),
  (2,  'Fashion',          'fashion',          'Outfits, styling, hauls and runway commentary.',             'checkroom',         '#E2165F', 1, 1, 2),
  (3,  'Travel',           'travel',           'Destinations, itineraries and travel storytelling.',         'flight_takeoff',    '#632CE5', 1, 1, 3),
  (4,  'Food',             'food',             'Recipes, restaurant reviews and food photography.',          'restaurant',        '#008644', 1, 1, 4),
  (5,  'Fitness',          'fitness',          'Workouts, training plans and athletic performance.',         'fitness_center',    '#006A34', 1, 1, 5),
  (6,  'Tech',             'tech',             'Gadget reviews, unboxings and software walkthroughs.',       'devices',           '#7C4DFF', 1, 1, 6),
  (7,  'Beauty',           'beauty',           'Skincare, makeup tutorials and product reviews.',            'face_retouching_natural','#E2165F', 1, 1, 7),
  (8,  'Gaming',           'gaming',           'Gameplay, streams, esports and reviews.',                    'sports_esports',    '#4F00D0', 1, 1, 8),
  (9,  'Health & Wellness','health-wellness',  'Nutrition, mindfulness and holistic wellbeing.',             'spa',               '#006A34', 0, 1, 9),
  (10, 'Music',            'music',            'Covers, original music and instrument content.',             'music_note',        '#632CE5', 0, 1, 10),
  (11, 'Education',        'education',        'Tutorials, exam prep and skill building.',                   'school',            '#B80049', 0, 1, 11),
  (12, 'Finance',          'finance',          'Personal finance, investing and market commentary.',         'savings',           '#008644', 0, 1, 12),
  (13, 'Parenting',        'parenting',        'Family life, kids and parenting advice.',                    'child_care',        '#E2165F', 0, 1, 13),
  (14, 'Automobile',       'automobile',       'Cars, bikes, reviews and road trips.',                       'directions_car',    '#4F00D0', 0, 1, 14),
  (15, 'Comedy',           'comedy',           'Sketches, stand-up and humour.',                             'theater_comedy',    '#B80049', 0, 1, 15),
  (16, 'Art & Design',     'art-design',       'Illustration, design process and creative direction.',       'palette',           '#7C4DFF', 0, 1, 16)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `icon` = VALUES(`icon`),
  `color_hex` = VALUES(`color_hex`), `is_featured` = VALUES(`is_featured`), `sort_order` = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Popular searches (curated, shown on the Search screen)
-- ---------------------------------------------------------------------
INSERT INTO `popular_searches` (`term`, `hits`, `is_curated`, `is_active`) VALUES
  ('Fashion Influencer', 0, 1, 1),
  ('Travel Vlogger',     0, 1, 1),
  ('Lifestyle Blogger',  0, 1, 1),
  ('Fitness Trainer',    0, 1, 1),
  ('Food Blogger',       0, 1, 1),
  ('Tech Reviewer',      0, 1, 1),
  ('Beauty Creator',     0, 1, 1)
ON DUPLICATE KEY UPDATE `is_curated` = VALUES(`is_curated`), `is_active` = VALUES(`is_active`);

-- ---------------------------------------------------------------------
-- Scheduled jobs registry
-- ---------------------------------------------------------------------
INSERT INTO `scheduled_jobs` (`job_key`, `name`, `description`, `schedule_hint`, `is_enabled`) VALUES
  ('queue:dispatch',        'Dispatch queues',        'Sends queued email, SMS and push notifications.',                    '* * * * *',    1),
  ('marketing:dispatch',    'Dispatch campaigns',     'Queues and sends scheduled and in-flight marketing campaigns.',      '*/5 * * * *',  1),
  ('bookings:expire',       'Expire stale bookings',  'Auto-expires unanswered requests and unpaid acceptances.',           '*/15 * * * *', 1),
  ('bookings:autocomplete', 'Auto-complete deliveries','Completes delivered bookings the brand did not action.',            '0 * * * *',    1),
  ('escrow:release',        'Release escrow',         'Moves matured earnings from locked to withdrawable balance.',        '0 * * * *',    1),
  ('analytics:rollup',      'Roll up analytics',      'Rebuilds the daily analytics and settlement tables.',                '10 0 * * *',   1),
  ('creators:refresh',      'Refresh creator stats',  'Recomputes ratings, counts and category tallies.',                   '30 0 * * *',   1),
  ('tokens:prune',          'Prune expired tokens',   'Deletes expired refresh tokens, OTPs and rate-limit buckets.',       '0 3 * * *',    1),
  ('backup:database',       'Database backup',        'Creates a compressed mysqldump in storage/backups.',                 '0 2 * * *',    1),
  ('logs:rotate',           'Rotate logs',            'Trims system logs and archives old files.',                          '0 4 * * 0',    1),
  ('automation:run',        'Run automation rules',   'Evaluates active automation rules against new events and performs their actions.', '*/5 * * * *', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `schedule_hint` = VALUES(`schedule_hint`);
