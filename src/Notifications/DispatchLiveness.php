<?php

declare(strict_types=1);

namespace App\Notifications;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Whether the dispatcher is running at all.
 *
 * `QueueHealth` reads the outbox, which answers "is work waiting" and not "is
 * anybody working". The two agree only while the queue is non-empty: a fresh
 * deployment with no cron entry has an empty queue, no pending rows, and a
 * healthy-looking report — until the first booking arrives and sits there.
 *
 * This asks the other question directly, from the record the dispatcher writes
 * about itself. Its failure mode is the absence of evidence, which is why
 * "never ran" is reported as its own state rather than as an age.
 *
 * The threshold is two missed intervals. One missed tick is a slow query or a
 * scheduler that started late; two in a row on a five-minute schedule is ten
 * minutes of a clinic sending nothing, which is worth waking somebody for and is
 * still short of the day `QueueHealth` waits before it calls the queue stale.
 */
final class DispatchLiveness
{
    public const OK = 'ok';

    public const LATE = 'late';

    public const NEVER = 'never';

    public const UNKNOWN = 'unknown';

    public const DEFAULT_INTERVAL_MINUTES = 5;

    public const LATE_AFTER_INTERVALS = 2;

    /**
     * @return array{
     *     state: string,
     *     age_minutes: float|null,
     *     interval_minutes: int,
     *     reasons: list<string>,
     *     last_run: string|null
     * }
     */
    public static function assess(
        ?string $lastRunUtc,
        ?string $nowUtc = null,
        int $intervalMinutes = self::DEFAULT_INTERVAL_MINUTES,
    ): array {
        $interval = max(1, $intervalMinutes);

        if ($lastRunUtc === null) {
            return [
                'state' => self::NEVER,
                'age_minutes' => null,
                'interval_minutes' => $interval,
                'reasons' => [
                    'No dispatch has ever been recorded. `outbox:dispatch` is not scheduled, or it has'
                    . ' never completed on this host — the queue will fill silently and nothing will say so.',
                ],
                'last_run' => null,
            ];
        }

        $now = new DateTimeImmutable($nowUtc ?? 'now', new DateTimeZone('UTC'));
        $last = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lastRunUtc, new DateTimeZone('UTC'));

        if ($last === false) {
            return [
                'state' => self::UNKNOWN,
                'age_minutes' => null,
                'interval_minutes' => $interval,
                'reasons' => ['The last dispatch time could not be read: ' . $lastRunUtc],
                'last_run' => $lastRunUtc,
            ];
        }

        $ageMinutes = ($now->getTimestamp() - $last->getTimestamp()) / 60;

        // A run in the future is not an old run; it is two clocks disagreeing, and
        // reporting it as healthy would hide whichever one is wrong.
        if ($ageMinutes < 0) {
            return [
                'state' => self::UNKNOWN,
                'age_minutes' => $ageMinutes,
                'interval_minutes' => $interval,
                'reasons' => [
                    'The last dispatch is recorded in the future (' . $lastRunUtc . '). The application'
                    . ' and the dispatcher host disagree about the time.',
                ],
                'last_run' => $lastRunUtc,
            ];
        }

        if ($ageMinutes > $interval * self::LATE_AFTER_INTERVALS) {
            return [
                'state' => self::LATE,
                'age_minutes' => $ageMinutes,
                'interval_minutes' => $interval,
                'reasons' => [
                    sprintf(
                        'The last dispatch completed %.0f minutes ago on a %d-minute schedule. Two intervals'
                        . ' have passed, so the scheduler has stopped or is failing before it reaches its end.',
                        $ageMinutes,
                        $interval,
                    ),
                ],
                'last_run' => $lastRunUtc,
            ];
        }

        return [
            'state' => self::OK,
            'age_minutes' => $ageMinutes,
            'interval_minutes' => $interval,
            'reasons' => [],
            'last_run' => $lastRunUtc,
        ];
    }
}
