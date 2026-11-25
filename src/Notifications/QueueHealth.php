<?php

declare(strict_types=1);

namespace App\Notifications;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Whether the outbox is actually being drained (D-09's remaining half).
 *
 * The transport work removed one way for the system to look healthy while nothing happens: a driver that
 * reported success without sending. This removes the other. The outbox only moves when `outbox:dispatch`
 * runs, and nothing in the repository guarantees that it does — so a queue whose oldest row is two days
 * old is a queue nobody is draining, and every patient whose message sits in it has been told nothing
 * while the row reads `pending`, which staff reasonably read as "in hand".
 *
 * The threshold is deliberately generous. A message queued minutes ago is normal; a queue that has not
 * moved in a day is a broken schedule, not a busy afternoon.
 */
final class QueueHealth
{
    public const OK = 'ok';

    public const ATTENTION = 'attention';

    public const STALE = 'stale';

    public const UNKNOWN = 'unknown';

    public const STALE_AFTER_HOURS = 24;

    /**
     * @return array{
     *     state: string,
     *     oldest_hours: float|null,
     *     reasons: list<string>,
     *     pending: int|null,
     *     failed: int,
     *     last_sent: string|null
     * }
     */
    public static function assess(
        ?int $pending,
        ?string $oldestPendingUtc,
        int $failed,
        ?string $lastSentUtc,
        ?string $nowUtc = null,
        int $staleAfterHours = self::STALE_AFTER_HOURS,
    ): array {
        $reasons = [];

        if ($pending === null) {
            // No table, or no permission to read it. "Cannot tell" is not "fine", and this project has
            // already learned that a false is a stronger claim than a null.
            return [
                'state' => self::UNKNOWN,
                'oldest_hours' => null,
                'reasons' => ['The outbox could not be read on this host, so queue health is unknown.'],
                'pending' => null,
                'failed' => $failed,
                'last_sent' => $lastSentUtc,
            ];
        }

        $now = new DateTimeImmutable($nowUtc ?? 'now', new DateTimeZone('UTC'));
        $oldestHours = null;

        if ($pending > 0 && $oldestPendingUtc !== null) {
            $oldest = new DateTimeImmutable($oldestPendingUtc, new DateTimeZone('UTC'));
            $oldestHours = ($now->getTimestamp() - $oldest->getTimestamp()) / 3600;
        }

        // "Never dispatched" is decisive on its own. A queue with rows in it and no dispatch in its
        // history is not young, it is unattended — and waiting for it to be a day old would let this
        // check report health for the whole first day of a broken schedule, which is the day somebody
        // would be most likely to ask it.
        $neverDispatched = $pending > 0 && $lastSentUtc === null;

        if ($neverDispatched) {
            $reasons[] = sprintf(
                '%d message(s) are queued and nothing has ever been dispatched. The `outbox:dispatch`'
                . ' schedule has not run at all.',
                $pending,
            );
        } elseif ($oldestHours !== null && $oldestHours > $staleAfterHours) {
            $reasons[] = sprintf(
                'The oldest queued message is %.1f hours old (%d waiting). Nothing is draining the outbox —'
                . ' check the `outbox:dispatch` schedule before anything else.',
                $oldestHours,
                $pending,
            );
        }

        if ($failed > 0) {
            $reasons[] = sprintf(
                '%d message(s) are in a failed state. They will not retry themselves once their attempts'
                . ' are exhausted; read the last_error on each.',
                $failed,
            );
        }

        $state = self::OK;

        if ($neverDispatched || ($oldestHours !== null && $oldestHours > $staleAfterHours)) {
            $state = self::STALE;
        } elseif ($failed > 0) {
            $state = self::ATTENTION;
        }

        return [
            'state' => $state,
            'oldest_hours' => $oldestHours,
            'reasons' => $reasons,
            'pending' => $pending,
            'failed' => $failed,
            'last_sent' => $lastSentUtc,
        ];
    }
}
