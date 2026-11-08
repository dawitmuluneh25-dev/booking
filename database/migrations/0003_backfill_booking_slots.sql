-- 0003_backfill_booking_slots.sql
-- 0002 introduced slot exclusivity but only for bookings created afterwards.
-- Any booking that already existed would have no slot row, so its time could
-- be claimed by a new request. This backfills one slot per future, non-cancelled
-- booking that is missing one.
--
-- INSERT IGNORE keeps the statement safe to re-run and never overwrites a slot
-- that is already claimed.

INSERT IGNORE INTO booking_slots (slot_start, booking_id, created_at)
SELECT b.scheduled_at, b.id, b.created_at
FROM bookings AS b
LEFT JOIN booking_slots AS s ON s.slot_start = b.scheduled_at
WHERE s.slot_start IS NULL
  AND b.status <> 'cancelled'
  AND b.scheduled_at >= UTC_TIMESTAMP();
