<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\OutboundMessage;
use App\Domain\OutboxRepository;
use App\Domain\OutboxStatus;
use PDO;
use Throwable;

final class PdoOutboxRepository implements OutboxRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<OutboundMessage> $messages */
    public function record(?int $bookingId, ?int $referralId, array $messages): void
    {
        (new OutboxWriter($this->pdo))->write($bookingId, $referralId, $messages);
    }

    /** @return list<OutboundMessage> */
    public function claim(int $limit, string $staleBeforeUtc): array
    {
        $limit = max(1, min($limit, 100));

        $this->pdo->beginTransaction();

        try {
            // A worker that died mid-send leaves rows in "sending"; requeue them
            // once they are older than the stale window.
            $requeue = $this->pdo->prepare(
                "UPDATE notification_outbox
                 SET status = 'pending', updated_at = UTC_TIMESTAMP()
                 WHERE status = 'sending' AND updated_at < :cutoff"
            );
            $requeue->execute(['cutoff' => $staleBeforeUtc]);

            $select = $this->pdo->prepare(
                "SELECT id, channel, template, recipient, payload, attempts, status, last_error
                 FROM notification_outbox
                 WHERE status IN ('pending', 'failed') AND available_at <= UTC_TIMESTAMP()
                 ORDER BY id
                 LIMIT :limit
                 FOR UPDATE SKIP LOCKED"
            );
            $select->bindValue('limit', $limit, PDO::PARAM_INT);
            $select->execute();

            /** @var list<array<string, mixed>> $rows */
            $rows = $select->fetchAll();

            if ($rows === []) {
                $this->pdo->commit();

                return [];
            }

            $ids = [];
            foreach ($rows as $row) {
                $ids[] = self::integer($row, 'id');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $mark = $this->pdo->prepare(
                "UPDATE notification_outbox
                 SET status = 'sending', attempts = attempts + 1, updated_at = UTC_TIMESTAMP()
                 WHERE id IN ({$placeholders})"
            );
            $mark->execute($ids);

            $messages = [];
            foreach ($rows as $row) {
                $messages[] = $this->hydrate(
                    $row,
                    OutboxStatus::Sending,
                    self::integer($row, 'attempts') + 1,
                );
            }

            $this->pdo->commit();

            return $messages;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function markSent(int $id): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE notification_outbox
             SET status = 'sent', sent_at = UTC_TIMESTAMP(), last_error = NULL, updated_at = UTC_TIMESTAMP()
             WHERE id = :id"
        );
        $statement->execute(['id' => $id]);
    }

    public function markFailed(int $id, string $error, string $availableAtUtc): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE notification_outbox
             SET status = 'failed', available_at = :available_at, last_error = :error, updated_at = UTC_TIMESTAMP()
             WHERE id = :id"
        );
        $statement->execute(['available_at' => $availableAtUtc, 'error' => $error, 'id' => $id]);
    }

    public function markDead(int $id, string $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE notification_outbox
             SET status = 'dead', last_error = :error, updated_at = UTC_TIMESTAMP()
             WHERE id = :id"
        );
        $statement->execute(['error' => $error, 'id' => $id]);
    }

    /** @return list<OutboundMessage> */
    public function recent(int $limit): array
    {
        $limit = max(1, min($limit, 100));

        $statement = $this->pdo->prepare(
            'SELECT id, channel, template, recipient, payload, attempts, status, last_error
             FROM notification_outbox
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = $this->hydrate(
                $row,
                OutboxStatus::from(self::text($row, 'status')),
                self::integer($row, 'attempts'),
            );
        }

        return $messages;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row, OutboxStatus $status, int $attempts): OutboundMessage
    {
        $lastError = $row['last_error'] ?? null;

        return new OutboundMessage(
            channel: NotificationChannel::from(self::text($row, 'channel')),
            template: NotificationTemplate::from(self::text($row, 'template')),
            recipient: self::text($row, 'recipient'),
            payload: $this->payload($row),
            id: self::integer($row, 'id'),
            attempts: $attempts,
            status: $status,
            lastError: is_string($lastError) && $lastError !== '' ? $lastError : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function payload(array $row): array
    {
        $decoded = json_decode(self::text($row, 'payload'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $payload = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $payload[$key] = (string) $value;
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }

    /** @param array<string, mixed> $row */
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return match (true) {
            is_int($value) => $value,
            is_float($value) => (int) $value,
            is_string($value) && is_numeric($value) => (int) $value,
            default => 0,
        };
    }
}
