-- 0002_create_booking_slots.sql
-- One row per occupied 30-minute slot. The primary key on slot_start is the
-- final guard against double-booking: the database, not the UI, decides.
--
-- Single-calendar model: one row per start time. When the product gains
-- multiple rooms or practitioners, this becomes (resource_id, slot_start).
--
-- Releasing a slot (cancellation) means deleting its row in the same
-- transaction that sets status = 'cancelled'.

CREATE TABLE booking_slots (
    slot_start DATETIME NOT NULL,
    booking_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (slot_start),
    KEY ix_booking_slots_booking (booking_id),
    CONSTRAINT fk_booking_slots_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
