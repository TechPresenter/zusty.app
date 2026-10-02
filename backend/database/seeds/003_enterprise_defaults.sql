-- =====================================================================
-- Zusty Influencer Marketplace — Seed 003
-- Permissions, approval workflows, AI prompts and starter feature flags
-- for the enterprise modules. Idempotent.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- New permissions
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`group_name`, `name`, `slug`, `description`, `sort_order`) VALUES
  ('Marketing',  'View marketing',      'marketing.view',    'Browse coupons, campaigns and popups.',            170),
  ('Marketing',  'Manage marketing',    'marketing.manage',  'Create and edit coupons, campaigns and popups.',   171),
  ('Marketing',  'Send campaigns',      'marketing.send',    'Dispatch newsletter and SMS campaigns.',           172),

  ('Security',   'View login history',  'security.logins',   'Read the authentication audit trail.',             180),
  ('Security',   'Manage IP whitelist', 'security.ip',       'Restrict panel access by IP address.',             181),
  ('Security',   'Manage sessions',     'security.sessions', 'Terminate another admin''s sessions.',             182),

  ('Developer',  'View API logs',       'developer.logs',    'Inspect REST API access logs.',                    190),
  ('Developer',  'Use API tester',      'developer.tester',  'Send authenticated test requests to the API.',     191),
  ('Developer',  'View query monitor',  'developer.queries', 'Inspect slow database queries.',                   192),
  ('Developer',  'Manage cache',        'developer.cache',   'Inspect and flush the application cache.',         193),

  ('Workflow',   'View approvals',      'workflow.view',     'See multi-level approval requests.',               200),
  ('Workflow',   'Act on approvals',    'workflow.act',      'Approve or reject a workflow step.',               201),
  ('Workflow',   'Manage automation',   'workflow.manage',   'Configure workflows and automation rules.',        202),

  ('AI',         'View AI',             'ai.view',           'See AI prompts, logs and spend.',                  210),
  ('AI',         'Manage AI',           'ai.manage',         'Edit prompts and AI configuration.',               211),

  ('Productivity', 'Manage recycle bin', 'recyclebin.manage', 'Restore or permanently purge deleted records.',   220),
  ('Productivity', 'View revisions',     'revisions.view',    'Browse and roll back content revisions.',         221)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `group_name` = VALUES(`group_name`),
  `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`);

-- Super Admin keeps everything.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, `id` FROM `permissions`;

-- Admin: everything except the destructive/system-config subset.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, `id` FROM `permissions`
WHERE `slug` IN ('marketing.view','marketing.manage','marketing.send',
                 'security.logins','developer.logs','developer.tester','developer.queries','developer.cache',
                 'workflow.view','workflow.act','workflow.manage','ai.view','ai.manage',
                 'recyclebin.manage','revisions.view');

-- Manager: marketing and workflow, read-only developer tooling.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 3, `id` FROM `permissions`
WHERE `slug` IN ('marketing.view','marketing.manage','workflow.view','workflow.act','ai.view','revisions.view');

-- Finance: approvals and the security trail.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 4, `id` FROM `permissions`
WHERE `slug` IN ('workflow.view','workflow.act','security.logins');

-- Content Manager: marketing content and revisions.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 6, `id` FROM `permissions`
WHERE `slug` IN ('marketing.view','marketing.manage','revisions.view','ai.view');

-- ---------------------------------------------------------------------
-- Settings for the new modules
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('security','require_2fa_for_admins','0','boolean','Require two-factor for admins','Blocks the panel until every admin enrols.',0,0,20),
  ('security','ip_whitelist_enabled','0','boolean','Enforce the IP whitelist','When on, only whitelisted addresses reach the panel.',0,0,21),
  ('security','api_log_sample_rate','100','integer','API log sample rate (%)','100 logs every request; lower it on a busy API.',0,0,22),
  ('security','slow_query_threshold_ms','500','integer','Slow query threshold (ms)','Queries above this are recorded by the monitor.',0,0,23),

  -- The provider, its key and its model live in `ai_providers` (Settings -> AI).
  ('ai','enabled','0','boolean','AI features enabled','Master switch. While it is off the app hides every AI button and the API refuses AI requests.',0,0,1),
  ('ai','feature_bio_writer','1','boolean','Bio writer for creators','Creators can draft their profile bio with AI.',0,0,10),
  ('ai','feature_campaign_brief','1','boolean','Campaign brief writer for brands','Brands can turn a one-line idea into a full campaign brief.',0,0,11),
  ('ai','feature_creator_match','1','boolean','Creator matching for brands','Brands describe a campaign and get the best-fitting creators, each with a reason.',0,0,12),
  ('ai','daily_limit_per_user','20','integer','Requests per account per day','Across all AI features. Stops one account running up the bill.',0,0,20),
  ('ai','monthly_budget','50','decimal','Monthly budget (USD)','AI requests stop for the rest of the month once spend reaches this. 0 means no limit.',0,0,21),

  ('cache','enabled','1','boolean','Cache enabled','Master switch for the application cache.',0,0,1),
  ('cache','default_ttl','3600','integer','Default TTL (s)','Lifetime for cached entries.',0,0,2)
