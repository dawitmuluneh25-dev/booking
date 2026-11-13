-- 0036_add_patient_identity_fields.sql
-- The §6.3 patient fields the intake does not yet hold: PAT-003, PAT-004, PAT-007
-- and PAT-008.
--
-- Every column is nullable and none has a default, because §6.2 is explicit that a
-- missing clinical field must not be defaulted to "normal", "none" or "not
-- applicable". An absent age is absent; it is not zero years old.
--
-- The pairing constraints are the interesting part. An age of "3" with no unit is
-- not an age — three days and three years are different patients — so the value and
-- the unit are either both present or both absent. The same is true of an
-- identifier and its type: PAT-004 warns that a hospital number is not globally
-- unique, and the type is what gives it a scope.

ALTER TABLE referrals
    ADD COLUMN patient_age_value SMALLINT UNSIGNED NULL AFTER patient_dob,
    ADD COLUMN patient_age_unit VARCHAR(8) NULL AFTER patient_age_value,
    -- §6.3 requires recording who supplied the age. The document states the
    -- requirement and does not state the vocabulary, so the values are ours.
    ADD COLUMN patient_age_source VARCHAR(32) NULL AFTER patient_age_unit,
    ADD COLUMN patient_identifier VARCHAR(64) NULL AFTER patient_age_source,
    ADD COLUMN patient_identifier_type VARCHAR(32) NULL AFTER patient_identifier,
    ADD COLUMN patient_identifier_facility VARCHAR(160) NULL AFTER patient_identifier_type,
    ADD COLUMN patient_email VARCHAR(160) NULL AFTER patient_identifier_facility,
    ADD COLUMN preferred_language VARCHAR(40) NULL AFTER patient_email;

ALTER TABLE referrals
    ADD CONSTRAINT ck_referrals_age_pairing
        CHECK ((patient_age_value IS NULL) = (patient_age_unit IS NULL));

ALTER TABLE referrals
    ADD CONSTRAINT ck_referrals_age_unit
        CHECK (patient_age_unit IS NULL OR patient_age_unit IN ('days', 'weeks', 'months', 'years'));

ALTER TABLE referrals
    ADD CONSTRAINT ck_referrals_age_source
        CHECK (
            patient_age_source IS NULL
            OR patient_age_source IN (
                'patient', 'parent_or_guardian', 'caregiver',
                'referring_clinician', 'staff', 'other'
            )
        );

-- An age with no stated source is the case §6.3 asks us not to create: the number
-- is worth less than the sentence that says who gave it.
ALTER TABLE referrals
    ADD CONSTRAINT ck_referrals_age_has_a_source
        CHECK (patient_age_value IS NULL OR patient_age_source IS NOT NULL);

ALTER TABLE referrals
    ADD CONSTRAINT ck_referrals_identifier_pairing
        CHECK ((patient_identifier IS NULL) = (patient_identifier_type IS NULL));

ALTER TABLE referrals
    ADD CONSTRAINT ck_referrals_identifier_type
        CHECK (
            patient_identifier_type IS NULL
            OR patient_identifier_type IN ('hospital_number', 'clinic_number', 'referral_number', 'other')
        );
