-- 0046_let_the_escalation_register_hold_unfilled_routes.sql
-- A register of five route categories, four of which have nobody in them yet.
--
-- 0042 built the roster Decision 4 asked for: five kinds, a named contact, a number, and a
-- separate act of verification. It assumed every row would arrive complete, because the only way
-- to create one was the admin form, and that form demands a name and a number.
--
-- HR-ENG-DEC-R1-003 D-06 asks for something slightly different: "The escalation register shall
-- contain these five route categories", seeded inactive, with the receiving role, contact, hours,
-- acknowledgement expectation, fallback route and verification recorded against each. Operations
-- has not supplied the contacts, and D-06 forbids inventing them. So the five categories have to be
-- able to exist as rows with nothing in them — otherwise the register cannot say what it is waiting
-- for, and the only honest answer to "which routes exist?" is "none".
--
-- Three changes follow, and the fourth is the rule that keeps them safe:
--
--   * contact_name and contact_phone become NULLable — a category can be listed before it is
--     staffed;
--   * operating_hours, acknowledgement_expectation and fallback_route are added, because D-06 names
--     them and a register that cannot record what it asked for is a list of names;
--   * ck_escalation_route_active_complete is the price of the two above: a route may be incomplete
--     only while it is inactive. Activating one without a contact is refused by the database, not by
--     a screen somebody can post around.
--
-- The seeded rows themselves are not inserted here. A migration that writes register content would
-- put five placeholder rows into every test database and every developer's copy, and "which routes
-- does this environment have?" would stop being a fact about the environment. `escalation:seed-
-- register` inserts them, on purpose, where somebody can see it happen.

ALTER TABLE escalation_routes
    MODIFY contact_name VARCHAR(120) NULL,
    MODIFY contact_phone VARCHAR(32) NULL;

ALTER TABLE escalation_routes
    ADD COLUMN operating_hours VARCHAR(120) NULL,
    ADD COLUMN acknowledgement_expectation VARCHAR(160) NULL,
    ADD COLUMN fallback_route VARCHAR(160) NULL;

-- The old rule read CHAR_LENGTH(TRIM(contact_phone)) >= 7. A NULL makes that expression NULL, which
-- MySQL and MariaDB both treat as satisfied, so the old constraint would have let a NULL through by
-- accident. Written explicitly so the allowance is a decision rather than an artefact of NULL
-- arithmetic.
ALTER TABLE escalation_routes
    DROP CONSTRAINT ck_escalation_route_phone;

ALTER TABLE escalation_routes
    ADD CONSTRAINT ck_escalation_route_phone
    CHECK (contact_phone IS NULL OR CHAR_LENGTH(TRIM(contact_phone)) >= 7);

-- D-06's "seed the five records as inactive", enforced: incomplete is a state, and inactive is the
-- only state it is allowed in.
ALTER TABLE escalation_routes
    ADD CONSTRAINT ck_escalation_route_active_complete
    CHECK (is_active = 0 OR (
        contact_name IS NOT NULL
        AND contact_phone IS NOT NULL
    ));
