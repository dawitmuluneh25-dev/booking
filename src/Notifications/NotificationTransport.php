<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\OutboundMessage;

/**
 * Delivery boundary. Implementations must throw on failure; the dispatcher
 * decides whether to retry or give up.
 */
interface NotificationTransport
{
    public function send(OutboundMessage $message): void;
}
