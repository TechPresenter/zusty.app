-- ---------------------------------------------------------------------
-- Onboarding slides and serviceable cities.
--
-- Both were hardcoded in the Flutter source; they are now admin-managed.
-- ---------------------------------------------------------------------

INSERT INTO `onboarding_slides` (`title`, `subtitle`, `icon`, `audience`, `sort_order`) VALUES
  ('Find the perfect Creator for your brand.',
   'Search creators by city, category and price, and book them in a few taps.',
   'search', 'all', 10),
  ('Verified creators you can trust.',
   'Look for the blue tick: it appears only after our team has checked a creator’s identity.',
   'verified_user', 'all', 20),
  ('Collaborate and get paid safely.',
   'Briefs, offers and payment in one place. The brand’s payment is held by Zusty until the work is approved.',
   'lock', 'all', 30)
ON DUPLICATE KEY UPDATE
  `subtitle`   = VALUES(`subtitle`),
  `icon`       = VALUES(`icon`),
  `sort_order` = VALUES(`sort_order`);

-- Serviceable cities, generated from the previously-hardcoded Dart list.
-- Modelled as a taxonomy so they reuse the existing admin UI, cached
-- bundle and ETag revalidation rather than needing a table of their own.

INSERT INTO `taxonomies` (`key`, `name`, `description`, `is_system`, `allows_multiple`, `sort_order`) VALUES
  ('city', 'Serviceable cities', 'Location selector in the app header and the Location filter.', 0, 0, 35)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

SET @t = (SELECT `id` FROM `taxonomies` WHERE `key` = 'city');
INSERT INTO `taxonomy_terms` (`taxonomy_id`, `value`, `label`, `description`, `metadata`, `sort_order`) VALUES
  (@t, 'Mumbai', 'Mumbai', 'Maharashtra', '{"lat":19.0760,"lng":72.8777,"metro":true}', 10),
  (@t, 'Delhi', 'Delhi', 'Delhi', '{"lat":28.6139,"lng":77.2090,"metro":true}', 20),
  (@t, 'Bengaluru', 'Bengaluru', 'Karnataka', '{"lat":12.9716,"lng":77.5946,"metro":true}', 30),
  (@t, 'Hyderabad', 'Hyderabad', 'Telangana', '{"lat":17.3850,"lng":78.4867,"metro":true}', 40),
  (@t, 'Chennai', 'Chennai', 'Tamil Nadu', '{"lat":13.0827,"lng":80.2707,"metro":true}', 50),
  (@t, 'Kolkata', 'Kolkata', 'West Bengal', '{"lat":22.5726,"lng":88.3639,"metro":true}', 60),
  (@t, 'Pune', 'Pune', 'Maharashtra', '{"lat":18.5204,"lng":73.8567,"metro":true}', 70),
  (@t, 'Ahmedabad', 'Ahmedabad', 'Gujarat', '{"lat":23.0225,"lng":72.5714,"metro":true}', 80),
  (@t, 'Jaipur', 'Jaipur', 'Rajasthan', '{"lat":26.9124,"lng":75.7873,"metro":false}', 90),
  (@t, 'Surat', 'Surat', 'Gujarat', '{"lat":21.1702,"lng":72.8311,"metro":false}', 100),
  (@t, 'Lucknow', 'Lucknow', 'Uttar Pradesh', '{"lat":26.8467,"lng":80.9462,"metro":false}', 110),
  (@t, 'Kanpur', 'Kanpur', 'Uttar Pradesh', '{"lat":26.4499,"lng":80.3319,"metro":false}', 120),
  (@t, 'Nagpur', 'Nagpur', 'Maharashtra', '{"lat":21.1458,"lng":79.0882,"metro":false}', 130),
  (@t, 'Indore', 'Indore', 'Madhya Pradesh', '{"lat":22.7196,"lng":75.8577,"metro":false}', 140),
  (@t, 'Bhopal', 'Bhopal', 'Madhya Pradesh', '{"lat":23.2599,"lng":77.4126,"metro":false}', 150),
  (@t, 'Chandigarh', 'Chandigarh', 'Chandigarh', '{"lat":30.7333,"lng":76.7794,"metro":false}', 160),
  (@t, 'Kochi', 'Kochi', 'Kerala', '{"lat":9.9312,"lng":76.2673,"metro":false}', 170),
  (@t, 'Goa', 'Goa', 'Goa', '{"lat":15.2993,"lng":74.1240,"metro":false}', 180),
  (@t, 'Coimbatore', 'Coimbatore', 'Tamil Nadu', '{"lat":11.0168,"lng":76.9558,"metro":false}', 190),
  (@t, 'Visakhapatnam', 'Visakhapatnam', 'Andhra Pradesh', '{"lat":17.6868,"lng":83.2185,"metro":false}', 200),
  (@t, 'Guwahati', 'Guwahati', 'Assam', '{"lat":26.1445,"lng":91.7362,"metro":false}', 210),
  (@t, 'Bhubaneswar', 'Bhubaneswar', 'Odisha', '{"lat":20.2961,"lng":85.8245,"metro":false}', 220),
  (@t, 'Dehradun', 'Dehradun', 'Uttarakhand', '{"lat":30.3165,"lng":78.0322,"metro":false}', 230),
  (@t, 'Manali', 'Manali', 'Himachal Pradesh', '{"lat":32.2432,"lng":77.1892,"metro":false}', 240),
  (@t, 'Ludhiana', 'Ludhiana', 'Punjab', '{"lat":30.9010,"lng":75.8573,"metro":false}', 250),
  (@t, 'Patna', 'Patna', 'Bihar', '{"lat":25.5941,"lng":85.1376,"metro":false}', 260),
  (@t, 'Vadodara', 'Vadodara', 'Gujarat', '{"lat":22.3072,"lng":73.1812,"metro":false}', 270),
  (@t, 'Mysuru', 'Mysuru', 'Karnataka', '{"lat":12.2958,"lng":76.6394,"metro":false}', 280),
  (@t, 'Nashik', 'Nashik', 'Maharashtra', '{"lat":19.9975,"lng":73.7898,"metro":false}', 290),
  (@t, 'Varanasi', 'Varanasi', 'Uttar Pradesh', '{"lat":25.3176,"lng":82.9739,"metro":false}', 300)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `description` = VALUES(`description`), `metadata` = VALUES(`metadata`), `sort_order` = VALUES(`sort_order`);
