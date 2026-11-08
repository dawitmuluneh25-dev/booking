-- 0015_create_identity_authority.sql
-- The authority model Phase 3 needs: who is a verified referrer, who may act for
-- a patient, what they consented to, and how a session is revoked.
--
-- Three things this deliberately does not do:
--
-- * no self-service registration table — accounts are provisioned by an
--   administrator, and there is no code path that creates a user from a form;
-- * no `role` column that a client can post to — role lives on `users` and is
--   changed only by a command that also revokes sessions;
-- * no verification "flag" on the account itself. Referral authority is a
--   separate, reviewable record: a physician's profile can be suspended without
--   disabling their ability to sign in.

CREATE TABLE physician_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    practice_number VARCHAR(40) NOT NULL,
    institution VARCHAR(160) NOT NULL,
    specialty VARCHAR(80) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    verified_by_user_id BIGINT UNSIGNED NULL,
    verified_at DATETIME NULL,
    review_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_physician_user (user_id),
    UNIQUE KEY uq_physician_practice_number (practice_number),
    KEY ix_physician_status (status, created_at),
    CONSTRAINT fk_physician_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_physician_verifier
        FOREIGN KEY (verified_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_physician_status
        CHECK (status IN ('pending', 'verified', 'rejected', 'suspended')),
    CONSTRAINT ck_physician_verified
        CHECK (status <> 'verified' OR (verified_by_user_id IS NOT NULL AND verified_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who may act for a patient. A guardian is not implied by a shared phone number or
-- a similar name; it is a recorded relationship with an authority level.
CREATE TABLE patient_relationships (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_user_id BIGINT UNSIGNED NOT NULL,
    related_user_id BIGINT UNSIGNED NOT NULL,
    relationship VARCHAR(20) NOT NULL,
    authority VARCHAR(20) NOT NULL DEFAULT 'view',
    verified_at DATETIME NULL,
    ended_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_relationship_pair (patient_user_id, related_user_id),
    KEY ix_relationship_related (related_user_id, ended_at),
    CONSTRAINT fk_relationship_patient
        FOREIGN KEY (patient_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_relationship_related
        FOREIGN KEY (related_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT ck_relationship_type
        CHECK (relationship IN ('self', 'guardian', 'carer', 'parent', 'other')),
    CONSTRAINT ck_relationship_authority
        CHECK (authority IN ('view', 'act')),
    CONSTRAINT ck_relationship_not_self
        CHECK (patient_user_id <> related_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Consent is a record with a date and an author, not a boolean on the patient.
-- Withdrawal is a separate row (`withdrawn_at`) so the history of what was agreed
-- survives the withdrawal.
CREATE TABLE consent_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_user_id BIGINT UNSIGNED NOT NULL,
    consent_type VARCHAR(24) NOT NULL,
    granted_by_user_id BIGINT UNSIGNED NOT NULL,
    evidence VARCHAR(500) NOT NULL,
    granted_at DATETIME NOT NULL,
    withdrawn_at DATETIME NULL,
    withdrawn_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_consent_patient (patient_user_id, consent_type, withdrawn_at),
    CONSTRAINT fk_consent_patient
        FOREIGN KEY (patient_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_consent_grantor
        FOREIGN KEY (granted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_consent_withdrawer
        FOREIGN KEY (withdrawn_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_consent_type
        CHECK (consent_type IN ('treatment', 'communication', 'guardian_access', 'data_sharing')),
    CONSTRAINT ck_consent_withdrawal
        CHECK ((withdrawn_at IS NULL) = (withdrawn_by_user_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Session revocation. Authentication re-reads the user on every request, but a
-- role change must also sign the person out: otherwise a demoted administrator
-- keeps a live session that still renders admin pages from cached state, and a
-- promoted one keeps being refused with no explanation.
ALTER TABLE users
    ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER is_active;

-- Which referrer sent this referral, for object-level scoping (AUTH-03). The free
-- text physician name stays: it is what the letter says, and a profile may be
-- created after the referral arrives.
ALTER TABLE referrals
    ADD COLUMN referring_physician_profile_id BIGINT UNSIGNED NULL AFTER referring_physician_phone,
    ADD KEY ix_referrals_physician (referring_physician_profile_id),
    ADD CONSTRAINT fk_referrals_physician
        FOREIGN KEY (referring_physician_profile_id)
        REFERENCES physician_profiles (id) ON DELETE SET NULL;
