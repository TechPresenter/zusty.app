-- ---------------------------------------------------------------------
-- 031 — The `creator` settings group
--
-- Every rule about a creator profile — which fields are mandatory, how often
-- a username may change, what completion percentage unlocks what — was either
-- absent or buried in code. There was no `creator` or `profile` group in
-- `settings` at all; the twenty that existed were ai, app, auth, booking,
-- branding, cache, commission, discovery, firebase, general, kyc, mail,
-- payment, referral, security, seo, sms, social, upload and wallet.
--
-- These rows make the whole policy operator-editable through the settings
-- screen that already exists, and reachable by the apps through the same
-- Config::setting* helpers every other group uses. Nothing here needs a
-- release to change.
--
-- `is_public` is 1 only where a client genuinely needs the value to render
-- correctly — the completion thresholds and the editable-field list. Rules an
-- operator sets for their own governance stay private.
-- ---------------------------------------------------------------------

INSERT INTO `settings`
  (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`, `updated_at`)
VALUES

-- --- Completion -------------------------------------------------------
('creator', 'required_completion_percent', '60', 'integer',
 'Minimum profile completion',
 'A creator must reach this percentage before their profile can be submitted for review.',
 1, 0, 10, NOW()),

('creator', 'completion_weights',
 '{"basic":15,"contact":10,"social":15,"portfolio":15,"kyc":15,"bio":5,"gallery":5,"pricing":10,"categories":5,"verification":5}',
 'json',
 'Profile completion weights',
 'How much each section contributes to the completion percentage. The values should total 100; anything else is scaled proportionally.',
 1, 0, 20, NOW()),

('creator', 'feature_unlock_thresholds',
 '{"accept_bookings":60,"appear_in_search":70,"apply_to_campaigns":75,"featured_eligible":90}',
 'json',
 'Feature unlock thresholds',
 'The completion percentage a creator must reach before each capability becomes available.',
 1, 0, 30, NOW()),

-- --- Mandatory and editable fields ------------------------------------
('creator', 'mandatory_fields',
 'display_name,handle,headline,about,primary_category_id,city,languages',
 'string',
 'Mandatory profile fields',
 'Comma-separated column names a creator must fill before submitting for review.',
 1, 0, 40, NOW()),

('creator', 'locked_fields', '', 'string',
 'Globally non-editable fields',
 'Comma-separated fields no creator may edit, whatever their individual section locks say. Leave empty to allow all.',
 1, 0, 50, NOW()),

('creator', 'required_verifications', 'email_verified,mobile_verified', 'string',
 'Required verifications',
 'Badge slugs a creator must hold before their profile is approved. Values come from the verification_badge taxonomy.',
 0, 0, 60, NOW()),

-- --- Username ---------------------------------------------------------
('creator', 'username_change_limit', '3', 'integer',
 'Username changes allowed',
 'How many times a creator may change their username. Zero locks it after signup; leave high to allow freely.',
 1, 0, 70, NOW()),

('creator', 'username_change_cooldown_days', '30', 'integer',
 'Days between username changes',
 'A creator must wait this long after changing their username before changing it again.',
 1, 0, 80, NOW()),

('creator', 'username_min_length', '3', 'integer',
 'Minimum username length', 'Shorter handles are reserved.', 1, 0, 90, NOW()),

-- --- Social -----------------------------------------------------------
('creator', 'min_social_accounts', '1', 'integer',
 'Minimum social accounts',
 'How many linked social accounts a creator needs before the social section counts as complete.',
 1, 0, 100, NOW()),

('creator', 'max_social_accounts', '20', 'integer',
 'Maximum social accounts', 'An upper bound, mostly to stop link-stuffing.', 1, 0, 110, NOW()),

('creator', 'require_verified_social', '0', 'boolean',
 'Require a verified social account',
 'When on, at least one linked account must be verified on its own platform before approval.',
 0, 0, 120, NOW()),

-- --- Portfolio and gallery -------------------------------------------
('creator', 'min_portfolio_items', '3', 'integer',
 'Minimum portfolio items',
 'How many pieces of work a creator must publish before the portfolio section counts as complete.',
 1, 0, 130, NOW()),

-- Migration 057. Each item is a stored file, so there is a ceiling.
('creator', 'max_portfolio_items', '100', 'integer',
 'Maximum portfolio items',
 'The most pieces of work one creator may keep in their portfolio, items still in review included. Each one is a stored file. Zero means no limit.',
 1, 0, 135, NOW()),

('creator', 'require_portfolio', '1', 'boolean',
 'Portfolio required', 'When on, a profile cannot be approved without a portfolio.', 0, 0, 140, NOW()),

('creator', 'moderate_gallery_uploads', '1', 'boolean',
 'Moderate gallery uploads',
 'When on, uploaded gallery media is held for admin review before it appears publicly.',
 0, 0, 150, NOW()),

-- --- Contact ----------------------------------------------------------
('creator', 'allow_email_change', '1', 'boolean',
 'Creators may change their email', 'Turning this off locks the email for every creator.', 0, 0, 160, NOW()),

('creator', 'allow_mobile_change', '1', 'boolean',
 'Creators may change their mobile number', 'Turning this off locks the number for every creator.', 0, 0, 170, NOW()),

('creator', 'otp_verification_enabled', '1', 'boolean',
 'Require OTP for contact changes',
 'When on, a changed email or mobile number must be confirmed by one-time code before it takes effect.',
 0, 0, 180, NOW()),

('creator', 'allow_alternate_contacts', '1', 'boolean',
 'Allow alternate email and mobile',
 'Lets a creator record a second address and number for recovery and payouts.',
 1, 0, 190, NOW()),

-- --- Approval and visibility -----------------------------------------
-- Defaults ON. CreatorController documented this behaviour in a comment and a
-- bug stopped it working, so an approved creator could rewrite their name and
-- bio unseen. Restoring the intent means defaulting to on; an operator running
-- an open marketplace can switch it off.
('creator', 'approve_profile_edits', '1', 'boolean',
 'Review profile edits before they go live',
 'When on, an edit to a reviewed field on an approved profile sends it back to pending review instead of publishing immediately.',
 0, 0, 200, NOW()),

('creator', 'reviewed_sections', 'display_name,handle,headline,about,price_min,price_max', 'string',
 'Sections that need review',
 'Only edits to these fields are held when review is on. Everything else publishes immediately.',
 0, 0, 210, NOW()),

('creator', 'default_profile_visibility', 'public', 'string',
 'Default profile visibility',
 'Whether a newly approved profile is public or private until the creator chooses.',
 1, 0, 220, NOW()),

-- --- Badge and featured rules ----------------------------------------
('creator', 'top_rated_min_rating', '4.50', 'decimal',
 'Top Rated: minimum rating', 'The average rating needed to hold the Top Rated badge.', 0, 0, 230, NOW()),

('creator', 'top_rated_min_reviews', '10', 'integer',
 'Top Rated: minimum reviews', 'How many reviews the average must be drawn from.', 0, 0, 240, NOW()),

('creator', 'trusted_min_collabs', '25', 'integer',
 'Trusted: minimum completed bookings', 'Completed bookings needed to hold the Trusted Creator badge.', 0, 0, 250, NOW()),

('creator', 'featured_max_slots', '12', 'integer',
 'Featured creator slots', 'How many creators may be featured at once. Zero means unlimited.', 0, 0, 260, NOW()),

('creator', 'featured_duration_days', '30', 'integer',
 'Featured duration', 'How long a featured placement lasts before it lapses.', 0, 0, 270, NOW())

ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `type`        = VALUES(`type`),
  `is_public`   = VALUES(`is_public`),
  `sort_order`  = VALUES(`sort_order`);
