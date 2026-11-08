<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DocumentAccessEvent;
use App\Domain\DocumentRepository;
use App\Domain\DocumentReviewException;
use App\Domain\DocumentScanStatus;
use App\Domain\ReferralDocument;

/**
 * Document store with the guarantees the database enforces.
 *
 * The review verdict is guarded the same way the PDO adapter guards it - only a
 * `pending` row can be decided - so a command test fails here for the reason it
 * would fail in production rather than passing on a permissive fake.
 */
final class InMemoryDocumentRepository implements DocumentRepository
{
    /** @var array<int, ReferralDocument> */
    private array $documents = [];

    /** @var list<DocumentAccessEvent> */
    private array $events = [];

    private int $nextId = 1;

    public function create(
        ReferralDocument $document,
        int $uploaderUserId,
        string $occurredAtUtc,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ReferralDocument {
        $stored = $document->withIdentity($this->nextId);
        $this->nextId++;
        $this->documents[$stored->id] = $stored;

        $this->events[] = new DocumentAccessEvent(
            $stored->id,
            $uploaderUserId,
            \App\Domain\DocumentAction::Upload,
            \App\Domain\AccessOutcome::Allowed,
            $occurredAtUtc,
            $ipAddress,
            $userAgent,
        );

        return $stored;
    }

    public function findByRef(string $documentRef): ?ReferralDocument
    {
        foreach ($this->documents as $document) {
            if ($document->documentRef === $documentRef) {
                return $document;
            }
        }

        return null;
    }

    /** @return list<ReferralDocument> */
    public function listForReferral(int $referralId): array
    {
        $documents = [];
        foreach ($this->documents as $document) {
            if ($document->referralId === $referralId) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    public function recordScanVerdict(
        ReferralDocument $document,
        DocumentScanStatus $status,
        int $reviewedByUserId,
        string $note,
        string $occurredAtUtc,
    ): ReferralDocument {
        $current = $this->documents[$document->id] ?? null;

        if ($current === null || $current->scanStatus !== DocumentScanStatus::Pending) {
            throw new DocumentReviewException(
                $current->scanStatus ?? DocumentScanStatus::Pending,
                'Another reviewer recorded a verdict first.',
            );
        }

        $updated = $current->withScanVerdict($status, $reviewedByUserId, $note, $occurredAtUtc);
        $this->documents[$updated->id] = $updated;

        return $updated;
    }

    public function recordAccess(DocumentAccessEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<array{action: string, outcome: string, occurred_at: string}> */
    public function recentAccessFor(int $documentId, int $limit): array
    {
        $rows = [];

        foreach (array_reverse($this->events) as $event) {
            if ($event->documentId !== $documentId) {
                continue;
            }

            $rows[] = [
                'action' => $event->action->value,
                'outcome' => $event->outcome->value,
                'occurred_at' => $event->occurredAtUtc,
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /** @return list<DocumentAccessEvent> */
    public function events(): array
    {
        return $this->events;
    }

    public function markDeleted(int $id, string $deletedAtUtc): void
    {
        $document = $this->documents[$id] ?? null;

        if ($document !== null) {
            $this->documents[$id] = $document->withDeletedAt($deletedAtUtc);
        }
    }
}
