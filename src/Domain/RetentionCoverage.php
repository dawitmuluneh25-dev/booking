<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which record classes the retention schedule covers, and which it only names.
 *
 * `retention:plan` printed the adopted periods and stopped there, so a class that
 * had a period but no code path read exactly like a class that was being purged.
 * Two of them were in that position: `security_and_audit` has a declared period
 * and nothing that applies it, and `dispatch_runs` (added in §54) holds an
 * operational history with no period at all.
 *
 * Both are deliberate, and both are the kind of deliberate that survives only if
 * something says so out loud. This does not delete anything and does not invent a
 * period: it reports the difference between a decision and an implementation,
 * which is the same distinction the release gates make for policy documents.
 */
final class RetentionCoverage
{
    /** A period exists and code applies it. */
    public const ENFORCED = 'enforced';

    /** A period exists in the schedule and nothing applies it. */
    public const DECLARED_ONLY = 'declared_only';

    /** Holds data, has no period in the schedule. */
    public const UNSCHEDULED = 'unscheduled';

    /**
     * The code paths that exist, regardless of what the schedule declares.
     *
     * Kept as a constant rather than inferred, because "is there a purge path"
     * cannot be discovered by inspecting a database — only by knowing the code.
     *
     * @var array<string, string>
     */
    public const ENFORCEMENT = [
        'bookings' => self::ENFORCED,
        'referrals' => self::ENFORCED,
        'notification_and_outbox' => self::ENFORCED,
        'security_and_audit' => self::DECLARED_ONLY,
        'dispatch_runs' => self::UNSCHEDULED,
    ];

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
                'note' => match ($status) {
                    self::ENFORCED => 'period applied by the purge path',
                    default => 'period declared, no code applies it — data outlives the schedule',
                },
            ];
        }

        foreach (self::ENFORCEMENT as $recordClass => $status) {
            if (array_key_exists($recordClass, $declared)) {
                continue;
            }

            if ($status === self::UNSCHEDULED) {
                $rows[] = [
                    'class' => $recordClass,
                    'status' => self::UNSCHEDULED,
                    'days' => null,
                    'note' => 'holds history with no period in the schedule — a decision nobody has made',
                ];

                continue;
            }

            $rows[] = [
                'class' => $recordClass,
                'status' => $status,
                'days' => null,
                'note' => 'enforced in code but absent from the schedule — the two disagree',
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
}
