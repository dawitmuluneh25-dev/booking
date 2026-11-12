-- 0030_create_procedure_consents.sql
-- Consent for a procedure, recorded as the dialogue it is (§18.1).
--
-- `consent_records` (0015) already holds the product-level consents — treatment,
-- communication, guardian access, data sharing — as a type, a grantor and a date.
-- That shape is wrong for §18.1: an informed consent is not an acceptance of terms
-- but a shared decision whose substance has to be readable years later. What was
-- discussed, with which version of the patient information, in which language,
-- through an interpreter or not, with whose authority when the patient is a child,
-- and who is accountable for it.
--
-- Two rules in this table are deliberately structural rather than advisory:
--
--   * `ck_procedure_consents_authority` refuses an agreement recorded on behalf of
--     somebody else whose authority has not been verified (§18.2). The system must
--     not assume that a parent or caregiver may consent; it records the
--     relationship and the verification separately, and cannot turn an unverified
--     relationship into a valid agreement.
--   * There is no `emergency` column and no bypass (§18.3). An emergency exception
--     is a decision the Clinical Lead and Legal/Governance define, and until they
--     define it the system records that consent could not be obtained and routes
--     the case to a person. A boolean here would be the generic override the
--     section forbids.
--
-- Withdrawal is a timestamp and an actor on the same row, not a status: what was
-- agreed, and by whom, stays readable after it is withdrawn (as with
-- `consent_records`). A correction is a new row that supersedes the old one, and
-- `supersedes_consent_id` is unique so the chain stays linear.

CREATE TABLE procedure_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id BIGINT UNSIGNED NOT NULL,
    -- The person who took part in the discussion, and how they stand to the
    -- patient. `self` means the patient themself.
    participant_relationship VARCHAR(16) NOT NULL,
    participant_name VARCHAR(120) NOT NULL,
    authority_verified TINYINT(1) NOT NULL DEFAULT 0,
    authority_verified_by_user_id BIGINT UNSIGNED NULL,
    authority_verified_at DATETIME NULL,
    capacity_assessed TINYINT(1) NOT NULL DEFAULT 0,
    capacity_note VARCHAR(500) NULL,
    -- Which site or lesion was discussed. Free text because the referral's own
    -- `target_site` is free text, and this record must be able to name it.
    target_site VARCHAR(120) NULL,
    -- §18.1: the information shown, its version and its language. Versions are not
    -- generated here; an empty string would let a record claim a discussion of
    -- "the leaflet" without saying which one.
    information_version VARCHAR(60) NOT NULL,
    information_language VARCHAR(16) NOT NULL,
    -- The practitioner's own account of benefits, material risks and alternatives,
    -- and of questions raised. Prose, because §18.1 asks for what was documented,
    -- not for a form that the software completes.
    discussion_record TEXT NOT NULL,
    questions_recorded TEXT NULL,
    interpreter_involved TINYINT(1) NOT NULL DEFAULT 0,
    interpreter_language VARCHAR(16) NULL,
    outcome VARCHAR(24) NOT NULL,
    -- Signature, attestation or the reference of an approved document held
    -- elsewhere. Required: an agreement with nothing behind it is not a record.
    attestation_reference VARCHAR(200) NOT NULL,
    responsible_practitioner_user_id BIGINT UNSIGNED NOT NULL,
    recorded_by_user_id BIGINT UNSIGNED NOT NULL,
    consented_at DATETIME NOT NULL,
    withdrawn_at DATETIME NULL,
    withdrawn_by_user_id BIGINT UNSIGNED NULL,
    withdrawal_reason VARCHAR(500) NULL,
    supersedes_consent_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_procedure_consents_supersedes (supersedes_consent_id),
    KEY ix_procedure_consents_referral (referral_id, consented_at),
    KEY ix_procedure_consents_practitioner (responsible_practitioner_user_id),
    CONSTRAINT fk_procedure_consents_referral
        FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_procedure_consents_practitioner
        FOREIGN KEY (responsible_practitioner_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_procedure_consents_recorder
        FOREIGN KEY (recorded_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_procedure_consents_authority_verifier
        FOREIGN KEY (authority_verified_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_procedure_consents_withdrawer
        FOREIGN KEY (withdrawn_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_procedure_consents_supersedes
        FOREIGN KEY (supersedes_consent_id) REFERENCES procedure_consents (id) ON DELETE RESTRICT,
    CONSTRAINT ck_procedure_consents_relationship CHECK (
        participant_relationship IN ('self', 'guardian', 'parent', 'carer', 'other')
    ),
    CONSTRAINT ck_procedure_consents_outcome CHECK (
        outcome IN ('agreed', 'declined', 'unable_to_consent')
    ),
    -- Verification is a fact with an author and a time, or it is absent.
    CONSTRAINT ck_procedure_consents_verified CHECK (
        authority_verified = 0
        OR (authority_verified_by_user_id IS NOT NULL AND authority_verified_at IS NOT NULL)
    ),
    -- §18.2: no agreement on behalf of another person without verified authority.
    CONSTRAINT ck_procedure_consents_authority CHECK (
        NOT (outcome = 'agreed' AND participant_relationship <> 'self' AND authority_verified = 0)
    ),
    CONSTRAINT ck_procedure_consents_interpreter CHECK (
        interpreter_involved = 0 OR interpreter_language IS NOT NULL
    ),
    CONSTRAINT ck_procedure_consents_withdrawal CHECK (
        (withdrawn_at IS NULL) = (withdrawn_by_user_id IS NULL)
    ),
    CONSTRAINT ck_procedure_consents_withdrawal_reason CHECK (
        withdrawn_at IS NULL OR CHAR_LENGTH(TRIM(COALESCE(withdrawal_reason, ''))) >= 10
    ),
    -- "Consent obtained" is not a record of a dialogue.
    CONSTRAINT ck_procedure_consents_discussion CHECK (
        CHAR_LENGTH(TRIM(discussion_record)) >= 30
    ),
    CONSTRAINT ck_procedure_consents_attestation CHECK (
        CHAR_LENGTH(TRIM(attestation_reference)) > 0
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
