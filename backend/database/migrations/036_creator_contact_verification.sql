-- ---------------------------------------------------------------------
-- 036 — Contact verification and change control
--
-- Everything below was checked against the live schema and the live code
-- before a line of it was written, because most of what the brief asks for
-- turned out to exist already. What exists is listed first, so the next
-- person does not rebuild it either.
--
--   * `otp_verifications` is a complete one-time-code engine. It carries
--     user_id, channel ENUM(sms,email), destination, purpose, code_hash,
--     attempts, max_attempts, expires_at, verified_at, ip_address and
--     created_at, and AuthService::sendOtp()/verifyOtp() already expire the
--     previous live code, count wrong entries against max_attempts and stamp
--     verified_at. NOTHING here duplicates that. The single change made to it
--     is four new members on the `purpose` ENUM, because that ENUM is the only
--     thing standing between the existing engine and these flows: under
--     STRICT_TRANS_TABLES an out-of-enum insert is a hard error, so today a
--     change-of-email code cannot be issued at all.
--
--   * `creator_profile_locks` already holds a 'contact' section with a UNIQUE
--     key on (creator_id, section), and CreatorProfileLockService is already
--     wired into the creator write paths through assertUnlocked(). The admin
--     lock on contact changes is therefore built and enforced. No parallel
--     lock table appears below — see note 5 for the one residual gap and why
--     it is a note rather than a table.
--
--   * settings already carry creator.allow_email_change,
--     creator.allow_mobile_change, creator.otp_verification_enabled and
--     creator.allow_alternate_contacts (seeds/031_creator_profile_settings).
--     The brief asks for an admin switch to enable or disable OTP
--     verification; that switch is a row that already exists, not new schema.
--
--   * auth.otp_resend_seconds and auth.otp_max_resends already throttle
--     re-sends, so no per-row resend ceiling is stored on the request below.
--     A ceiling copied onto every row would freeze at creation time and drift
--     away from the setting the operator actually edits.
--
-- Four things are genuinely missing. Each is closed once, below.
--
-- 1. A CHANGE CANNOT BE STAGED, so it cannot be made conditional on proof.
--    AccountController::updateProfile() (lines 77-100) writes a new email or
--    phone straight onto `users` and sets email_verified_at / phone_verified_at
--    to NULL in the same UPDATE. A typo therefore costs the account both its
--    verified address AND its route back — the code that would fix it goes to
--    the address that does not exist — and `users` is left asserting an
--    identifier nobody has proved they hold. It behaves that way because there
--    was nowhere to park a pending value; the only writable place was the live
--    column.
--
--    `contact_change_requests` is that place. The new value lives there,
--    `users` is not touched at all until the row reaches status='applied', and
--    'applied' is reachable only from 'verified'. verified_at and applied_at
--    are separate columns rather than one, because "the holder proved it" and
--    "the account now uses it" are two different facts, and an admin looking
--    at a stalled change needs to see which of the two happened. The
--    consequence worth stating plainly: after this, a completed change leaves
--    email_verified_at SET, not NULL, because the new address was proved
--    before it landed. Re-verifying after a change stops being a second chore
--    and becomes the precondition of the change.
--
-- 2. NO ALTERNATE CONTACTS. `users` holds exactly one email and one phone and
--    there is no second slot anywhere in the schema. `user_contact_points`
--    adds them — see the table for why it is a side table rather than two more
--    columns on `users`.
--
-- 3. NO VERIFICATION HISTORY, and `otp_verifications` cannot become it:
--    CronRunner::purge() runs
--        DELETE FROM otp_verifications WHERE expires_at < NOW() - INTERVAL 7 DAY
--    every night, so that table is a seven-day window by construction. An
--    admin asking who changed this address, when, and on whose authority needs
--    an answer older than a week.
--
--    The three existing audit trails were each considered and each rejected on
--    the same ground — the row they hang off does not exist for this subject.
--    `kyc_events`.profile_id is NOT NULL against kyc_profiles, and a user who
--    never started KYC has no profile. `creator_badge_events`.creator_id is
--    NOT NULL against creators, and a brand user has no creator row, yet
--    brands verify email and phone exactly like creators do. `login_history`
--    records sign-in attempts, a different subject entirely, and has no notion
--    of a value changing from one thing into another.
--
--    So `contact_verification_events` is keyed to users(id), and its columns
--    are kyc_events vocabulary verbatim — actor_type / actor_id / actor_name /
--    action / from_status / to_status / note / metadata / ip_address /
--    user_agent / created_at. An operator who has read one audit trail on this
--    platform should not have to learn a second vocabulary to read this one.
--
--    It is deliberately NOT backfilled. Around two thousand accounts carry a
--    non-null email_verified_at and nobody recorded how or when those were
--    proved; inventing rows that claim otherwise would put fabricated entries
--    in the one table whose entire value is that it can be trusted. The log
--    starts empty and starts telling the truth from today.
--
-- 4. TWO CATALOGUES WERE ABOUT TO BE HARDCODED. What an alternate contact is
--    FOR (work, billing, recovery, WhatsApp) and WHY someone is changing a
--    contact (lost access, typo, suspected compromise) are both lists an
--    operator will want to extend, and neither has any code behind it. Both
--    become taxonomies, served through /app/taxonomies with the rest.
--
--    The ENUMs that remain below are deliberate and are not catalogues.
--    `channel` has exactly two members and each is a different transport with
--    different code behind it, SmsService against the mail queue. `target`
--    distinguishes the primary identifier on `users` from a row in the side
--    table, which is a structural fact rather than a list. `status` is a state
--    machine whose every transition is written in the application. An operator
--    cannot usefully invent a third transport or a sixth status. Where the
--    vocabulary genuinely may grow without ceremony — `verified_via` — the
--    column is a VARCHAR and the application owns the words, matching the
--    reasoning already applied to creator_profile_locks.section in 029.
--
-- 5. The one residual gap, recorded rather than papered over: locks are keyed
--    to creators(id), so an administrator can freeze a creator contact section
--    but not a brand user one. Building a second, user-keyed lock table to
--    cover that would leave two mechanisms answering "is this locked", and two
--    answers is how a lock silently stops being enforced. The chokepoint used
--    instead is the request itself — a pending change can be moved to
--    status='rejected' by an admin, recorded with a reviewer and a note. If
--    per-user locks are ever wanted, the right move is to widen
--    creator_profile_locks, not to add a rival to it.
--
--    Note also that 'contact' is one section covering both email and mobile,
--    while the brief asks to lock them separately. That needs no migration
--    either way: `creator_profile_locks`.`section` is a VARCHAR, and the
--    vocabulary lives in CreatorProfileLockService::SECTIONS today and in the
--    `profile_section` taxonomy once 038 lands. So splitting it into
--    'contact_email' and 'contact_mobile' is two lines of PHP, or after 038
--    two rows an operator types in — not a schema change in either case.
--
--    038 is worth reading alongside this file. Its `profile_section` term for
--    'contact' carries {"requires_review":false,"verified_by":"otp"}, which
--    says the same thing from the other side: a contact change is not gated by
--    an administrator reviewing it, it is gated by the holder of the new value
--    proving it. That gate is `contact_change_requests` below. The two tables
--    have deliberately similar names and entirely different jobs —
--    creator_change_requests holds profile edits awaiting a human, this holds
--    identifier changes awaiting proof — and a contact change should travel
--    through this one only.
--
-- Every statement is additive and safe to re-run: nothing is dropped, nothing
-- is renamed, no existing row is rewritten.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4;


