-- 0016_create_document_storage.sql
-- Private document storage, quarantine state, access audit, and the two
-- identifier types Phase 4 needs: an opaque document reference for URLs and a
-- hashed, single-use, expiring tracking token for status links.
--
-- Three deliberate choices, each of which closes a way the prototype leaked
-- patient data:
--
-- 1. `document_ref` is a random 32-hex value, not the primary key and not the
--    storage key. A URL therefore contains no patient identifier and no number
--    that can be walked from 1 upwards to find somebody else's letter (DOC-01,
--    DOC-06). The storage key stays server-side only.
--
-- 2. Quarantine is a state, not a directory. `scan_status` defaults to
--    'pending' and the serving path refuses anything that is not 'clean' or
--    'not_required' (DOC-05). One row, one place a file can be, no "which
--    directory is it in" ambiguity during a retention sweep.
--
-- 3. Access events carry the document id, the actor, the action and the
--    outcome - never the filename or the storage key. The audit trail can answer
--    "who opened it" without becoming a second copy of the PHI (SEC 13, DOC-010).
--
-- `document_access_events.document_id` is ON DELETE RESTRICT: documents are
-- redacted and marked deleted, never removed, so the audit chain cannot be
-- orphaned (SEC 14.4, SEC 21).

ALTER TABLE referrals
    ADD COLUMN redacted_at DATETIME NULL AFTER updated_at;

ALTER TABLE referrals
    ADD KEY ix_referrals_redaction (redacted_at, status);

ALTER TABLE referral_documents
    ADD COLUMN document_ref CHAR(32) NULL AFTER id;

-- Rows created before this migration get a deterministic reference derived from
-- their own storage key, so the unique index below can be added to a populated
-- table without a window in which the column is nullable.
UPDATE referral_documents
   SET document_ref = SUBSTRING(SHA2(CONCAT(storage_key, ':', id), 256), 1, 32)
 WHERE document_ref IS NULL;

ALTER TABLE referral_documents
    MODIFY COLUMN document_ref CHAR(32) NOT NULL;

ALTER TABLE referral_documents
    ADD UNIQUE KEY uq_documents_ref (document_ref);

ALTER TABLE referral_documents
    MODIFY COLUMN mime_type VARCHAR(120) NOT NULL;

ALTER TABLE referral_documents
    ADD COLUMN file_extension VARCHAR(12) NULL AFTER mime_type,
    ADD COLUMN scan_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER sha256,
    ADD COLUMN scan_checked_at DATETIME NULL AFTER scan_status,
    ADD COLUMN scan_note VARCHAR(190) NULL AFTER scan_checked_at,
    ADD COLUMN access_class VARCHAR(20) NOT NULL DEFAULT 'clinical_private' AFTER scan_note,
    ADD COLUMN verified_by_user_id BIGINT UNSIGNED NULL AFTER access_class,
    ADD COLUMN verified_at DATETIME NULL AFTER verified_by_user_id,
    ADD COLUMN retention_until DATETIME NULL AFTER verified_at;

ALTER TABLE referral_documents
    ADD KEY ix_documents_scan (scan_status, created_at);

ALTER TABLE referral_documents
    ADD CONSTRAINT fk_documents_verifier
        FOREIGN KEY (verified_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT ck_documents_scan CHECK (scan_status IN (
        'pending', 'clean', 'infected', 'error', 'not_required'
    )),
    ADD CONSTRAINT ck_documents_access CHECK (access_class IN (
        'clinical_private', 'administrative_private', 'restricted'
    ));

CREATE TABLE document_access_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(20) NOT NULL,
    outcome VARCHAR(16) NOT NULL,
    occurred_at DATETIME NOT NULL,
    ip_address VARBINARY(16) NULL,
    user_agent VARCHAR(190) NULL,
    reason VARCHAR(190) NULL,
    PRIMARY KEY (id),
    KEY ix_document_access_document (document_id, occurred_at),
    KEY ix_document_access_user (user_id, occurred_at),
    CONSTRAINT fk_doc_access_document
        FOREIGN KEY (document_id) REFERENCES referral_documents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_doc_access_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_doc_access_action CHECK (action IN (
        'view', 'download', 'upload', 'delete_request', 'quarantine_review'
    )),
    CONSTRAINT ck_doc_access_outcome CHECK (outcome IN ('allowed', 'denied', 'error'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A status link is a bearer credential, so only its hash is stored. `used_at`
-- is the single-use marker and is set by the same UPDATE that validates the
-- token, which is what makes two simultaneous redemptions resolve to one winner
-- instead of two readers of the same secret.
CREATE TABLE referral_tracking_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    referral_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(24) NOT NULL DEFAULT 'status_link',
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    issued_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tracking_token_hash (token_hash),
    KEY ix_tracking_referral (referral_id, created_at),
    KEY ix_tracking_expiry (expires_at),
    CONSTRAINT fk_tracking_referral
        FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tracking_issuer
        FOREIGN KEY (issued_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_tracking_purpose CHECK (purpose IN ('status_link'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One retention sweep now covers bookings, referrals and their documents on the
-- same schedule (SEC 14.4). The extra counters keep the run evidence honest
-- about what the sweep actually did.
ALTER TABLE retention_runs
    ADD COLUMN referrals_redacted INT UNSIGNED NOT NULL DEFAULT 0 AFTER bookings_redacted,
    ADD COLUMN documents_redacted INT UNSIGNED NOT NULL DEFAULT 0 AFTER referrals_redacted;
