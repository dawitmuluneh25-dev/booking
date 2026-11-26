<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RetentionCoverage;
use PHPUnit\Framework\TestCase;

/**
 * Telling a decision apart from an implementation.
 *
 * A class with a declared period and no purge path reads identically to a class
 * being emptied on schedule. These cases are the difference, and the reason the
 * report exists at all.
 */
final class RetentionCoverageTest extends TestCase
{
    public function testAClassWithAPeriodAndACodePathIsNotAProblem(): void
    {
        $rows = RetentionCoverage::gaps(['bookings' => 2557]);

        self::assertSame(RetentionCoverage::ENFORCED, $rows[0]['status']);
        self::assertNotContains('bookings (enforced)', RetentionCoverage::problems($rows));
    }

    public function testADeclaredPeriodWithNoCodeIsReportedAsSuch(): void
    {
        $rows = RetentionCoverage::gaps(['security_and_audit' => 2557]);

        self::assertSame(RetentionCoverage::DECLARED_ONLY, $rows[0]['status']);
        self::assertContains('security_and_audit (declared_only)', RetentionCoverage::problems($rows));
    }

    public function testHistoryWithNoPeriodIsNamedRatherThanOmitted(): void
    {
        // dispatch_runs is not in the schedule at all. Omitting it from a coverage
        // report would make an unmade decision look like a covered class.
        $rows = RetentionCoverage::gaps([]);

        $classes = array_column($rows, 'status', 'class');

        self::assertArrayHasKey('dispatch_runs', $classes);
        self::assertSame(RetentionCoverage::UNSCHEDULED, $classes['dispatch_runs']);
    }

    public function testARealScheduleYieldsExactlyTheKnownGaps(): void
    {
        $rows = RetentionCoverage::gaps([
            'bookings' => 2557,
            'referrals' => 2557,
            'notification_and_outbox' => 365,
            'security_and_audit' => 2557,
        ]);

        self::assertSame(
            ['security_and_audit (declared_only)', 'dispatch_runs (unscheduled)'],
            RetentionCoverage::problems($rows),
        );
    }

    public function testEnforcementWithoutADeclaredPeriodIsADisagreementNotSilence(): void
    {
        // Code that deletes on a period nobody adopted is worse than code that does
        // nothing: it destroys records the decision did not cover.
        $rows = RetentionCoverage::gaps([]);
        $bookings = array_values(array_filter($rows, static fn (array $row): bool => $row['class'] === 'bookings'));

        self::assertSame('enforced', $bookings[0]['status']);
        self::assertStringContainsString('disagree', (string) $bookings[0]['note']);
    }
}
