<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\OutboundMessage;
use App\Notifications\NotificationTransport;
use RuntimeException;

/**
 * Transport double that records deliveries and can be told to fail.
 */
final class RecordingTransport implements NotificationTransport
{
    /** @var list<OutboundMessage> */
    public array $sent = [];

    public int $failuresRemaining = 0;

    public function send(OutboundMessage $message): void
    {
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new RuntimeException('Transport unavailable');
        }

        $this->sent[] = $message;
    }
}
