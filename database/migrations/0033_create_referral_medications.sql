-- 0033_create_referral_medications.sql
-- Current medications, as a list rather than a paragraph (§6.5, MED-002).
--
-- The package asks for a structured list plus free text, and for "unknown" and
-- "not reconciled" to be marked distinctly. Both parts matter, and they answer
-- different questions:
--
--   * the rows say *what* was reported — name, and the dose and frequency where
--     they were known, free text because a dose that is known is often only known
--     loosely ("5 mg, sometimes half a tablet");
--   * `medication_review_status` on the parent assessment says how far the
--     conversation got, because a list nobody has checked is not the same record as
--     a list a clinician has reconciled, and an empty list is not "takes nothing".
--
-- The four statuses are the distinction §6.5 asks for, and the command pairs each
-- with the shape it implies:
--
--   recorded                  a clinician-entered list, reviewed
--   not_reconciled            a list that exists but nobody has checked it yet
--   none_reported_confirmed   asked, and the answer was none
--   unknown                   the question has not been answered
--
-- Rows hang off the assessment revision, not off the referral, so superseding an
-- assessment supersedes its medication list with it. That is the point of keeping
-- the two together: "what was on the list when the decision was made" has to be
-- answerable, and a list stored against the referral would quietly become today's
-- list under yesterday's assessment.

ALTER TABLE referral_clinical_assessments
    ADD COLUMN medication_review_status VARCHAR(24) NULL AFTER examination_context,
    ADD CONSTRAINT ck_referral_clinical_assessments_medication_status CHECK (
        medication_review_status IS NULL OR medication_review_status IN (
            'recorded', 'not_reconciled', 'none_reported_confirmed', 'unknown'
        )
    );

CREATE TABLE referral_medications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    dose VARCHAR(100) NULL,
    frequency VARCHAR(100) NULL,
    position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY ix_referral_medications_assessment (assessment_id, position),
    CONSTRAINT fk_referral_medications_assessment
        FOREIGN KEY (assessment_id) REFERENCES referral_clinical_assessments (id) ON DELETE RESTRICT,
    CONSTRAINT ck_referral_medications_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT ck_referral_medications_position CHECK (position BETWEEN 1 AND 50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
