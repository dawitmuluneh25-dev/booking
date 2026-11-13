-- 0037_add_referrer_detail_fields.sql
-- The §6.4 fields the referral record does not yet hold: REF-003, REF-006, REF-009.
--
-- REF-003 is only half of what §6.4 asks for, and the other half already exists.
-- The document wants the credential *and* its verification state with an evidence
-- reference. Verification is not a property of a referral: it is a property of a
-- person, and it already lives on `physician_profiles` as pending, verified or
-- suspended (AUTH-02). Storing a second copy here would create two answers to "is
-- this clinician verified?" and one of them would eventually be wrong. What is
-- stored here is the credential as it was stated on this referral, which is a fact
-- about the referral.
--
-- All three are nullable: a patient-originated request has no referring clinician
-- and no clinical question, and §6.2 forbids defaulting those absences to a value.

ALTER TABLE referrals
    ADD COLUMN referring_physician_credentials VARCHAR(120) NULL AFTER referring_physician_name,
    ADD COLUMN referring_department VARCHAR(120) NULL AFTER referring_facility,
    ADD COLUMN clinical_question TEXT NULL AFTER clinical_summary;
