-- ---------------------------------------------------------------------
-- 010 — Cashfree settings
--
-- Credentials live in the settings table so operations can rotate keys or
-- flip sandbox → production from Admin → Settings → Payments without a
-- deploy. Secrets are stored encrypted (`is_encrypted = 1`) and are never
-- returned by the public /settings endpoint (`is_public = 0`).
-- ---------------------------------------------------------------------

INSERT INTO `settings`
  (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`)
VALUES
  ('payment','cashfree_enabled','0','boolean','Cashfree enabled','Enable the Cashfree gateway.',1,0,11),
  ('payment','cashfree_app_id','','string','Cashfree app id','Client id issued in the Cashfree dashboard.',1,0,12),
  ('payment','cashfree_secret_key','','secret','Cashfree secret key','Stored encrypted. Also signs webhooks.',0,1,13),
  ('payment','cashfree_mode','sandbox','string','Cashfree mode','sandbox or production.',1,0,14),
  ('payment','cashfree_return_url','','string','Cashfree return URL','Where the hosted checkout sends the payer back.',1,0,15)
ON DUPLICATE KEY UPDATE
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`),
  `type`        = VALUES(`type`),
  `sort_order`  = VALUES(`sort_order`);
