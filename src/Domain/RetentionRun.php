<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One executed retention sweep, kept as evidence that the policy ran.
 *
 * The three counters are separate because a sweep can legitimately do one thing
 * and not the others, and an audit line that reports a single total cannot say
 * whether the referral documents were actually covered.
 */
final readonly class RetentionRun
{
    public function __construct(
        public string $cutoffDate,
        public int $bookingsRedacted,
        public string $actor,
        public string $ranAtUtc,
        public int $referralsRedacted = 0,
        public int $documentsRedacted = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            cutoffDate: self::text($row, 'cutoff_date'),
            bookingsRedacted: self::number($row, 'bookings_redacted'),
            actor: self::text($row, 'actor'),
            ranAtUtc: self::text($row, 'ran_at'),
            referralsRedacted: self::number($row, 'referrals_redacted'),
            documentsRedacted: self::number($row, 'documents_redacted'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }

    /** @param array<string, mixed> $row */
    private static function number(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
