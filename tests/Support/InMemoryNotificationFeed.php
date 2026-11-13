<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\InAppNotification;
use App\Domain\NotificationFeedRepository;

/**
 * A notification list with nothing behind it. The double exists so the page can be
 * tested without inventing outbox rows: what the page does with a list is a
 * different question from what the list contains.
 */
final class InMemoryNotificationFeed implements NotificationFeedRepository
{
    /** @var list<InAppNotification> */
    private array $notifications = [];

    public function add(string $reference, string $occurredAt): void
    {
        $this->notifications[] = new InAppNotification($reference, $occurredAt);
    }

    /** @return list<InAppNotification> */
    public function recent(int $limit): array
    {
        return array_slice(array_reverse($this->notifications), 0, $limit);
    }
}
