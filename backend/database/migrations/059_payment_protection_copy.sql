-- ---------------------------------------------------------------------
-- 059 - Payment protection, not "escrow"; a named Grievance Officer.
--
-- The operator's decisions (2026-10-10):
--
--   P-ESCROW. Brands' money settles from Cashfree into Zusty's own account,
--   and no regulated escrow or nodal arrangement is confirmed, so nothing a
--   user or Google can read calls it escrow. The copy says what happens:
--   when a booking is confirmed the brand's payment is held by Zusty; it is
--   released to the creator when the brand approves the work, or
--   automatically booking.auto_complete_days after delivery if the brand
--   does not respond; if there is a dispute, our team reviews it before any
--   money is released. Internal names (bookings.escrow_status, the
--   escrow_hold and escrow_release ledger categories, the escrow:release
--   job key) stay as they are.
--
--   P-GRIEVANCE. The privacy policy names the "Grievance Officer,
--   Appsgain Technologies Private Limited" at support@zusty.app (a
--   designation, no personal name, address or phone), who acknowledges a
--   complaint within 24 hours and resolves it within 15 days (IT Rules
--   2021, rule 3(2)) and takes data-protection requests under the DPDP Act.
--   The terms and the contact page point to it.
--
--   1. CMS pages, edited in place (an operator's rewrite of a passage is
--      left alone, because each REPLACE matches the seeded wording only):
--        privacy               section 2 says what the code keeps of a
--                              payment (Cashfree's references, amount,
--                              status and method type; card details, UPI ID
--                              and contact details removed, PaymentService::
--                              scrubGatewayResponse), that the wallet also
--                              holds referral rewards (ReferralService), and
--                              that a checkout phone goes to Cashfree with
--                              that order only, with request logs keeping
--                              its last four digits (ApiLogService); section
--                              5 says "on hold" for maturing earnings;
--                              section 8 names the Grievance Officer.
--        terms                 section 5 "payment protection"; section 6
--                              "held by Zusty" plus the dispute review;
--                              section 17 points to the Grievance Officer.
--        refund-policy         "Payment protection" heading and subtitle,
--                              disputes keep the money "held by Zusty".
--        about                 payment protection, and the review window.
--        community-guidelines  "payment protection".
--        contact               a Complaints item for the Grievance Officer.
--      Each page whose content changes gets its version bumped once.
--   2. FAQs: how Zusty works, payment security and non-delivery.
--   3. Notification templates: payment.success, campaign.hired and
--      booking.disputed (body, and the dormant SMS text).
--   4. The first home banner and the third onboarding slide.
--   5. Admin-facing labels: the escrow:release job's display name, and the
--      booking timeline notes BookingService writes (the app shows those).
--   6. Two settings that still point at the old names:
--        app.android_store_url    the Play listing of com.zuste.zuste,
--                                 when it is empty or names com.zuste.app;
--        referral.share_base_url  https://zusty.app/invite/, when it is on
--                                 zuste.app or zustee.com. The app builds
--                                 share links as this + "/" + the code, and
--                                 the website serves them at /invite/{code}.
--      social.* and seo.twitter_handle are the company's own; untouched.
--   7. The switched-off sample testimonial that opened "Escrow changed...".
--   8. A one-time scrub of stored Cashfree replies (payments and
--      payment_webhooks): the payer's phone in the app/wallet, pay_later
--      and cardless_emi objects, and the card / EMI card objects, so the
--      privacy policy's "card details, UPI ID and contact details taken
--      out" holds for every method. PaymentService drops them from new
--      replies.
--
-- booking.auto_complete_days is read once, here, and printed into the
-- about page (as 053 does for the terms, refund policy and FAQ).
--
-- Re-runnable: every statement is limited to rows that still hold the old
-- wording or value, and none of the new wording matches again, so a second
-- run changes nothing and bumps no version. On a fresh install the tables
-- are empty when this runs and the seeds (001, 002, 009, 029, 030) carry
-- the same copy. Valid on MariaDB 10.4 and 11.8. In SQL source the regex
-- escapes are doubled: '\\.' reaches the server as \. (a literal dot).
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- The review window, as the auto-complete job applies it (never under 1).
SET @z059_days := GREATEST(1, COALESCE(
    (SELECT CAST(`value` AS UNSIGNED) FROM `settings`
      WHERE `group_key` = 'booking' AND `setting_key` = 'auto_complete_days' LIMIT 1),
    7));
