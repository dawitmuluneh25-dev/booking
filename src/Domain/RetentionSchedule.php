<?php

declare(strict_types=1);

namespace App\Domain;

use App\Bootstrap\Config;
use InvalidArgumentException;

/**
 * HR-ENG-DEC-R1-003 D-02's retention schedule, by record class.
 *
 * Until now this application had one number. `Config::DEFAULT_RETENTION_DAYS` is 2,557 — seven years
 * — and one sweep applied it to ten record classes at once, while `notification_outbox` and the audit
 * trail were swept by nothing at all. That is not a retention schedule; it is a single rule that
 * happens to be right for the clinical records and wrong for everything else.
 *
 * D-02 adopts a period **per class** and attaches a condition to the whole table: Legal/Data
 * Protection must validate the periods, the lawful basis and the preservation exceptions "before
 * production activation". So this class carries the schedule and an activation flag, and the flag is
 * off. While it is off, nothing in the application changes: `retention:purge` still runs the single
 * seven-year window it ran before, and `retention:plan` now *prints the schedule it is not running*
 * so that the difference between the adopted policy and the executed one is visible rather than
 * assumed.
 *
 * What the schedule does not do is delete anything. Execution per class is a separate step with its
 * own risks — an audit row deleted on a wrong cut-off destroys the evidence that a deletion was
 * authorised — and it is not something to switch on in the same change that introduces the table.
 *
 * Two periods deserve a note because they look arbitrary and are not:
 *
 *   - **Twelve months for notification records** is 365 days here. D-02 says "12 months" and does not
 *     say which calendar; a fixed 365 keeps the cut-off a function of the run date alone, so two
 *     purges either side of a leap day cannot disagree about the same row.
 *   - **35 days for encrypted backups is a maximum, not a target.** At the hourly cadence with the
 *     14-copy cap, the oldest archive is about 13 hours old, so the age limit never binds. It is
 *     recorded because D-02 records it, and it would bind if the cadence were reduced.
 */
final class RetentionSchedule
{
    /** Seven calendar years, including two leap days. The same figure the privacy notice states. */
    public const SEVEN_YEARS_DAYS = Config::DEFAULT_RETENTION_DAYS;

    /** Five calendar years, excluding leap days: the period adopted for three classes on 26 Nov 2026. */
    public const FIVE_YEARS_DAYS = 1825;

    /** D-02's "12 months", as a fixed 365 days. */
    public const TWELVE_MONTHS_DAYS = 365;

    /** D-02's "maximum 35-day rolling retention" for encrypted backups. */
    public const BACKUP_MAX_AGE_DAYS = 35;

    /** D-02's "subject to a 14-copy cap". */
    public const BACKUP_COPY_CAP = 14;

    /**
     * D-02's table, verbatim: record category => adopted planning period in days.
     *
     * @var array<string, int>
     */
    public const CLASSES = [
        'clinical_and_procedure' => self::FIVE_YEARS_DAYS,
        'referral_and_intake' => self::SEVEN_YEARS_DAYS,
        'uploaded_clinical_documents' => self::FIVE_YEARS_DAYS,
        'appointment_and_administrative' => self::SEVEN_YEARS_DAYS,
        'notification_and_outbox' => self::TWELVE_MONTHS_DAYS,
        // Back to seven years on 26 November 2026, reversing part of the same day's amendment. A
        // five-year audit trail outlived nothing: referrals and appointments keep seven, so a record
        // deleted in year six would leave no evidence of who authorised it.
        'security_and_audit' => self::SEVEN_YEARS_DAYS,
        'dispatch_runs' => self::FIVE_YEARS_DAYS,
    ];

    /**
     * @param bool $active whether Legal/Data Protection have validated the schedule
     */
    public function __construct(public readonly bool $active = false)
    {
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * The period adopted for one record class.
     *
     * @throws InvalidArgumentException the class is not in D-02's table
     */
    public function daysFor(string $recordClass): int
    {
        if (!array_key_exists($recordClass, self::CLASSES)) {
            throw new InvalidArgumentException(
                'No retention period is adopted for "' . $recordClass . '". D-02 lists: '
                . implode(', ', array_keys(self::CLASSES)) . '.'
            );
        }

        return self::CLASSES[$recordClass];
    }

    /** @return array<string, int> */
    public function classes(): array
    {
        return self::CLASSES;
    }

    public function backupMaxAgeDays(): int
    {
        return self::BACKUP_MAX_AGE_DAYS;
    }

    public function backupCopyCap(): int
    {
        return self::BACKUP_COPY_CAP;
    }

    /**
     * Why the schedule is not being executed, in the words of the decision that gates it.
     *
     * Printed by `retention:plan` on every run while the flag is off. A schedule that is adopted and
     * not applied is exactly the kind of thing that gets forgotten between the decision and the
     * cutover, so it says so every time it is asked.
     */
    public function inactiveReason(): string
    {
        return 'Adopted by HR-ENG-DEC-R1-003 D-02 and not activated: Legal / Data Protection must '
            . 'validate the periods, the lawful basis, the preservation exceptions and the applicable '
            . 'Ethiopian requirements before this schedule runs (D-02, "Legal condition"). Until then '
            . 'retention:purge applies the single seven-year window to bookings and referrals, and '
            . 'notification and audit records are swept by nothing.';
    }
}
