-- D-02: `security_and_audit` is 2,557 days, and until now nothing applied that period.
--
-- The class was `declared_only`: the schedule named it, the gate counted seven classes, and no code path
-- ever removed a row. That is the state a retention policy reaches when it is written before the sweeps
-- — and the reason `RetentionCoverage` distinguishes a declared period from an enforced one.
--
-- Two counters, not one. `audit_events_removed` is the number that matters: once those rows are gone, this
-- run record is the only place left that can say how many went and when. `login_attempts_removed` counts a
-- different act against a different table, and a single number could not answer "did the audit trail
-- shrink?" without also counting failed sign-ins.
--
-- `account_request_attempts` is not here. It empties after one day under the rate-limit hygiene path, so a
-- 2,557-day ceiling over it would be a number that never applies.
--
-- `retention_runs` itself is never swept by this class: it is the record of the sweeps, and a class that
-- deleted its own evidence would be indistinguishable from one that never ran.

ALTER TABLE retention_runs
    ADD COLUMN audit_events_removed INT UNSIGNED NOT NULL DEFAULT 0 AFTER uploaded_documents_redacted,
    ADD COLUMN login_attempts_removed INT UNSIGNED NOT NULL DEFAULT 0 AFTER audit_events_removed;
