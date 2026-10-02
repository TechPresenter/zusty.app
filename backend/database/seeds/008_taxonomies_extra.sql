-- ---------------------------------------------------------------------
-- Additional taxonomies discovered while migrating the remaining
-- hardcoded Dart tables.
-- ---------------------------------------------------------------------

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `sort_order`) VALUES
  ('id_proof_type', 'Government ID types', 'Accepted identity documents on creator KYC.', 1, 0, 145)
ON DUPLICATE KEY UPDATE
  `name`        = VALUES(`name`),
  `description` = VALUES(`description`),
  `is_system`   = VALUES(`is_system`);

SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'id_proof_type');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `icon`, `is_default`, `sort_order`) VALUES
  (@t, 'aadhaar',         'Aadhaar',         'badge',          1, 10),
  (@t, 'pan',             'PAN card',        'credit_card',    0, 20),
  (@t, 'passport',        'Passport',        'flight',         0, 30),
  (@t, 'driving_license', 'Driving licence', 'directions_car', 0, 40),
  (@t, 'voter_id',        'Voter ID',        'how_to_vote',    0, 50)
ON DUPLICATE KEY UPDATE
  `label`      = VALUES(`label`),
  `icon`       = VALUES(`icon`),
  `sort_order` = VALUES(`sort_order`);

-- The support ticket list gained a payouts option during the migration.
SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'ticket_category');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `sort_order`) VALUES
  (@t, 'payouts', 'Payout issue', 35)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`);