SET @z059_window := IF(@z059_days = 1, '1 day', CONCAT(@z059_days, ' days'));

-- ---------------------------------------------------------------------
-- 1. CMS pages
-- ---------------------------------------------------------------------

-- Privacy Policy.
UPDATE `pages`
   SET `content` =
         REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`content`,
           'Our payment provider needs a mobile number for every checkout: we send the one on your account, or, if there is none, the app asks for one when you pay and sends it with that order only, without saving it to your account.',
           'Our payment provider, Cashfree, needs a mobile number for every checkout: we send the one on your account, or, if there is none, the app asks for one when you pay and we send it to Cashfree with that order only. That number is not saved to your account, and if the payment cannot be started, the log we keep of that request shows only its last four digits.'),
           'Cashfree tells us each payment’s reference, amount and status and a summary of the method used, such as the card network and last four digits, the UPI ID or the bank name.',
           'Cashfree tells us each payment’s reference, amount and status and the type of method used, such as UPI, card or net banking. We keep Cashfree’s reply with the card details, UPI ID and contact details taken out; for a net banking payment it can still name your bank.'),
           'The wallet holds a creator’s earnings and any refunds; you cannot pay money into it.',
           'The wallet holds a creator’s earnings, any refunds credited to it, and referral rewards: while our referral programme runs, you and a friend you invited each get one when that friend completes a first booking as a brand. You cannot pay money into the wallet.'),
           '(including earnings still held in escrow)',
           '(including earnings still on hold)'),
           '<h2>8. Questions and grievances</h2><p>Questions about this policy, requests about your data and complaints go to <a href="mailto:support@zusty.app">support@zusty.app</a>. For a complaint, put “Grievance” in the subject line; we acknowledge it and resolve it within the time Indian law sets. Zusty is operated by Appsgain Technologies Private Limited.</p>',
           '<h2>8. Questions and grievances</h2><p>Questions about this policy go to <a href="mailto:support@zusty.app">support@zusty.app</a>. Zusty is operated by Appsgain Technologies Private Limited.</p><p><strong>Grievance Officer.</strong> Complaints about Zusty, about content or conduct on it, or about how we handle your personal data go to the Grievance Officer, Appsgain Technologies Private Limited, at <a href="mailto:support@zusty.app">support@zusty.app</a>. Put “Grievance” in the subject line. We acknowledge a complaint within 24 hours and resolve it within 15 days of receiving it, as rule 3(2) of the Information Technology (Intermediary Guidelines and Digital Media Ethics Code) Rules, 2021 requires.</p><p>The Grievance Officer is also our contact for requests under the Digital Personal Data Protection Act, 2023: write to the same address to access, correct or erase your personal data, or to withdraw a consent you gave. We act on a request once we have confirmed it comes from you.</p>'),
       `version`      = `version` + 1,
       `published_at` = NOW()
 WHERE `slug` = 'privacy'
   AND (`content` LIKE BINARY '%sends it with that order only, without saving it to your account.%'
     OR `content` LIKE BINARY '%a summary of the method used, such as the card network and last four digits%'
     OR `content` LIKE BINARY '%The wallet holds a creator’s earnings and any refunds; you cannot pay money into it.%'
     OR `content` LIKE BINARY '%(including earnings still held in escrow)%'
     OR `content` LIKE BINARY '%we acknowledge it and resolve it within the time Indian law sets.%');

-- Terms & Conditions.
UPDATE `pages`
   SET `content` =
         REPLACE(REPLACE(REPLACE(REPLACE(`content`,
           'We provide escrow, messaging, dispute resolution and record-keeping,',
           'We provide payment protection, messaging, dispute resolution and record-keeping,'),
           'The payment is held in escrow and released to the creator when the brand approves the deliverables.',
           'The payment is held by Zusty and released to the creator when the brand approves the deliverables.'),
           'the booking completes automatically and the payment is released to the creator. Platform fees',
           'the booking completes automatically and the payment is released to the creator. If there is a dispute, our team reviews it before any money is released. Platform fees'),
           '<h2>17. Contact</h2><p>Questions about these terms: <a href="mailto:support@zusty.app">support@zusty.app</a>.</p>',
           '<h2>17. Contact and complaints</h2><p>Questions about these terms: <a href="mailto:support@zusty.app">support@zusty.app</a>. Complaints go to our Grievance Officer at the same address, who acknowledges them within 24 hours and resolves them within 15 days; section 8 of our <a href="/privacy">Privacy Policy</a> explains how.</p>'),
       `version`      = `version` + 1,
       `published_at` = NOW()
 WHERE `slug` = 'terms'
   AND (`content` LIKE BINARY '%We provide escrow, messaging,%'
     OR `content` LIKE BINARY '%The payment is held in escrow and released to the creator%'
     OR `content` LIKE BINARY '%<h2>17. Contact</h2><p>Questions about these terms: <a href="mailto:support@zusty.app">support@zusty.app</a>.</p>%');

