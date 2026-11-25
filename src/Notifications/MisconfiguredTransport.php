<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\OutboundMessage;
use RuntimeException;

/**
 * The transport used when production is configured to deliver nothing.
 *
 * It throws on every message, with the reason, rather than returning a quiet success. That choice is the
 * whole point: the outbox row becomes `failed` with a message an operator can read, instead of `sent`
 * with a null provider id that nobody inspects. A queue that says "sent" when nothing left the building
 * is worse than a queue that says "failed", because the first one answers the wrong question
 * confidently.
 *
 * Thrown as a plain `RuntimeException` rather than `PermanentNotificationFailure`: this is not a
 * per-message condition, it is a configuration that must be fixed and retried, and marking the rows
 * permanently failed would punish the patients whose messages were queued during the mistake.
 */
final class MisconfiguredTransport implements NotificationTransport
{
    public function __construct(private readonly string $reason)
    {
    }

    public function send(OutboundMessage $message): ?string
    {
        throw new RuntimeException('Notifications are not being delivered: ' . $this->reason);
    }
}
