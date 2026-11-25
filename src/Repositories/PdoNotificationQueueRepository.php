<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\NotificationQueueRepository;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * One query per number, because the answer has to be readable when the queue is large.
 *
 * `status` values in this schema are `pending`, `sent` and `failed`; `sent_at` is the dispatch time rather
 * than `updated_at`, because a redaction also touches `updated_at` and would otherwise look like recent
 * activity — a queue that has not moved would appear to have moved on the day retention ran.
 */
final class PdoNotificationQueueRepository implements NotificationQueueRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function snapshot(): array
    {
        $pending = $this->prepare(
            "SELECT COUNT(*) AS total, MIN(created_at) AS oldest
               FROM notification_outbox
              WHERE status = 'pending'"
        );
        $pending->execute();
        $row = $pending->fetch(PDO::FETCH_ASSOC);

        $failed = $this->prepare(
            "SELECT COUNT(*) FROM notification_outbox WHERE status = 'failed'"
        );
        $failed->execute();

        $lastSent = $this->prepare(
            "SELECT MAX(sent_at) FROM notification_outbox WHERE sent_at IS NOT NULL"
        );
        $lastSent->execute();

        $total = is_array($row) ? ($row['total'] ?? 0) : 0;
        $oldest = is_array($row) ? ($row['oldest'] ?? null) : null;
        $failedCount = $failed->fetchColumn();
        $sent = $lastSent->fetchColumn();

        return [
            'pending' => is_numeric($total) ? (int) $total : 0,
            'oldestPending' => is_string($oldest) && $oldest !== '' ? $oldest : null,
            'failed' => is_numeric($failedCount) ? (int) $failedCount : 0,
            'lastSent' => is_string($sent) && $sent !== '' ? $sent : null,
        ];
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('The driver refused to prepare a queue-health statement.');
        }

        return $statement;
    }
}