-- Refund & Cancellation Policy.
UPDATE `pages`
   SET `subtitle` = REPLACE(`subtitle`, 'Escrow protects both sides', 'Payment protection for both sides'),
       `meta_description` = REPLACE(`meta_description`,
           'How cancellations, escrow releases, disputes and refunds work on Zusty.',
           'How cancellations, payment releases, disputes and refunds work on Zusty.'),
       `content` =
         REPLACE(REPLACE(`content`,
           '<h2>Escrow</h2><p>Brands pay for each booking',
           '<h2>Payment protection</h2><p>Brands pay for each booking'),
           'and the money stays in escrow until then.',
           'and the money stays held by Zusty until then.'),
       `version` = `version` + 1
 WHERE `slug` = 'refund-policy'
   AND (`subtitle` LIKE BINARY 'Escrow protects both sides'
     OR `meta_description` LIKE BINARY '%escrow releases%'
     OR `content` LIKE BINARY '%<h2>Escrow</h2><p>Brands pay for each booking%'
     OR `content` LIKE BINARY '%and the money stays in escrow until then.%');

-- About.
UPDATE `pages`
   SET `meta_description` = REPLACE(`meta_description`,
           'and secures every collaboration with escrow payments.',
           'and holds each payment until the work is approved.'),
       `content` =
         REPLACE(REPLACE(`content`,
           'clear pricing and escrow-backed payments to both sides.',
           'clear pricing and payment protection to both sides.'),
           '; the money is held in escrow until the work is approved.</li>',
           CONCAT('; Zusty holds the payment until you approve the work, or releases it to the creator automatically ', @z059_window, ' after delivery if you do not respond.</li>')),
       `version` = `version` + 1
 WHERE `slug` = 'about'
   AND (`meta_description` LIKE BINARY '%with escrow payments.%'
     OR `content` LIKE BINARY '%escrow-backed payments to both sides.%'
     OR `content` LIKE BINARY '%; the money is held in escrow until the work is approved.</li>%');

-- Community Guidelines.
UPDATE `pages`
   SET `content` = REPLACE(`content`,
           'removes escrow protection from both sides',
           'removes payment protection from both sides'),
       `version` = `version` + 1
 WHERE `slug` = 'community-guidelines'
   AND `content` LIKE BINARY '%removes escrow protection from both sides%';

-- Contact: where complaints go.
UPDATE `pages`
   SET `content` = REPLACE(`content`,
           '<li><strong>In the app</strong> — Profile → Help &amp; support → Start a new ticket</li>',
           '<li><strong>In the app</strong> — Profile → Help &amp; support → Start a new ticket</li><li><strong>Complaints</strong> — write to our Grievance Officer, Appsgain Technologies Private Limited, at <a href="mailto:support@zusty.app">support@zusty.app</a> with “Grievance” in the subject line. We acknowledge a complaint within 24 hours and resolve it within 15 days.</li>'),
       `version` = `version` + 1
 WHERE `slug` = 'contact'
   AND `content` LIKE BINARY '%<li><strong>In the app</strong> — Profile → Help &amp; support → Start a new ticket</li>%'
   AND `content` NOT LIKE '%Grievance Officer%';

-- ---------------------------------------------------------------------
-- 2. FAQs
--
-- Matched on the seeded question and the seeded phrase, so an answer an
-- operator has rewritten is kept.
-- ---------------------------------------------------------------------
UPDATE `faqs`
   SET `answer` = REPLACE(`answer`,
           'Payment is held in escrow until the work is approved.',
           'Zusty holds the brand''s payment until the work is approved.')
 WHERE `question` = 'How does Zusty work?'
   AND `answer` LIKE BINARY '%Payment is held in escrow until the work is approved.%';

UPDATE `faqs`
   SET `answer` = CONCAT(
           REPLACE(`answer`, 'Your money is held in escrow and released', 'Your payment is held by Zusty and released'),
           ' If there is a dispute, our team reviews it before any money is released.')
 WHERE `question` = 'Is my payment secure?'
   AND `answer` LIKE BINARY '%Your money is held in escrow and released%';

