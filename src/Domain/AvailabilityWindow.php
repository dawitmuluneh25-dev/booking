<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One recurring opening window for a resource, as wall-clock times.
 *
 * Times are stored as the clinic's own `H:i`, not as instants: "Tuesdays, 08:00
 * to 16:00" is a rule that survives a daylight-saving change, whereas an instant
 * would drift by an hour twice a year. Conversion to UTC happens once, at the
 * edge, in `ResourceSchedule`.
 */
final readonly class AvailabilityWindow
{
    public function __construct(
        public int $weekday,
        public string $opensAt,
        public string $closesAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): ?self
    {
        $weekday = $row['weekday'] ?? null;
        $opens = self::timeOf($row['opens_at'] ?? null);
        $closes = self::timeOf($row['closes_at'] ?? null);

        if (!is_numeric($weekday) || $opens === null || $closes === null) {
            return null;
        }

        return new self((int) $weekday, $opens, $closes);
    }

    /** MySQL may hand back `08:00:00`, `08:00`, or a full datetime. */
    private static function timeOf(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (preg_match('/\A([0-2][0-9]):([0-5][0-9])(?::[0-5][0-9])?\z/', $value, $match) !== 1) {
            return null;
        }

        return $match[1] . ':' . $match[2];
    }
}