ON DUPLICATE KEY UPDATE
  `type` = VALUES(`type`), `label` = VALUES(`label`),
  `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Approval workflows
-- ---------------------------------------------------------------------
INSERT INTO `approval_workflows` (`entity_type`, `name`, `description`, `threshold_amount`, `is_active`) VALUES
  ('withdrawal', 'High-value payout', 'Payouts above the threshold need a second approver before settlement.', 50000.00, 1),
  ('refund',     'Large refund',      'Refunds above the threshold need finance sign-off.',                     25000.00, 1),
  ('creator',    'Creator approval',  'New creator profiles are reviewed before going live.',                   NULL,     1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `threshold_amount` = VALUES(`threshold_amount`);

INSERT IGNORE INTO `approval_steps` (`workflow_id`, `step_order`, `name`, `role_id`, `permission`)
SELECT w.`id`, 1, 'Finance review', 4, 'wallet.withdrawals' FROM `approval_workflows` w WHERE w.`entity_type` = 'withdrawal';

INSERT IGNORE INTO `approval_steps` (`workflow_id`, `step_order`, `name`, `role_id`, `permission`)
SELECT w.`id`, 2, 'Admin sign-off', 2, 'wallet.withdrawals' FROM `approval_workflows` w WHERE w.`entity_type` = 'withdrawal';

INSERT IGNORE INTO `approval_steps` (`workflow_id`, `step_order`, `name`, `role_id`, `permission`)
SELECT w.`id`, 1, 'Finance review', 4, 'payments.refund' FROM `approval_workflows` w WHERE w.`entity_type` = 'refund';

INSERT IGNORE INTO `approval_steps` (`workflow_id`, `step_order`, `name`, `role_id`, `permission`)
SELECT w.`id`, 1, 'Moderation', 5, 'creators.approve' FROM `approval_workflows` w WHERE w.`entity_type` = 'creator';

-- ---------------------------------------------------------------------
-- AI prompt templates
-- ---------------------------------------------------------------------
INSERT INTO `ai_prompts` (`code`, `name`, `purpose`, `system_prompt`, `user_prompt`, `temperature`, `max_tokens`, `is_active`) VALUES
('creator_match', 'Creator matching', 'creator_match',
 'You match brands with influencers on an Indian creator marketplace. Judge fit on audience overlap, content style, past performance and budget. Be concise and specific; never invent metrics you were not given.',
 "Brand brief:\n{{brief}}\n\nBudget: {{currency}}{{budget}}\nPlatform: {{platform}}\nCategory: {{category}}\n\nCandidate creators, one per line, each starting with its id:\n{{candidates}}\n\nPick the creators who fit this brief best and give each a fit score out of 100 and one short reason a brand manager would find useful.",
 0.40, 1500, 1),

('campaign_brief', 'Campaign brief writer', 'campaign_brief',
 'You write clear, actionable influencer campaign briefs for Indian brands. Keep them practical and specific. Never promise guaranteed reach or engagement.',
 "Write a campaign brief.\n\nBrand: {{brand_name}}\nProduct: {{product}}\nGoal: {{goal}}\nPlatform: {{platform}}\nDeliverables: {{deliverables}}\nBudget: {{currency}}{{budget}}\n\nInclude: objective, target audience, key messages, deliverables, do's and don'ts, and mandatory disclosure requirements under the ASCI guidelines.",
 0.70, 2000, 1),

('bio_writer', 'Creator bio writer', 'bio_writer',
 'You help creators write their marketplace profile. Write in first person, warm and professional, 60-90 words. Never fabricate follower counts, brands worked with, or achievements.',
 "Write a profile bio.\n\nName: {{name}}\nNiche: {{niche}}\nCity: {{city}}\nPlatforms: {{platforms}}\nStyle notes: {{notes}}",
 0.80, 500, 1),

('moderation', 'Content moderation', 'moderation',
 'You screen user-generated content for an influencer marketplace. Flag: spam, fraud, adult content, hate speech, impersonation, and attempts to move payment off-platform. Respond only with the JSON object requested.',
 "Assess this content and reply with JSON: {\"flagged\": bool, \"categories\": [], \"severity\": \"low|medium|high\", \"reason\": \"\"}\n\nContent:\n{{content}}",
 0.00, 500, 1),

('support_reply', 'Support reply draft', 'support_reply',
 'You draft first-response replies for a marketplace support team. Be accurate, warm and brief. Never promise refunds, payouts or timelines you were not explicitly told about. End by noting a human will confirm.',
 "Draft a reply.\n\nCategory: {{category}}\nSubject: {{subject}}\nCustomer message:\n{{message}}\n\nKnown account context:\n{{context}}",
 0.50, 800, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `system_prompt` = VALUES(`system_prompt`),
  `user_prompt` = VALUES(`user_prompt`), `purpose` = VALUES(`purpose`);

-- ---------------------------------------------------------------------
-- Feature flags
-- ---------------------------------------------------------------------
INSERT INTO `feature_flags` (`flag_key`, `name`, `description`, `is_enabled`, `rollout_percent`, `audience`) VALUES
  ('coupons_at_checkout', 'Coupons at checkout',   'Show the coupon field on the payment screen.',        1, 100, 'brands'),
  ('dynamic_homepage',    'Dynamic homepage',      'Render Home from configured sections.',               0, 100, 'all'),
  ('creator_video_calls', 'Creator video calls',   'In-app video calls between brands and creators.',     0,   0, 'beta'),
  ('instant_payouts',     'Instant payouts',       'Same-day settlement for verified creators.',          0,  10, 'creators')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`);

