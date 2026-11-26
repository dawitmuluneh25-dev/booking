<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notifications\DispatchLiveness;
use PHPUnit\Framework\TestCase;

/**
 * The check that notices a schedule nobody installed.
 *
 * The queue cannot answer this question: an empty queue looks identical whether
 * the dispatcher ran a minute ago or has never existed. Every case here is a
 * state an operator would otherwise have to infer from silence.
 */
final class DispatchLivenessTest extends TestCase
{
    private const NOW = '2026-11-26 09:00:00';

    public function testARunInsideTwoIntervalsIsHealthy(): void
    {
        $result = DispatchLiveness::assess('2026-11-26 08:58:00', self::NOW);

        self::assertSame(DispatchLiveness::OK, $result['state']);
        self::assertSame([], $result['reasons']);
        self::assertEqualsWithDelta(2.0, (float) $result['age_minutes'], 0.01);
    }

    public function testNoRunAtAllIsItsOwnStateRatherThanAnAge(): void
    {
        $result = DispatchLiveness::assess(null, self::NOW);

        self::assertSame(DispatchLiveness::NEVER, $result['state']);
        self::assertNull($result['age_minutes'], 'There is no age to report for a run that never happened.');
        self::assertStringContainsString('not scheduled', (string) $result['reasons'][0]);
    }

    public function testTwoMissedIntervalsIsLate(): void
    {
        // 5-minute schedule, last run 11 minutes ago: the scheduler has stopped, and
        // a queue that happens to be empty right now must not mask that.
        $result = DispatchLiveness::assess('2026-11-26 08:49:00', self::NOW);

        self::assertSame(DispatchLiveness::LATE, $result['state']);
        self::assertStringContainsString('stopped', (string) $result['reasons'][0]);
    }

    public function testExactlyTwoIntervalsIsStillHealthy(): void
    {
        // Boundary: the threshold is "more than two intervals", so a run that lands
        // exactly on it is a scheduler that is keeping up, not one that has stopped.
        $result = DispatchLiveness::assess('2026-11-26 08:50:00', self::NOW);

        self::assertSame(DispatchLiveness::OK, $result['state']);
    }

    public function testTheIntervalIsConfigurable(): void
    {
        $hourly = DispatchLiveness::assess('2026-11-26 08:30:00', self::NOW, 60);

        self::assertSame(DispatchLiveness::OK, $hourly['state']);
        self::assertSame(60, $hourly['interval_minutes']);
    }

    public function testARunInTheFutureIsAClockDisagreementNotAHealthySchedule(): void
    {
        $result = DispatchLiveness::assess('2026-11-26 09:30:00', self::NOW);

        self::assertSame(DispatchLiveness::UNKNOWN, $result['state']);
        self::assertStringContainsString('disagree', (string) $result['reasons'][0]);
    }

    public function testAnUnreadableTimestampIsUnknownRatherThanAssumedFresh(): void
    {
        $result = DispatchLiveness::assess('not a timestamp', self::NOW);

        self::assertSame(DispatchLiveness::UNKNOWN, $result['state']);
        self::assertNull($result['age_minutes']);
    }

    public function testAZeroIntervalIsRaisedToOneRatherThanDisablingTheCheck(): void
    {
        $result = DispatchLiveness::assess('2026-11-26 08:57:00', self::NOW, 0);

        self::assertSame(1, $result['interval_minutes']);
        self::assertSame(DispatchLiveness::LATE, $result['state'], 'Three minutes is late on a one-minute schedule.');
    }
}
