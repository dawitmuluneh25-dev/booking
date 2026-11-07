-- 0001_create_bookings.sql
-- Baseline schema: a booking request is stored as a request, never as a
-- confirmed appointment. Confirmation is a later, explicit status change.

CREATE TABLE bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(20) NOT NULL,
    patient_name VARCHAR(120) NOT NULL,
    patient_phone VARCHAR(32) NOT NULL,
    service VARCHAR(32) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'requested',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bookings_reference (reference),
    KEY ix_bookings_scheduled_at (scheduled_at),
    KEY ix_bookings_status_scheduled (status, scheduled_at),
    CONSTRAINT ck_bookings_service CHECK (service IN ('consultation', 'follow_up', 'procedure', 'other')),
    CONSTRAINT ck_bookings_status CHECK (status IN ('requested', 'confirmed', 'cancelled', 'completed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
