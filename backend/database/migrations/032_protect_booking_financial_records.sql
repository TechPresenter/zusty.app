-- ---------------------------------------------------------------------
-- 032 — Stop a booking delete from erasing its invoice and its commission
--
-- Migration 031 closed the `users` half of the cascade problem: the money
-- tables no longer disappear when an account is purged from the recycle bin.
-- It left the other parent open. `bookings` is a recyclable type too
-- (ProductivityController::RECYCLABLE, 'bookings' => bookings), so the same
-- one-click purge —
--
--     DELETE FROM `bookings` WHERE `id` = :id AND `deleted_at` IS NOT NULL
--
-- still cascaded straight through two money-bearing children:
--
--     invoices.booking_id           ON DELETE CASCADE   (26 of 82 bookings)
--     platform_earnings.booking_id  ON DELETE CASCADE   (53 of 82 bookings)
--
-- An invoice is a numbered statutory document — `uq_invoices_number` exists
-- precisely because the series must be unbroken — and `platform_earnings` is
-- the commission ledger every revenue report is built from. Deleting a
-- booking silently removed both. Nothing at the database level said no, for
-- the same reason 031 was needed on the `users` side.
--
-- Both edges become RESTRICT. The reasoning matches 031 exactly: restrict the
-- edges that carry money, leave the per-row children that every parent has.
--
--   * `booking_status_history`.`booking_id` stays CASCADE. All 82 bookings
--     have status history, so restricting it would not protect a financial
--     record — it would disable booking deletion outright and break the
--     recycle bin for the type. 031 made the same call for the same reason.
--
--   * `booking_deliverables`, `booking_offers`, `booking_disputes` and
--     `reviews` stay CASCADE. They carry no money.
--
--   * `payments`.`booking_id` and `refunds`.`booking_id` were already
--     SET NULL, so those rows already survived a booking delete. Untouched.
--
-- A booking that never invoiced and never earned commission still deletes
-- exactly as before — 28 of the 82 current bookings are in that state, so the
-- recycle bin keeps working for the abandoned and test bookings it exists to
-- clear. A booking with a financial record is now refused by InnoDB
-- (errno 1451) instead of taking the record with it.
--
-- Both columns are NOT NULL and both constraints were already ON UPDATE
-- RESTRICT, which is the default and is preserved by not restating it.
-- Dropping and re-adding a constraint keeps its backing index
-- (`idx_invoices_booking`, `uq_pe_booking`), so no query plan changes.
--
-- This only ever tightens a delete rule, so it cannot fail on existing data.
-- ---------------------------------------------------------------------

ALTER TABLE `invoices`
  DROP FOREIGN KEY `fk_invoices_booking`;
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT;

ALTER TABLE `platform_earnings`
  DROP FOREIGN KEY `fk_pe_booking`;
ALTER TABLE `platform_earnings`
  ADD CONSTRAINT `fk_pe_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE RESTRICT;
