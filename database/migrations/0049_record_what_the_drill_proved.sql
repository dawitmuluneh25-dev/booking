-- HR-ENG-DEC-R1-003 §3.2 gate 6: a restore test, recorded.
--
-- `db:restore-drill` restores the newest archive into a scratch schema, compares row counts and prints
-- its verdict. Until now it printed and forgot: the evidence for "the backups restore" lived in whoever
-- happened to read the terminal, which is why the release gate reported the fact as unobservable rather
-- than true.
--
-- A row here is written by the drill itself, after the comparison passes. That ordering is the point:
-- the table cannot be filled by somebody asserting success, only by a run that compared 47 tables and
-- found their counts identical. A failed drill is recorded too, because "we tried on the 3rd and it
-- failed" is evidence a reader needs and an absence cannot express.
--
-- `verdict` is constrained rather than free text: a typo in a status column turns a failure into
-- something the gate reads as neither.

CREATE TABLE restore_drills (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    archive_name VARCHAR(120) NOT NULL,
    archive_encrypted TINYINT(1) NOT NULL,
    target_database VARCHAR(64) NOT NULL,
    tables_checked INT UNSIGNED NOT NULL,
    rows_checked INT UNSIGNED NOT NULL,
    verdict VARCHAR(16) NOT NULL,
    actor VARCHAR(190) NOT NULL,
    verified_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_restore_drills_verified (verdict, verified_at),
    CONSTRAINT ck_restore_drill_verdict CHECK (verdict IN ('ok', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
