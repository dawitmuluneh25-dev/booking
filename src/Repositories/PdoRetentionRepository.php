<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\BookingStatus;
use App\Domain\RetentionRepository;
use App\Domain\RetentionRun;
use PDO;
use Throwable;

/**
 * Redaction is a write that must be visible in the same place as the booking:
 * the name, phone and notes are overwritten, `redacted_at` is stamped, the
 * queued notification recipient is overwritten too, and the status history is
 * left untouched so "what happened" survives without "who it happened to".
 */
final class PdoRetentionRepository implements RetentionRepository
{
    private const TERMINAL = [BookingStatus::Completed->value, BookingStatus::Cancelled->value];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function eligibleCount(string $cutoffUtc): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM bookings
             WHERE redacted_at IS NULL
               AND status IN (:completed, :cancelled)
               AND scheduled_at < :cutoff'
        );
        $statement->execute([
            'completed' => self::TERMINAL[0],
            'cancelled' => self::TERMINAL[1],
            'cutoff' => $cutoffUtc,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function redactBefore(string $cutoffUtc, int $limit): int
    {
        $this->pdo->beginTransaction();

        try {
            $select = $this->pdo->prepare(
                'SELECT id FROM bookings
                 WHERE redacted_at IS NULL
                   AND status IN (:completed, :cancelled)
                   AND scheduled_at < :cutoff
                 ORDER BY scheduled_at
                 LIMIT :limit
                 FOR UPDATE'
            );
            $select->bindValue('completed', self::TERMINAL[0]);
            $select->bindValue('cancelled', self::TERMINAL[1]);
            $select->bindValue('cutoff', $cutoffUtc);
            $select->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
            $select->execute();

            $ids = [];
            foreach ($select->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $id = $row['id'] ?? null;
                if (is_int($id) || is_string($id)) {
                    $ids[] = (int) $id;
                }
            }

            if ($ids === []) {
                $this->pdo->commit();

                return 0;
            }

            $in = implode(', ', array_fill(0, count($ids), '?'));

            // Positional placeholders only: PDO does not allow named and
            // positional parameters in the same statement.
            $redact = $this->pdo->prepare(
                'UPDATE bookings
                 SET patient_name = ?,
                     patient_phone = ?,
                     notes = NULL,
                     redacted_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id IN (' . $in . ')'
            );
            $redact->bindValue(1, '[redacted]');
            $redact->bindValue(2, '[redacted]');
            foreach ($ids as $index => $id) {
                $redact->bindValue($index + 3, $id, PDO::PARAM_INT);
            }
            $redact->execute();
            $changed = $redact->rowCount();

            // A queued message holds the phone number in `recipient`.
            $outbox = $this->pdo->prepare(
                'UPDATE notification_outbox
                 SET recipient = ?, updated_at = UTC_TIMESTAMP()
                 WHERE booking_id IN (' . $in . ')'
            );
            $outbox->bindValue(1, '[redacted]');
            foreach ($ids as $index => $id) {
                $outbox->bindValue($index + 2, $id, PDO::PARAM_INT);
            }
            $outbox->execute();

            $this->pdo->commit();

            return $changed;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function recordRun(string $cutoffDate, int $redacted, string $actor): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO retention_runs (cutoff_date, bookings_redacted, actor, ran_at)
             VALUES (:cutoff_date, :redacted, :actor, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'cutoff_date' => $cutoffDate,
            'redacted' => $redacted,
            'actor' => mb_substr($actor, 0, 120),
        ]);
    }

    /** @return list<RetentionRun> */
    public function recentRuns(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT cutoff_date, bookings_redacted, actor, ran_at
             FROM retention_runs
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();

        $runs = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $runs[] = RetentionRun::fromRow($row);
            }
        }

        return $runs;
    }
}
