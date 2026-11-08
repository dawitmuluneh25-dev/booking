<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ReferralRetentionRepository;
use App\Domain\ReferralStatus;
use App\Domain\Transaction;
use PDO;

/**
 * MySQL implementation of referral retention.
 *
 * Only referrals in a terminal state are eligible: a declined, cancelled or
 * closed referral has stopped changing, so redacting it cannot remove information
 * a clinician still needs. The trigger is `updated_at`, the moment the record
 * stopped moving, rather than `created_at`, which would redact a long-running
 * case while it was still open.
 *
 * What survives: the reference, the status, the procedure code, the dates and the
 * whole status history, because the audit trail must still be able to say what
 * happened. What goes: every column that names or describes a person.
 */
final class PdoReferralRetentionRepository implements ReferralRetentionRepository
{
    private const TERMINAL = [
        ReferralStatus::Declined->value,
        ReferralStatus::Cancelled->value,
        ReferralStatus::Closed->value,
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Transaction $transaction,
    ) {
    }

    public function eligibleCount(string $cutoffUtc): int
    {
        // Positional throughout: PDO will not mix `?` and `:named` in one
        // statement, and the terminal list is built dynamically.
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM referrals
              WHERE redacted_at IS NULL
                AND status IN (' . $this->placeholders() . ')
                AND updated_at < ?'
        );

        $this->bindTerminals($statement, $cutoffUtc);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @return list<int> */
    public function eligibleIds(string $cutoffUtc, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM referrals
              WHERE redacted_at IS NULL
                AND status IN (' . $this->placeholders() . ')
                AND updated_at < ?
              ORDER BY updated_at
              LIMIT ?'
        );

        $this->bindTerminals($statement, $cutoffUtc);
        $statement->bindValue(count(self::TERMINAL) + 2, max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row) && is_numeric($row['id'] ?? null)) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /** @return list<array{document_id: int, storage_key: string}> */
    public function documentsFor(int $referralId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, storage_key FROM referral_documents
              WHERE referral_id = :id AND deleted_at IS NULL
              ORDER BY id'
        );
        $statement->execute(['id' => $referralId]);

        $documents = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null)) {
                continue;
            }

            $documents[] = [
                'document_id' => (int) $row['id'],
                'storage_key' => self::text($row['storage_key'] ?? null),
            ];
        }

        return $documents;
    }

    public function redactReferral(int $referralId, string $occurredAtUtc): void
    {
        $this->transaction->run(function () use ($referralId, $occurredAtUtc): void {
            $update = $this->pdo->prepare(
                "UPDATE referrals
                    SET patient_name = '[redacted]',
                        patient_phone = '[redacted]',
                        patient_dob = NULL,
                        guardian_name = NULL,
                        guardian_phone = NULL,
                        guardian_relationship = NULL,
                        clinical_summary = NULL,
                        target_site = NULL,
                        referring_physician_name = NULL,
                        referring_facility = NULL,
                        referring_physician_phone = NULL,
                        redacted_at = :redacted_at,
                        updated_at = :updated_at
                  WHERE id = :id
                    AND redacted_at IS NULL"
            );
            $update->execute([
                'redacted_at' => $occurredAtUtc,
                'updated_at' => $occurredAtUtc,
                'id' => $referralId,
            ]);

            // Status links are bearer credentials to this referral's identity.
            // Once the identity is gone the tokens have nothing left to guard, and
            // a live secret pointing at a redacted record is a liability rather
            // than an audit trail.
            $tokens = $this->pdo->prepare('DELETE FROM referral_tracking_tokens WHERE referral_id = :id');
            $tokens->execute(['id' => $referralId]);
        });
    }

    public function redactDocument(int $documentId, string $occurredAtUtc): void
    {
        $update = $this->pdo->prepare(
            "UPDATE referral_documents
                SET original_name = '[redacted]',
                    redacted_at = :redacted_at
              WHERE id = :id
                AND redacted_at IS NULL"
        );
        $update->execute(['redacted_at' => $occurredAtUtc, 'id' => $documentId]);
    }

    public function markDocumentDeleted(int $documentId, string $occurredAtUtc): void
    {
        $update = $this->pdo->prepare(
            'UPDATE referral_documents
                SET deleted_at = :deleted_at
              WHERE id = :id
                AND deleted_at IS NULL'
        );
        $update->execute(['deleted_at' => $occurredAtUtc, 'id' => $documentId]);
    }

    public function recordRun(string $cutoffDate, int $referrals, int $documents, string $actor): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO retention_runs
                (cutoff_date, bookings_redacted, referrals_redacted, documents_redacted, actor, ran_at)
             VALUES
                (:cutoff_date, 0, :referrals, :documents, :actor, UTC_TIMESTAMP())'
        );
        $insert->execute([
            'cutoff_date' => $cutoffDate,
            'referrals' => $referrals,
            'documents' => $documents,
            'actor' => mb_substr($actor, 0, 120),
        ]);
    }

    /** Reads a value the driver may return as any scalar, without casting mixed. */
    private static function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }

    private function placeholders(): string
    {
        return implode(', ', array_fill(0, count(self::TERMINAL), '?'));
    }

    private function bindTerminals(\PDOStatement $statement, string $cutoffUtc): void
    {
        foreach (self::TERMINAL as $index => $status) {
            $statement->bindValue($index + 1, $status);
        }

        $statement->bindValue(count(self::TERMINAL) + 1, $cutoffUtc);
    }
}
