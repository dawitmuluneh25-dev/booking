<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notifications\QueueHealth;
use PHPUnit\Framework\TestCase;

/**
 * The rule that stops the outbox from looking healthy while nothing drains it.
 *
 * The case that matters most is the first: rows queued, and no dispatch in the table's history. A
 * threshold-only rule would report `ok` for that for a whole day, which is precisely the window in which
 * somebody would ask whether messages are going out.
 */
final class QueueHealthTest extends TestCase
{
    private const NOW = '2026-11-26 12:00:00';

    public function testAQueueThatHasNeverDispatchedIsStaleWhateverItsAge(): void
    {
        $health = QueueHealth::assess(14, '2026-11-26 02:00:00', 0, null, self::NOW);

        self::assertSame(QueueHealth::STALE, $health['state']);
        self::assertStringContainsString('nothing has ever been dispatched', $health['reasons'][0]);
    }

    public function testAnOldQueueIsStaleEvenWhenDispatchHasHappenedBefore(): void
    {
        $health = QueueHealth::assess(5, '2026-11-25 04:00:00', 0, '2026-11-24 09:00:00', self::NOW);

        self::assertSame(QueueHealth::STALE, $health['state']);
        self::assertStringContainsString('32.0 hours old', $health['reasons'][0]);
    }

    public function testARecentlyQueuedMessageIsNotAProblem(): void
    {
        $health = QueueHealth::assess(3, '2026-11-26 10:00:00', 0, '2026-11-26 09:00:00', self::NOW);

        self::assertSame(QueueHealth::OK, $health['state']);
        self::assertSame([], $health['reasons']);
        self::assertEqualsWithDelta(2.0, (float) $health['oldest_hours'], 0.1);
    }

    public function testFailuresNeedAttentionWithoutBeingCalledStale(): void
    {
        $health = QueueHealth::assess(0, null, 4, '2026-11-26 09:00:00', self::NOW);

        self::assertSame(QueueHealth::ATTENTION, $health['state']);
        self::assertStringContainsString('4 message(s)', $health['reasons'][0]);
    }

    public function testAnEmptyOutboxIsHealthy(): void
    {
        $health = QueueHealth::assess(0, null, 0, '2026-11-26 09:00:00', self::NOW);

        self::assertSame(QueueHealth::OK, $health['state']);
        self::assertNull($health['oldest_hours']);
    }

    public function testAnUnreadableOutboxIsUnknownRatherThanHealthy(): void
    {
        $health = QueueHealth::assess(null, null, 0, null, self::NOW);

        self::assertSame(QueueHealth::UNKNOWN, $health['state']);
        self::assertNull($health['pending']);
        self::assertStringContainsString('could not be read', $health['reasons'][0]);
    }
}