-- =====================================================================
-- 1. The two catalogues, as admin-managed taxonomies
-- =====================================================================

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('contact_label', 'Contact labels',
        'What an additional email address or phone number is for. Offered when someone adds an alternate contact. Add a row to offer a new kind.',
        1, 0, 1, 210, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- `metadata.channels` tells the client which labels belong to which kind of
-- contact, so WhatsApp is never offered as a label for an email address. The
-- app reads that list; it does not know the words themselves.
INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`metadata`, v.`is_default`, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'personal' AS `value`, 'Personal' AS `label`,
           'A private address or number, away from work.' AS `description`,
           'person' AS `icon`, '#0891B2' AS `color_hex`,
           '{"channels":["email","phone"]}' AS `metadata`,
           1 AS `is_default`, 10 AS `sort_order` UNION ALL
    SELECT 'work',     'Work',              'A workplace address or desk number.',
           'briefcase', '#334155', '{"channels":["email","phone"]}',                       0,  20 UNION ALL
    SELECT 'billing',  'Billing',           'Where invoices and payout notices should go.',
           'receipt',   '#B45309', '{"channels":["email"]}',                               0,  30 UNION ALL
    SELECT 'recovery', 'Recovery',          'Used only to get back into a locked-out account.',
           'shield',    '#059669', '{"channels":["email","phone"]}',                       0,  40 UNION ALL
    SELECT 'whatsapp', 'WhatsApp',          'A number reachable on WhatsApp.',
           'chat',      '#25D366', '{"channels":["phone"]}',                               0,  50 UNION ALL
    SELECT 'manager',  'Manager or agency', 'Whoever handles bookings on their behalf.',
           'users',     '#7C3AED', '{"channels":["email","phone"]}',                       0,  60 UNION ALL
    SELECT 'family',   'Family',            'A relative reachable when the creator is not.',
           'home',      '#DB2777', '{"channels":["phone"]}',                               0,  70 UNION ALL
    SELECT 'other',    'Other',             'Anything the list above does not cover.',
           'link',      '#64748B', '{"channels":["email","phone"],"requires_note":true}',  0,  80
  ) v
 WHERE t.`key` = 'contact_label'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`),
  `metadata`    = VALUES(`metadata`);


INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `is_active`, `sort_order`, `created_at`, `updated_at`)
VALUES ('contact_change_reason', 'Contact change reasons',
        'Why someone is changing their email or mobile number. Stored on the request and shown in the verification log.',
        1, 0, 1, 220, NOW(), NOW())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- `metadata.notify_admin` marks a reason that should raise a flag rather than
-- pass quietly. A suspected compromise is a security event wearing the clothes
-- of a profile edit, and an operator should hear about it the same day — but
-- WHICH reasons deserve that is an operator judgement, so it lives in the term
-- and not in a condition somebody has to redeploy to change.
-- `metadata.requires_note` forces the free-text box open for the vague ones.
INSERT INTO `taxonomy_terms`
  (`taxonomy_id`, `value`, `label`, `description`, `icon`, `color_hex`, `metadata`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT t.`id`, v.`value`, v.`label`, v.`description`, v.`icon`, v.`color_hex`, v.`metadata`, 0, 1, v.`sort_order`, NOW(), NOW()
  FROM `taxonomies` t
  JOIN (
    SELECT 'lost_access' AS `value`, 'I have lost access to it' AS `label`,
           'The old address or number can no longer receive a code.' AS `description`,
           'alert' AS `icon`, '#B45309' AS `color_hex`,
           '{"notify_admin":false,"requires_note":false}' AS `metadata`, 10 AS `sort_order` UNION ALL
    SELECT 'mistyped',    'It was entered incorrectly',  'A typo at sign-up that was never corrected.',
           'edit',   '#64748B', '{"notify_admin":false,"requires_note":false}', 20 UNION ALL
    SELECT 'new_number',  'I changed my number',         'A new SIM, a new operator, or a ported number.',
           'phone',  '#0891B2', '{"notify_admin":false,"requires_note":false}', 30 UNION ALL
    SELECT 'new_email',   'I changed my email provider', 'Moving to a different mailbox.',
           'mail',   '#0891B2', '{"notify_admin":false,"requires_note":false}', 40 UNION ALL
    SELECT 'changed_job', 'I changed employer',          'A work address that is no longer theirs.',
           'work',   '#334155', '{"notify_admin":false,"requires_note":false}', 50 UNION ALL
    SELECT 'compromised', 'I think it was compromised',  'Someone else may have had access to it.',
           'shield', '#DC2626', '{"notify_admin":true,"requires_note":true}',   60 UNION ALL
    SELECT 'other',       'Another reason',              'Anything the list above does not cover.',
           'link',   '#64748B', '{"notify_admin":false,"requires_note":true}',  70
  ) v
 WHERE t.`key` = 'contact_change_reason'
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `icon`        = VALUES(`icon`),
  `color_hex`   = VALUES(`color_hex`),
  `metadata`    = VALUES(`metadata`);


-- =====================================================================
-- 2. Teach the existing OTP engine the four flows it cannot express
-- =====================================================================
--
-- Adding members to an ENUM rewrites no rows and invalidates no existing
-- value, so the eight codes currently in the table are untouched and every
-- caller keeps working.
--
-- Four new purposes rather than one shared 'change_contact', because the
-- lookup in AuthService::verifyOtp() is by (destination, purpose) and nothing
-- else. If a change of primary email and the verification of an alternate
-- email shared a purpose, a code issued to prove one could be redeemed against
-- the other for the same address. The purpose IS the scope of the code, so it
-- has to name both the channel and the slot.
--
-- The new index exists for the admin verification screen. `idx_otp_user`
-- covers user_id alone, which finds the rows but then sorts them; the log is
-- always read newest-first for one account, and usually filtered to one flow.

ALTER TABLE `otp_verifications`
  MODIFY COLUMN `purpose` ENUM(
      'register','login','verify_phone','verify_email','reset_password','withdrawal','delete_account',
      'change_email','change_phone','verify_alt_email','verify_alt_phone'
  ) NOT NULL,
  ADD INDEX IF NOT EXISTS `idx_otp_user_purpose` (`user_id`, `purpose`, `created_at`);


-- =====================================================================
-- 3. Alternate contacts
-- =====================================================================
--
-- A side table rather than alternate_email / alternate_phone columns on
-- `users`, for three reasons that all point the same way.
--
--   a) UNIQUENESS. Plain columns on `users` can carry no meaningful unique
--      key, so two accounts could each claim the same alternate address, and
--      the moment anything is allowed to look an account up by it — recovery,
--      support, payouts — the answer is ambiguous. Here the exclusivity is
--      real, and it is scoped precisely: `verified_key` is NULL until the
--      address is proved, so an unverified claim blocks nobody, and the first
--      person to actually prove an address owns it. This is the same generated
--      column plus unique key idiom `users` already uses for email_active and
--      phone_active, for the same reason.
--
--   b) POLICY, NOT SCHEMA, SHOULD SAY HOW MANY. The brief says AN alternate
--      email and AN alternate mobile. Two columns would carve that number
--      into the table, and the day an agency wants three billing addresses it
--      becomes a migration. Here the limit is the setting seeded in section 6,
--      and an operator raises it in the admin screen.
--
--   c) A LABEL NEEDS SOMEWHERE TO LIVE. "Alternate email" says nothing;
--      "billing" says what to do with it. A column pair has no room for that.
--
-- What this table deliberately does NOT do is mirror the primary email and
-- phone. Those stay exactly where they are on `users`, where every existing
-- query, unique key and generated column already expects them. Copying them
-- here would create two sources of truth for the account address, which is
-- precisely the failure this whole migration exists to prevent.
--
-- Removing an alternate deletes the row; presence of the row is the fact, and
-- the history of it lives in contact_verification_events, which survives.

CREATE TABLE IF NOT EXISTS `user_contact_points` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            BIGINT UNSIGNED NOT NULL,
  `channel`            ENUM('email','phone') NOT NULL
                       COMMENT 'Names the identifier, not the transport - otp_verifications.channel says sms because there it names how the code travels',
  -- A `contact_label` term value. NULL is allowed: an operator who empties the
  -- taxonomy must not thereby make it impossible to add a contact at all.
  `label`              VARCHAR(60) NULL,
  -- The address itself; for a phone this is the national number only, split
  -- from its country code exactly as `users` splits phone from
  -- phone_country_code, so the two can be compared without parsing.
  `value`              VARCHAR(191) NOT NULL,
  `phone_country_code` VARCHAR(6) NULL,
  `verified_at`        DATETIME NULL,
  -- Application-owned vocabulary: otp, admin_force, firebase, import. A
  -- VARCHAR because a new proof route should be a deploy, not a migration.
  `verified_via`       VARCHAR(30) NULL,
  -- Revoking a verification sets verified_at back to NULL and writes an event.
  -- There is deliberately no revoked_at column beside it: two columns that can
  -- disagree about whether something is verified is one column too many.
  `is_recovery`        TINYINT(1) NOT NULL DEFAULT 0
                       COMMENT 'May be used to recover the account, not only to reach the person',
  `sort_order`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Set when the owning account is soft-deleted, releasing the address.
  --
  -- Account deletion in this codebase is SOFT: AuthService writes
  -- status='deleted' and deleted_at, and the row stays. Without this column the
  -- unique key below would bind a verified address to a dead account for ever,
  -- so a person who deleted their account could never re-register with their
  -- own email — and no operator could free it from the admin panel.
  --
  -- This is the same problem migration 030 solved for `users`.`email` and
  -- `phone`, and it is solved the same way, so the two behave alike.
  `deleted_at`         DATETIME NULL DEFAULT NULL,
  -- NULL while unproved OR once released, which is what lets the unique key
  -- below bind only live, verified addresses. COALESCE on the country code
  -- matters: CONCAT with a NULL yields NULL, which would silently disable the
  -- constraint for every email row rather than enforce it.
  `verified_key`       VARCHAR(210) GENERATED ALWAYS AS (
                         IF(`verified_at` IS NULL OR `deleted_at` IS NOT NULL,
                            NULL,
                            CONCAT(`channel`, ':', COALESCE(`phone_country_code`, ''), `value`))
                       ) STORED,
  PRIMARY KEY (`id`),
  -- One account cannot list the same address twice, verified or not.
  UNIQUE KEY `uq_ucp_user_value` (`user_id`, `channel`, `value`),
  -- Two accounts cannot both have proved the same address.
  UNIQUE KEY `uq_ucp_verified` (`verified_key`),
  KEY `idx_ucp_user` (`user_id`, `channel`, `sort_order`),
  KEY `idx_ucp_lookup` (`channel`, `value`),
  CONSTRAINT `fk_ucp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 4. Pending contact changes
