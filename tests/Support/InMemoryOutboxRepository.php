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

    /** @param list<OutboundMessage> $messages */
    public function record(?int $bookingId, ?int $referralId, array $messages): void
    {
        foreach ($messages as $message) {
            $this->enqueue($message);
        }
    }

    /** @param list<OutboundMessage> $messages */
    public function recordForUser(int $userId, array $messages): void
    {
        // The real writer keys the dedupe key on the account; the double keeps one
        // row per message, which is enough for every assertion made against it.
        $this->record(null, null, $messages);
    }

    public function scrubPayload(int $id): void
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]->status !== OutboxStatus::Sent) {
            return;
        }

        $message = $this->rows[$id]->message;
        $this->rows[$id]->payloadScrubbed = true;
        $this->rows[$id]->message = new OutboundMessage(
            channel: $message->channel,
            template: $message->template,
            recipient: $message->recipient,
            payload: [],
            id: $message->id,
            attempts: $message->attempts,
            status: $message->status,
            lastError: $message->lastError,
            dedupeKey: $message->dedupeKey,
            deliveredAt: $message->deliveredAt,
        );
    }

    public function payloadWasScrubbed(int $id): bool
    {
        return $this->rows[$id]->payloadScrubbed ?? false;
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

    public function markSent(int $id, ?string $providerMessageId = null): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]->status = OutboxStatus::Sent;
            $this->rows[$id]->providerMessageId = $providerMessageId;
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

    public function markDelivered(string $providerMessageId, string $deliveredAtUtc): bool
    {
        if ($providerMessageId === '') {
            return false;
        }

        foreach ($this->rows as $row) {
            if ($row->providerMessageId !== $providerMessageId) {
                continue;
            }

            if ($row->status !== OutboxStatus::Sent) {
                return false;
            }

            // The first report wins; a repeated callback does not move the moment.
            $row->deliveredAt ??= $deliveredAtUtc;

            return true;
        }

        return false;
    }

    public function deliveredAtOf(int $id): ?string
    {
        return $this->rows[$id]->deliveredAt ?? null;
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

    /** The identifier the transport returned, as the real repository persists it. */
    public function providerMessageIdOf(int $id): ?string
    {
        return $this->rows[$id]->providerMessageId;
    }
}
