-- 0020_split_biopsy_services_and_add_draft.sql
-- Two changes the intake screen needs, both decided rather than assumed.
--
-- 1. FNAC and core biopsy become separate procedures. The product decision
--    (2026-11-10) is that the referral records which technique is requested,
--    because the two have different consumables and different expected
--    histopathology. The combined `image_guided_biopsy` code is retired to
--    inactive rather than deleted: two referrals in the existing data use it,
--    and a stored code must keep resolving to a name even after it stops being
--    offered. Nothing is rewritten, because which technique those two cases used
--    is not recorded anywhere and cannot be inferred.
--
-- 2. `draft` joins the referral vocabulary. A referrer saves an incomplete
--    referral and returns to it; a draft is not submitted, is not visible to
--    anyone else, and does not enter the triage queue. The status CHECK is
--    dropped and recreated rather than extended, because MySQL has no way to add
--    a value to an existing CHECK.

INSERT INTO procedures (code, label, category, default_duration_minutes, created_at, updated_at)
VALUES
    ('image_guided_fnac', 'Image-guided fine needle aspiration (FNAC)', 'elective', 30,
        UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('image_guided_core_biopsy', 'Image-guided core biopsy', 'elective', 30,
        UTC_TIMESTAMP(), UTC_TIMESTAMP());

UPDATE procedures
SET active = 0,
    label = 'Image-guided biopsy (combined, retired)',
    updated_at = UTC_TIMESTAMP()
WHERE code = 'image_guided_biopsy';

ALTER TABLE referrals DROP CONSTRAINT ck_referrals_status;

ALTER TABLE referrals ADD CONSTRAINT ck_referrals_status CHECK (status IN (
    'draft', 'submitted', 'received', 'administrative_review', 'awaiting_information',
    'awaiting_imaging', 'clinical_review', 'clinically_approved',
    'approved_for_scheduling', 'deferred', 'declined', 'alternative_pathway',
    'converted_to_appointment', 'cancelled', 'closed'
));
