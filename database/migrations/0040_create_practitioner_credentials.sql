-- 0040_create_practitioner_credentials.sql
-- Who may approve a procedure, and what says so (AC-009, LP-003, §16.3).
--
-- `physician_profiles` answers a different question. It records whether referrals
-- from a clinician are accepted; it grants no clinical authority and its subject
-- never approves anything. This table records the other direction: a practitioner
-- at this clinic, verified against a credential, holding a procedure-specific
-- privilege. Keeping them apart means suspending a referrer and suspending an
-- operator are separate acts with separate records, which they are.
--
-- The Product Owner's Decision 6 (14 November 2026) fixed the minimum record and
-- made the enforcement non-deferrable: approval must be unavailable unless the
-- verification is current and covers the requested procedure. The columns below
-- are that minimum, in the order the decision lists them, with three deliberate
-- omissions:
--
--   * no `is_approved` boolean — a flag with no date cannot expire, and expiry is
--     the failure this register exists to catch;
--   * no `expired` status — expiry is a fact about a date, computed when the
--     decision is taken. A stored 'expired' would need a job nobody has written,
--     and until it ran the row would keep saying 'verified';
--   * no free-text privilege scope — the scope is a reference to `procedures.code`,
--     so "covers this procedure" is a join rather than a sentence somebody has to
--     interpret at 2am.
--
-- `evidence_reference` is NOT NULL and has a minimum length because every row here
-- is somebody's recorded verification, not a claim awaiting one. A register that
-- accepts "verified, evidence: —" is a register that records confidence.
--
-- The institutional privilege status is nullable and free text on purpose: the
-- Product Owner's decision asks Clinical Governance first whether an existing
-- institutional register is the authoritative source. Until that is answered, this
-- table records what the verifier saw and does not pretend to be that register.

CREATE TABLE practitioner_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- The linked application account (Decision 6, first bullet).
    user_id BIGINT UNSIGNED NOT NULL,
    credential_type VARCHAR(60) NOT NULL,
    registration_number VARCHAR(60) NOT NULL,
    qualifications VARCHAR(500) NULL,
    -- What the verifier checked, so a later reader can weigh the verification.
    evidence_reference VARCHAR(200) NOT NULL,
    institutional_privilege_status VARCHAR(60) NULL,
    supervision_restriction VARCHAR(500) NULL,
    -- 'pending' is deliberately absent: recording the row *is* the verification.
    -- A credential nobody has verified is not a pending record, it is an absence.
    status VARCHAR(16) NOT NULL DEFAULT 'verified',
    verified_by_user_id BIGINT UNSIGNED NOT NULL,
    verified_at DATETIME NOT NULL,
    effective_from DATE NOT NULL,
    expires_on DATE NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    -- One registration number per credential type: the same number cannot be
    -- verified twice under two spellings of the same body.
    UNIQUE KEY uq_practitioner_credential (credential_type, registration_number),
    KEY ix_practitioner_credential_user (user_id, status),
    KEY ix_practitioner_credential_expiry (expires_on),
    CONSTRAINT fk_practitioner_credential_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_practitioner_credential_verifier
        FOREIGN KEY (verified_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_practitioner_credential_status
        CHECK (status IN ('verified', 'suspended', 'revoked')),
    CONSTRAINT ck_practitioner_credential_window
        CHECK (expires_on IS NULL OR expires_on >= effective_from),
    CONSTRAINT ck_practitioner_credential_evidence
        CHECK (CHAR_LENGTH(TRIM(evidence_reference)) >= 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The procedure-specific scope (Decision 6: "Procedure-specific competency and
-- privilege scope").
--
-- A privilege is a window, not a flag: it starts, and it may end. Ending one is
-- recorded by setting `effective_to`, because "it stopped counting on the 4th" is
-- a fact a later reader can check, whereas a deleted row leaves them guessing
-- whether the privilege ever existed.
--
-- Delegation and temporary coverage are recorded rather than implied (§16.3):
-- `basis` cannot be anything but 'own' without naming the practitioner whose
-- privilege is being covered, and temporary coverage cannot be unbounded — a
-- "temporary" authority with no end date is a permanent one wearing a label.
--
-- Supervision text is recorded and displayed, and deliberately not interpreted:
-- what a supervision condition permits is a clinical rule, and the decision that
-- supplies it has not been made. The check refuses on scope and dates, never on
-- the presence of a sentence.

CREATE TABLE practitioner_privileges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    credential_id BIGINT UNSIGNED NOT NULL,
    procedure_code VARCHAR(40) NOT NULL,
    basis VARCHAR(24) NOT NULL DEFAULT 'own',
    delegated_from_user_id BIGINT UNSIGNED NULL,
    supervision VARCHAR(300) NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    granted_by_user_id BIGINT UNSIGNED NOT NULL,
    granted_at DATETIME NOT NULL,
    note VARCHAR(500) NULL,
    PRIMARY KEY (id),
    -- Two grants of the same procedure that start on the same day are one grant
    -- entered twice. A later re-grant starts on its own date and keeps its history.
    UNIQUE KEY uq_practitioner_privilege (credential_id, procedure_code, effective_from),
    KEY ix_practitioner_privilege_procedure (procedure_code, effective_to),
    CONSTRAINT fk_practitioner_privilege_credential
        FOREIGN KEY (credential_id) REFERENCES practitioner_credentials (id) ON DELETE CASCADE,
    CONSTRAINT fk_practitioner_privilege_procedure
        FOREIGN KEY (procedure_code) REFERENCES procedures (code) ON DELETE RESTRICT,
    CONSTRAINT fk_practitioner_privilege_delegator
        FOREIGN KEY (delegated_from_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_practitioner_privilege_grantor
        FOREIGN KEY (granted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_practitioner_privilege_basis
        CHECK (basis IN ('own', 'delegated', 'temporary_coverage')),
    CONSTRAINT ck_practitioner_privilege_window
        CHECK (effective_to IS NULL OR effective_to >= effective_from),
    CONSTRAINT ck_practitioner_privilege_delegation
        CHECK (basis = 'own' OR delegated_from_user_id IS NOT NULL),
    CONSTRAINT ck_practitioner_privilege_temporary
        CHECK (basis <> 'temporary_coverage' OR effective_to IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
