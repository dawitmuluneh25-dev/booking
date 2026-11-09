-- 0017_create_appointment_reschedules.sql
-- A record of every move an appointment has made.
--
-- Rescheduling is not a status change, so filing it in
-- `appointment_status_history` would have meant writing rows whose `from_status`
-- and `to_status` are the same value - a status history that says "scheduled to
-- scheduled" hides the thing that actually happened. This table says what moved,
-- from where, to where, by whom, and when.
--
-- It matters for criterion 2 of Phase 5: when a reschedule fails because the
-- target is taken, this table gains no row and the appointment keeps its original
-- time. The absence is the evidence that the original was never released.

CREATE TABLE appointment_reschedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id BIGINT UNSIGNED NOT NULL,
    from_resource_id BIGINT UNSIGNED NOT NULL,
    to_resource_id BIGINT UNSIGNED NOT NULL,
    from_starts_at DATETIME NOT NULL,
    from_ends_at DATETIME NOT NULL,
    to_starts_at DATETIME NOT NULL,
    to_ends_at DATETIME NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    actor_role VARCHAR(32) NOT NULL,
    reason VARCHAR(255) NULL,
    occurred_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_reschedules_appointment (appointment_id, occurred_at),
    CONSTRAINT fk_reschedules_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_reschedules_from_resource
        FOREIGN KEY (from_resource_id) REFERENCES resources (id) ON DELETE RESTRICT,
    CONSTRAINT fk_reschedules_to_resource
        FOREIGN KEY (to_resource_id) REFERENCES resources (id) ON DELETE RESTRICT,
    CONSTRAINT fk_reschedules_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_reschedules_moved CHECK (to_starts_at <> from_starts_at OR to_resource_id <> from_resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
