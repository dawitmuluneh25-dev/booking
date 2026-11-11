<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\OutboundMessage;

/**
 * Delivery boundary. Implementations must throw on failure; the dispatcher
 * decides whether to retry or give up.
 *
 * A successful send returns the provider's identifier for the message, or null
 * when the provider does not issue one. Acceptance is not delivery and is not
 * acknowledgement: the identifier only makes it possible to ask the provider
 * later what became of it (O-3).
 */
interface NotificationTransport
{
    public function send(OutboundMessage $message): ?string;
}
