<?php

declare(strict_types=1);

namespace App\Domain;

interface OutboxRepository
{
    /**
     * Reserve a batch for this worker. Claims are atomic, so two concurrent
     * dispatchers cannot send the same row.
     *
     * @param string $staleBeforeUtc rows stuck in "sending" before this time are requeued
     *
     * @return list<OutboundMessage>
     */
    public function claim(int $limit, string $staleBeforeUtc): array;

    public function markSent(int $id): void;

    public function markFailed(int $id, string $error, string $availableAtUtc): void;

    public function markDead(int $id, string $error): void;

    /** @return list<OutboundMessage> */
    public function recent(int $limit): array;
}
