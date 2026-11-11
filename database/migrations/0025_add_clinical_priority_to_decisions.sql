-- 0025_add_clinical_priority_to_decisions.sql
-- The category a clinician assigned, recorded on the decision that assigned it.
--
-- `referrals.clinical_priority` holds the value that stands today. On its own it
-- cannot say when it was assigned, by whom, or what it replaced, and
-- HR-IR-CLIN-R1-001 §12.1 requires the review record itself to carry "clinical
-- priority and rationale, if assigned or changed". Reading that from the decision
-- row answers it from the record; reading it from the referral answers it from the
-- newest timestamp, which is the mistake `currentDecision()` already exists to
-- avoid.
--
-- Existing rows stay NULL rather than being back-filled. Every decision already
-- in this table was taken before the approved model existed; picking a category
-- for them would invent a clinical judgement nobody recorded, which is the one
-- thing §12.4 forbids.
--
-- Ordering (the lesson from 0024): the column is added before the constraint, and
-- nothing here rewrites a row afterwards, so no constraint has to be lifted first.

ALTER TABLE clinical_decisions
    ADD COLUMN clinical_priority VARCHAR(32) NULL AFTER rationale;

ALTER TABLE clinical_decisions
    ADD CONSTRAINT ck_decisions_priority CHECK (
        clinical_priority IS NULL
        OR clinical_priority IN ('routine', 'expedited', 'urgent_direct_coordination')
    );
