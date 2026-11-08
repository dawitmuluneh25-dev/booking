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
        int $openHour,
        int $closeHour,
    ): array {
        if ($slotMinutes <= 0 || $closeHour <= $openHour) {
            return [];
        }

        $local = $date->setTimezone($timezone);
        $cursor = $local->setTime($openHour, 0);
        $end = $local->setTime($closeHour, 0);

        $slots = [];
        while ($cursor < $end) {
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
}
