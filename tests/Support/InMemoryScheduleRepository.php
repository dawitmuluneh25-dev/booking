<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AvailabilityWindow;
use App\Domain\Resource;
use App\Domain\ScheduledAppointment;
use App\Domain\ScheduleRepository;

/**
 * The read side of the schedule, held in arrays.
 *
 * Windows and occupancy are seeded explicitly rather than derived, so a test can
 * describe a clinic morning in three lines and assert exactly what the wizard
 * offers from it.
 */
final class InMemoryScheduleRepository implements ScheduleRepository
{
    /** @var list<Resource> */
    private array $resources = [];

    /** @var array<int, list<AvailabilityWindow>> */
    private array $windows = [];

    /** @var array<int, array<string, int>> resource => slot => appointment id */
    private array $occupancy = [];

    /** @var list<ScheduledAppointment> */
    private array $appointments = [];

    public function addResource(Resource $resource): void
    {
        $this->resources[] = $resource;
    }

    /** @param list<AvailabilityWindow> $windows */
    public function setWindows(int $resourceId, array $windows): void
    {
        $this->windows[$resourceId] = $windows;
    }

    public function occupy(int $resourceId, string $slotStartUtc, int $appointmentId): void
    {
        $this->occupancy[$resourceId][$slotStartUtc] = $appointmentId;
    }

    public function addAppointment(ScheduledAppointment $appointment): void
    {
        $this->appointments[] = $appointment;
    }

    /** @return list<Resource> */
    public function resources(): array
    {
        return $this->resources;
    }

    /** @return list<AvailabilityWindow> */
    public function windowsFor(int $resourceId, int $isoWeekday, string $localDate): array
    {
        $windows = [];

        foreach ($this->windows[$resourceId] ?? [] as $window) {
            if ($window->weekday === $isoWeekday) {
                $windows[] = $window;
            }
        }

        return $windows;
    }

    /** @return array<string, int> */
    public function occupancyBetween(int $resourceId, string $fromUtc, string $toUtc): array
    {
        $between = [];

        foreach ($this->occupancy[$resourceId] ?? [] as $slot => $appointmentId) {
            if ($slot >= $fromUtc && $slot < $toUtc) {
                $between[$slot] = $appointmentId;
            }
        }

        return $between;
    }

    /** @return list<ScheduledAppointment> */
    public function appointmentsBetween(string $fromUtc, string $toUtc): array
    {
        $between = [];

        foreach ($this->appointments as $scheduled) {
            if ($scheduled->appointment->startsAtUtc < $toUtc && $scheduled->appointment->endsAtUtc > $fromUtc) {
                $between[] = $scheduled;
            }
        }

        return $between;
    }
}
