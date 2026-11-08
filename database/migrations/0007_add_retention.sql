-- 0007_add_retention.sql
-- Data protection: personal data has a maximum useful life.
--
-- A booking that happened or was cancelled keeps its reference, slot, status
-- and audit history forever, but the name, phone number and free-text notes are
-- overwritten once the retention window has passed. `redacted_at` records when
-- that happened so the sweep is idempotent and the state is visible.
--
-- retention_runs records each sweep: who ran it, over what cutoff, and how many
-- rows it touched. A retention action that cannot be audited is not a policy.

ALTER TABLE bookings
    ADD COLUMN redacted_at DATETIME NULL AFTER updated_at;

CREATE TABLE retention_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cutoff_date DATE NOT NULL,
    bookings_redacted INT UNSIGNED NOT NULL,
    actor VARCHAR(120) NOT NULL,
    ran_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_retention_runs_ran_at (ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
