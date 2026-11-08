<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One executed retention sweep, kept as evidence that the policy ran.
 */
final readonly class RetentionRun
{
    public function __construct(
        public string $cutoffDate,
        public int $bookingsRedacted,
        public string $actor,
        public string $ranAtUtc,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            cutoffDate: self::text($row, 'cutoff_date'),
            bookingsRedacted: (int) self::text($row, 'bookings_redacted'),
            actor: self::text($row, 'actor'),
            ranAtUtc: self::text($row, 'ran_at'),
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
}
