<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentRepository;
use App\Domain\AppointmentStatus;
use App\Domain\AppointmentTransitionException;
use App\Domain\SlotUnavailableException;

/**
 * Appointment store that enforces the same occupancy rule as the primary key on
 * `resource_slots`: a resource holds a quantum once.
 */
final class InMemoryAppointmentRepository implements AppointmentRepository
{
    /** @var array<int, Appointment> */
    private array $appointments = [];

    /** @var array<string, int> "resource|slot" => appointment id */
    private array $occupancy = [];

    public function book(
        Appointment $appointment,
        int $resourceId,
        array $slotStarts,
        Actor $actor,
    ): Appointment {
        foreach ($slotStarts as $slotStart) {
            $key = $resourceId . '|' . $slotStart;

            if (isset($this->occupancy[$key])) {
                throw new SlotUnavailableException($resourceId, $slotStart);
            }
        }

        $stored = $appointment->withIdentity(count($this->appointments) + 1);
        $this->appointments[$stored->id] = $stored;

        foreach ($slotStarts as $slotStart) {
            $this->occupancy[$resourceId . '|' . $slotStart] = $stored->id;
        }

        return $stored;
    }

    public function findByReference(string $reference): ?Appointment
    {
        foreach ($this->appointments as $appointment) {
            if ($appointment->reference === $reference) {
                return $appointment;
            }
        }

        return null;
    }

    public function findById(int $id): ?Appointment
    {
        return $this->appointments[$id] ?? null;
    }

    public function transition(
        Appointment $appointment,
        AppointmentStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): Appointment {
        $stored = $this->appointments[$appointment->id] ?? null;

        if ($stored === null || $stored->version !== $appointment->version) {
            throw new AppointmentTransitionException(
                $appointment->status,
                $target,
                'The appointment changed while you were working on it. Reload and try again.',
            );
        }

        if (!$stored->status->canTransitionTo($target)) {
            throw new AppointmentTransitionException($stored->status, $target);
        }

        $moved = Appointment::fromRow([
            'id' => $stored->id,
            'reference' => $stored->reference,
            'referral_id' => $stored->referralId,
            'status' => $target->value,
            'version' => $stored->version + 1,
            'starts_at' => $stored->startsAtUtc,
            'ends_at' => $stored->endsAtUtc,
            'duration_minutes' => $stored->durationMinutes,
            'patient_name' => $stored->patientName,
            'patient_phone' => $stored->patientPhone,
            'created_by_user_id' => $stored->createdByUserId,
            'created_at' => $stored->createdAtUtc,
        ]);

        $this->appointments[$moved->id] = $moved;

        if (!$target->holdsResources()) {
            foreach ($this->occupancy as $key => $appointmentId) {
                if ($appointmentId === $moved->id) {
                    unset($this->occupancy[$key]);
                }
            }
        }

        return $moved;
    }

    /** @return list<string> */
    public function occupiedSlots(int $resourceId): array
    {
        $slots = [];

        foreach (array_keys($this->occupancy) as $key) {
            [$resource, $slot] = explode('|', (string) $key, 2);

            if ((int) $resource === $resourceId) {
                $slots[] = $slot;
            }
        }

        sort($slots);

        return $slots;
    }

    public function count(): int
    {
        return count($this->appointments);
    }
}
