-- 0019_create_procedures.sql
-- The procedure catalogue, and the constraint that makes `procedure_code` a
-- reference rather than a free string.
--
-- Why now: `referrals.procedure_code` was a VARCHAR validated only by a regular
-- expression, so any lowercase token was a procedure. That is how the day board
-- came to print "Us guided biopsy" — the code was stored, cleaned up at render
-- time, and never resolved to a name. A catalogue turns the code into a
-- reference and gives the name one place to live.
--
-- Clinical fields are deliberately absent. The UI/UX specification is explicit
-- that procedure-specific required fields must be approved by clinical
-- governance (REF-02, ADM-03), so `required_fields` starts NULL and the CHECK
-- below makes it impossible to add fields without naming who approved them.
--
-- Existing rows are preserved: every code already stored is carried into the
-- catalogue as an inactive entry, so history stays valid while the intake form
-- only offers approved services.

CREATE TABLE procedures (
    code VARCHAR(40) NOT NULL,
    label VARCHAR(120) NOT NULL,
    -- 'urgent' is not a public booking option; it exists so an urgent case can
    -- name the procedure it is about without borrowing an elective one (WF-04).
    category VARCHAR(16) NOT NULL DEFAULT 'elective',
    default_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    -- Approved extra fields, as a JSON object of field => rule. NULL means no
    -- field set has been approved for this procedure yet.
    required_fields JSON NULL,
    field_set_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    approved_by VARCHAR(120) NULL,
    approved_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (code),
    KEY ix_procedures_active (active, category),
    CONSTRAINT ck_procedures_category CHECK (category IN ('elective', 'urgent')),
    -- A clinical field set that nobody approved is exactly what REF-02 forbids.
    CONSTRAINT ck_procedures_approved CHECK (
        required_fields IS NULL OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The three services the UI/UX specification names (PUB-02 §7). Labels are the
-- specification's own wording; no duration or field set is claimed as approved.
INSERT INTO procedures (code, label, category, default_duration_minutes, created_at, updated_at)
VALUES
    ('image_guided_biopsy', 'Image-guided biopsy and FNAC', 'elective', 30, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('image_guided_abscess_drainage', 'Image-guided abscess drainage', 'elective', 45, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('hydrostatic_intussusception_reduction', 'Hydrostatic intussusception reduction', 'urgent', 60, UTC_TIMESTAMP(), UTC_TIMESTAMP());

-- Codes already in the database: kept so their referrals survive, inactive so
-- the form stops offering a procedure nobody configured.
INSERT INTO procedures (code, label, category, active, created_at, updated_at)
SELECT DISTINCT r.procedure_code, r.procedure_code, 'elective', 0, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM referrals AS r
WHERE NOT EXISTS (
    SELECT 1 FROM procedures AS p WHERE p.code = r.procedure_code
);

ALTER TABLE referrals
    ADD CONSTRAINT fk_referrals_procedure
    FOREIGN KEY (procedure_code) REFERENCES procedures (code)
    ON UPDATE RESTRICT ON DELETE RESTRICT;
