-- =====================================================================
-- Zusty Influencer Marketplace — Seed 005
-- Integration hub permissions, granted to the roles that already administer
-- settings.
--
-- The integration catalogue itself is declared in app/Admin/Integration.php,
-- so nothing about the services is seeded here. Credentials already stored in
-- `settings` are adopted by the hub on first save rather than copied now — a
-- copy would immediately drift from the settings the running platform reads.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO `permissions` (`group_name`, `name`, `slug`, `description`, `sort_order`) VALUES
  ('Integrations', 'View integrations',   'integrations.view',
   'See the integrations hub, connection status and activity logs.', 1),
  ('Integrations', 'Manage integrations', 'integrations.manage',
   'Add credentials, enable services, rotate webhook secrets and run syncs.', 2)
ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `description` = VALUES(`description`),
  `group_name`  = VALUES(`group_name`);

-- Grant to every role that can already reach Settings, so no role gains a
-- capability it did not effectively have.
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
  FROM `roles` r
  JOIN `permissions` p ON p.`slug` = 'integrations.view'
 WHERE r.`id` IN (SELECT rp.`role_id` FROM `role_permissions` rp
                    JOIN `permissions` sp ON sp.`id` = rp.`permission_id`
                   WHERE sp.`slug` = 'settings.view')
ON DUPLICATE KEY UPDATE `role_id` = `role_permissions`.`role_id`;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.`id`, p.`id`
  FROM `roles` r
  JOIN `permissions` p ON p.`slug` = 'integrations.manage'
 WHERE r.`id` IN (SELECT rp.`role_id` FROM `role_permissions` rp
                    JOIN `permissions` sp ON sp.`id` = rp.`permission_id`
                   WHERE sp.`slug` = 'settings.manage')
ON DUPLICATE KEY UPDATE `role_id` = `role_permissions`.`role_id`;

-- The auto-sync job. Without this row the job runs from cron but never appears
-- under System → Jobs, so it cannot be inspected, run manually or disabled from
-- the panel — and a silently absent job is exactly the kind an operator only
-- discovers once a sync has been failing for a week.
INSERT INTO `scheduled_jobs` (`job_key`, `name`, `description`, `schedule_hint`, `is_enabled`) VALUES
  ('integrations:sync', 'Sync integrations',
   'Runs every enabled integration whose auto-sync interval is due.', '*/30 * * * *', 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`), `schedule_hint` = VALUES(`schedule_hint`);
