<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bootstrap\Config;
use App\Domain\RetentionSchedule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * D-02's retention table, as code, with its gate closed.
 *
 * The decision adopts a period per record class and then makes the whole table conditional on Legal
 * validation. So there are two things to hold: the numbers must be the ones D-02 adopted, and the
 * gate must be shut by default — an unset environment variable must leave the old single window
 * running rather than start deleting on an unvalidated schedule.
 *
 * The periods are asserted rather than derived on purpose. A schedule computed from "seven years" at
 * run time would drift from the privacy notice on a leap year; these are the figures D-02 adopted
 * and the figures the notice states.
 */
final class RetentionScheduleTest extends TestCase
{
    public function testTheClosedScheduleIsTheDefault(): void
    {
        self::assertFalse((new RetentionSchedule())->isActive());
    }

    public function testTheTableIsTheOneTheDecisionAdopted(): void
    {
        // Amended 26 November 2026: four classes moved to 1,825 days and `dispatch_runs` was added.
        // The earlier seven-year figures are asserted nowhere now, which is the point of a test that
        // states the schedule rather than deriving it — the change had to be made deliberately here.
        self::assertSame(
            [
                'clinical_and_procedure' => 1825,
                'referral_and_intake' => 2557,
                'uploaded_clinical_documents' => 1825,
                'appointment_and_administrative' => 2557,
                'notification_and_outbox' => 365,
                'security_and_audit' => 1825,
                'dispatch_runs' => 1825,
            ],
            (new RetentionSchedule())->classes(),
        );
    }

    /**
     * The classes still differ, which is the whole reason the single constant had to go: clinical
     * records at five years, notifications at one.
     */
    public function testNotificationRecordsAreKeptForTwelveMonthsAndClinicalRecordsForFiveYears(): void
    {
        $schedule = new RetentionSchedule();

        self::assertSame(365, $schedule->daysFor('notification_and_outbox'));
        self::assertSame(1825, $schedule->daysFor('clinical_and_procedure'));
        self::assertLessThan(
            $schedule->daysFor('clinical_and_procedure'),
            $schedule->daysFor('notification_and_outbox'),
        );
    }

    /**
     * Audit records moved from seven years to five on 26 November 2026, and this test records that
     * rather than preserving the old figure: an audit trail is the evidence of every other deletion,
     * so shortening it is a decision that should be visible in a diff and not only in a database.
     *
     * It is no longer the longest period in the schedule — referral and appointment records keep seven
     * years — which is worth knowing before the sweep for this class is written.
     */
    public function testAuditRecordsAreKeptForFiveYears(): void
    {
        self::assertSame(1825, (new RetentionSchedule())->daysFor('security_and_audit'));
    }

    public function testTheBackupRowIsAMaximumAgeAndACopyCap(): void
    {
        $schedule = new RetentionSchedule();

        self::assertSame(35, $schedule->backupMaxAgeDays());
        self::assertSame(14, $schedule->backupCopyCap());
    }

    public function testTheSevenYearFigureIsTheOneThePrivacyNoticeStates(): void
    {
        self::assertSame(Config::DEFAULT_RETENTION_DAYS, RetentionSchedule::SEVEN_YEARS_DAYS);
    }

    public function testAnUnknownRecordClassIsRefusedRatherThanDefaulted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RetentionSchedule())->daysFor('everything_else');
    }

    /**
     * The reason the schedule is not running names the authority that has to lift the gate. A message
     * that said "not activated" without saying by whom would leave the next reader guessing.
     */
    public function testTheInactiveReasonNamesTheGateAndWhatIsUnmanagedToday(): void
    {
        $reason = (new RetentionSchedule())->inactiveReason();

        self::assertStringContainsString('Legal / Data Protection', $reason);
        self::assertStringContainsString('notification and audit records are swept by nothing', $reason);
    }
}
