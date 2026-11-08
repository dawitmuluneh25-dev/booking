<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RetentionPolicy;
use App\Domain\RetentionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryRetentionRepository;

final class RetentionServiceTest extends TestCase
{
    public function testPlanCountsOnlyEligibleRows(): void
    {
        $repository = new InMemoryRetentionRepository([
            ['id' => 1, 'status' => 'completed', 'scheduled_at' => '2020-01-01 09:00:00'],
            ['id' => 2, 'status' => 'cancelled', 'scheduled_at' => '2020-02-01 09:00:00'],
            ['id' => 3, 'status' => 'requested', 'scheduled_at' => '2020-03-01 09:00:00'],
            ['id' => 4, 'status' => 'completed', 'scheduled_at' => '2099-01-01 09:00:00'],
        ]);

        $plan = (new RetentionService($repository, new RetentionPolicy(365)))->plan();

        self::assertSame(2, $plan['eligible']);
        self::assertSame(365, $plan['days']);
    }

    public function testPurgeRedactsEligibleRowsAndRecordsOneRun(): void
    {
        $repository = new InMemoryRetentionRepository([
            ['id' => 1, 'status' => 'completed', 'scheduled_at' => '2020-01-01 09:00:00'],
            ['id' => 2, 'status' => 'cancelled', 'scheduled_at' => '2020-02-01 09:00:00'],
            ['id' => 3, 'status' => 'requested', 'scheduled_at' => '2020-03-01 09:00:00'],
        ]);

        $result = (new RetentionService($repository, new RetentionPolicy(365)))->purge('cli:test', 500);

        self::assertSame(2, $result['redacted']);
        self::assertSame(1, $result['batches']);
        self::assertFalse($result['remaining']);
        self::assertSame(0, $repository->eligibleCount('2999-01-01 00:00:00'));

        $runs = $repository->runs();
        self::assertCount(1, $runs);
        self::assertSame(2, $runs[0]['redacted']);
        self::assertSame('cli:test', $runs[0]['actor']);
    }

    public function testPurgeStopsAtTheBatchCeilingAndSaysMoreIsWaiting(): void
    {
        $bookings = [];
        for ($i = 1; $i <= 5; $i++) {
            $bookings[] = ['id' => $i, 'status' => 'completed', 'scheduled_at' => '2020-01-0' . $i . ' 09:00:00'];
        }

        $repository = new InMemoryRetentionRepository($bookings);

        $result = (new RetentionService($repository, new RetentionPolicy(365)))->purge('cli:test', 2, 2);

        self::assertSame(4, $result['redacted']);
        self::assertSame(2, $result['batches']);
        self::assertTrue($result['remaining']);
        self::assertSame(1, $repository->eligibleCount('2999-01-01 00:00:00'));
    }

    public function testNothingEligibleRecordsNoRun(): void
    {
        $repository = new InMemoryRetentionRepository([
            ['id' => 1, 'status' => 'confirmed', 'scheduled_at' => '2020-01-01 09:00:00'],
        ]);

        $result = (new RetentionService($repository, new RetentionPolicy(365)))->purge('cli:test');

        self::assertSame(0, $result['redacted']);
        self::assertSame([], $repository->runs());
    }
}
