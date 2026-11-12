-- 0028_create_referral_priority_changes.sql
-- Changing an assigned clinical priority, with the change on the record.
--
-- §13.1 names "assign/change" as one action and requires the category to be
-- recorded "if changed from a previously assigned category". Until now only the
-- assignment half existed: `referrals.clinical_priority` holds the standing value
-- and `clinical_decisions.clinical_priority` holds the category each decision
-- assigned. Neither says that a referral which reads `expedited` today was
-- `routine` last week, or who changed it and why.
--
-- Overwriting the referral column would have been enough for the queue ordering
-- and wrong for everything else: the only evidence that a child's referral was
-- escalated would be a value in a mutable column, and the earlier category would
-- be gone. So the change is its own append-only row carrying both values.
--
-- `from_priority` is nullable because the column must be able to describe a
-- referral that had no category when the change was made. The command refuses
-- that case — reassignment requires something to reassign from — but a schema
-- that cannot represent it would be lying about the rows it holds.

CREATE TABLE referral_priority_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id BIGINT UNSIGNED NOT NULL,
    from_priority VARCHAR(32) NULL,
    to_priority VARCHAR(32) NOT NULL,
    reason TEXT NOT NULL,
    changed_by_user_id BIGINT UNSIGNED NOT NULL,
    changed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_priority_changes_referral (referral_id, changed_at),
    KEY ix_priority_changes_actor (changed_by_user_id),
    CONSTRAINT fk_priority_changes_referral
        FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_priority_changes_actor
        FOREIGN KEY (changed_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_priority_changes_from CHECK (
        from_priority IS NULL OR from_priority IN ('routine', 'expedited', 'urgent_direct_coordination')
    ),
    CONSTRAINT ck_priority_changes_to CHECK (
        to_priority IN ('routine', 'expedited', 'urgent_direct_coordination')
    ),
    -- A one-word reason is not a reason. The command enforces the same floor; this
    -- is the backstop for anything that writes the table directly.
    CONSTRAINT ck_priority_changes_reason CHECK (CHAR_LENGTH(reason) >= 10),
    CONSTRAINT ck_priority_changes_moves CHECK (
        from_priority IS NULL OR from_priority <> to_priority
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
