<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ClinicalDocumentRetentionRepository;

/**
 * In-memory stand-in for the document sweep, so the ordering rule can be tested without MySQL.
 *
 * The ordering rule is the interesting part: bytes, then row. `recordRun` is kept so a test can assert
 * nothing was recorded when nothing moved.
 */
final class InMemoryClinicalDocumentRetentionRepository implements ClinicalDocumentRetentionRepository
{
    /** @var list<array{cutoff_date: string, documents: int, actor: string}> */
    public array $runs = [];

    /** @var list<int> */
    public array $redacted = [];

    /** @var list<int> */
    public array $markedDeleted = [];

    /**
     * @param list<array{document_id: int, storage_key: string, created_at: string}> $documents
     */
    public function __construct(public array $documents = [])
    {
    }

    public function eligibleCount(string $cutoffUtc): int
    {
        return count($this->eligible($cutoffUtc));
    }

    /** @return list<array{document_id: int, storage_key: string}> */
    public function eligibleDocuments(string $cutoffUtc, int $limit): array
    {
        $eligible = $this->eligible($cutoffUtc);

        usort($eligible, static fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);

        $batch = [];

        foreach (array_slice($eligible, 0, max(1, $limit)) as $document) {
            $batch[] = ['document_id' => $document['document_id'], 'storage_key' => $document['storage_key']];
        }

        return $batch;
    }

    public function redactDocument(int $documentId, string $occurredAtUtc): void
    {
        $this->redacted[] = $documentId;
        $this->remove($documentId);
    }

    public function markDocumentDeleted(int $documentId, string $occurredAtUtc): void
    {
        $this->markedDeleted[] = $documentId;
    }

    public function recordRun(string $cutoffDate, int $documents, string $actor): void
    {
        $this->runs[] = ['cutoff_date' => $cutoffDate, 'documents' => $documents, 'actor' => $actor];
    }

    /** @return list<array{document_id: int, storage_key: string, created_at: string}> */
    private function eligible(string $cutoffUtc): array
    {
        $eligible = [];

        foreach ($this->documents as $document) {
            if ($document['created_at'] < $cutoffUtc) {
                $eligible[] = $document;
            }
        }

        return $eligible;
    }

    private function remove(int $documentId): void
    {
        $remaining = [];

        foreach ($this->documents as $document) {
            if ($document['document_id'] !== $documentId) {
                $remaining[] = $document;
            }
        }

        $this->documents = $remaining;
    }
}
