<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Appointment;
use App\Domain\AvailabilityWindow;
use App\Domain\Resource;
use App\Domain\ScheduledAppointment;
use App\Domain\ScheduleRepository;
use PDO;

/**
 * Read-only schedule queries.
 *
 * `appointmentsBetween` joins through `appointment_resources` rather than reading
 * a resource id off the appointment, because the appointment does not have one:
 * the link table is what records who and what is committed. Filtering on the
 * statuses that still hold a room is not an optimisation - a cancelled
 * appointment has already lost its occupancy rows, and showing it on the board as
 * if it still blocked the room would be a lie to the scheduler.
 */
final class PdoScheduleRepository implements ScheduleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Resource> */
    public function resources(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, code, name, kind, modality, is_active
               FROM resources
              WHERE is_active = 1 AND kind IN ('suite', 'room')
              ORDER BY id"
        );

        if ($statement === false) {
            return [];
        }

        $resources = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $resources[] = Resource::fromRow($row);
            }
        }

        return $resources;
    }

    /** @return list<AvailabilityWindow> */
    public function windowsFor(int $resourceId, int $isoWeekday, string $localDate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT weekday, opens_at, closes_at
               FROM resource_availability
              WHERE resource_id = :resource_id
                AND weekday = :weekday
                AND (valid_from IS NULL OR valid_from <= :valid_on)
                AND (valid_to IS NULL OR valid_to >= :valid_until)
              ORDER BY opens_at'
        );
        $statement->execute([
            'resource_id' => $resourceId,
            'weekday' => $isoWeekday,
            'valid_on' => $localDate,
            'valid_until' => $localDate,
        ]);

        $windows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $window = AvailabilityWindow::fromRow($row);

            if ($window !== null) {
                $windows[] = $window;
            }
        }

        return $windows;
    }

    /** @return array<string, int> */
    public function occupancyBetween(int $resourceId, string $fromUtc, string $toUtc): array
    {
        $statement = $this->pdo->prepare(
            'SELECT slot_start, appointment_id
               FROM resource_slots
              WHERE resource_id = :resource_id
                AND slot_start >= :from_utc
                AND slot_start < :to_utc'
        );
        $statement->execute(['resource_id' => $resourceId, 'from_utc' => $fromUtc, 'to_utc' => $toUtc]);

        $occupied = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row) || !is_scalar($row['slot_start'] ?? null)) {
                continue;
            }

            $occupied[(string) $row['slot_start']] = is_numeric($row['appointment_id'] ?? null)
                ? (int) $row['appointment_id']
                : 0;
        }

        return $occupied;
    }

    /** @return list<ScheduledAppointment> */
    public function appointmentsBetween(string $fromUtc, string $toUtc): array
    {
        $statement = $this->pdo->prepare(
            "SELECT a.id, a.reference, a.referral_id, a.status, a.version, a.starts_at, a.ends_at,
                    a.duration_minutes, a.patient_name, a.patient_phone, a.created_by_user_id,
                    a.created_at, ar.resource_id
               FROM appointments a
               JOIN appointment_resources ar
                 ON ar.appointment_id = a.id AND ar.role = 'primary'
              WHERE a.status NOT IN ('cancelled', 'no_show')
                AND a.starts_at < :to_utc
                AND a.ends_at > :from_utc
              ORDER BY a.starts_at, ar.resource_id"
        );
        $statement->execute(['from_utc' => $fromUtc, 'to_utc' => $toUtc]);

        $scheduled = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $scheduled[] = new ScheduledAppointment(
                Appointment::fromRow($row),
                is_numeric($row['resource_id'] ?? null) ? (int) $row['resource_id'] : 0,
            );
        }

        return $scheduled;
    }
}
