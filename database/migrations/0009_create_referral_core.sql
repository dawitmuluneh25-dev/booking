-- 0009_create_referral_core.sql
-- The elective referral aggregate: the clinical request, its history, the
-- radiologist's decisions, and the documents attached to it.
--
-- WF-01: a referral is not an appointment. `referrals.status` deliberately has
-- no scheduled/in_progress/completed value. Appointment and encounter states
-- live in `appointments` (0010), which is what WF-15 asks for and what the
-- discarded prototype got wrong by keeping one status field for both.
--
-- Conventions follow 0001-0008: InnoDB, utf8mb4, BIGINT UNSIGNED surrogate keys,
-- a human reference for correspondence, VARCHAR + named CHECK instead of MySQL
-- ENUM, and DATETIME columns holding UTC. The `_utc` suffix from the database
-- specification (DDS 4.2) is not retrofitted to eight existing tables; the
-- meaning is documented here instead of implied by a name that is only half
-- applied.
--
-- Immutability: `referral_status_history` and `clinical_decisions` are
-- append-only. No repository issues UPDATE or DELETE against them; a correction
-- is a new row. `supersedes_decision_id` is unique, so a decision can be
-- superseded at most once and the current decision is the row that nothing
-- supersedes.

CREATE TABLE referrals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(24) NOT NULL,
    status VARCHAR(32) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    patient_name VARCHAR(120) NOT NULL,
    patient_phone VARCHAR(32) NOT NULL,
    patient_dob DATE NULL,
    patient_sex VARCHAR(16) NULL,
    guardian_name VARCHAR(120) NULL,
    guardian_phone VARCHAR(32) NULL,
    guardian_relationship VARCHAR(40) NULL,
    guardian_verified_at DATETIME NULL,
    procedure_code VARCHAR(40) NOT NULL,
    requested_urgency VARCHAR(16) NOT NULL DEFAULT 'routine',
    clinical_priority VARCHAR(16) NULL,
    clinical_summary TEXT NULL,
    target_site VARCHAR(120) NULL,
    referring_physician_name VARCHAR(120) NULL,
    referring_facility VARCHAR(160) NULL,
    referring_physician_phone VARCHAR(32) NULL,
    submitted_by_user_id BIGINT UNSIGNED NOT NULL,
    submitted_on_behalf_of VARCHAR(16) NOT NULL,
    received_at DATETIME NULL,
    approved_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_referrals_reference (reference),
    KEY ix_referrals_status_created (status, created_at),
    KEY ix_referrals_procedure (procedure_code),
    KEY ix_referrals_submitter (submitted_by_user_id),
    CONSTRAINT fk_referrals_submitter
        FOREIGN KEY (submitted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_referrals_status CHECK (status IN (
        'submitted', 'received', 'administrative_review', 'awaiting_information',
        'awaiting_imaging', 'clinical_review', 'clinically_approved',
        'approved_for_scheduling', 'deferred', 'declined', 'alternative_pathway',
        'converted_to_appointment', 'cancelled', 'closed'
    )),
    CONSTRAINT ck_referrals_urgency CHECK (requested_urgency IN ('routine', 'priority')),
    CONSTRAINT ck_referrals_priority CHECK (
        clinical_priority IS NULL OR clinical_priority IN ('routine', 'priority', 'urgent')
    ),
    CONSTRAINT ck_referrals_behalf CHECK (
        submitted_on_behalf_of IN ('self', 'guardian', 'referrer', 'staff')
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE referral_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_role VARCHAR(32) NOT NULL,
    reason VARCHAR(255) NULL,
    occurred_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_referral_history_referral (referral_id, occurred_at),
    CONSTRAINT fk_referral_history_referral
        FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_referral_history_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clinical_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id BIGINT UNSIGNED NOT NULL,
    decision_type VARCHAR(24) NOT NULL,
    rationale TEXT NOT NULL,
    approved_technique VARCHAR(120) NULL,
    modality VARCHAR(60) NULL,
    sedation VARCHAR(60) NULL,
    estimated_duration_minutes SMALLINT UNSIGNED NULL,
    preprocedure_conditions TEXT NULL,
    redirect_destination VARCHAR(160) NULL,
    information_requested TEXT NULL,
    decided_by_user_id BIGINT UNSIGNED NOT NULL,
    decided_at DATETIME NOT NULL,
    supersedes_decision_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_decisions_supersedes (supersedes_decision_id),
    KEY ix_decisions_referral (referral_id, decided_at),
    KEY ix_decisions_decider (decided_by_user_id),
    CONSTRAINT fk_decisions_referral
        FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_decisions_decider
        FOREIGN KEY (decided_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_decisions_supersedes
        FOREIGN KEY (supersedes_decision_id) REFERENCES clinical_decisions (id) ON DELETE RESTRICT,
    CONSTRAINT ck_decisions_type CHECK (decision_type IN (
        'approved', 'deferred', 'declined', 'redirected', 'more_information'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE referral_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(24) NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    storage_key VARCHAR(190) NOT NULL,
    uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    redacted_at DATETIME NULL,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documents_storage_key (storage_key),
    KEY ix_documents_referral (referral_id, created_at),
    CONSTRAINT fk_documents_referral
        FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_documents_uploader
        FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_documents_kind CHECK (kind IN (
        'imaging_report', 'lab_result', 'referral_letter', 'consent', 'identity', 'other'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Roles used by the referral workflow must be assignable before Phase 3 adds
-- physician verification. Widening the CHECK now keeps the domain enum and the
-- database in agreement instead of leaving a role that cannot be stored.
ALTER TABLE users DROP CONSTRAINT ck_users_role;

ALTER TABLE users ADD CONSTRAINT ck_users_role CHECK (
    role IN ('admin', 'staff', 'radiologist', 'physician', 'coordinator', 'patient')
);
