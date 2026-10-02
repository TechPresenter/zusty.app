-- ---------------------------------------------------------------------
-- 031 — Stop a user delete from cascading through the money trail
--
-- Permanently deleting a user from /admin/recycle-bin runs a plain
--
--     DELETE FROM `users` WHERE `id` = :id AND `deleted_at` IS NOT NULL
--
-- (ProductivityController::purgeDeleted, ::emptyBinType, ::purgeOlderThan)
-- and every money-bearing child of `users` was ON DELETE CASCADE. One click
-- therefore erased that account's payments, refunds, withdrawal requests,
-- invoices, bookings and its entire wallet ledger — records the business is
-- required to keep, and rows other tables' history refers to. Nothing at the
-- database level said no; the confirmation screen counted the rows it was
-- about to destroy and destroyed them.
--
-- The delete rule becomes RESTRICT on exactly the edges that carry money:
--
--     payments.user_id, refunds.user_id, withdrawal_requests.user_id,
--     invoices.user_id, bookings.brand_user_id, bookings.creator_user_id,
--     wallet_transactions.user_id, wallet_transactions.wallet_id
--
-- An account that never transacted still deletes exactly as before — none of
-- these tables holds a row for it — so the recycle bin keeps working for the
-- spam and test accounts it exists to clear. An account with financial
-- history is now refused by InnoDB (errno 1451) instead of being erased.
--
-- Two deliberate departures from the obvious "restrict everything" reading:
--
--   * `wallets`.`user_id` stays CASCADE. WalletService::forUser() creates a
--     wallet row for every account at registration — 99 of the 104 rows in
--     `users` have one — so RESTRICTing it would not protect anything extra,
--     it would simply make permanent deletion impossible for every user
--     forever. The protection is placed one level down instead:
--     `wallet_transactions`.`wallet_id` is RESTRICT, so the cascade into
--     `wallets` is refused the moment the ledger is not empty. A wallet that
--     never moved money carries no history and may still go.
--
--   * `booking_status_history`.`booking_id` and the other per-row audit
--     children are left alone for the same reason — every booking has status
--     history, so restricting it would disable booking deletion outright
--     rather than protect a financial record.
--
-- ON UPDATE was already RESTRICT on all eight and is restated unchanged.
-- Dropping and re-adding a constraint keeps its backing index, so no lookup
-- plan changes.
-- ---------------------------------------------------------------------

ALTER TABLE `payments`
  DROP FOREIGN KEY `fk_payments_user`;
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `refunds`
  DROP FOREIGN KEY `fk_refunds_user`;
ALTER TABLE `refunds`
  ADD CONSTRAINT `fk_refunds_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `withdrawal_requests`
  DROP FOREIGN KEY `fk_wr_user`;
ALTER TABLE `withdrawal_requests`
  ADD CONSTRAINT `fk_wr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `invoices`
  DROP FOREIGN KEY `fk_invoices_user`;
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `bookings`
  DROP FOREIGN KEY `fk_bookings_brand`;
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_bookings_brand` FOREIGN KEY (`brand_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `bookings`
  DROP FOREIGN KEY `fk_bookings_creator_user`;
ALTER TABLE `bookings`
  ADD CONSTRAINT `fk_bookings_creator_user` FOREIGN KEY (`creator_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `wallet_transactions`
  DROP FOREIGN KEY `fk_wt_user`;
ALTER TABLE `wallet_transactions`
  ADD CONSTRAINT `fk_wt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `wallet_transactions`
  DROP FOREIGN KEY `fk_wt_wallet`;
ALTER TABLE `wallet_transactions`
  ADD CONSTRAINT `fk_wt_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE RESTRICT;
