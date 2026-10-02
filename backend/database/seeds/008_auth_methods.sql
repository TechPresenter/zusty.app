-- =====================================================================
-- Zusty — Seed 008
-- Authentication & Login Integration module.
--
-- What is deliberately NOT here, because it already has a home and a second
-- copy would drift from the first:
--
--   SMS gateway credentials  -> `sms` group        (seed 001)
--   SMTP credentials         -> `mail` group       (seed 001)
--   OTP length and lifetime  -> `security` group   (seed 001)
--   Password minimum length  -> `security` group   (seed 001)
--   Signup-per-role toggles  -> `app` group        (seed 001)
--   Firebase server config   -> `firebase` group   (seed 001)
--   Provider secrets         -> `integrations`.`credentials`, AES-256-GCM
--
-- The `auth` group holds only what has no home: per-method policy, the client
-- half of the Firebase options, and presentation. Every row here is
-- is_public = 0 and is_encrypted = 0 — the group contains no secret by
-- construction, so even a future mistake that dumped the whole group could not
-- leak a credential.
--
-- The ON DUPLICATE KEY tail deliberately omits `value`, so re-running this seed
-- never overwrites what an operator has since configured.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO `settings`
  (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES

  -- 100 · Google. These four are written by the settings_map mirror on
  -- app/Admin/Integration.php whenever google_login is saved, so an operator
  -- edits them in one place and both stores agree.
  ('auth','google_web_client_id','','string','Google web client ID','Mirrored from the Google Sign-In integration. Android also uses it as the serverClientId.',0,0,101),
  ('auth','google_android_client_id','','string','Google Android client ID','Mirrored from the Google Sign-In integration.',0,0,102),
  ('auth','google_ios_client_id','','string','Google iOS client ID','Mirrored from the Google Sign-In integration.',0,0,103),
  ('auth','google_web_redirect_uri','','string','Google web redirect URI','Mirrored from the Google Sign-In integration.',0,0,104),

  -- 200 · Apple. team_id, key_id and the .p8 are NOT mirrored: no client needs
  -- them, and this is the group the public config reads from.
  ('auth','apple_service_id','','string','Apple Service ID','Mirrored from the Sign in with Apple integration.',0,0,201),
  ('auth','apple_redirect_uri','','string','Apple return URL','Mirrored from the Sign in with Apple integration.',0,0,202),

  -- 250 · Facebook
  ('auth','facebook_app_id','','string','Facebook app ID','Mirrored from the Facebook Login integration.',0,0,251),

  -- 300 · Mobile OTP policy. Code length and lifetime stay in `security`.
  ('auth','otp_login_enabled','0','boolean','OTP sign-in issues a session','Off means codes only verify a number; on means a code alone can sign someone in.',0,0,301),
  ('auth','otp_channels','["sms"]','json','OTP channels','Which channels may carry a one-time code.',0,0,302),
  ('auth','otp_default_country_code','+91','string','Default country code','Pre-selected on the phone field.',0,0,303),
  ('auth','otp_allowed_country_codes','["+91"]','json','Allowed country codes','Empty allows every country.',0,0,304),
  ('auth','otp_phone_digits','10','integer','Phone digits','Expected national number length.',0,0,305),
  ('auth','otp_resend_seconds','30','integer','Resend cooldown (s)','How long before the resend button re-enables.',0,0,306),
  ('auth','otp_max_resends','3','integer','Maximum resends','Per verification attempt.',0,0,307),

  -- 400 · Email and password policy
  ('auth','forgot_password_enabled','1','boolean','Password recovery enabled','Off hides the link and refuses the endpoint.',0,0,401),
  ('auth','reset_token_ttl_minutes','60','integer','Reset link lifetime (min)','How long a password-reset link stays valid.',0,0,402),
  ('auth','identifier_mode','email_or_phone','string','Sign-in identifier','email | phone | email_or_phone',0,0,403),

  -- 500 · Guest
  ('auth','guest_browse_routes','["home","explore","creators","search","pages","blogs","faqs"]','json','Guest-browsable areas','What a guest may reach without an account.',0,0,501),
  ('auth','guest_prompt_on','["book","message","favorite","wallet","profile","apply"]','json','Prompt to sign up on','Actions that trigger the create-account sheet.',0,0,502),
  ('auth','guest_session_days','30','integer','Guest session length (days)','How long a guest session stays valid.',0,0,503),
  ('auth','guest_purge_days','90','integer','Purge idle guests after (days)','Unconverted, idle guest rows are soft-deleted past this.',0,0,504),
  ('auth','guest_max_per_ip_per_day','5','integer','Guest sessions per IP per day','Blunts automated guest-account farming.',0,0,505),

  -- 600 · Firebase CLIENT options. The server half (project_id, web_api_key,
  -- service_account_json, storage_bucket) stays in the `firebase` group; these
  -- are the values the mobile SDKs need and that have no home today.
  ('auth','firebase_app_id_android','','string','Firebase app ID (Android)','From the Android app in the Firebase console.',0,0,601),
  ('auth','firebase_app_id_ios','','string','Firebase app ID (iOS)','From the iOS app in the Firebase console.',0,0,602),
  ('auth','firebase_app_id_web','','string','Firebase app ID (Web)','From the web app in the Firebase console.',0,0,603),
  ('auth','firebase_messaging_sender_id','','string','Messaging sender ID','The project number.',0,0,604),
  ('auth','firebase_auth_domain','','string','Auth domain','Usually PROJECT_ID.firebaseapp.com.',0,0,605),
  ('auth','firebase_android_package_name','com.zuste.zuste','string','Android package name','Build-time value, recorded for reference.',0,0,606),
  ('auth','firebase_ios_bundle_id','com.zuste.zuste','string','iOS bundle identifier','Build-time value, recorded for reference.',0,0,607),

  -- 700 · Presentation and flow
  ('auth','method_order','["email_password","mobile_otp","google","apple","facebook","guest"]','json','Sign-in button order','The order the apps render the sign-in options.',0,0,701),
  ('auth','social_divider_label','OR CONTINUE WITH','string','Sign-in divider label','Shown above the social buttons.',0,0,702),
  ('auth','signup_divider_label','OR SIGN UP WITH','string','Sign-up divider label','Shown above the social buttons on sign-up.',0,0,703),
  ('auth','default_signup_role','brand','string','Default sign-up role','brand | creator',0,0,704),
  ('auth','role_choice_visible','1','boolean','Show the role chooser','Off signs everyone up as the default role.',0,0,705),
  ('auth','require_terms_accept','1','boolean','Require accepting the terms','Enforced server-side on registration.',0,0,706),
  ('auth','terms_url','','string','Terms URL','Blank falls back to the site URL plus /terms.',0,0,707),
  ('auth','privacy_url','','string','Privacy URL','Blank falls back to the site URL plus /privacy.',0,0,708)

ON DUPLICATE KEY UPDATE
  `type` = VALUES(`type`), `label` = VALUES(`label`),
  `description` = VALUES(`description`), `is_public` = VALUES(`is_public`),
  `is_encrypted` = VALUES(`is_encrypted`), `sort_order` = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Remove the credential leak this module replaces.
--
-- google_login previously mirrored its web client ID into settings group
-- `social`, and ContentController dumps that entire group to an unauthenticated
-- endpoint. The mirror now targets the `auth` group instead; this deletes the
-- row it used to write.
-- ---------------------------------------------------------------------
DELETE FROM `settings` WHERE `group_key` = 'social' AND `setting_key` = 'google_client_id';

-- ---------------------------------------------------------------------
-- The six sign-in methods.
--
-- `integrations`.`is_enabled` is the single authority for "is this method on".
-- google_login and apple_login may already have rows with credentials, so the
-- ON DUPLICATE tail touches nothing — an existing install keeps its state.
--
-- Defaults reproduce today's behaviour exactly, so seeding changes nothing for
-- anyone already running: everything the API accepts today stays accepted.
-- guest_login is the exception and ships off, because it did not exist before
-- and enabling a new anonymous entry point by default would be wrong.
-- ---------------------------------------------------------------------
INSERT INTO `integrations` (`integration_key`, `is_enabled`, `status`) VALUES
  ('email_password', 1, 'connected'),
  ('mobile_otp',     1, 'connected'),
  ('google_login',   1, 'connected'),
  ('apple_login',    1, 'connected'),
  ('facebook_login', 0, 'disabled'),
  ('guest_login',    0, 'disabled')
ON DUPLICATE KEY UPDATE `integration_key` = `integrations`.`integration_key`;

-- ---------------------------------------------------------------------
-- Permissions
-- ---------------------------------------------------------------------
INSERT INTO `permissions` (`group_name`, `name`, `slug`, `description`, `sort_order`) VALUES
  ('Authentication', 'View login methods',   'auth.view',
   'See which sign-in methods are enabled, their status and their sign-in activity.', 1),
  ('Authentication', 'Manage login methods', 'auth.manage',
   'Enable or disable sign-in methods and store provider credentials.', 2),
  ('Authentication', 'Test login methods',   'auth.test',
   'Run connection tests against Google, Apple, SMS and email providers.', 3)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `group_name` = VALUES(`group_name`);

-- Granted to whoever already administers settings, so no role gains a
-- capability it did not effectively have.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
  FROM `roles` r
  JOIN `permissions` p ON p.`slug` = 'auth.view'
 WHERE r.`id` IN (SELECT rp.`role_id` FROM `role_permissions` rp
                    JOIN `permissions` sp ON sp.`id` = rp.`permission_id`
                   WHERE sp.`slug` = 'settings.view')
ON DUPLICATE KEY UPDATE `role_id` = `role_permissions`.`role_id`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
  FROM `roles` r
  JOIN `permissions` p ON p.`slug` IN ('auth.manage', 'auth.test')
 WHERE r.`id` IN (SELECT rp.`role_id` FROM `role_permissions` rp
                    JOIN `permissions` sp ON sp.`id` = rp.`permission_id`
                   WHERE sp.`slug` = 'settings.manage')
ON DUPLICATE KEY UPDATE `role_id` = `role_permissions`.`role_id`;

-- ---------------------------------------------------------------------
-- The guest purge job. Without this row the job runs from cron but never
-- appears under System → Jobs, so an operator cannot see it, run it or turn
-- it off — and a sweep that has been failing looks exactly like one that works.
-- ---------------------------------------------------------------------
INSERT INTO `scheduled_jobs` (`job_key`, `name`, `description`, `schedule_hint`, `is_enabled`) VALUES
  ('auth:purge-guests', 'Purge stale guest accounts',
   'Soft-deletes guest accounts that never converted and have been idle past the configured retention.',
   '0 4 * * *', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `schedule_hint` = VALUES(`schedule_hint`);
