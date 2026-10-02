-- ---------------------------------------------------------------------
-- 020 — Cashfree Payments
--
-- Cashfree is the primary India-facing gateway (UPI, cards, net banking,
-- pay later, wallets). It joins Razorpay and Stripe rather than replacing
-- them, so both ENUMs simply gain a member — existing rows are untouched
-- and the unique keys on (gateway, gateway_payment_id) keep working.
-- ---------------------------------------------------------------------

ALTER TABLE `payments`
  MODIFY COLUMN `gateway` ENUM('razorpay','stripe','cashfree','wallet','manual') NOT NULL;

ALTER TABLE `payment_webhooks`
  MODIFY COLUMN `gateway` ENUM('razorpay','stripe','cashfree') NOT NULL;

-- Cashfree returns its own order handle (`cf_order_id`) alongside the one we
-- send. Storing it makes reconciliation against a Cashfree settlement report
-- a straight join instead of a fuzzy amount match.
ALTER TABLE `payments`
  ADD COLUMN `gateway_reference` VARCHAR(120) NULL
    COMMENT 'Gateway-side handle, e.g. Cashfree cf_order_id'
    AFTER `gateway_payment_id`;
