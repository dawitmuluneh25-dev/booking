-- 0022_create_resource_blocks.sql
-- Maintenance and closure windows, so a suite can be taken out of service without
-- pretending an appointment holds it.
--
-- A block is not an appointment. Writing a fake appointment would put a patient
-- who does not exist into the record, into any report that counts appointments,
-- and into `resource_slots` where the reservation logic would treat it as
-- occupancy it must never move. The block is a separate fact: this resource is
-- not available between two instants, for a stated reason.
--
-- Times are UTC like every other instant; the admin form converts from the clinic
-- zone on the way in. `reason` is required because "why is Thursday closed" is the
-- first question anyone asks, and an unexplained closed day cannot be defended.

CREATE TABLE resource_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_id BIGINT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    reason VARCHAR(255) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_blocks_resource_window (resource_id, starts_at, ends_at),
    KEY ix_blocks_ends (ends_at),
    CONSTRAINT fk_blocks_resource
        FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE RESTRICT,
    CONSTRAINT fk_blocks_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_blocks_window CHECK (ends_at > starts_at),
    CONSTRAINT ck_blocks_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
