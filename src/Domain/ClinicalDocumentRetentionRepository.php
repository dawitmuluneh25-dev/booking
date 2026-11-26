<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The persistence side of the `uploaded_clinical_documents` class (D-02: 1,825 days).
 *
 * Separate from `ReferralRetentionRepository` even though it reads the same table, because the trigger is
 * different. That repository answers "which referrals are finished"; this one answers "which documents are
 * old", and the two populations do not overlap: a document this contract returns belongs to a referral
 * that is still live, which is exactly the case the referral sweep can never reach.
 *
 * It deliberately has no method that touches `referrals`. Retiring a document on its own period must not
 * redact its parent — the referral is younger than 2,557 days by construction, so redacting it here would
 * destroy a record the schedule still protects.
 */
interface ClinicalDocumentRetentionRepository
{
    /** Documents past the cutoff that still carry a filename. */
    public function eligibleCount(string $cutoffUtc): int;

    /**
     * One batch of eligible documents, oldest first.
     *
     * @return list<array{document_id: int, storage_key: string}>
     */
    public function eligibleDocuments(string $cutoffUtc, int $limit): array;

    /** Overwrite the original filename and stamp `redacted_at`. */
    public function redactDocument(int $documentId, string $occurredAtUtc): void;

    /** Mark the row's bytes as gone, so nothing offers a download that cannot be served. */
    public function markDocumentDeleted(int $documentId, string $occurredAtUtc): void;

    /** Record the sweep in `retention_runs` against its own counter. */
    public function recordRun(string $cutoffDate, int $documents, string $actor): void;
}
