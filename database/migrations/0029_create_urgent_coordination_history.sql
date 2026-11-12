-- 0029_create_urgent_coordination_history.sql
-- The coordination axis gets the same trail the clinical axis already had.
--
-- `urgent_coordination` on the case row records where the operational side has
-- got to. Three of its six states are written today — acknowledged, contacting,
-- escalated — each by its own command with its own row elsewhere. `coordinating`
-- and `resolved` have no writer, so a case reads "Acknowledged" for the rest of
-- its life, including after it closes. On a board that exists to show whether
-- anybody has picked a case up, that is the difference between "nobody is on
-- this" and "this is finished".
--
-- `urgent_status_history` cannot carry these rows. Its `from_status`/`to_status`
-- pair is the clinical axis, and the timeline renders it as "Status a → b"; a
-- coordination move written there would read as a clinical change and put the two
-- axes back together, which is the one thing WF-05 exists to keep apart.
--
-- Append-only, like the clinical trail: a move is a row, and nothing rewrites one.

CREATE TABLE urgent_coordination_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    urgent_case_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    actor_role VARCHAR(20) NOT NULL,
    reason TEXT NULL,
    occurred_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_urgent_coordination_case (urgent_case_id, occurred_at),
    CONSTRAINT fk_urgent_coordination_case
        FOREIGN KEY (urgent_case_id) REFERENCES urgent_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_urgent_coordination_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_urgent_coordination_to CHECK (to_status IN (
        'unacknowledged',
        'acknowledged',
        'contacting',
        'coordinating',
        'escalated',
        'resolved'
    )),
    CONSTRAINT ck_urgent_coordination_from CHECK (
        from_status IS NULL OR from_status IN (
            'unacknowledged',
            'acknowledged',
            'contacting',
            'coordinating',
            'escalated',
            'resolved'
        )
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
