<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Opening hours for one weekday, in clinic-local wall-clock time.
 *
 * Times are `HH:MM` strings rather than DateTime objects so a stored value
 * cannot drift with the server timezone. Comparison is lexicographic, which is
 * correct for zero-padded 24-hour times.
 */
final readonly class OpeningHours
{
    private const LABELS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public function __construct(
        public int $weekday,
        public bool $closed,
        public string $opens,
        public string $closes,
    ) {
        if (!isset(self::LABELS[$weekday])) {
            throw new InvalidArgumentException('Weekday must be 1 (Monday) to 7 (Sunday).');
        }

        if (self::normalizeTime($opens) !== $opens || self::normalizeTime($closes) !== $closes) {
            throw new InvalidArgumentException('Opening hours must be HH:MM.');
        }

        if (!$closed && $closes <= $opens) {
            throw new InvalidArgumentException('Closing time must be after opening time.');
        }
    }

    /** Accepts `8:00`, `08:00` and `08:00:00`; returns `HH:MM` or null when invalid. */
    public static function normalizeTime(string $value): ?string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    public function label(): string
    {
        return self::LABELS[$this->weekday] ?? (string) $this->weekday;
    }

    public function spans(): string
    {
        return $this->closed ? 'Closed' : $this->opens . '–' . $this->closes;
    }

    /**
     * True when an appointment of $slotMinutes starting at $local fits inside
     * this weekday's window.
     */
    public function contains(DateTimeImmutable $local, int $slotMinutes = 0): bool
    {
        if ($this->closed) {
            return false;
        }

        if ((int) $local->format('N') !== $this->weekday) {
            return false;
        }

        $start = $local->format('H:i');
        $end = $local->modify('+' . max(0, $slotMinutes) . ' minutes')->format('H:i');

        // An appointment that would run past midnight never fits.
        if ($end < $start) {
            return false;
        }

        return $start >= $this->opens && $end <= $this->closes;
    }
}