UPDATE `faqs`
   SET `answer` = REPLACE(`answer`,
           'While a dispute is open the money stays in escrow.',
           'While a dispute is open, Zusty keeps holding the payment.')
 WHERE `question` = 'What happens if a creator does not deliver?'
   AND `answer` LIKE BINARY '%While a dispute is open the money stays in escrow.%';

-- ---------------------------------------------------------------------
-- 3. Notification templates
-- ---------------------------------------------------------------------
UPDATE `notification_templates`
   SET `body` = REPLACE(`body`, 'Your funds are held safely in escrow.', 'Zusty holds it until you approve the work.')
 WHERE `code` = 'payment.success'
   AND `body` LIKE BINARY '%Your funds are held safely in escrow.%';

UPDATE `notification_templates`
   SET `body` = REPLACE(`body`,
           'The brand will raise a booking so the payment can be held in escrow.',
           'The brand will raise a booking, and Zusty holds its payment until the work is approved.')
 WHERE `code` = 'campaign.hired'
   AND `body` LIKE BINARY '%so the payment can be held in escrow.%';

UPDATE `notification_templates`
   SET `body` = REPLACE(`body`,
           'The payment stays in escrow until our team has reviewed it.',
           'Zusty keeps holding the payment until our team has reviewed it.'),
       `sms_body` = REPLACE(`sms_body`, 'Your payment stays in escrow.', 'The payment stays on hold until our team has reviewed it.')
 WHERE `code` = 'booking.disputed'
   AND (`body` LIKE BINARY '%The payment stays in escrow until our team has reviewed it.%'
     OR `sms_body` LIKE BINARY '%Your payment stays in escrow.%');

-- ---------------------------------------------------------------------
-- 4. Home banner and onboarding slide
-- ---------------------------------------------------------------------
UPDATE `banners`
   SET `subtitle` = 'Verified creators, transparent pricing, and payment protection on every booking.'
 WHERE `subtitle` = 'Verified creators, transparent pricing, escrow-backed payments.';

UPDATE `onboarding_slides`
   SET `subtitle` = 'Briefs, offers and payment in one place. The brand’s payment is held by Zusty until the work is approved.'
 WHERE `subtitle` = 'Briefs, negotiation, and escrow-protected payments — the whole campaign in one place.';

-- ---------------------------------------------------------------------
-- 5. Labels: the job's display name, and the timeline notes
--
-- The job key stays escrow:release (the crontab and CronRunner use it).
-- BookingService now writes the new notes; these are the rows it wrote
-- before, which the booking screen's status timeline still shows.
-- ---------------------------------------------------------------------
UPDATE `scheduled_jobs`
   SET `name` = 'Release held earnings'
 WHERE `job_key` = 'escrow:release'
   AND `name` = 'Release escrow';

UPDATE `booking_status_history`
   SET `note` = 'Payment captured and held by Zusty.'
 WHERE `note` = 'Payment captured and held in escrow.';

UPDATE `booking_status_history`
   SET `note` = 'Completed by an administrator; payment released.'
 WHERE `note` = 'Completed by an administrator; escrow released.';

-- ---------------------------------------------------------------------
-- 6. Settings that still point at the old names
-- ---------------------------------------------------------------------
UPDATE `settings`
   SET `value` = 'https://play.google.com/store/apps/details?id=com.zuste.zuste'
 WHERE `group_key` = 'app'
   AND `setting_key` = 'android_store_url'
   AND (`value` IS NULL
     OR TRIM(`value`) = ''
     OR `value` REGEXP '[?&]id=com\\.zuste\\.app([&#]|$)');

UPDATE `settings`
   SET `value` = 'https://zusty.app/invite/'
 WHERE `group_key` = 'referral'
   AND `setting_key` = 'share_base_url'
   AND `value` REGEXP '^https?://([a-z0-9-]+\\.)*(zuste\\.app|zustee\\.com)(:[0-9]+)?(/|$)';

-- ---------------------------------------------------------------------
-- 7. The sample testimonial that said "escrow"
--
-- Seeded switched off (053), but one toggle in the panel would show it.
-- Matched on the seeded author and opening, so an operator's edit is kept.
-- ---------------------------------------------------------------------
UPDATE `testimonials`
   SET `content` = REPLACE(`content`, 'Escrow changed everything for me.', 'Payment protection changed everything for me.')
 WHERE `author_name` = 'Rohit Verma'
   AND `content` LIKE BINARY 'Escrow changed everything for me.%';

