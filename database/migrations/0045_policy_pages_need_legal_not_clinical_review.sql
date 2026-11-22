-- 0045_policy_pages_need_legal_not_clinical_review.sql
-- One publication rule, two kinds of page.
--
-- 0035 encoded §17.5 as a CHECK: a row cannot reach 'published' without a clinical reviewer and an
-- approver, both dates, a review date and a translation-review status. §17.5 was written about
-- clinical copy — a service page describing a procedure — and for that copy a clinical reviewer is
-- the right requirement.
--
-- HR-ENG-DEC-R1-003 D-01 gives a different authority for a policy notice: Legal/Data Protection and
-- the Product Owner approve the content and confirm the dates. Neither is a clinician. The old
-- CHECK therefore made the privacy notice unpublishable for a reason that does not apply to it, and
-- the shortest way past a rule that does not fit is to type a clinician's name into a field they
-- never signed — which is precisely the forgery the rule exists to prevent.
--
-- So the clinical-reviewer requirement now follows the content key, the same classifier the
-- application uses (PublishedContent::POLICY_PREFIX): `policy.%` rows do not need one, and every
-- other row still does. Nothing is relaxed: a policy page still cannot publish without an approver,
-- an approval date, an effective date, a review date and a translation-review status, which is the
-- set D-01 does supply the dates for and does not supply the approval for.
--
-- DROP CONSTRAINT rather than DROP CHECK: both MySQL 8.0.19+ and MariaDB 10.2.22+ accept it, and the
-- CI database-parity job runs this file on MariaDB 10.11 as well as MySQL 8.4.

ALTER TABLE published_content
    DROP CONSTRAINT ck_published_content_complete;

-- The replacement, with the reviewer requirement made conditional and everything else unchanged.
ALTER TABLE published_content
    ADD CONSTRAINT ck_published_content_complete
    CHECK (status <> 'published' OR (
        (content_key LIKE 'policy.%' OR clinical_reviewer IS NOT NULL)
        AND approver IS NOT NULL
        AND approved_on IS NOT NULL
        AND effective_on IS NOT NULL
        AND review_on IS NOT NULL
        AND translation_review_status IS NOT NULL
        AND published_at IS NOT NULL
    ));
