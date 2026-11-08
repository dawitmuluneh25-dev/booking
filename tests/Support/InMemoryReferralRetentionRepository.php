<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ReferralRetentionRepository;

/**
 * Referral retention store for tests.
 *
 * Eligibility is decided by the caller's seed values rather than re-derived, so a
 * test can state "this referral is past the cutoff and this one is not" without
 * building a clock.
 */
final class InMemoryReferralRetentionRepository implements ReferralRetentionRepository
{
    /** @var array<int, array{eligible: bool, documents: list<array{document_id: int, storage_key: string}>}> */
    private array $referrals = [];

    /** @var list<int> */
    private array $redactedReferrals = [];

    /** @var list<int> */
    private array $redactedDocuments = [];

    /** @var list<int> */
    private array $deletedDocuments = [];

    /** @var list<array{cutoff_date: string, referrals: int, documents: int, actor: string}> */
    private array $runs = [];

    /** @param list<array{document_id: int, storage_key: string}> $documents */
    public function seedReferral(int $referralId, bool $eligible, array $documents = []): void
    {
        $this->referrals[$referralId] = ['eligible' => $eligible, 'documents' => $documents];
    }

    public function eligibleCount(string $cutoffUtc): int
    {
        return count($this->eligibleIds($cutoffUtc, PHP_INT_MAX));
    }

    /** @return list<int> */
    public function eligibleIds(string $cutoffUtc, int $limit): array
    {
        $ids = [];

        foreach ($this->referrals as $id => $referral) {
            if (!$referral['eligible'] || in_array($id, $this->redactedReferrals, true)) {
                continue;
            }

            $ids[] = $id;

            if (count($ids) >= $limit) {
                break;
            }
        }

        return $ids;
    }

    /** @return list<array{document_id: int, storage_key: string}> */
    public function documentsFor(int $referralId): array
    {
        $documents = [];

        foreach ($this->referrals[$referralId]['documents'] ?? [] as $document) {
            if (!in_array($document['document_id'], $this->deletedDocuments, true)) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    public function redactReferral(int $referralId, string $occurredAtUtc): void
    {
        $this->redactedReferrals[] = $referralId;
    }

    public function redactDocument(int $documentId, string $occurredAtUtc): void
    {
        $this->redactedDocuments[] = $documentId;
    }

    public function markDocumentDeleted(int $documentId, string $occurredAtUtc): void
    {
        $this->deletedDocuments[] = $documentId;
    }

    public function recordRun(string $cutoffDate, int $referrals, int $documents, string $actor): void
    {
        $this->runs[] = [
            'cutoff_date' => $cutoffDate,
            'referrals' => $referrals,
            'documents' => $documents,
            'actor' => $actor,
        ];
    }

    /** @return list<int> */
    public function redactedReferrals(): array
    {
        return $this->redactedReferrals;
    }

    /** @return list<int> */
    public function redactedDocuments(): array
    {
        return $this->redactedDocuments;
    }

    /** @return list<int> */
    public function deletedDocuments(): array
    {
        return $this->deletedDocuments;
    }

    /** @return list<array{cutoff_date: string, referrals: int, documents: int, actor: string}> */
    public function runs(): array
    {
        return $this->runs;
    }
}