-- ---------------------------------------------------------------------
-- 8. Stored Cashfree replies: what the privacy policy says is taken out
--
-- The policy (section 2) says Cashfree's reply is kept with the card
-- details, UPI ID and contact details taken out. PaymentService now also
-- drops `phone` (Cashfree's app/wallet, pay_later and cardless_emi objects
-- carry the payer's number) and the `emi` / `card_emi` card objects before
-- a reply is stored. This is the one-time scrub of rows stored before that,
-- with the card object 048 missed inside payment_method. Ids, statuses,
-- amounts, the method type and the provider stay. Refund replies carry no
-- payment method, so `refunds` is not touched.
--
-- MariaDB 10.4's JSON_REMOVE returns NULL when a path's parent is missing,
-- so each statement removes only keys whose parent object is known to
-- exist (the WHERE checks the path), and COALESCE keeps the stored reply
-- should it still come back NULL. A row with none of these paths is not
-- touched, so a second run changes nothing.
-- ---------------------------------------------------------------------
UPDATE `payments`
   SET `gateway_response` = COALESCE(JSON_REMOVE(`gateway_response`,
         '$.payment_method.card', '$.payment_method.emi', '$.payment_method.card_emi'), `gateway_response`)
 WHERE `gateway_response` IS NOT NULL
   AND JSON_VALID(`gateway_response`)
   AND JSON_CONTAINS_PATH(`gateway_response`, 'one',
         '$.payment_method.card', '$.payment_method.emi', '$.payment_method.card_emi');

UPDATE `payments`
   SET `gateway_response` = COALESCE(JSON_REMOVE(`gateway_response`, '$.payment_method.app.phone'), `gateway_response`)
 WHERE `gateway_response` IS NOT NULL
   AND JSON_VALID(`gateway_response`)
   AND JSON_CONTAINS_PATH(`gateway_response`, 'one', '$.payment_method.app.phone');

UPDATE `payments`
   SET `gateway_response` = COALESCE(JSON_REMOVE(`gateway_response`, '$.payment_method.pay_later.phone'), `gateway_response`)
 WHERE `gateway_response` IS NOT NULL
   AND JSON_VALID(`gateway_response`)
   AND JSON_CONTAINS_PATH(`gateway_response`, 'one', '$.payment_method.pay_later.phone');

UPDATE `payments`
   SET `gateway_response` = COALESCE(JSON_REMOVE(`gateway_response`, '$.payment_method.cardless_emi.phone'), `gateway_response`)
 WHERE `gateway_response` IS NOT NULL
   AND JSON_VALID(`gateway_response`)
   AND JSON_CONTAINS_PATH(`gateway_response`, 'one', '$.payment_method.cardless_emi.phone');

UPDATE `payment_webhooks`
   SET `payload` = COALESCE(JSON_REMOVE(`payload`,
         '$.data.payment.payment_method.card', '$.data.payment.payment_method.emi',
         '$.data.payment.payment_method.card_emi'), `payload`)
 WHERE JSON_VALID(`payload`)
   AND JSON_CONTAINS_PATH(`payload`, 'one',
         '$.data.payment.payment_method.card', '$.data.payment.payment_method.emi',
         '$.data.payment.payment_method.card_emi');

UPDATE `payment_webhooks`
   SET `payload` = COALESCE(JSON_REMOVE(`payload`, '$.data.payment.payment_method.app.phone'), `payload`)
 WHERE JSON_VALID(`payload`)
   AND JSON_CONTAINS_PATH(`payload`, 'one', '$.data.payment.payment_method.app.phone');

UPDATE `payment_webhooks`
   SET `payload` = COALESCE(JSON_REMOVE(`payload`, '$.data.payment.payment_method.pay_later.phone'), `payload`)
 WHERE JSON_VALID(`payload`)
   AND JSON_CONTAINS_PATH(`payload`, 'one', '$.data.payment.payment_method.pay_later.phone');

UPDATE `payment_webhooks`
   SET `payload` = COALESCE(JSON_REMOVE(`payload`, '$.data.payment.payment_method.cardless_emi.phone'), `payload`)
 WHERE JSON_VALID(`payload`)
   AND JSON_CONTAINS_PATH(`payload`, 'one', '$.data.payment.payment_method.cardless_emi.phone');