-- =====================================================================
--
-- The whole point of the table is the gap between `new_value` and `users`.
-- Nothing here is authoritative for the account: reads of the account address
-- go on hitting `users` and `user_contact_points`. A row here is a proposal.
--
-- The state machine, and the rule the application must hold to:
--
--   pending    a code has been sent to new_value and nothing else has happened
--   verified   the holder of new_value proved it; `users` still untouched
--   applied    and only now is `users` (or the contact point) written
--   rejected   an administrator refused it
--   cancelled  the user withdrew it
--   expired    expires_at passed without proof
--
-- 'applied' is reachable only from 'verified'. That is what makes an
-- unverified change unable to overwrite a verified address: there is no path
-- from 'pending' to the `users` row at all.
--
-- `otp_id` is the binding between the proposal and the proof, so the
-- application can insist that the code being redeemed is the code this request
-- issued, rather than any live code that happens to match destination and
-- purpose. It is ON DELETE SET NULL and not CASCADE for a concrete reason:
-- CronRunner::purge() deletes otp_verifications rows a week after they expire,
-- and under CASCADE that nightly job would quietly destroy the change requests
-- themselves — including applied ones, which are the record of what happened.
-- SET NULL lets the proof age out while the request remains legible.
--
-- `attempt_count` counts codes issued against this request. It is not the same
-- counter as otp_verifications.attempts, which counts wrong entries against a
-- single code; both are needed, because five wrong guesses and five fresh
-- codes are different kinds of suspicious. The ceiling is not stored here — it
-- is auth.otp_max_resends in settings, read at the time of the request.

