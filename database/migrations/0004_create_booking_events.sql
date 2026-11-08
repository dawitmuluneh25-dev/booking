-- 0004_create_booking_events.sql
-- Append-only history for lifecycle changes. Application code never updates or
-- deletes these rows; a correction is recorded as a further event.
--
-- actor is a free-form label until accounts exist (e.g. "cli:dawit"), and can
-- later become a user reference without changing the shape of the history.

CREATE TABLE booking_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NOT NULL,
    to_status VARCHAR(20) NOT NULL,
    actor VARCHAR(120) NOT NULL,
    reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_booking_events_booking (booking_id, id),
    CONSTRAINT fk_booking_events_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
