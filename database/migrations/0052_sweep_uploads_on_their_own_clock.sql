-- D-02: `uploaded_clinical_documents` is 1,825 days, and until now nothing applied that period.
--
-- Documents were only ever redacted from inside `ReferralRetentionService::redactOne()` — that is, when
-- their parent referral was swept, on the referral's 2,557-day clock. A document attached to a referral
-- still inside its seven-year window was therefore kept past its own five-year period, indefinitely.
--
-- The class was reported as `partial` for the wrong reason until 26 November 2026: the note said
-- "uploads outside a referral" were not reached, but `referral_documents.referral_id` is NOT NULL and
-- always has been. There is no orphan population. The gap was a missing trigger, not a missing table.
--
-- This counter is separate from `documents_redacted` on purpose. That column counts files removed by the
-- referral sweep; this one counts documents retired because their own period expired while their
-- referral was still live. Two different acts, two different triggers, and a single counter would make
-- the run record unable to say which one happened.

ALTER TABLE retention_runs
    ADD COLUMN uploaded_documents_redacted INT UNSIGNED NOT NULL DEFAULT 0 AFTER documents_redacted;
