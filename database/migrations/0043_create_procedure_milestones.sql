-- 0043_create_procedure_milestones.sql
-- What happened on the procedure day, in the order it happened (§15).
--
-- `appointment_status_history` already records the room's state changes. This
-- table records the *events* those states were meant to describe, and it does so
-- with a name, an actor and a time of its own:
--
--   * arrival — the patient is here;
--   * consent_verified — the consent process was checked at the point of care,
--     naming the consent record it was checked against (§14.2 COM-CHK-013);
--   * procedure_started — the operator began;
--   * procedure_completed — the operator finished;
--   * recovery_completed — the post-procedure observation is over.
--
-- Append-only. There is no `updated_at`, and no application code issues UPDATE or
-- DELETE against this table: a milestone is a statement that somebody did
-- something at a time, and a statement of that kind is corrected by another
-- statement, not by rewriting this one. The status history is the same shape for
-- the same reason.
--
-- `out_of_order_reason` exists because AC-4 permits an out-of-sequence record with
-- a reason and forbids one without. The CHECK is the second gate: a reason shorter
-- than ten characters is not a reason, so it cannot be stored even by a writer that
-- skipped the command.
--
-- `resulting_status` is what this milestone did to the appointment, when it did
-- anything. Storing it means the page can show one timeline rather than two: a
-- status row that a milestone explains is not printed twice.
--
-- `consent_id` may only be set on `consent_verified`. A milestone that says consent
-- was verified should be able to name what was verified, and no other event has a
-- consent to name.

CREATE TABLE procedure_milestones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(32) NOT NULL,
    occurred_at DATETIME NOT NULL,
    recorded_by_user_id BIGINT UNSIGNED NOT NULL,
    recorded_by_role VARCHAR(32) NOT NULL,
    note VARCHAR(500) NULL,
    consent_id BIGINT UNSIGNED NULL,
    resulting_status VARCHAR(32) NULL,
    out_of_order_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    -- Every read is "this appointment's day, oldest first".
    KEY ix_procedure_milestones_appointment (appointment_id, occurred_at),
    CONSTRAINT fk_procedure_milestone_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_procedure_milestone_recorder
        FOREIGN KEY (recorded_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_procedure_milestone_consent
        FOREIGN KEY (consent_id) REFERENCES procedure_consents (id) ON DELETE RESTRICT,
    CONSTRAINT ck_procedure_milestone_kind CHECK (kind IN (
        'arrival', 'consent_verified', 'procedure_started',
        'procedure_completed', 'recovery_completed'
    )),
    CONSTRAINT ck_procedure_milestone_resulting_status CHECK (
        resulting_status IS NULL OR resulting_status IN (
            'held', 'scheduled', 'confirmed', 'checked_in', 'preprocedure_verification',
            'ready', 'in_progress', 'completed', 'cancelled', 'no_show', 'deferred'
        )
    ),
    CONSTRAINT ck_procedure_milestone_reason CHECK (
        out_of_order_reason IS NULL OR CHAR_LENGTH(TRIM(out_of_order_reason)) >= 10
    ),
    CONSTRAINT ck_procedure_milestone_consent CHECK (
        consent_id IS NULL OR kind = 'consent_verified'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