-- ---------------------------------------------------------------------
-- Media folders
-- ---------------------------------------------------------------------
INSERT INTO `media_folders` (`name`, `slug`, `path`, `is_system`) VALUES
  ('General',   'general',   'general',   1),
  ('Banners',   'banners',   'banners',   1),
  ('Blog',      'blogs',     'blogs',     1),
  ('Branding',  'branding',  'branding',  1),
  ('Marketing', 'marketing', 'marketing', 0)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- ---------------------------------------------------------------------
-- Default app drawer menu, mirroring the reference design's More screen
-- ---------------------------------------------------------------------
INSERT INTO `menus` (`id`, `name`, `slug`, `location`, `description`, `is_active`) VALUES
  (1, 'App drawer', 'app-drawer', 'app_drawer', 'The More / Menu screen in the mobile apps.', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Every `link_value` below must be a path the Flutter router actually
-- registers. '/favorites' and '/settings' were seeded here for months and
-- neither is a real route — the drawer was never rendered, so nobody found
-- out. The app now refuses to navigate to an unregistered path rather than
-- throwing, but the seed should still ship destinations that exist.
--
-- The ON DUPLICATE KEY clause only became meaningful with the unique key added
-- in migration 027; before it, every install re-inserted the whole block and
-- the table grew by seven rows a time.
INSERT INTO `menu_items` (`menu_id`, `label`, `icon`, `link_type`, `link_value`, `audience`, `sort_order`) VALUES
  (1, 'My Bookings',    'calendar_today',         'screen', '/bookings',      'all',      1),
  (1, 'My Wallet',      'account_balance_wallet', 'screen', '/wallet',        'all',      2),
  (1, 'Notifications',  'notifications',          'screen', '/notifications', 'all',      3),
  (1, 'Saved Creators', 'bookmark',               'screen', '/saved',         'brands',   4),
  (1, 'Invite & Earn',  'person_add',             'screen', '/invite',        'all',      5),
  (1, 'Help & Support', 'help',                   'screen', '/support',       'all',      6),
  (1, 'Edit Profile',   'person',                 'screen', '/profile/edit',  'all',      7)
ON DUPLICATE KEY UPDATE
  `icon`       = VALUES(`icon`),
  `link_value` = VALUES(`link_value`),
  `sort_order` = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Homepage sections mirroring the reference Home screen
-- ---------------------------------------------------------------------
INSERT INTO `homepage_sections` (`title`, `subtitle`, `section_type`, `data_source`, `item_limit`, `layout`, `cta_label`, `cta_action`, `audience`, `sort_order`) VALUES
  ('Featured',            NULL,                          'banner_carousel', 'featured',  5, 'full',     NULL,       NULL,          'all', 1),
  ('Top Categories',      NULL,                          'category_strip',  'featured', 10, 'carousel', 'View all', '/categories', 'all', 2),
  ('Top Creators For You','Handpicked for your brand',   'creator_grid',    'top_rated', 8, 'grid_2',   'View all', '/explore',    'all', 3),
  ('Trending Now',        'Most booked this week',       'creator_carousel','trending',  8, 'carousel', 'View all', '/explore',    'all', 4),
  ('New on Zusty',        'Fresh talent worth watching', 'creator_carousel','newest',    8, 'carousel', 'View all', '/explore',    'all', 5)
ON DUPLICATE KEY UPDATE `subtitle` = VALUES(`subtitle`), `sort_order` = VALUES(`sort_order`);
