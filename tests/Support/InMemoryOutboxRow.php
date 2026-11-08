<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\OutboundMessage;
use App\Domain\OutboxStatus;

/**
 * Mutable row for the in-memory outbox. A small object keeps the test double
 * readable without array-shape gymnastics.
 */
final class InMemoryOutboxRow
{
    public function __construct(
        public OutboundMessage $message,
        public OutboxStatus $status = OutboxStatus::Pending,
        public int $attempts = 0,
        public string $availableAt = '',
    ) {
    }
}
