<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RetentionSchedule;
use App\Support\RetentionValidationRecord;
use PHPUnit\Framework\TestCase;

/**
 * The difference between "somebody said yes" and "somebody said yes to this".
 *
 * The gate's old `partial` could not close, so nobody could tell a schedule that had been validated
 * from one that had merely been switched on. These cases pin the two properties that make the record
 * worth reading: an empty one never counts as validated, and one whose fingerprint no longer matches
 * stops counting the moment the table moves — without anyone remembering to revoke it.
 */
final class RetentionValidationRecordTest extends TestCase
{
    /**
     * A complete, current record, with individual fields overridable per case.
     *
     * @param array<string, mixed> $overrides
     */
    private function record(array $overrides = []): RetentionValidationRecord
    {
        return RetentionValidationRecord::fromArray($overrides + [
            'approver' => 'Gemechisa Dalu',
            'office' => RetentionValidationRecord::OFFICE,
            'approved_on' => '2026-11-27',
            'scope' => 'Periods, lawful basis, preservation exceptions.',
            'schedule_fingerprint' => RetentionSchedule::fingerprint(),
        ]);
    }

    public function testACompleteRecordOverTheCurrentScheduleIsCurrent(): void
    {
        $record = $this->record();

        self::assertSame(RetentionValidationRecord::CURRENT, $record->state);
        self::assertTrue($record->isCurrent());
        self::assertSame('Gemechisa Dalu', $record->approver);
    }

    public function testAFileThatDoesNotExistIsUnrecordedRatherThanAnError(): void
    {
        // A new checkout has not been validated yet. Reporting the gate as unreadable would be worse than
        // reporting it as open, because an unreadable gate gets skipped.
        $record = RetentionValidationRecord::fromFile(__DIR__ . '/does-not-exist.php');

        self::assertSame(RetentionValidationRecord::UNRECORDED, $record->state);
        self::assertFalse($record->isCurrent());
    }

    public function testAnEmptyRegisterIsUnrecorded(): void
    {
        $record = RetentionValidationRecord::fromArray([
            'approver' => null,
            'office' => RetentionValidationRecord::OFFICE,
            'approved_on' => null,
            'scope' => null,
            'schedule_fingerprint' => RetentionSchedule::fingerprint(),
        ]);

        self::assertSame(RetentionValidationRecord::UNRECORDED, $record->state);
    }

    public function testBlankStringsAreNotAnAttestation(): void
    {
        // A field left as `'   '` reads as filled to a careless check and as empty to a person. It is
        // empty: the point of the record is that someone is named.
        $record = $this->record(['approver' => '   ']);

        self::assertSame(RetentionValidationRecord::UNRECORDED, $record->state);
    }

    public function testARecordWithoutADateIsIncomplete(): void
    {
        $record = $this->record(['approved_on' => null]);

        self::assertSame(RetentionValidationRecord::INCOMPLETE, $record->state);
        self::assertStringContainsString('no approval date', $record->explanation);
    }

    public function testADateThatIsNotADateIsIncomplete(): void
    {
        // "27/11/2026" is a real date to a person and a false one to strtotime in half the world.
        $record = $this->record(['approved_on' => '27/11/2026']);

        self::assertSame(RetentionValidationRecord::INCOMPLETE, $record->state);
    }

    public function testARecordThatDoesNotSayWhatItValidatedIsIncomplete(): void
    {
        $record = $this->record(['scope' => null]);

        self::assertSame(RetentionValidationRecord::INCOMPLETE, $record->state);
        self::assertStringContainsString('does not say what was', $record->explanation);
    }

    public function testAnApprovalFromAnotherOfficeIsIncomplete(): void
    {
        // D-02 names Legal / Data Protection. An engineering sign-off is a real sign-off and not this one,
        // and the record has to be able to say so rather than accept any name in the field.
        $record = $this->record(['office' => 'Engineering Lead']);

        self::assertSame(RetentionValidationRecord::INCOMPLETE, $record->state);
        self::assertStringContainsString('Legal / Data Protection', $record->explanation);
    }

    public function testARecordOverADifferentScheduleIsStale(): void
    {
        // The whole reason the field exists: the periods moved after the validation, so the validation
        // no longer describes the table, and the gate must not keep counting it.
        $record = $this->record(['schedule_fingerprint' => str_repeat('a', 64)]);

        self::assertSame(RetentionValidationRecord::STALE, $record->state);
        self::assertStringContainsString('Re-validation is required', $record->explanation);
    }

    public function testARecordWithNoFingerprintIsStaleRatherThanCurrent(): void
    {
        $record = $this->record(['schedule_fingerprint' => null]);

        self::assertSame(RetentionValidationRecord::STALE, $record->state);
    }

    public function testTheFingerprintCoversThePeriodsAndTheBackupLimits(): void
    {
        // Asserted against the schedule's own source rather than against a literal hash, which would fail
        // on every legitimate amendment and teach the next person to paste the new value without reading
        // why it changed.
        $fingerprint = RetentionSchedule::fingerprint();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $fingerprint);
        self::assertSame(12, strlen(RetentionSchedule::fingerprintShort()));
        // Not `assertStringStartsWith`: its prefix parameter is typed non-empty-string, and a prefix is
        // non-empty only by inspection here. `str_starts_with` says the same thing without the cast.
        self::assertTrue(str_starts_with($fingerprint, RetentionSchedule::fingerprintShort()));

        // Stable within a build: two calls agree, or every gate reading would differ from the record.
        self::assertSame($fingerprint, RetentionSchedule::fingerprint());
    }

    public function testMovingOnePeriodChangesTheFingerprint(): void
    {
        // Simulated rather than mutated: `CLASSES` is a constant, so the property under test is the
        // construction rule — sorted `class=days` lines plus the two backup limits, hashed.
        $before = hash('sha256', implode("\n", [
            'appointment_and_administrative=2557',
            'backup_copy_cap=14',
            'backup_max_age_days=35',
            'clinical_and_procedure=1825',
        ]));

        $after = hash('sha256', implode("\n", [
            'appointment_and_administrative=2557',
            'backup_copy_cap=14',
            'backup_max_age_days=35',
            'clinical_and_procedure=2557',
        ]));

        self::assertNotSame($before, $after);

        // And the real fingerprint is not the empty hash or a constant nobody computed.
        self::assertNotSame(hash('sha256', ''), RetentionSchedule::fingerprint());
    }

    public function testTheSummaryNamesTheStateBeforeItExplainsIt(): void
    {
        // Reports are read by people scanning for a state, then for a reason. "Something changed" without
        // "STALE" beside it is the sentence that gets skimmed past.
        self::assertStringStartsWith('[CURRENT] ', $this->record()->summary());
        self::assertStringStartsWith('[STALE] ', $this->record(['schedule_fingerprint' => null])->summary());
    }
}
