-- 0006_create_notification_outbox.sql
-- Transactional outbox: messages are written in the same transaction as the
-- booking change, then delivered later by a CLI dispatcher.
--
-- No provider is called from a web request: a slow or failing SMS gateway must
-- never roll back or delay a booking.
--
-- The unique key makes enqueueing idempotent per booking + transition + channel,
-- so a retried request cannot send the same notification twice.

CREATE TABLE notification_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(20) NOT NULL,
    template VARCHAR(40) NOT NULL,
    recipient VARCHAR(64) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_outbox_transition (booking_id, template, channel),
    KEY ix_outbox_dispatch (status, available_at),
    CONSTRAINT fk_outbox_booking
        FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT ck_outbox_channel CHECK (channel IN ('sms', 'email', 'whatsapp')),
    CONSTRAINT ck_outbox_status CHECK (status IN ('pending', 'sending', 'sent', 'failed', 'dead'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
