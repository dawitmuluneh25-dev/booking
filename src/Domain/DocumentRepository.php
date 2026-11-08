<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Persistence boundary for referral documents and their access audit.
 *
 * `create` takes the uploader and the upload timestamp as required arguments: a
 * stored file with no record of who put it there is not a document this system is
 * willing to hold (SEC 14.1 step 10). The implementation writes the upload audit
 * event in the same transaction - it has to, because only the insert knows the
 * id the event must reference.
 */
interface DocumentRepository
{
    public function create(
        ReferralDocument $document,
        int $uploaderUserId,
        string $occurredAtUtc,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ReferralDocument;

    /** Resolved server-side from the opaque reference in the URL. */
    public function findByRef(string $documentRef): ?ReferralDocument;

    /** @return list<ReferralDocument> oldest first */
    public function listForReferral(int $referralId): array;

    /**
     * Record a quarantine verdict. Only a document still awaiting review can be
     * decided, and the update is guarded so two reviewers cannot both win.
     *
     * @throws DocumentReviewException when the document is not awaiting review
     */
    public function recordScanVerdict(
        ReferralDocument $document,
        DocumentScanStatus $status,
        int $reviewedByUserId,
        string $note,
        string $occurredAtUtc,
    ): ReferralDocument;

    public function recordAccess(DocumentAccessEvent $event): void;

    /** @return list<array{action: string, outcome: string, occurred_at: string}> newest first */
    public function recentAccessFor(int $documentId, int $limit): array;
}
