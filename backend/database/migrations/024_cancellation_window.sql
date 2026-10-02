-- ---------------------------------------------------------------------
-- 024 — Make the free-cancellation window reachable
--
-- `booking.default_delivery_days` and `booking.free_cancellation_days` were
-- both 7. BookingService::refundableAmount() charges the late fee when
--
--     delivery_due_at - now  <=  free_cancellation_days
--
-- and BookingService::create() defaults delivery_due_at to
-- now + default_delivery_days. With both at 7 the condition is true the
-- instant a booking exists, so EVERY brand cancellation incurred the 25% late
-- fee and the advertised free window could never once be used — while the API
-- kept reporting `free_window_days: 7` to the client.
--
-- Three days is the usual marketplace shape: a brand gets a few days to change
-- its mind, and the creator is protected close to the delivery date. Both
-- values remain editable in Admin → Settings → Bookings; the point of this
-- migration is that the DEFAULTS no longer contradict each other.
-- ---------------------------------------------------------------------

UPDATE `settings`
   SET `value`       = '3',
       `description` = 'Full refund if cancelled more than this many days before delivery. Must be less than default_delivery_days or the window is unreachable.'
 WHERE `group_key`   = 'booking'
   AND `setting_key` = 'free_cancellation_days';