CREATE TABLE IF NOT EXISTS `contact_change_requests` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`                BIGINT UNSIGNED NOT NULL,
  `channel`                ENUM('email','phone') NOT NULL,
  -- Which slot the proved value will land in: the identifier on `users`, or a
  -- row in user_contact_points.
  `target`                 ENUM('primary','alternate') NOT NULL DEFAULT 'primary',
  -- Set when the request edits an alternate that already exists; NULL when it
  -- adds a new alternate, and always NULL for target='primary'.
  `contact_point_id`       BIGINT UNSIGNED NULL,
  -- What the request will displace, snapshotted at request time. Kept because
  -- an admin reading a six-month-old request needs to see what was replaced,
  -- and by then `users` holds only the winner.
  `current_value`          VARCHAR(191) NULL,
  `new_value`              VARCHAR(191) NOT NULL,
  `new_phone_country_code` VARCHAR(6) NULL,
  -- A `contact_change_reason` term value, plus the free text some terms ask
  -- for. Nullable because a reason must never be the thing that stops someone
  -- fixing a wrong address.
  `reason_code`            VARCHAR(60) NULL,
  `reason_note`            VARCHAR(500) NULL,
  `status`                 ENUM('pending','verified','applied','rejected','cancelled','expired') NOT NULL DEFAULT 'pending',
  `otp_id`                 BIGINT UNSIGNED NULL,
  `attempt_count`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at`             DATETIME NOT NULL,
  `verified_at`            DATETIME NULL COMMENT 'The holder of new_value proved it',
  `applied_at`             DATETIME NULL COMMENT 'The account was actually rewritten',
  `closed_at`              DATETIME NULL COMMENT 'Rejected, cancelled or expired at',
  -- kyc_events vocabulary, so the request and its log read alike.
  `actor_type`             ENUM('user','admin','system') NOT NULL DEFAULT 'user',
  `actor_id`               BIGINT UNSIGNED NULL,
  `actor_name`             VARCHAR(150) NULL,
  -- An admin id, matching creator_profile_locks.locked_by, which is likewise
  -- unconstrained: an operator account being removed must not take the record
  -- of their decisions with it.
  `reviewed_by`            INT UNSIGNED NULL,
  `review_note`            VARCHAR(500) NULL,
  `ip_address`             VARBINARY(16) NULL,
  `user_agent`             VARCHAR(255) NULL,
  `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- NULL for everything except a live request, so exactly one change can be in
  -- flight per account per channel per slot. Two competing pending changes to
  -- one address is the race this table was built to remove; leaving it to the
  -- application to remember would reintroduce it. The application should
  -- INSERT ... ON DUPLICATE KEY UPDATE so that starting a new change
  -- supersedes the old one rather than erroring, the same shape
  -- CreatorProfileLockService uses against uq_creator_section_lock.
  `open_key`               VARCHAR(80) GENERATED ALWAYS AS (
                             IF(`status` <> 'pending',
                                NULL,
                                CONCAT(`user_id`, ':', `channel`, ':', `target`))
                           ) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ccr_open` (`open_key`),
  KEY `idx_ccr_user` (`user_id`, `created_at`),
  -- The sweep that expires stale requests reads exactly this pair.
  KEY `idx_ccr_status` (`status`, `expires_at`),
  -- Answers "is anyone already trying to claim this address", which is worth
  -- checking before a code is sent to it.
  KEY `idx_ccr_new_value` (`channel`, `new_value`),
  CONSTRAINT `fk_ccr_user`  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ccr_point` FOREIGN KEY (`contact_point_id`) REFERENCES `user_contact_points` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ccr_otp`   FOREIGN KEY (`otp_id`) REFERENCES `otp_verifications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 5. The verification log
-- =====================================================================
--
-- Columns are kyc_events, verbatim where they apply, plus the three facts this
-- subject has that KYC does not: which channel, which slot, and which address.
--
-- Suggested `action` values, owned by the application exactly as kyc_events
-- owns its own: otp_sent, otp_failed, verified, change_requested,
-- change_applied, change_rejected, change_cancelled, change_expired,
-- alternate_added, alternate_removed, force_verified, verification_revoked,
-- otp_disabled, otp_enabled. VARCHAR, not an ENUM, for the same reason
-- kyc_events.action is a VARCHAR: a new action is a new code path, and the
-- code path should not need a migration to be able to record itself.
--
-- `destination` stores the address in full. It is an audit record and a masked
-- audit record answers no question worth asking; the admin screen may mask it
-- on display, but the row must be able to prove which address was involved.
--
-- ON DELETE CASCADE on user_id, and this is the deliberate departure from the
-- house rule that audit rows survive. Every account that ever signs in will
-- have rows here, so RESTRICT would not protect a record — it would make
-- permanent deletion impossible for every user forever, the exact trap 031
-- called out when it left `wallets` on CASCADE. These rows are also personal
-- data about the deleted person, held for security review rather than as a
-- business record, so erasing them with the account is the correct outcome and
-- not merely the convenient one. The financial trail 031 protects is elsewhere
-- and is untouched by this.

CREATE TABLE IF NOT EXISTS `contact_verification_events` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  -- Set when the event belongs to a change; NULL for a plain verification, an
  -- admin force-verify, or an account-level switch.
  `request_id`  BIGINT UNSIGNED NULL,
  `channel`     ENUM('email','phone') NULL,
  `target`      ENUM('primary','alternate') NULL,
  `destination` VARCHAR(191) NULL,
  `actor_type`  ENUM('user','admin','system') NOT NULL DEFAULT 'system',
  `actor_id`    BIGINT UNSIGNED NULL,
  -- Denormalised so the history survives the actor being deleted, exactly as
  -- kyc_events.actor_name is.
  `actor_name`  VARCHAR(150) NULL,
  `action`      VARCHAR(60) NOT NULL,
  `from_status` VARCHAR(30) NULL,
  `to_status`   VARCHAR(30) NULL,
  `note`        VARCHAR(1000) NULL,
  `metadata`    LONGTEXT NULL,
  `ip_address`  VARBINARY(16) NULL,
  `user_agent`  VARCHAR(255) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cve_user` (`user_id`, `created_at`),
  KEY `idx_cve_action` (`action`, `created_at`),
  KEY `idx_cve_request` (`request_id`),
  -- Finds every account that has touched an address, which is how a support
  -- agent answers "who else has tried to claim this".
  KEY `idx_cve_destination` (`destination`, `created_at`),
  CONSTRAINT `fk_cve_user`    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cve_request` FOREIGN KEY (`request_id`) REFERENCES `contact_change_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
