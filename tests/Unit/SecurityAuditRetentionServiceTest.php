<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RetentionPolicy;
use App\Domain\SecurityAuditRetentionService;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemorySecurityAuditRetentionRepository;

/**
 * The sweep that deletes evidence.
 *
 * Every other class removes something that describes a record; this one removes the record of what was
 * done. Two properties therefore matter more than coverage: it must not run when nothing is eligible,
 * and when it does run it must leave a row saying how much went — because after the delete, that row is
 * the only thing that can answer the question.
 */
final class SecurityAuditRetentionServiceTest extends TestCase
{
    /** 2,557: the `security_and_audit` period, spelled out as the other sweep tests do. */
    private function service(
        InMemorySecurityAuditRetentionRepository $repository,
        int $days = 2557,
    ): SecurityAuditRetentionService {
        return new SecurityAuditRetentionService($repository, new RetentionPolicy($days));
    }

    public function testPlanCountsBothTablesAndSumsThem(): void
    {
        $repository = new InMemorySecurityAuditRetentionRepository();
        $repository->seed('audit_events', 40);
        $repository->seed('login_attempts', 3);

        $plan = $this->service($repository)->plan();

        self::assertSame(43, $plan['eligible']);
        self::assertSame(['audit_events' => 40, 'login_attempts' => 3], $plan['by_table']);
        self::assertSame(2557, $plan['days']);
    }

    public function testPlanChangesNothing(): void
    {
        // A dry run that quietly removed rows would make `retention:purge` without `--yes` the most
        // dangerous command in the project.
        $repository = new InMemorySecurityAuditRetentionRepository();
        $repository->seed('audit_events', 5);

        $this->service($repository)->plan();

        self::assertSame([], $repository->runs());
        self::assertSame(5, $repository->eligibleCounts('2026-01-01 00:00:00')['audit_events']);
    }

    public function testNothingEligibleWritesNoRunRow(): void
    {
        // A run row claiming zero is indistinguishable from a sweep that never looked, and one row a day
        // saying "nothing happened" is how this table becomes unreadable.
        $repository = new InMemorySecurityAuditRetentionRepository();

        $result = $this->service($repository)->purge('cli:retention');

        self::assertSame(0, $result['removed']);
        self::assertSame([], $repository->runs());
        self::assertFalse($result['remaining']);
    }

    public function testASweepRecordsWhatItRemovedPerTable(): void
    {
        $repository = new InMemorySecurityAuditRetentionRepository();
        $repository->seed('audit_events', 12);
        $repository->seed('login_attempts', 4);

        $result = $this->service($repository)->purge('cli:retention', 500, 20);

        self::assertSame(16, $result['removed']);
        self::assertSame(['audit_events' => 12, 'login_attempts' => 4], $result['by_table']);
        self::assertCount(1, $repository->runs());
        self::assertSame('cli:retention', $repository->runs()[0]['actor']);
        self::assertSame(12, $repository->runs()[0]['removed']['audit_events']);
    }

    public function testBatchingStopsAtTheCeilingAndSaysMoreIsWaiting(): void
    {
        // Forty rows, batches of ten, two batches allowed: the sweep must stop and say so rather than run
        // until the table is empty inside one long transaction.
        $repository = new InMemorySecurityAuditRetentionRepository();
        $repository->seed('audit_events', 40);

        $result = $this->service($repository)->purge('cli:retention', 10, 2);

        self::assertSame(20, $result['removed']);
        self::assertSame(2, $result['batches']);
        self::assertTrue($result['remaining']);
        self::assertSame(20, $repository->eligibleCounts('2026-01-01 00:00:00')['audit_events']);
    }

    public function testABlankActorStillGetsAName(): void
    {
        // An unattributed deletion of audit rows is worse than no deletion; the fallback exists so a
        // caller cannot produce one by passing an empty string.
        $repository = new InMemorySecurityAuditRetentionRepository();
        $repository->seed('audit_events', 1);

        $this->service($repository)->purge('   ');

        self::assertSame('retention:cli', $repository->runs()[0]['actor']);
    }
}
