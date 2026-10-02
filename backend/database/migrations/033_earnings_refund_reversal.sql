-- Platform revenue must fall when money is refunded.
--
-- `platform_earnings` accrues the platform's fee and commission when a booking
-- is captured, and nothing ever reversed it when that money went back. A
-- refunded booking therefore kept earning the platform a fee it no longer
-- held, on the dashboard and in every finance report.
--
-- The accrued figures stay exactly as they were: finance needs to see what was
-- originally earned, and rewriting them in place would destroy that record.
-- Instead the refunded share is recorded beside them, so live revenue is the
-- accrual net of what came back, and the original is still legible.
--
-- Additive and backward compatible. The default of 0 says "nothing refunded",
-- which is true of every row that exists today.

ALTER TABLE `platform_earnings`
    ADD COLUMN IF NOT EXISTS `refunded_ratio` DECIMAL(6,5) NOT NULL DEFAULT 0.00000
        COMMENT 'Cumulative refunded share of this accrual, 0.00000-1.00000'
        AFTER `total`;

-- The seven rows already marked reversed were fully refunded, so their live
-- contribution is nil. Stating that explicitly keeps the ratio and the status
-- in agreement rather than leaving the column to imply they still earn.
UPDATE `platform_earnings`
   SET `refunded_ratio` = 1.00000
 WHERE `status` = 'reversed'
   AND `refunded_ratio` = 0.00000;
