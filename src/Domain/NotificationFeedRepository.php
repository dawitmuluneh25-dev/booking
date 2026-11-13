<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The read model behind the in-app notification list.
 *
 * It reads what the system has already decided to send, in the order it happened.
 * There is no second write path and no second record: a notification that was
 * queued, retried or dead-lettered appears here because the outbox row exists, so
 * the list cannot disagree with what actually happened.
 *
 * A message that failed is still a message somebody needed. It appears with the
 * time it was created, so an operator reading the list sees a gap where a delivery
 * should have been rather than a quiet absence.
 */
interface NotificationFeedRepository
{
    /** @return list<InAppNotification> newest first */
    public function recent(int $limit): array;
}
