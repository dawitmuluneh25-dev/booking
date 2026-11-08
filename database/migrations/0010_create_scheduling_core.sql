-- 0010_create_scheduling_core.sql
-- Resources, their availability, appointments, and the occupancy rows that make
-- a double booking impossible.
--
-- The reservation guard is `resource_slots`: one row per resource per occupied
-- time quantum, with a composite primary key. Two concurrent bookings for the
-- same resource and start time cannot both commit — the second one fails on the
-- primary key, not on a SELECT that raced. This is the same idea as
-- `booking_slots` in 0002, generalised from one calendar to many resources, and
-- it is what ATDS 22.5 measures: 20 concurrent clients, exactly one success.
--
-- Appointment state is separate from referral state (WF-15). An appointment can
-- exist for a referral, but cancelling it does not change the referral; the
-- referral stays at `converted_to_appointment` until someone closes it.
--
-- `appointments.referral_id` is nullable on purpose: an urgent intussusception
-- case (0011) gets an appointment without ever being an elective referral.

CREATE TABLE resources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    kind VARCHAR(24) NOT NULL,
    modality VARCHAR(60) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_resources_code (code),
    CONSTRAINT ck_resources_kind CHECK (kind IN ('suite', 'room', 'clinician', 'equipment'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Recurring weekly availability. Dates are wall-clock in the clinic time zone,
-- converted at the edge; the database stores the rule, not an instant.
CREATE TABLE resource_availability (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_id BIGINT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    opens_at TIME NOT NULL,
    closes_at TIME NOT NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    PRIMARY KEY (id),
    KEY ix_availability_resource (resource_id, weekday),
    CONSTRAINT fk_availability_resource
        FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE CASCADE,
    CONSTRAINT ck_availability_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT ck_availability_window CHECK (closes_at > opens_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appointments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(24) NOT NULL,
    referral_id BIGINT UNSIGNED NULL,
    status VARCHAR(32) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    patient_name VARCHAR(120) NOT NULL,
    patient_phone VARCHAR(32) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_appointments_reference (reference),
    KEY ix_appointments_starts (starts_at),
    KEY ix_appointments_status_starts (status, starts_at),
    KEY ix_appointments_referral (referral_id),
    CONSTRAINT fk_appointments_referral
        FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_appointments_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_appointments_window CHECK (ends_at > starts_at),
    CONSTRAINT ck_appointments_status CHECK (status IN (
        'held', 'scheduled', 'confirmed', 'checked_in', 'preprocedure_verification',
        'ready', 'in_progress', 'completed', 'cancelled', 'no_show', 'deferred'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Booked occupancy. The primary key is the concurrency control: a resource can
-- hold a given quantum once, whatever the application believes.
CREATE TABLE resource_slots (
    resource_id BIGINT UNSIGNED NOT NULL,
    slot_start DATETIME NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (resource_id, slot_start),
    KEY ix_resource_slots_appointment (appointment_id),
    CONSTRAINT fk_resource_slots_resource
        FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE RESTRICT,
    CONSTRAINT fk_resource_slots_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who and what is committed to an appointment. Availability is derived from
-- this table, so the schedule has one source of truth.
CREATE TABLE appointment_resources (
    appointment_id BIGINT UNSIGNED NOT NULL,
    resource_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(24) NOT NULL DEFAULT 'primary',
    PRIMARY KEY (appointment_id, resource_id),
    KEY ix_appointment_resources_resource (resource_id),
    CONSTRAINT fk_appointment_resources_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE,
    CONSTRAINT fk_appointment_resources_resource
        FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE RESTRICT,
    CONSTRAINT ck_appointment_resources_role CHECK (role IN ('primary', 'assisting', 'supervising'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appointment_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_role VARCHAR(32) NOT NULL,
    reason VARCHAR(255) NULL,
    occurred_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_appointment_history (appointment_id, occurred_at),
    CONSTRAINT fk_appointment_history_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_appointment_history_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO resources (code, name, kind, modality, is_active, created_at, updated_at) VALUES
    ('suite-1', 'IR Suite 1', 'suite', 'CT and fluoroscopy', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('suite-2', 'IR Suite 2', 'suite', 'Ultrasound', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('room-3', 'Procedure Room 3', 'room', 'Minor procedures and recovery', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP());
