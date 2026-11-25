<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\NotificationRetentionRepository;
use App\Domain\NotificationRetentionService;
use App\Domain\RetentionPolicy;
use Tests\Support\InMemoryNotificationRetentionRepository;
use PHPUnit\Framework\TestCase;

/**
 * The notification class of D-02's schedule: 365 days, batched, audited.
 *
 * The arithmetic is simple enough that the interesting assertions are about the edges: a blank actor
 * must not produce a run record with no author, a sweep that finds nothing must not write a run row
 * (or the audit trail fills with noise and stops being read), and hitting the batch ceiling must say so
 * rather than reporting a clean finish.
 */
final class NotificationRetentionServiceTest extends TestCase
{
    public function testThePlanReportsTheClassPeriodAndTheCutoff(): void
    {
        $repository = new InMemoryNotificationRetentionRepository(7);
        $service = new NotificationRetentionService($repository, new RetentionPolicy(365));

        $plan = $service->plan();

        self::assertSame(365, $plan['days']);
        self::assertSame(7, $plan['eligible']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $plan['cutoff']);
    }

    public function testPurgeRetiresEverythingInOneBatchWhenItFits(): void
    {
        $repository = new InMemoryNotificationRetentionRepository(12);
        $service = new NotificationRetentionService($repository, new RetentionPolicy(365));

        $result = $service->purge('cli:test', 500);

        self::assertSame(12, $result['redacted']);
        self::assertSame(1, $result['batches']);
        self::assertFalse($result['remaining']);
        self::assertCount(1, $repository->runs);
        self::assertSame(12, $repository->runs[0]['redacted']);
        self::assertSame('cli:test', $repository->runs[0]['actor']);
    }

    public function testASweepThatFindsNothingWritesNoRunRow(): void
    {
        $repository = new InMemoryNotificationRetentionRepository(0);
        $service = new NotificationRetentionService($repository, new RetentionPolicy(365));

        $result = $service->purge('cli:test');

        self::assertSame(0, $result['redacted']);
        self::assertFalse($result['remaining']);
        self::assertSame([], $repository->runs);
    }

    public function testHittingTheBatchCeilingIsReportedRatherThanCalledFinished(): void
    {
        $repository = new InMemoryNotificationRetentionRepository(1200);
        $service = new NotificationRetentionService($repository, new RetentionPolicy(365));

        $result = $service->purge('cli:test', 500, 2);

        self::assertSame(1000, $result['redacted']);
        self::assertSame(2, $result['batches']);
        self::assertTrue($result['remaining'], 'A full batch at the ceiling means more is waiting.');
    }

    public function testABlankActorIsRecordedAsTheCommandThatRan(): void
    {
        $repository = new InMemoryNotificationRetentionRepository(1);
        $service = new NotificationRetentionService($repository, new RetentionPolicy(365));

        $service->purge('   ');

        self::assertSame('retention:cli', $repository->runs[0]['actor']);
    }

    public function testTheCutoffFollowsThePolicyRatherThanTheWallClock(): void
    {
        $repository = new InMemoryNotificationRetentionRepository(1);
        $service = new NotificationRetentionService($repository, new RetentionPolicy(365));

        $result = $service->purge('cli:test');

        $cutoff = new \DateTimeImmutable($result['cutoff'], new \DateTimeZone('UTC'));
        $expected = (new RetentionPolicy(365))->cutoff();

        self::assertLessThan(2, abs($cutoff->getTimestamp() - $expected->getTimestamp()));
    }
}
