<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RetentionCoverage;
use PHPUnit\Framework\TestCase;

/**
 * Telling a decision apart from an implementation.
 *
 * The first version of this test asserted against table names (`bookings`,
 * `referrals`) instead of the schedule's class names, so it agreed with a report
 * that was wrong: four purged classes were shown as gaps and two implementation
 * paths appeared as classes without a period. Running `retention:plan` against
 * the real database is what caught it, which is why one case below takes the real
 * schedule verbatim.
 */
final class RetentionCoverageTest extends TestCase
{
    /** The classes `RetentionSchedule` actually declares, in its own order. */
    private const REAL_SCHEDULE = [
        'clinical_and_procedure' => 1825,
        'referral_and_intake' => 2557,
        'uploaded_clinical_documents' => 1825,
        'appointment_and_administrative' => 2557,
        'notification_and_outbox' => 365,
        'security_and_audit' => 2557,
        'dispatch_runs' => 1825,
    ];

    public function testAPurgedClassIsNotReportedAsAGap(): void
    {
        $rows = RetentionCoverage::gaps(['appointment_and_administrative' => 2557]);

        self::assertSame(RetentionCoverage::ENFORCED, $rows[0]['status']);
        self::assertNotContains(
            'appointment_and_administrative (enforced)',
            RetentionCoverage::problems($rows),
        );
    }

    public function testADeclaredPeriodWithNoCodeIsReportedAsSuch(): void
    {
        $rows = RetentionCoverage::gaps(['security_and_audit' => 2557]);

        self::assertSame(RetentionCoverage::DECLARED_ONLY, $rows[0]['status']);
        self::assertContains('security_and_audit (declared_only)', RetentionCoverage::problems($rows));
    }

    public function testUploadedDocumentsAreEnforcedOnTheirOwnClock(): void
    {
        // Until 26 November 2026 this class was `partial`, and the note named a population that cannot
        // exist: `referral_documents.referral_id` is NOT NULL, so there are no uploads outside a
        // referral. The real gap was a missing trigger, and the class now has its own sweep on its own
        // 1,825-day period rather than riding its referral's 2,557-day one.
        $rows = RetentionCoverage::gaps(['uploaded_clinical_documents' => 1825]);

        self::assertSame(RetentionCoverage::ENFORCED, $rows[0]['status']);
        self::assertNotContains('uploaded_clinical_documents (enforced)', RetentionCoverage::problems($rows));
    }

    public function testAPartlyCoveredClassIsStillAProblem(): void
    {
        // `partial` exists because a boolean could not say "some of this class is swept and some is
        // not". A declared period is not enough to clear it — only `enforced` is — so a partly covered
        // class has to keep appearing in the problem list with its period intact.
        $problems = RetentionCoverage::problems([
            ['class' => 'some_future_class', 'status' => RetentionCoverage::PARTIAL, 'days' => 1825, 'note' => ''],
        ]);

        self::assertSame(['some_future_class (partial)'], $problems);
    }

    public function testAnEmptyScheduleReportsNoRowsAtAll(): void
    {
        // `dispatch_runs` was the one `UNSCHEDULED` record until 26 November 2026, when it entered the
        // schedule and got a sweep. With the list empty, an empty schedule now yields nothing — which is
        // the correct answer, and the reason this test changed rather than being deleted.
        self::assertSame([], RetentionCoverage::gaps([]));
    }

    public function testCodeThatPurgesOutsideTheScheduleIsReported(): void
    {
        // The other direction, and the more dangerous one: a sweep deleting rows on a period nobody
        // adopted destroys records the decision never covered. `problems()` has to say so.
        $problems = RetentionCoverage::problems([
            ['class' => 'dispatch_runs', 'status' => RetentionCoverage::ENFORCED, 'days' => null, 'note' => ''],
        ]);

        self::assertSame(['dispatch_runs (enforced)'], $problems);
    }

    public function testTheRealScheduleYieldsExactlyTheKnownGaps(): void
    {
        $problems = RetentionCoverage::problems(RetentionCoverage::gaps(self::REAL_SCHEDULE));

        self::assertSame(
            [
                'clinical_and_procedure (declared_only)',
                'security_and_audit (declared_only)',
            ],
            $problems,
            'Two classes have a period and no sweep. dispatch_runs left this list on 26 November 2026 and'
                . ' uploaded_clinical_documents followed the same day, both when their sweeps were written.',
        );
    }

    public function testNoPhantomClassAppearsForWorkThatIsAlreadyCounted(): void
    {
        // `bookings` and `referrals` are tables, not classes. Reporting them as classes
        // without a period is how the first version produced "bookings (enforced)" in a
        // list of things that are not covered.
        $classes = array_column(RetentionCoverage::gaps(self::REAL_SCHEDULE), 'class');

        self::assertNotContains('bookings', $classes);
        self::assertNotContains('referrals', $classes);
    }
}
