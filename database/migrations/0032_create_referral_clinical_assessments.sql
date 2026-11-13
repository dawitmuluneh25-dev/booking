-- 0032_create_referral_clinical_assessments.sql
-- The pre-procedure assessment (§6.5, §6.6, A-02).
--
-- The rule that shapes this table is §6.2: a missing clinical field must not be
-- silently defaulted to "normal", "none" or "not applicable", and an empty
-- medication, allergy or pregnancy field does not mean the patient has none.
--
-- So the three questions that a form would normally turn into checkboxes are stored
-- as controlled vocabularies that include their own absence:
--
--   anticoagulant  reported_use | no_use_confirmed | unknown | not_yet_assessed
--   allergy        known_allergy | no_known_allergy_confirmed | unknown | not_yet_assessed
--   pregnancy      not_relevant | assessment_required | assessed_per_local_policy | unresolved
--
-- "No use confirmed" is a clinical statement somebody made after asking. "Unknown"
-- is a different fact. A nullable boolean could express neither, and would make
-- every blank form read as "no allergy" — which is the failure the section exists
-- to prevent. The column is therefore nullable *and* has a vocabulary, rather than
-- NOT NULL with a default: no row claims an assessment nobody performed, and the
-- page can say "not recorded" without inventing a negative.
--
-- Append-only, with a supersede chain like `clinical_decisions` and
-- `procedure_consents`: an assessment is a judgement made at a time, and "the
-- allergy was unknown before the procedure" has to survive "it was confirmed
-- afterwards". `supersedes_id` is unique, so the chain stays linear.
--
-- Deliberately absent from this increment: MED-002 current medications as a
-- structured list. It needs its own child table, and a free-text column here would
-- be the half-built version of a field the package describes as structured.

CREATE TABLE referral_clinical_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id BIGINT UNSIGNED NOT NULL,
    -- MED-003
    anticoagulant_status VARCHAR(32) NULL,
    anticoagulant_details VARCHAR(500) NULL,
    -- MED-004
    allergy_status VARCHAR(32) NULL,
    allergy_details VARCHAR(500) NULL,
    -- MED-005
    pregnancy_status VARCHAR(32) NULL,
    pregnancy_details VARCHAR(500) NULL,
    -- MED-001, MED-006, MED-007
    relevant_history TEXT NULL,
    prior_procedure VARCHAR(1000) NULL,
    examination_context TEXT NULL,
    -- IMG-001, IMG-002, IMG-004
    imaging_modality VARCHAR(32) NULL,
    imaging_date DATE NULL,
    imaging_reference VARCHAR(200) NULL,
    recorded_by_user_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    supersedes_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_referral_clinical_assessments_supersedes (supersedes_id),
    KEY ix_referral_clinical_assessments_referral (referral_id, recorded_at),
    CONSTRAINT fk_referral_clinical_assessments_referral
        FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_referral_clinical_assessments_recorder
        FOREIGN KEY (recorded_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_referral_clinical_assessments_supersedes
        FOREIGN KEY (supersedes_id) REFERENCES referral_clinical_assessments (id) ON DELETE RESTRICT,
    CONSTRAINT ck_referral_clinical_assessments_anticoagulant CHECK (
        anticoagulant_status IS NULL OR anticoagulant_status IN (
            'reported_use', 'no_use_confirmed', 'unknown', 'not_yet_assessed'
        )
    ),
    CONSTRAINT ck_referral_clinical_assessments_allergy CHECK (
        allergy_status IS NULL OR allergy_status IN (
            'known_allergy', 'no_known_allergy_confirmed', 'unknown', 'not_yet_assessed'
        )
    ),
    CONSTRAINT ck_referral_clinical_assessments_pregnancy CHECK (
        pregnancy_status IS NULL OR pregnancy_status IN (
            'not_relevant', 'assessment_required', 'assessed_per_local_policy', 'unresolved'
        )
    ),
    CONSTRAINT ck_referral_clinical_assessments_imaging CHECK (
        imaging_modality IS NULL OR imaging_modality IN (
            'ultrasound', 'ct', 'mri', 'fluoroscopy', 'other', 'none_available', 'unknown'
        )
    ),
    -- A stated status is a claim somebody made; the ones that assert a finding need
    -- somewhere to say what was found. "Known allergy" with no detail is a record a
    -- reader cannot act on, and §6.5 asks for the reaction where known.
    CONSTRAINT ck_referral_clinical_assessments_allergy_detail CHECK (
        NOT (allergy_status = 'known_allergy' AND allergy_details IS NULL)
    ),
    CONSTRAINT ck_referral_clinical_assessments_anticoagulant_detail CHECK (
        NOT (anticoagulant_status = 'reported_use' AND anticoagulant_details IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
