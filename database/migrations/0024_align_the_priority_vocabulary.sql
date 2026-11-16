-- 0024_align_the_priority_vocabulary.sql
-- The clinical priority model is approved (HR-IR-CLIN-R1-001 §13.1, A-08) as
-- three categories: routine, expedited and urgent direct coordination.
--
-- The catalogue disagreed with it in two directions. It stored `priority`, which
-- is not an approved word, and it offered no way to store `expedited` — the one
-- category an authorized clinician selects when earlier review or scheduling is
-- warranted. A report counting "priority" referrals could therefore never be
-- read against the approved model, and the model's middle category could not be
-- recorded at all.
--
-- Order matters, and this file learned it the hard way. Converting the data
-- first fails: writing 'expedited' into clinical_priority while the old CHECK is
-- still in force violates that CHECK, so the migration stops at its second
-- statement. The old rule is removed first, the data is converted while nothing
-- is enforcing the retired vocabulary, and the new rules are installed last,
-- validating everything the conversion produced.
--
-- The submitter/clinical split is kept, and it is the reason the two constraints
-- differ. `requested_urgency` is what a referrer, patient or guardian asks for,
-- so it accepts the requestable half only; `clinical_priority` is what an
-- authorized clinician assigns, so it accepts all three. A public form must not
-- be able to produce "urgent direct coordination" by posting a value.
--
-- Existing rows are converted rather than left behind: `priority` and `urgent`
-- have one meaning each in the approved model, and the mapping is exact. No row
-- is deleted and no other column is touched.

ALTER TABLE referrals DROP CONSTRAINT ck_referrals_urgency;

ALTER TABLE referrals DROP CONSTRAINT ck_referrals_priority;

UPDATE referrals SET requested_urgency = 'expedited' WHERE requested_urgency = 'priority';

UPDATE referrals SET clinical_priority = 'expedited' WHERE clinical_priority = 'priority';

UPDATE referrals SET clinical_priority = 'urgent_direct_coordination' WHERE clinical_priority = 'urgent';

-- 26 characters is the width of the approved value; the column had 16.
ALTER TABLE referrals MODIFY clinical_priority VARCHAR(32) NULL;

ALTER TABLE referrals ADD CONSTRAINT ck_referrals_urgency CHECK (
    requested_urgency IN ('routine', 'expedited')
);

ALTER TABLE referrals ADD CONSTRAINT ck_referrals_priority CHECK (
    clinical_priority IS NULL
    OR clinical_priority IN ('routine', 'expedited', 'urgent_direct_coordination')
);
