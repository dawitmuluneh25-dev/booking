-- 0013_create_urgent_status_history.sql
-- Append-only trail for urgent case transitions.
--
-- The database specification lists acknowledgements, contact attempts, escalations
-- and coordination events for the urgent aggregate, but no status history. Those
-- four record what people did; they do not record that the clinical status moved,
-- or who moved it. Without this table, "who marked this child as accepted for
-- coordination, and when" has no answer, which is what WF-09 forbids.
--
-- Kept deliberately identical in shape to referral_status_history so the two
-- pathways are read the same way in an audit.

CREATE TABLE urgent_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    urgent_case_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    actor_role VARCHAR(20) NOT NULL,
    reason TEXT NULL,
    occurred_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_urgent_history_case (urgent_case_id, occurred_at),
    CONSTRAINT fk_urgent_history_case
        FOREIGN KEY (urgent_case_id) REFERENCES urgent_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_urgent_history_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
