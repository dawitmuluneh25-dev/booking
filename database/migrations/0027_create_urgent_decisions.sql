-- 0027_create_urgent_decisions.sql
-- The clinical decision on an urgent case, recorded rather than implied.
--
-- `urgent_status_history` already says a case moved from `under_clinical_review`
-- to `accepted_for_coordination`. It cannot say why, by which controlled choice,
-- or on whose judgement — and §10.2 `INT-016` requires exactly that: a
-- coordination decision with a controlled choice and a rationale, mandatory
-- before the case is closed or scheduled.
--
-- A status trail is a record of movement. This table is the record of a decision,
-- which is the thing a clinician is accountable for. Keeping them apart is what
-- lets a deferred review leave the status where it was while still being recorded
-- as a decision, and stops "the case is accepted" from being the only evidence
-- that anybody decided to accept it.
--
-- Append-only, like `clinical_decisions`: no UPDATE and no DELETE. A change of
-- mind is a new row, and `supersedes_decision_id` is unique, so a decision can be
-- superseded at most once and the current decision is the row nothing supersedes.

CREATE TABLE urgent_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    urgent_case_id BIGINT UNSIGNED NOT NULL,
    decision_type VARCHAR(40) NOT NULL,
    rationale TEXT NOT NULL,
    -- The state this decision moved the case into, or NULL where the choice
    -- releases no state (a deferral, or `other`). Denormalised on purpose: a later
    -- change to the mapping must not silently rewrite what an earlier decision did.
    resulting_status VARCHAR(32) NULL,
    decided_by_user_id BIGINT UNSIGNED NOT NULL,
    decided_at DATETIME NOT NULL,
    supersedes_decision_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_urgent_decisions_supersedes (supersedes_decision_id),
    KEY ix_urgent_decisions_case (urgent_case_id, decided_at),
    KEY ix_urgent_decisions_decider (decided_by_user_id),
    CONSTRAINT fk_urgent_decisions_case
        FOREIGN KEY (urgent_case_id) REFERENCES urgent_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_urgent_decisions_decider
        FOREIGN KEY (decided_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_urgent_decisions_type CHECK (decision_type IN (
        'proceed_to_resource_coordination',
        'alternative_pathway',
        'transfer',
        'defer_pending_review',
        'decline_ir_reduction',
        'other'
    )),
    CONSTRAINT ck_urgent_decisions_result CHECK (
        resulting_status IS NULL OR resulting_status IN (
            'under_clinical_review',
            'accepted_for_coordination',
            'redirected',
            'declined',
            'transferred',
            'completed'
        )
    ),
    CONSTRAINT ck_urgent_decisions_rationale CHECK (CHAR_LENGTH(rationale) >= 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
