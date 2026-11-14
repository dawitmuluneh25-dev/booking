-- 0042_create_escalation_routes.sql
-- Who to telephone, and whether anybody has checked that they answer (Decision 4).
--
-- Product Owner Decision 4 (14 November 2026) approved event-driven manual
-- escalation and refused every numeric interval, countdown and time-based
-- automatic escalation. What it *does* require is a real roster: primary, secondary,
-- receiving surgical team, after-hours where coverage genuinely exists, and a
-- technical fallback that is never a substitute for a clinical acknowledgement.
--
-- So this table holds contact arrangements and nothing about time. There is no
-- `interval_minutes`, no `escalate_after`, no `sla`. Their absence is the decision.
--
-- Two rules are written into the schema rather than left to the screen:
--
--   * a route is not verified by being typed in. `verified_at` and
--     `verified_by_user_id` are set by a separate act, so recording a number and
--     confirming that somebody answers it are different facts with different dates;
--   * an after-hours route that is not staffed cannot be verified. Decision 4 allows
--     after-hours escalation "where generally available only" — a number that rings
--     an empty office is worse than no number, because the caller believes the child
--     has been handed on.
--
-- Retiring a route (`is_active = 0`) keeps the row and its verification: a case
-- escalated through it last month still has to explain where it went.

CREATE TABLE escalation_routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind VARCHAR(24) NOT NULL,
    label VARCHAR(120) NOT NULL,
    contact_name VARCHAR(120) NOT NULL,
    contact_phone VARCHAR(32) NOT NULL,
    is_staffed TINYINT(1) NOT NULL DEFAULT 1,
    verified_by_user_id BIGINT UNSIGNED NULL,
    verified_at DATETIME NULL,
    verification_note VARCHAR(300) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    -- The same role cannot be listed twice under one label; two on-call
    -- radiologists need two different labels, which is what makes the roster
    -- readable rather than ambiguous.
    UNIQUE KEY uq_escalation_route (kind, label),
    KEY ix_escalation_route_kind (kind, is_active, verified_at),
    CONSTRAINT fk_escalation_route_verifier
        FOREIGN KEY (verified_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_escalation_route_kind
        CHECK (kind IN ('primary', 'secondary', 'receiving_team', 'after_hours', 'technical_fallback')),
    CONSTRAINT ck_escalation_route_verified
        CHECK ((verified_by_user_id IS NULL) = (verified_at IS NULL)),
    CONSTRAINT ck_escalation_route_note
        CHECK (verified_at IS NULL OR CHAR_LENGTH(TRIM(COALESCE(verification_note, ''))) >= 10),
    CONSTRAINT ck_escalation_route_after_hours
        CHECK (kind <> 'after_hours' OR verified_at IS NULL OR is_staffed = 1),
    CONSTRAINT ck_escalation_route_phone
        CHECK (CHAR_LENGTH(TRIM(contact_phone)) >= 7)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
