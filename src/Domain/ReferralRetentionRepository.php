<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The persistence side of referral and document retention.
 *
 * Kept separate from `RetentionRepository` (bookings) because the two aggregates
 * have different eligibility rules and different things to redact; sharing one
 * interface would produce a method with two meanings.
 */
interface ReferralRetentionRepository
{
    /** Referrals still holding identity data that are past the cutoff. */
    public function eligibleCount(string $cutoffUtc): int;

    /** @return list<int> */
    public function eligibleIds(string $cutoffUtc, int $limit): array;

    /**
     * Documents of one referral that still have bytes recorded, with the storage
     * key the caller must delete.
     *
     * @return list<array{document_id: int, storage_key: string}>
     */
    public function documentsFor(int $referralId): array;

    /**
     * Overwrite the identity-bearing columns and stamp `redacted_at`, in one
     * transaction that also destroys that referral's status-link tokens.
     */
    public function redactReferral(int $referralId, string $occurredAtUtc): void;

    /** Overwrite the filename and stamp `redacted_at` on the document row. */
    public function redactDocument(int $documentId, string $occurredAtUtc): void;

    /** Stamp `deleted_at` once the bytes are gone from storage. */
    public function markDocumentDeleted(int $documentId, string $occurredAtUtc): void;

    public function recordRun(string $cutoffDate, int $referrals, int $documents, string $actor): void;
}
