<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ClinicalDocumentRetentionRepository;
use PDO;

/**
 * Sweeps uploaded clinical documents on their own period.
 *
 * The trigger is `created_at`, not the referral's `updated_at`. A document is finished the moment it is
 * stored — nothing about it changes afterwards — so its own age is the only honest clock. That is also
 * what makes this population different from the referral sweep's: a document here belongs to a referral
 * that is still inside its 2,557-day window, which is precisely why the referral sweep has never seen it.
 *
 * `redacted_at IS NULL` is the only exclusion. A soft-deleted row still carries an `original_name`, and
 * the filename is often the most identifying field in the row, so a document whose bytes are already gone
 * is still worth redacting.
 */
final class PdoClinicalDocumentRetentionRepository implements ClinicalDocumentRetentionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function eligibleCount(string $cutoffUtc): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM referral_documents
              WHERE redacted_at IS NULL
                AND created_at < :cutoff'
        );
        $statement->execute(['cutoff' => $cutoffUtc]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array{document_id: int, storage_key: string}> */
    public function eligibleDocuments(string $cutoffUtc, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, storage_key FROM referral_documents
              WHERE redacted_at IS NULL
                AND created_at < :cutoff
              ORDER BY created_at
              LIMIT :limit'
        );
        $statement->bindValue('cutoff', $cutoffUtc);
        $statement->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $documents = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $key = $row['storage_key'] ?? null;

            if (!is_numeric($id) || !is_string($key)) {
                continue;
            }

            $documents[] = ['document_id' => (int) $id, 'storage_key' => $key];
        }

        return $documents;
    }

    public function redactDocument(int $documentId, string $occurredAtUtc): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE referral_documents
                SET original_name = '[redacted]',
                    redacted_at = :redacted_at
              WHERE id = :id
                AND redacted_at IS NULL"
        );
        $statement->execute(['redacted_at' => $occurredAtUtc, 'id' => $documentId]);
    }

    public function markDocumentDeleted(int $documentId, string $occurredAtUtc): void
    {
        // `deleted_at` is what stops a document being offered for download. It is set here rather than
        // left to the file's absence, because "the bytes are missing" and "this document is gone" are
        // different facts and only the second one is true after a deliberate sweep.
        $statement = $this->pdo->prepare(
            'UPDATE referral_documents
                SET deleted_at = :deleted_at
              WHERE id = :id
                AND deleted_at IS NULL'
        );
        $statement->execute(['deleted_at' => $occurredAtUtc, 'id' => $documentId]);
    }

    public function recordRun(string $cutoffDate, int $documents, string $actor): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO retention_runs
                (cutoff_date, bookings_redacted, referrals_redacted, documents_redacted,
                 uploaded_documents_redacted, notifications_redacted, actor, ran_at)
             VALUES
                (:cutoff_date, 0, 0, 0, :documents, 0, :actor, :ran_at)'
        );

        $statement->execute([
            'cutoff_date' => $cutoffDate,
            'documents' => $documents,
            'actor' => $actor,
            'ran_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
