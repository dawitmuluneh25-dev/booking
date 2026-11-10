<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Where "is this slot free?" is answered, and the only place it is answered.
 *
 * PRD 7.4 makes server-side availability authoritative, so this class decides
 * what the wizard may offer. The browser receives a list of times that fit; it is
 * never asked whether a slot is free, and a hand-crafted POST that names an
 * occupied time is refused by `resource_slots` rather than by a check here.
 *
 * A candidate must satisfy three things at once:
 *
 * - the whole appointment fits inside one opening window, not merely the start;
 * - none of its quanta is occupied;
 * - it starts in the future.
 *
 * The quantum is 15 minutes, matching `BookAppointment`; both call
 * `quantumStarts()` so the reservation and the offer cannot drift apart.
 */
final class ResourceSchedule
{
    public const SLOT_MINUTES = 15;

    /**
     * The occupancy keys an appointment of this length occupies.
     *
     * @return list<string> UTC `Y-m-d H:i:s`, ascending
     */
    public static function quantumStarts(DateTimeImmutable $startsAtUtc, int $durationMinutes): array
    {
        $starts = [];

        for ($offset = 0; $offset < $durationMinutes; $offset += self::SLOT_MINUTES) {
            $slot = $startsAtUtc->modify('+' . $offset . ' minutes');

            if ($slot === false) {
                continue;
            }

            $starts[] = $slot->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        return $starts;
    }

    /**
     * Bookable starts for one resource on one local date.
     *
     * @param list<AvailabilityWindow> $windows        the resource's windows for that weekday
     * @param list<string>             $occupiedStarts UTC starts already held by other appointments
     *
     * @return list<array{utc: string, label: string}>
     */
    public function freeSlots(
        array $windows,
        array $occupiedStarts,
        DateTimeImmutable $localDate,
        DateTimeZone $zone,
        int $durationMinutes,
        DateTimeImmutable $nowUtc,
    ): array {
        if ($durationMinutes <= 0 || $durationMinutes % self::SLOT_MINUTES !== 0) {
            return [];
        }

        $occupied = [];
        foreach ($occupiedStarts as $start) {
            $occupied[$start] = true;
        }

        $now = $nowUtc->setTimezone(new DateTimeZone('UTC'));
        $free = [];

        foreach ($windows as $window) {
            $opens = $this->localTime($localDate, $window->opensAt, $zone);
            $closes = $this->localTime($localDate, $window->closesAt, $zone);

            if ($opens === null || $closes === null) {
                continue;
            }

            $latestStart = $closes->modify('-' . $durationMinutes . ' minutes');

            if ($latestStart === false) {
                continue;
            }

            $step = '+' . self::SLOT_MINUTES . ' minutes';

            for ($cursor = $opens; $cursor <= $latestStart; $cursor = $cursor->modify($step)) {
                if ($cursor === false) {
                    break;
                }

                $utcStart = $cursor->setTimezone(new DateTimeZone('UTC'));

                if ($utcStart <= $now) {
                    continue;
                }

                if (!$this->isFree($utcStart, $durationMinutes, $occupied)) {
                    continue;
                }

                $key = $utcStart->format('Y-m-d H:i:s');
                $free[$key] = ['utc' => $key, 'label' => $cursor->format('H:i')];
            }
        }

        ksort($free);

        return array_values($free);
    }

    /** @param array<string, true> $occupied */
    private function isFree(DateTimeImmutable $startUtc, int $durationMinutes, array $occupied): bool
    {
        foreach (self::quantumStarts($startUtc, $durationMinutes) as $quantum) {
            if (isset($occupied[$quantum])) {
                return false;
            }
        }

        return true;
    }

    private function localTime(DateTimeImmutable $date, string $time, DateTimeZone $zone): ?DateTimeImmutable
    {
        if (preg_match('/\A([0-9]{2}):([0-9]{2})\z/', $time, $match) !== 1) {
            return null;
        }

        $value = $date->setTimezone($zone)->format('Y-m-d') . ' ' . $match[1] . ':' . $match[2] . ':00';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);

        return $parsed === false ? null : $parsed;
    }
}
