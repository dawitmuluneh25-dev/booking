<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A read-only snapshot of the outbox, for the queue-health check.
 *
 * Separate from the retention repository on purpose: that one retires rows, this one only looks. A
 * monitoring path that could write is a monitoring path that can change what it is measuring.
 */
interface NotificationQueueRepository
{
    /**
     * @return array{
     *     pending: int,
     *     oldestPending: string|null,
     *     failed: int,
     *     lastSent: string|null
     * }
     */
    public function snapshot(): array;
}
