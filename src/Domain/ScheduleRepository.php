<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Read side of the schedule: what is bookable, when, and what is already booked.
 *
 * Deliberately separate from `AppointmentRepository`. Writes go through the
 * occupancy table and its lock; reads are a plain query, and mixing them into one
 * interface invites a caller to believe that a read has taken a reservation.
 */
interface ScheduleRepository
{
    /** @return list<Resource> active suites and rooms, in a stable order */
    public function resources(): array;

    /**
     * Windows that apply to one weekday on one date.
     *
     * Availability is a rule with an optional validity range, so this takes both
     * the weekday the rule is keyed by and the date it is being asked about.
     *
     * @return list<AvailabilityWindow>
     */
    public function windowsFor(int $resourceId, int $isoWeekday, string $localDate): array;

    /**
     * Occupied quanta for a resource in a UTC range.
     *
     * @return array<string, int> slot start => appointment id
     */
    public function occupancyBetween(int $resourceId, string $fromUtc, string $toUtc): array;

    /**
     * Appointments that still hold their room and overlap a UTC range.
     *
     * @return list<ScheduledAppointment>
     */
    public function appointmentsBetween(string $fromUtc, string $toUtc): array;
}
