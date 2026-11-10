-- 0018_create_audit_events.sql
-- One table for authority changes, credential decisions and document releases.
--
-- Why a second audit table when history tables already exist: `booking_events`,
-- `referral_status_history` and `urgent_events` answer "what happened to this
-- object". None of them can answer "who was made an administrator, and when",
-- because a role change is not an object transition inside a workflow. Phase 2's
-- exit criterion ("account and permission changes are auditable") was unmet
-- without this table.
--
-- Append-only by construction: nothing in `src/` issues UPDATE or DELETE against
-- it, and a correction is a new row. The actor reference is RESTRICT rather than
-- SET NULL so an account that wrote history cannot be deleted out from under it;
-- retention redacts the person instead of removing the record (ADR-010).
--
-- No patient data: `object_type`/`object_ref` name an account, a physician profile
-- or a document's opaque reference. Patient name, phone and clinical free text
-- have no column here, and `object_ref` for a document is the same random handle
-- the URL uses, not a filename.

CREATE TABLE audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Nullable: a failed sign-in has no authenticated actor, and inventing one
    -- would make the row a lie.
    actor_user_id BIGINT UNSIGNED NULL,
    actor_role VARCHAR(32) NULL,
    action VARCHAR(64) NOT NULL,
    object_type VARCHAR(40) NOT NULL,
    object_ref VARCHAR(120) NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    -- One id per request or console run. Correlation, not identity.
    request_id CHAR(16) NULL,
    occurred_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_audit_actor (actor_user_id, occurred_at),
    KEY ix_audit_action (action, occurred_at),
    KEY ix_audit_object (object_type, object_ref, occurred_at),
    KEY ix_audit_occurred (occurred_at),
    CONSTRAINT fk_audit_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_audit_action CHECK (action <> ''),
    CONSTRAINT ck_audit_object CHECK (object_type <> '')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
