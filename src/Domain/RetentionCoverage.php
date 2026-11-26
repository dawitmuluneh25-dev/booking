<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which record classes the retention schedule covers, and which it only names.
 *
 * `retention:plan` printed the adopted periods and stopped there, so a class that
 * had a period but no code path read exactly like a class that was being purged.
 *
 * The keys here are the schedule's own class names — `appointment_and_administrative`,
 * not `bookings`. The first version of this class used the table names and produced
 * a report that was wrong in both directions: four classes that are purged were
 * labelled as gaps, and two implementation paths appeared as classes with no period.
 * A coverage report that cries wolf is worse than no report, because somebody
 * eventually stops reading it.
 */
final class RetentionCoverage
{
    /** A period exists and code applies it to the whole class. */
    public const ENFORCED = 'enforced';

    /** A period exists and code applies it to part of the class. */
    public const PARTIAL = 'partial';

    /** A period exists in the schedule and nothing applies it. */
    public const DECLARED_ONLY = 'declared_only';

    /** Holds data, has no period in the schedule. */
    public const UNSCHEDULED = 'unscheduled';

    /**
     * Enforcement by schedule class name.
     *
     * Kept as a constant rather than inferred, because "is there a purge path"
     * cannot be discovered by inspecting a database — only by knowing the code.
     * Each value was checked against the repository that does the work:
     * `PdoRetentionRepository` redacts `bookings`; `PdoReferralRetentionRepository`
     * redacts `referrals`, redacts `referral_documents` and deletes their tracking
     * tokens; `PdoNotificationRetentionRepository` redacts `notification_outbox`.
     *
     * @var array<string, string>
     */
    public const ENFORCEMENT = [
        'appointment_and_administrative' => self::ENFORCED,
        'referral_and_intake' => self::ENFORCED,
        'notification_and_outbox' => self::ENFORCED,
        // Referral documents are redacted with their referral. An upload that is not
        // attached to a referral is not reached by that sweep, and no other path
        // reaches it either — so the class is partly covered, not covered.
        'uploaded_clinical_documents' => self::PARTIAL,
        'clinical_and_procedure' => self::DECLARED_ONLY,
        'security_and_audit' => self::DECLARED_ONLY,
        // Added 26 November 2026. `PdoDispatchRunRetentionRepository` deletes the whole row rather than
        // redacting it, because a run row is a timestamp, four counters and a hostname — there is
        // nothing in it that identifies a person, so removal is the honest act and `enforced` is the
        // honest status.
        'dispatch_runs' => self::ENFORCED,
    ];

    /**
     * Records that hold data and have no class in the schedule at all.
     *
     * @var array<string, string>
     */
    public const UNSCHEDULED_RECORDS = [];

    /**
     * Records that hold data and have no class in the schedule.
     *
     * Empty since 26 November 2026, when `dispatch_runs` entered the schedule and gained a sweep. It is
     * a method rather than a bare constant so the concept survives the list being empty: this is where
     * the next table with data and no period is declared, and a reader who finds `[]` here learns that
     * nothing is currently in that state, which is different from the question never having been asked.
     *
     * @return array<string, string>
     */
    public static function unscheduledRecords(): array
    {
        return self::UNSCHEDULED_RECORDS;
    }

    /**
     * @param array<string, int> $declared Classes with a period, and the period in days.
     *
     * @return list<array{class: string, status: string, days: int|null, note: string}>
     */
    public static function gaps(array $declared): array
    {
        $rows = [];

        foreach ($declared as $recordClass => $days) {
            $status = self::ENFORCEMENT[$recordClass] ?? self::DECLARED_ONLY;

            $rows[] = [
                'class' => $recordClass,
                'status' => $status,
                'days' => $days,
                'note' => self::note($status, true),
            ];
        }

        foreach (self::unscheduledRecords() as $recordClass => $note) {
            if (array_key_exists($recordClass, $declared)) {
                continue;
            }

            $rows[] = [
                'class' => $recordClass,
                'status' => self::UNSCHEDULED,
                'days' => null,
                'note' => $note,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{class: string, status: string, days: int|null, note: string}> $rows
     *
     * @return list<string>
     */
    public static function problems(array $rows): array
    {
        $problems = [];

        foreach ($rows as $row) {
            // Enforced with no declared period is also a problem: code that deletes
            // on a period nobody adopted destroys records the decision never covered.
            if ($row['status'] === self::ENFORCED && $row['days'] !== null) {
                continue;
            }

            $problems[] = $row['class'] . ' (' . $row['status'] . ')';
        }

        return $problems;
    }

    private static function note(string $status, bool $declared): string
    {
        if ($status === self::ENFORCED) {
            return $declared
                ? 'period applied by the purge path'
                : 'purged in code but absent from the schedule — the two disagree';
        }

        if ($status === self::PARTIAL) {
            return 'partly applied: referral documents are redacted with their referral,'
                . ' uploads outside a referral are not';
        }

        return 'period declared, no code applies it — data outlives the schedule';
    }
}
