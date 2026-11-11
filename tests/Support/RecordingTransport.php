<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\OutboundMessage;
use App\Notifications\NotificationTransport;
use App\Notifications\PermanentNotificationFailure;
use RuntimeException;

/**
 * Transport double that records deliveries and can be told to fail.
 *
 * Two failure modes, because the dispatcher treats them differently and a double
 * that only knew how to fail transiently could not tell the two paths apart: a
 * bridge being down deserves the backoff ladder, a rejected payload does not.
 */
final class RecordingTransport implements NotificationTransport
{
    /** @var list<OutboundMessage> */
    public array $sent = [];

    /** Transient failures left before deliveries start succeeding. */
    public int $failuresRemaining = 0;

    /** Permanent failures left before deliveries start succeeding. */
    public int $permanentFailuresRemaining = 0;

    /** What the provider is imagined to have answered, when it answers at all. */
    public ?string $providerMessageId = null;

    public function send(OutboundMessage $message): ?string
    {
        if ($this->permanentFailuresRemaining > 0) {
            $this->permanentFailuresRemaining--;

            throw new PermanentNotificationFailure('The provider rejected the message.');
        }

        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;

            throw new RuntimeException('Transport unavailable');
        }

        $this->sent[] = $message;

        return $this->providerMessageId;
    }
}