-- 6. The two settings the tables above need and that do not exist yet
-- =====================================================================
--
-- Only two. The switches for allowing an email change, allowing a mobile
-- change, requiring OTP and allowing alternates at all are already seeded
-- under the `creator` group and are not restated here.
--
-- ON DUPLICATE KEY UPDATE deliberately leaves `value` alone. Re-running this
-- migration must not reset a number an operator has since changed; only the
-- label and help text are refreshed.

INSERT INTO `settings`
  (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`, `updated_at`)
VALUES
-- Distinct from security.otp_ttl, which is 300 seconds and governs one code.
-- A request has to outlive several codes, because the first SMS not arriving
-- is the ordinary case, not the exceptional one.
('security', 'contact_change_ttl_minutes', '30', 'integer',
 'Pending contact change lifetime (min)',
 'How long a requested email or mobile change stays open before it expires unverified. It must outlast a single code, since a request may need several.',
 0, 0, 12, NOW()),

-- Sits beside creator.allow_alternate_contacts, where an operator will look
-- for it, although it governs any account and not only creators. Defaults to
-- 1, which is exactly what the brief asks for - an alternate email and an
-- alternate mobile - while leaving the schema able to hold more the day
-- somebody raises it.
('creator', 'max_alternate_contacts_per_channel', '1', 'integer',
 'Alternate contacts allowed',
 'How many additional email addresses, and how many additional mobile numbers, one account may hold. Zero switches alternates off.',
 1, 0, 195, NOW())
ON DUPLICATE KEY UPDATE
  `type`        = VALUES(`type`),
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `is_public`   = VALUES(`is_public`),
  `sort_order`  = VALUES(`sort_order`);
