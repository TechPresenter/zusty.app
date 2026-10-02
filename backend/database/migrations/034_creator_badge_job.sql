-- Register the creator badge maintenance job.
--
-- Migration 029 built the badge tables; this session wired them to a service
-- and an admin screen. The nightly half — retiring badges whose validity has
-- run out, and re-evaluating the automatic ones against the data they are
-- derived from — needs a row here or the admin System > Jobs screen will not
-- list it and an operator will never know to schedule it.
--
-- Runs at 00:45, after creators:refresh at 00:30, because `top_rated` and
-- `trusted_creator` are decided from the rating and collaboration counts that
-- job recomputes. Reversing the order would judge today's badges on
-- yesterday's numbers.

INSERT INTO `scheduled_jobs` (`job_key`, `name`, `description`, `schedule_hint`, `is_enabled`)
VALUES (
    'creators:badges',
    'Creator verification badges',
    'Expires lapsed badges and re-evaluates the automatic ones (email, mobile, identity, KYC, social, top rated, trusted).',
    '45 0 * * *',
    1
)
ON DUPLICATE KEY UPDATE
    `name`          = VALUES(`name`),
    `description`   = VALUES(`description`),
    `schedule_hint` = VALUES(`schedule_hint`);

-- The catalogue seeded in 029 points `identity_verified` at
-- kyc_profiles.is_identity_verified. That column has never existed there — the
-- flag lives on `users`, which is what the KYC module actually sets and what
-- CreatorBadgeService reads. Correcting the metadata so the documented source
-- and the implemented rule agree.
UPDATE `taxonomy_terms` t
  JOIN `taxonomies` x ON x.`id` = t.`taxonomy_id`
   SET t.`metadata` = '{"auto":true,"source":"users.is_identity_verified","expires_days":null}'
 WHERE x.`key` = 'verification_badge'
   AND t.`value` = 'identity_verified';
