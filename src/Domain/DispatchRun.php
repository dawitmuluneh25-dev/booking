<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One completed invocation of `outbox:dispatch`.
 *
 * It exists to answer a question the outbox cannot: is anything draining it? A
 * pending row proves work is waiting, not that somebody is looking.
 */
final class DispatchRun
{
    public function __construct(
        public readonly string $startedAtUtc,
        public readonly string $finishedAtUtc,
        public readonly int $claimed,
        public readonly int $sent,
        public readonly int $retrying,
        public readonly int $dead,
        public readonly string $host,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            startedAtUtc: self::text($row, 'started_at'),
            finishedAtUtc: self::text($row, 'finished_at'),
            claimed: self::number($row, 'claimed'),
            sent: self::number($row, 'sent'),
            retrying: self::number($row, 'retrying'),
            dead: self::number($row, 'dead'),
            host: self::text($row, 'host'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Counts arrive from PDO as strings on some drivers and as integers on others.
     * A count that cannot be read becomes zero rather than a cast of a mixed value,
     * which is how a null silently becomes a confident `0`.
     *
     * @param array<string, mixed> $row
     */
    private static function number(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : 0;
    }
}
