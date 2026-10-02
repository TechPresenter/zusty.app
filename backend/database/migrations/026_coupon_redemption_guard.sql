-- ---------------------------------------------------------------------
-- 026 — Stop a coupon being spent twice on the same booking
--
-- `coupon_redemptions` had only non-unique lookup indexes, so two concurrent
-- checkouts on the same booking — a double-tapped Pay button, or a retry after
-- a timeout — could each insert a redemption and each apply the discount.
--
-- The unique key makes the second insert fail instead, which is what
-- CouponService::redeem() relies on to be safe under concurrency.
--
-- booking_id is nullable (a wallet top-up redemption has no booking), and
-- MySQL permits repeated NULLs in a unique key, so this constrains exactly the
-- booking case it is meant to.
-- ---------------------------------------------------------------------

-- Clear any duplicate that predates the constraint, keeping the earliest.
DELETE r FROM `coupon_redemptions` r
INNER JOIN `coupon_redemptions` keep
        ON keep.`coupon_id`  = r.`coupon_id`
       AND keep.`user_id`    = r.`user_id`
       AND keep.`booking_id` = r.`booking_id`
       AND keep.`id`         < r.`id`
WHERE r.`booking_id` IS NOT NULL;

ALTER TABLE `coupon_redemptions`
  ADD UNIQUE KEY `uq_redemption_booking` (`coupon_id`, `user_id`, `booking_id`);
