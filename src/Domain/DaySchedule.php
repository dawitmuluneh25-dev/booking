<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds the bookable slot grid for one day in the clinic's timezone.
 *
 * Slots are generated on the wall clock (so an opening time stays 08:00 across
 * a DST change) and converted to UTC only when persisted or queried.
 */
final class DaySchedule
{
    /**
     * @return list<DateTimeImmutable> slots in the given timezone, ascending
     */
    public static function slots(
        DateTimeImmutable $date,
        DateTimeZone $timezone,
        int $slotMinutes,
        string $opens,
        string $closes,
    ): array {
        $start = self::minutes($opens);
        $end = self::minutes($closes);

        if ($slotMinutes <= 0 || $start === null || $end === null || $end <= $start) {
            return [];
        }

        $local = $date->setTimezone($timezone);
        $cursor = $local->setTime(intdiv($start, 60), $start % 60);
        $limit = $local->setTime(intdiv($end, 60), $end % 60);

        $slots = [];
        while ($cursor < $limit) {
            $slots[] = $cursor;
            $cursor = $cursor->modify('+' . $slotMinutes . ' minutes');
        }

        return $slots;
    }

    public static function parseDate(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }

    /** Minutes since midnight for an `HH:MM` value, or null when invalid. */
    private static function minutes(string $time): ?int
    {
        $normalized = OpeningHours::normalizeTime($time);

        if ($normalized === null) {
            return null;
        }

        [$hour, $minute] = explode(':', $normalized);

        return (int) $hour * 60 + (int) $minute;
    }
}
