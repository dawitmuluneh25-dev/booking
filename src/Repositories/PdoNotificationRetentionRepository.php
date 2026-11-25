<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\NotificationRetentionRepository;
use PDO;
use Throwable;

/**
 * Retires outbox rows a year after they were queued.
 *
 * Three fields carry a person: `recipient` (a phone number or an address), `payload` (the procedure and
 * case reference a message was about) and `last_error`, which some providers echo the recipient into.
 * All three are overwritten in the same statement as the `redacted_at` stamp, so there is never a state
 * where a row is marked retired but still holds the number.
 *
 * `dedupe_key` is deliberately left alone. It reads `subject:<user id>:<subject id>:<template>:<channel>`
 * — pseudonymous, contains no recipient, and is the unique key that stops a retried dispatch from
 * sending a second message. Rewriting it would trade a retention guarantee for a duplicate-message risk.
 * `provider_message_id` stays for the same reason receipts exist.
 */
final class PdoNotificationRetentionRepository implements NotificationRetentionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function eligibleCount(string $cutoffUtc): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notification_outbox
              WHERE redacted_at IS NULL
                AND created_at < :cutoff'
        );
        $statement->execute(['cutoff' => $cutoffUtc]);

        return (int) $statement->fetchColumn();
    }

    public function redactBefore(string $cutoffUtc, int $limit): int
    {
        $limit = max(1, $limit);

        $this->pdo->beginTransaction();

        try {
            $select = $this->pdo->prepare(
                'SELECT id FROM notification_outbox
                  WHERE redacted_at IS NULL
                    AND created_at < :cutoff
                  ORDER BY created_at
                  LIMIT :limit
                  FOR UPDATE'
            );
            $select->bindValue('cutoff', $cutoffUtc);
            $select->bindValue('limit', $limit, PDO::PARAM_INT);
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

            // Named placeholders for the id list as well as the columns: PDO does not support mixing
            // `:name` and `?` in one statement, and the failure is a runtime exception rather than a
            // parse error, which is exactly the kind of thing that reaches production.
            $placeholders = [];

            foreach (array_keys($ids) as $index) {
                $placeholders[] = ':id' . $index;
            }

            $update = $this->pdo->prepare(
                'UPDATE notification_outbox
                    SET recipient = :recipient,
                        payload = :payload,
                        last_error = NULL,
                        redacted_at = :redacted_at,
                        updated_at = :updated_at
                  WHERE id IN (' . implode(', ', $placeholders) . ')
                    AND redacted_at IS NULL'
            );

            $now = gmdate('Y-m-d H:i:s');
            $update->bindValue(':recipient', '[redacted]');
            $update->bindValue(':payload', '{"redacted": true}');
            $update->bindValue(':redacted_at', $now);
            $update->bindValue(':updated_at', $now);

            foreach ($ids as $index => $id) {
                $update->bindValue(':id' . $index, $id, PDO::PARAM_INT);
            }

            $update->execute();
            $changed = $update->rowCount();

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
        // The three older counters have no default, so this row states them as zero rather than
        // leaving them out: a notification sweep that recorded a null booking count would be
        // indistinguishable from one that never looked at bookings, and the columns are unsigned.
        $statement = $this->pdo->prepare(
            'INSERT INTO retention_runs
                (cutoff_date, bookings_redacted, referrals_redacted, documents_redacted,
                 notifications_redacted, actor, ran_at)
             VALUES
                (:cutoff_date, 0, 0, 0, :redacted, :actor, :ran_at)'
        );
        $statement->execute([
            'cutoff_date' => $cutoffDate,
            'redacted' => $redacted,
            'actor' => $actor,
            'ran_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
