<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\DispatchRunRetentionService;
use App\Domain\RetentionPolicy;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDispatchRunRetentionRepository;

/**
 * The batching rules for the dispatch-ledger sweep.
 *
 * The interesting cases are not "it deletes old rows" — that is the repository's job and is asserted
 * against MySQL. They are the ones an operator reads off the console: whether `remaining` tells the
 * truth when the batch ceiling is hit, and whether a sweep that removed nothing writes a run row.
 *
 * `remaining: false` while 400 rows wait is the failure that matters, because it is indistinguishable
 * from "finished" at the only moment anybody looks.
 */
final class DispatchRunRetentionServiceTest extends TestCase
{
    private const DAY = 86400;

    public function testItRemovesEverythingInsideOneBatchAndSaysItIsFinished(): void
    {
        $repository = new InMemoryDispatchRunRetentionRepository([
            $this->daysAgo(2000),
            $this->daysAgo(1900),
        ]);

        $result = (new DispatchRunRetentionService($repository, new RetentionPolicy(1825)))
            ->purge('cli:test', 10, 5);

        self::assertSame(2, $result['removed']);
        self::assertSame(1, $result['batches']);
        self::assertFalse($result['remaining']);
    }

    public function testItReportsThatWorkIsLeftWhenTheCeilingIsReached(): void
    {
        $repository = new InMemoryDispatchRunRetentionRepository([
            $this->daysAgo(2100),
            $this->daysAgo(2000),
            $this->daysAgo(1900),
        ]);

        $result = (new DispatchRunRetentionService($repository, new RetentionPolicy(1825)))
            ->purge('cli:test', 1, 2);

        self::assertSame(2, $result['removed']);
        self::assertSame(2, $result['batches']);
        self::assertTrue($result['remaining'], 'Two full batches at a ceiling of two means one row is still waiting.');
        self::assertSame(1, $repository->eligibleCount(gmdate('Y-m-d H:i:s')));
    }

    public function testAnEmptySweepWritesNoRunRow(): void
    {
        $repository = new InMemoryDispatchRunRetentionRepository([$this->daysAgo(10)]);

        $result = (new DispatchRunRetentionService($repository, new RetentionPolicy(1825)))
            ->purge('cli:test', 10, 5);

        self::assertSame(0, $result['removed']);
        self::assertSame([], $repository->runs, 'A row claiming zero is indistinguishable from a sweep that never ran.');
    }

    public function testTheRunNamesTheActorAndTheCutoffDate(): void
    {
        $repository = new InMemoryDispatchRunRetentionRepository([$this->daysAgo(2000)]);

        $result = (new DispatchRunRetentionService($repository, new RetentionPolicy(1825)))
            ->purge('cli:retention', 10, 5);

        self::assertCount(1, $repository->runs);
        self::assertSame('cli:retention', $repository->runs[0]['actor']);
        self::assertSame(1, $repository->runs[0]['removed']);
        self::assertSame(substr($result['cutoff'], 0, 10), $repository->runs[0]['cutoff_date']);
    }

    public function testABlankActorIsRecordedAsTheSweeperRatherThanAsAnEmptyString(): void
    {
        $repository = new InMemoryDispatchRunRetentionRepository([$this->daysAgo(2000)]);

        (new DispatchRunRetentionService($repository, new RetentionPolicy(1825)))->purge('  ', 10, 5);

        self::assertSame('retention:cli', $repository->runs[0]['actor']);
    }

    public function testThePlanCountsWithoutRemoving(): void
    {
        $repository = new InMemoryDispatchRunRetentionRepository([$this->daysAgo(2000)]);

        $plan = (new DispatchRunRetentionService($repository, new RetentionPolicy(1825)))->plan();

        self::assertSame(1, $plan['eligible']);
        self::assertSame(1825, $plan['days']);
        self::assertCount(1, $repository->stamps, 'plan() must not delete anything.');
    }

    private function daysAgo(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($days * self::DAY));
    }
}
