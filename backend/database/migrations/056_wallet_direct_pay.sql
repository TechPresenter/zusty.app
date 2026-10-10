-- ---------------------------------------------------------------------
-- 056 - Bookings are paid directly; wallet top-up is switched off.
--
-- The operator's decision (2026-10-10, U2, for Google Play review): brands
-- no longer load money into the wallet in advance ("Add money"). Each
-- booking is paid through the gateway's checkout (Cashfree). The wallet
-- keeps what the platform itself puts there - creator earnings, withdrawn
-- to a bank account or UPI ID, and refunds - so it never holds money a
-- customer pre-loaded, which is what would make it a semi-closed prepaid
-- payment instrument under RBI's rules. Refunds go back to the original
-- payment method by default (enforced in code, PaymentService). An admin
-- can switch top-ups back on later under Settings -> Wallet.
--
--   1. wallet.topup_enabled, value 0. The app hides every "Add money"
--      button, preset and prompt (GET /app/config -> wallet.topup_enabled)
--      and POST /wallet/topup answers 403 topup_disabled.
--   2. The Wallet tab lists the new switch right under "Wallet enabled";
--      the top-up limits and the wallet-payment switch say what they now
--      mean.
--   3. The seeded "What payment methods are accepted?" FAQ no longer
--      offers the wallet as an ordinary way to pay.
--   4. The Refund & Cancellation Policy, and the cancellation and dispute
--      FAQs, say when a refund goes to the wallet instead of the original
--      method: when the payment cannot be returned there automatically (a
--      recorded bank transfer, say), or when our team agrees it with the
--      brand. Applied on top of migration 053's text.
--
-- Re-runnable: the setting keeps whatever value it already holds, and
-- every UPDATE is limited to rows that still hold what it changes, so a
-- second run changes nothing. An FAQ an admin has rewritten is left alone.
-- Valid on MariaDB 10.4 and 11.8.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. The switch
--
-- On a fresh install (migrations run before seeds) seed 001 then finds the
-- row and keeps its value. A re-run keeps an admin's later choice.
-- ---------------------------------------------------------------------
INSERT INTO `settings`
  (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('wallet', 'topup_enabled', '0', 'boolean', 'Wallet top-up ("Add money")',
   'Off: brands pay each booking at checkout and the app offers no Add money. On: brands can preload money, which makes the wallet a prepaid instrument under RBI rules.',
   1, 0, 2)
ON DUPLICATE KEY UPDATE
  `type`        = VALUES(`type`),
  `label`       = VALUES(`label`),
  `description` = VALUES(`description`);

-- ---------------------------------------------------------------------
-- 2. The Wallet tab: order and wording
-- ---------------------------------------------------------------------
UPDATE `settings` SET `sort_order` = 3
 WHERE `group_key` = 'wallet' AND `setting_key` = 'min_topup' AND `sort_order` <> 3;

UPDATE `settings` SET `sort_order` = 4
 WHERE `group_key` = 'wallet' AND `setting_key` = 'max_topup' AND `sort_order` <> 4;

UPDATE `settings` SET `sort_order` = 5
 WHERE `group_key` = 'wallet' AND `setting_key` = 'min_withdrawal' AND `sort_order` <> 5;

UPDATE `settings` SET `sort_order` = 6
 WHERE `group_key` = 'wallet' AND `setting_key` = 'max_withdrawal' AND `sort_order` <> 6;

UPDATE `settings` SET `sort_order` = 7
 WHERE `group_key` = 'wallet' AND `setting_key` = 'withdrawal_hold_days' AND `sort_order` <> 7;

UPDATE `settings` SET `sort_order` = 8
 WHERE `group_key` = 'wallet' AND `setting_key` = 'require_kyc_for_withdrawal' AND `sort_order` <> 8;

UPDATE `settings`
   SET `description` = 'Smallest wallet deposit. Applies only while wallet top-up is on.'
 WHERE `group_key` = 'wallet' AND `setting_key` = 'min_topup'
   AND `description` = 'Smallest wallet deposit.';

UPDATE `settings`
   SET `description` = 'Largest single deposit. Applies only while wallet top-up is on.'
 WHERE `group_key` = 'wallet' AND `setting_key` = 'max_topup'
   AND `description` = 'Largest single deposit.';

UPDATE `settings`
   SET `description` = 'Let a brand pay a booking from balance already in the wallet (a refund, say). Offered at checkout only when it covers the whole total.'
 WHERE `group_key` = 'payment' AND `setting_key` = 'allow_wallet_payment'
   AND `description` = 'Let brands pay from wallet balance.';

-- ---------------------------------------------------------------------
-- 3. FAQ copy
-- ---------------------------------------------------------------------
UPDATE `faqs`
   SET `answer` = 'Each booking is paid through Cashfree''s secure checkout, with UPI (Google Pay, PhonePe, Paytm), a debit or credit card, or net banking. If a refund has gone to your Zusty wallet and the balance covers the whole booking, you can pay with that instead. Every payment is processed over an encrypted connection by a PCI-DSS compliant gateway.'
 WHERE `answer` = 'We accept all major credit and debit cards, UPI (Google Pay, PhonePe, Paytm), net banking and your Zusty wallet balance. Every payment is processed over an encrypted connection by a PCI-DSS compliant gateway.';

-- ---------------------------------------------------------------------
-- 4. Where a refund goes, in the policy and the FAQs
--
-- Each REPLACE rewrites a phrase migration 053 wrote; once rewritten the
-- phrase no longer matches, so a second run changes nothing. A page or
-- answer an admin has reworded is left alone.
-- ---------------------------------------------------------------------
UPDATE `pages`
   SET `content` = REPLACE(`content`,
         'A payment made from Zusty wallet balance is refunded to the wallet. Refunds to a card',
         'A payment made from Zusty wallet balance is refunded to the wallet. If a payment cannot be returned to its original method automatically, or our support team agrees it with you, the refund is credited to your Zusty wallet instead. You can use it to pay for a booking it covers in full, or withdraw it to your bank account or UPI ID; for an amount below the minimum withdrawal, ask our support team to pay it out. Refunds to a card'),
       `version` = `version` + 1
 WHERE `slug` = 'refund-policy'
   AND `content` LIKE BINARY '%A payment made from Zusty wallet balance is refunded to the wallet. Refunds to a card%';

UPDATE `faqs`
   SET `answer` = REPLACE(`answer`,
         'Refunds go back to your original payment method.',
         'Refunds go back to your original payment method, or to your Zusty wallet when they cannot go back there automatically.')
 WHERE `question` = 'Can I cancel a booking?'
   AND `answer` LIKE BINARY '%If the creator cancels, you get a full refund including fees. Refunds go back to your original payment method.';

UPDATE `faqs`
   SET `answer` = REPLACE(`answer`,
         'A refund goes back to your original payment method.',
         'A refund goes back to your original payment method, or to your Zusty wallet when it cannot go back there automatically or you agree that with our team.')
 WHERE `question` = 'What happens if a creator does not deliver?'
   AND `answer` LIKE BINARY '%releases, refunds or splits the payment. A refund goes back to your original payment method.';
