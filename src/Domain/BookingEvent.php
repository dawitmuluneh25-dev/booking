<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One row of the append-only lifecycle history.
 */
final readonly class BookingEvent
{
    public function __construct(
        public int $id,
        public string $reference,
        public string $fromStatus,
        public string $toStatus,
        public string $actor,
        public ?string $reason,
        public string $createdAtUtc,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) self::text($row, 'id'),
            reference: self::text($row, 'reference'),
            fromStatus: self::text($row, 'from_status'),
            toStatus: self::text($row, 'to_status'),
            actor: self::text($row, 'actor'),
            reason: self::nullableText($row, 'reason'),
            createdAtUtc: self::text($row, 'created_at'),
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
    private static function nullableText(array $row, string $key): ?string
    {
        $value = self::text($row, $key);

        return $value === '' ? null : $value;
    }
}
