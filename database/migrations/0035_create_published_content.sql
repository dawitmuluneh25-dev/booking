-- 0035_create_published_content.sql
-- Patient-facing clinical content, with §17.5's provenance travelling beside it.
--
-- HR-IR-CLIN-R1-001 §17 supplies English master drafts for four service pages and
-- then §17.5 says what has to be true before any of them may be shown to a patient:
-- a content ID and audience, a version, an author, a clinical reviewer and an
-- approver, approval and effective dates, a review date and the version it
-- supersedes, the language and its translation-review status, and a rule that
-- expired or withdrawn content is hidden.
--
-- The reason this is a table rather than four templates is that §17.5 asks for
-- facts about each page, and a fact that lives in a PHP constant cannot be
-- reviewed, cannot expire, and cannot be shown to name an approver it does not
-- have. The approver named for this set is Dawit Muluneh, approved 2026-11-13;
-- that name is a row here, so replacing the approver is a data change.
--
-- Two structural guarantees are worth naming, because they are the point:
--
--   ck_published_content_complete — a row cannot reach 'published' without a
--   clinical reviewer, an approver, both dates, a review date and a
--   translation-review status. §17.5 is enforced by the schema, not by a form
--   that might be bypassed by a hand-crafted request.
--
--   uq_published_content_one_live — at most one published version of a page per
--   language, enforced through a generated column so that drafts and withdrawn
--   versions (which produce NULL) never collide. Two live versions of one page
--   would mean the site shows whichever the query happened to return first.
--
-- Expiry is deliberately *not* a status. A page expires because its review date
-- has passed, which is a fact about time rather than a decision anybody made, so
-- there is no 'expired' value a background job would have to invent by writing.

CREATE TABLE published_content (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- The stable identity of the page across versions, e.g. `service.fnac`.
    content_key VARCHAR(64) NOT NULL,
    -- §6.1's access classes: who the copy is written for.
    audience VARCHAR(16) NOT NULL,
    -- §17.5 records the language. Only the source language exists today, and it
    -- is a CHECK rather than a free string because §17.5 makes a translated page
    -- a reviewed artefact: adding Amharic or Afaan Oromo is a migration that
    -- carries the review behind it, not a label somebody can type.
    language VARCHAR(8) NOT NULL,
    title VARCHAR(160) NOT NULL,
    -- Sections of the page as JSON: [{"heading":"…","paragraphs":["…"]}] with
    -- optional "bullets" and "faq" arrays. Structured rather than Markdown or
    -- HTML so the template escapes every string it prints and no renderer, and
    -- no injected markup, sits between the approved words and the patient.
    body JSON NOT NULL,
    version VARCHAR(16) NOT NULL,
    author VARCHAR(120) NOT NULL,
    -- §17.5 lists a clinical reviewer and an approver as separate records. They
    -- may be the same person, but the schema does not assume it.
    clinical_reviewer VARCHAR(120) NULL,
    approver VARCHAR(120) NULL,
    approved_on DATE NULL,
    effective_on DATE NULL,
    -- The date the copy must be looked at again. Past it, the page stops being
    -- served (see PublishedContent::isServableOn()).
    review_on DATE NULL,
    superseded_version VARCHAR(16) NULL,
    translation_review_status VARCHAR(24) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    published_by_user_id BIGINT UNSIGNED NULL,
    withdrawn_at DATETIME NULL,
    withdrawn_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    -- NULL unless this row is the live version of its page. The unique key over
    -- it is what makes "two live versions" unrepresentable.
    live_key VARCHAR(64) GENERATED ALWAYS AS (
        CASE WHEN status = 'published' THEN content_key ELSE NULL END
    ) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_published_content_version (content_key, version, language),
    UNIQUE KEY uq_published_content_one_live (live_key, language),
    KEY ix_published_content_status (status, review_on),
    CONSTRAINT ck_published_content_status
        CHECK (status IN ('draft', 'published', 'withdrawn')),
    CONSTRAINT ck_published_content_audience
        CHECK (audience IN ('patient', 'referrer')),
    CONSTRAINT ck_published_content_language
        CHECK (language IN ('en')),
    CONSTRAINT ck_published_content_translation
        CHECK (translation_review_status IS NULL
            OR translation_review_status IN ('source', 'in_review', 'reviewed', 'not_required')),
    -- §17.5, in the schema: nothing is published on the strength of a name alone.
    CONSTRAINT ck_published_content_complete
        CHECK (status <> 'published' OR (
            clinical_reviewer IS NOT NULL
            AND approver IS NOT NULL
            AND approved_on IS NOT NULL
            AND effective_on IS NOT NULL
            AND review_on IS NOT NULL
            AND translation_review_status IS NOT NULL
            AND published_at IS NOT NULL
        )),
    -- A review date before the approval it reviews is not a review.
    CONSTRAINT ck_published_content_review_after_approval
        CHECK (review_on IS NULL OR approved_on IS NULL OR review_on >= approved_on),
    CONSTRAINT ck_published_content_effective_after_approval
        CHECK (effective_on IS NULL OR approved_on IS NULL OR effective_on >= approved_on),
    CONSTRAINT ck_published_content_withdrawn
        CHECK (status <> 'withdrawn' OR withdrawn_at IS NOT NULL),
    CONSTRAINT fk_published_content_publisher
        FOREIGN KEY (published_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_published_content_withdrawer
        FOREIGN KEY (withdrawn_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
