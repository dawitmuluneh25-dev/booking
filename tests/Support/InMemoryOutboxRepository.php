<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\OutboundMessage;
use App\Domain\OutboxRepository;
use App\Domain\OutboxStatus;

/**
 * In-memory outbox with the same claim semantics as the PDO version: a claim
 * moves rows to "sending" and increments their attempt count exactly once.
 */
final class InMemoryOutboxRepository implements OutboxRepository
{
    /** @var array<int, InMemoryOutboxRow> */
    private array $rows = [];

    private int $nextId = 1;

    public function enqueue(OutboundMessage $message, ?string $availableAt = null): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = new InMemoryOutboxRow(
            $message,
            OutboxStatus::Pending,
            0,
            $availableAt ?? gmdate('Y-m-d H:i:s'),
        );

        return $id;
    }

    /** @return list<OutboundMessage> */
    public function claim(int $limit, string $staleBeforeUtc): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $claimed = [];

        foreach ($this->rows as $id => $row) {
            if (count($claimed) >= $limit) {
                break;
            }

            $claimable = $row->status === OutboxStatus::Pending || $row->status === OutboxStatus::Failed;
            if (!$claimable || $row->availableAt > $now) {
                continue;
            }

            $row->status = OutboxStatus::Sending;
            $row->attempts++;

            $claimed[] = $row->message->withDispatch($id, $row->attempts);
        }

        return $claimed;
    }

    public function markSent(int $id): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]->status = OutboxStatus::Sent;
        }
    }

    public function markFailed(int $id, string $error, string $availableAtUtc): void
    {
        if (!isset($this->rows[$id])) {
            return;
        }

        $row = $this->rows[$id];
        $row->status = OutboxStatus::Failed;
        $row->availableAt = $availableAtUtc;
        $row->message = $row->message->withState(OutboxStatus::Failed, $row->attempts, $error);
    }

    public function markDead(int $id, string $error): void
    {
        if (!isset($this->rows[$id])) {
            return;
        }

        $row = $this->rows[$id];
        $row->status = OutboxStatus::Dead;
        $row->message = $row->message->withState(OutboxStatus::Dead, $row->attempts, $error);
    }

    /** @return list<OutboundMessage> */
    public function recent(int $limit): array
    {
        $messages = [];

        foreach (array_reverse($this->rows, true) as $row) {
            if (count($messages) >= $limit) {
                break;
            }

            $messages[] = $row->message->withState($row->status, $row->attempts);
        }

        return $messages;
    }

    public function statusOf(int $id): OutboxStatus
    {
        return $this->rows[$id]->status;
    }

    public function forceAttempts(int $id, int $attempts): void
    {
        $this->rows[$id]->attempts = $attempts;
    }
}
