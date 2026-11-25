-- HR-ENG-DEC-R1-003 D-05 and D-08: the five appointments get holders, in the database, with a trail.
--
-- D-07 adopted the five responsibilities as structure and deliberately named nobody. D-05 is the
-- decision that names them, and D-08 is the decision about where a name lives: secrets stay in the
-- environment, operational values move here. An operational value is something Operations edits —
-- a person on a rota, their deputy — and it cannot live in a commit, because the change would need a
-- deploy and would leave no attributable record.
--
-- Rows are seeded for all five responsibilities with no holder. That is deliberate: the screen must
-- not depend on a row existing before somebody can fill it in, and an empty row is the truthful
-- representation of "this appointment exists and nobody holds it" — which is exactly what the
-- coverage gate needs to be able to say.
--
-- No generic key/value table. A text bucket would take any key, any value, and record nothing about
-- what either means; the escalation roster already has typed columns, and this table does too. If a
-- future operational value arrives, it gets a column with a name, not a row in a dictionary.
--
-- Table options follow the rest of the schema — `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` and no explicit
-- collation. Naming a MySQL 8 collation here (`utf8mb4_0900_ai_ci`) is what the repository's MariaDB
-- parity job catches: MariaDB 10.11 does not have it, and the migration fails at apply time.

CREATE TABLE operational_appointments (
    role_key VARCHAR(48) NOT NULL,
    holder_name VARCHAR(120) NULL DEFAULT NULL,
    deputy_name VARCHAR(120) NULL DEFAULT NULL,
    updated_by VARCHAR(190) NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (role_key),
    CONSTRAINT ck_operational_appointment_holder
        CHECK (holder_name IS NULL OR CHAR_LENGTH(holder_name) >= 2),
    CONSTRAINT ck_operational_appointment_deputy
        CHECK (deputy_name IS NULL OR CHAR_LENGTH(deputy_name) >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO operational_appointments (role_key, holder_name, deputy_name, updated_by, updated_at)
VALUES
    ('operations_lead', NULL, NULL, 'migration:0048', UTC_TIMESTAMP()),
    ('referral_intake_coordinator', NULL, NULL, 'migration:0048', UTC_TIMESTAMP()),
    ('clinical_coordination_officer', NULL, NULL, 'migration:0048', UTC_TIMESTAMP()),
    ('procedure_scheduling_coordinator', NULL, NULL, 'migration:0048', UTC_TIMESTAMP()),
    ('backup_escalation_duty_coordinator', NULL, NULL, 'migration:0048', UTC_TIMESTAMP());
