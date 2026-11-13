-- 0031_create_laboratory_destinations.sql
-- Where a specimen goes (§19.1, LP-004 – LP-006).
--
-- A specimen record has to say where the specimen went in a form that can be
-- counted and audited, so the destination is a reference to a configured row and
-- never a free-text string. This table is that catalogue.
--
-- The series is serialised on purpose: `Pathology Lab 1`, `Pathology Lab 2`, and so
-- on. There is deliberately no name column. The approved decision named the scheme
-- and left the number of laboratories unstated, because laboratories exist as they
-- exist — inventing either a count or a set of plausible-sounding names would be
-- inventing an operating fact. A real name, once somebody supplies one, arrives as a
-- migration with content behind it, not as a text box that can drift out of step
-- with what the laboratory is actually called.
--
-- `is_active` exists so a laboratory that closes stops being offered for new
-- dispatch while the specimens already sent there keep pointing at something.

CREATE TABLE laboratory_destinations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- The serial is the identity: it produces both the code
    -- (`PATHOLOGY-LAB-<n>`) and the label (`Pathology Lab <n>`), so the two can
    -- never disagree.
    serial_no INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_laboratory_destinations_serial (serial_no),
    CONSTRAINT ck_laboratory_destinations_serial CHECK (serial_no BETWEEN 1 AND 999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
