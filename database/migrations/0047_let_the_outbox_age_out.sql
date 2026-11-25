-- HR-ENG-DEC-R1-003 D-02: the notification class retires at 365 days.
--
-- The outbox holds a recipient (a phone number or an address) and a payload naming the procedure and
-- the case reference. The booking and referral sweeps already overwrite the recipient of an outbox row
-- that belongs to a redacted record; what was missing was the standalone case — a referral notification
-- whose referral is still live, or an account request, sitting in the table for ever because nothing
-- ever looked at the notification class on its own.
--
-- `redacted_at` is what makes the sweep idempotent and auditable: without it, every run would
-- re-redact the same rows, report a count that means nothing, and leave no way to answer "when was this
-- one retired". It mirrors `bookings.redacted_at` and `referral_documents.redacted_at`, which is the
-- pattern the rest of the retention code already reads.
--
-- The index is on (redacted_at, created_at) because both the count and the batch select filter on
-- `redacted_at IS NULL` and order by age. Ordering by `created_at` alone would sort the whole table on
-- every sweep once the table grows past a few hundred thousand rows.

ALTER TABLE notification_outbox
    ADD COLUMN redacted_at DATETIME NULL DEFAULT NULL AFTER updated_at,
    ADD INDEX idx_outbox_retention (redacted_at, created_at);

-- The run record keeps counting per class. A sweep that retires notifications has to leave the same
-- kind of trace as one that retires bookings, or the audit trail quietly develops a hole exactly where
-- the newest retention rule applies.

ALTER TABLE retention_runs
    ADD COLUMN notifications_redacted INT UNSIGNED NOT NULL DEFAULT 0 AFTER documents_redacted;
