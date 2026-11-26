-- D-02: `dispatch_runs` entered the schedule on 26 November 2026 at 1,825 days.
--
-- The class joined the schedule and nothing applied the period, which is the state the coverage
-- report exists to expose: a period that reads like retention and deletes nothing. This migration
-- adds the counter that lets the sweep leave a trace.
--
-- The sweep deletes rather than redacts. A run row holds a start and finish time, four counters and
-- a hostname — no recipient, no case reference, no free text. There is nothing in it to pseudonymise,
-- so the act is removal, and `removed` is the honest verb.
--
-- What is lost when a row goes: the aggregate count of one dispatch invocation. What is not lost is
-- the per-message record, which lives in `notification_outbox` with its provider message id and its
-- status, and is what `outbox:receipt` reconciles against. The ledger's accounting value is the
-- outbox; the ledger's own value is liveness, and `notify:dispatch-check` reads the newest row, which
-- a 1,825-day window never touches.

ALTER TABLE retention_runs
    ADD COLUMN dispatch_runs_removed INT UNSIGNED NOT NULL DEFAULT 0 AFTER notifications_redacted;
