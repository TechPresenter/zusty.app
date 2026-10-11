-- ---------------------------------------------------------------------
-- 028 — CMS remainder: a few extra contact-form and newsletter rows
--
-- This file used to insert its own media (1401-1436), coupons (1501-1512),
-- coupon redemptions, popups (1516-1520) and support tickets (1601-1616).
-- 026 seeds those tables in full under the same ids, so whichever file ran
-- first won: a fresh install showed 026's rows while an older database
-- kept these, and these tickets' messages were attached to 026's tickets.
-- 026 is now the only source of them; this file adds only the rows below.
--
-- Rows
--   contact_messages      6 (auto id, keyed on email + subject)
--   newsletter_subscribers 8 (auto id, keyed on email)
--
-- Every address is on a reserved domain (.example, example.com) and every
-- phone follows the demo pattern, so nothing here can reach a real person.
-- Every statement is re-runnable.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Contact form and newsletter
-- ---------------------------------------------------------------------
INSERT INTO `contact_messages`
  (`name`, `email`, `phone`, `subject`, `message`, `source`, `status`, `created_at`)
SELECT * FROM (
  SELECT 'Meera Iyer' AS n,'meera@lumehomes.example' AS e,'9000001901' AS p,'Partnership enquiry' AS s,'We run a home decor brand in Bengaluru and would like to understand enterprise pricing for booking twenty creators a quarter.' AS m,'web' AS src,'replied' AS st,DATE_SUB(NOW(), INTERVAL 34 DAY) AS c UNION ALL
  SELECT 'Arjun Rao','arjun.rao@finlypay.example','9000001902','API access','Do you offer an API so we can pull booking status into our internal dashboard?','web','read',DATE_SUB(NOW(), INTERVAL 19 DAY) UNION ALL
  SELECT 'Sana Qureshi','sana@thecraftedit.example','9000001903','Creator onboarding','I manage six creators and would like to onboard them together rather than one by one.','app','new',DATE_SUB(NOW(), INTERVAL 6 DAY) UNION ALL
  SELECT 'Vikram Nair','vikram@nairmedia.example',NULL,'Press enquiry','Writing a piece on India''s creator economy and would like a comment on rate transparency.','web','replied',DATE_SUB(NOW(), INTERVAL 52 DAY) UNION ALL
  SELECT 'Priyanka Das','priyanka.das@zephyrtea.example','9000001905','Invoice query','Can we receive a consolidated monthly invoice instead of one per booking?','web','new',DATE_SUB(NOW(), INTERVAL 3 DAY) UNION ALL
  SELECT 'Growth Team','offers@seo-backlinks-cheap.example','','Boost your rankings','Buy 5000 backlinks today for page one placement in a week.','web','spam',DATE_SUB(NOW(), INTERVAL 11 DAY)
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `contact_messages` cm WHERE cm.`email` = seed.e AND cm.`subject` = seed.s
);

INSERT INTO `newsletter_subscribers` (`email`, `name`, `source`, `status`, `unsubscribe_token`, `subscribed_at`)
SELECT * FROM (
  SELECT 'meera@lumehomes.example' AS e,'Meera Iyer' AS n,'web' AS s,'subscribed' AS st,MD5('nl-1') AS t,DATE_SUB(NOW(), INTERVAL 120 DAY) AS c UNION ALL
  SELECT 'arjun.rao@finlypay.example','Arjun Rao','web','subscribed',MD5('nl-2'),DATE_SUB(NOW(), INTERVAL 98 DAY) UNION ALL
  SELECT 'sana@thecraftedit.example','Sana Qureshi','app','subscribed',MD5('nl-3'),DATE_SUB(NOW(), INTERVAL 76 DAY) UNION ALL
  SELECT 'vikram@nairmedia.example','Vikram Nair','web','unsubscribed',MD5('nl-4'),DATE_SUB(NOW(), INTERVAL 210 DAY) UNION ALL
  SELECT 'priyanka.das@zephyrtea.example','Priyanka Das','web','subscribed',MD5('nl-5'),DATE_SUB(NOW(), INTERVAL 41 DAY) UNION ALL
  SELECT 'rohit.kulkarni@example.com','Rohit Kulkarni','app','subscribed',MD5('nl-6'),DATE_SUB(NOW(), INTERVAL 33 DAY) UNION ALL
  SELECT 'anita.pillai@example.com','Anita Pillai','import','bounced',MD5('nl-7'),DATE_SUB(NOW(), INTERVAL 165 DAY) UNION ALL
  SELECT 'devang.shah@example.com','Devang Shah','web','subscribed',MD5('nl-8'),DATE_SUB(NOW(), INTERVAL 22 DAY)
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `newsletter_subscribers` ns WHERE ns.`email` = seed.e
);
