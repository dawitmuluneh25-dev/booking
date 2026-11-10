<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What the reception screen shows, assembled from the read side.
 *
 * The point of this class is that the screen and the booking form ask the same
 * question of the same code. If the board shows 09:00 as free, the wizard offers
 * 09:00, and a POST for 09:00 either succeeds or fails for a reason the desk can
 * act on — there is no second opinion between the calendar and the form
 * (PRD 7.4).
 */
final class ScheduleBoard
{
    public function __construct(
        private readonly ScheduleRepository $schedule,
        private readonly ResourceBlockRepository $blocks,
        private readonly ResourceSchedule $calculator = new ResourceSchedule(),
    ) {
    }

    /** @return list<Resource> */
    public function resources(): array
    {
        return $this->schedule->resources();
    }

    /**
     * One row per resource: its appointments that day, and how much of the day is
     * still bookable at the requested length.
     *
     * @return list<array{resource: Resource, appointments: list<Appointment>, freeCount: int}>
     */
    public function day(
        DateTimeImmutable $localDate,
        DateTimeZone $zone,
        int $durationMinutes,
        DateTimeImmutable $nowUtc,
    ): array {
        [$fromUtc, $toUtc] = $this->dayBounds($localDate, $zone);
        $appointments = $this->schedule->appointmentsBetween($fromUtc, $toUtc);

        $rows = [];

        foreach ($this->schedule->resources() as $resource) {
            $onResource = [];

            foreach ($appointments as $scheduled) {
                if ($scheduled->resourceId === $resource->id) {
                    $onResource[] = $scheduled->appointment;
                }
            }

            usort(
                $onResource,
                static fn (Appointment $a, Appointment $b): int => strcmp($a->startsAtUtc, $b->startsAtUtc),
            );

            $rows[] = [
                'resource' => $resource,
                'appointments' => $onResource,
                'freeCount' => count($this->freeSlotsFor($resource->id, $localDate, $zone, $durationMinutes, $nowUtc)),
            ];
        }

        return $rows;
    }

    /**
     * Bookable starts for one resource on one local date, with the desired length.
     *
     * @return list<array{utc: string, label: string}>
     */
    public function freeSlots(
        int $resourceId,
        DateTimeImmutable $localDate,
        DateTimeZone $zone,
        int $durationMinutes,
        DateTimeImmutable $nowUtc,
    ): array {
        return $this->freeSlotsFor($resourceId, $localDate, $zone, $durationMinutes, $nowUtc);
    }

    /**
     * The UTC window covering one local day.
     *
     * Two days are queried rather than one because a local day can begin on the
     * previous UTC date; the extra hours are filtered out by the per-resource
     * window arithmetic, not by the range.
     *
     * @return array{0: string, 1: string}
     */
    public function dayBounds(DateTimeImmutable $localDate, DateTimeZone $zone): array
    {
        $start = $localDate->setTimezone($zone)->setTime(0, 0);
        $end = $start->modify('+1 day');

        if ($end === false) {
            $end = $start;
        }

        $utc = new DateTimeZone('UTC');

        return [
            $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }

    /** @return list<array{utc: string, label: string}> */
    private function freeSlotsFor(
        int $resourceId,
        DateTimeImmutable $localDate,
        DateTimeZone $zone,
        int $durationMinutes,
        DateTimeImmutable $nowUtc,
    ): array {
        [$fromUtc, $toUtc] = $this->dayBounds($localDate, $zone);

        // A closure is not a booking, but to the question "may this be offered"
        // both answers are the same. Merging them here keeps one arithmetic path
        // for the board, the wizard and the day view.
        $occupied = array_keys($this->schedule->occupancyBetween($resourceId, $fromUtc, $toUtc));

        foreach (array_keys($this->blocks->blockedQuanta($resourceId, $fromUtc, $toUtc)) as $blocked) {
            $occupied[] = $blocked;
        }

        $local = $localDate->setTimezone($zone);

        return $this->calculator->freeSlots(
            $this->schedule->windowsFor($resourceId, (int) $local->format('N'), $local->format('Y-m-d')),
            array_map(static fn (int|string $start): string => (string) $start, $occupied),
            $localDate,
            $zone,
            $durationMinutes,
            $nowUtc,
        );
    }
}
