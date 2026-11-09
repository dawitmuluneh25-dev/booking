<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentRepository;
use App\Domain\AppointmentStatus;
use App\Domain\AppointmentTransitionException;
use App\Domain\RescheduleTarget;
use App\Domain\SlotUnavailableException;

/**
 * Appointment store that enforces the same occupancy rule as the primary key on
 * `resource_slots`: a resource holds a quantum once.
 *
 * `reschedule` acquires the whole new window before releasing anything, so a test
 * that fills the target slot sees the original booking survive - the same
 * behaviour the transaction in MySQL gives, reproduced without a database.
 */
final class InMemoryAppointmentRepository implements AppointmentRepository
{
    /** @var array<int, Appointment> */
    private array $appointments = [];

    /** @var array<string, int> "resource|slot" => appointment id */
    private array $occupancy = [];

    /** @var array<int, int> appointment id => primary resource id */
    private array $resourceOf = [];

    /** @var list<array<string, mixed>> */
    private array $reschedules = [];

    /** @var list<int> */
    private array $locks = [];

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
        $this->resourceOf[$stored->id] = $resourceId;

        foreach ($slotStarts as $slotStart) {
            $this->occupancy[$resourceId . '|' . $slotStart] = $stored->id;
        }

        return $stored;
    }

    public function lockResource(int $resourceId): void
    {
        if (!in_array($resourceId, $this->locks, true)) {
            $this->locks[] = $resourceId;
        }
    }

    public function reschedule(
        Appointment $appointment,
        RescheduleTarget $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): Appointment {
        $stored = $this->appointments[$appointment->id] ?? null;
        $previousResourceId = $this->resourceOf[$appointment->id] ?? null;

        if ($stored === null || $previousResourceId === null || $stored->version !== $appointment->version) {
            throw new AppointmentTransitionException(
                $appointment->status,
                $appointment->status,
                'The appointment changed while you were moving it. Reload and try again.',
            );
        }

        $held = [];
        foreach (array_keys($this->occupancy) as $key) {
            if ($this->occupancy[$key] === $stored->id) {
                $held[(string) $key] = true;
            }
        }

        // Acquire: check every new quantum before touching the old ones.
        foreach ($target->slotStarts as $slotStart) {
            $key = $target->resourceId . '|' . $slotStart;

            if (isset($this->occupancy[$key])) {
                if ($this->occupancy[$key] === $stored->id) {
                    continue;
                }

                throw new SlotUnavailableException($target->resourceId, $slotStart);
            }
        }

        foreach ($target->slotStarts as $slotStart) {
            $this->occupancy[$target->resourceId . '|' . $slotStart] = $stored->id;
        }

        // Release: only the rows the new window does not include.
        foreach (array_keys($held) as $key) {
            [$resource, $slot] = explode('|', $key, 2);
            $stillWanted = (int) $resource === $target->resourceId
                && in_array($slot, $target->slotStarts, true);

            if (!$stillWanted) {
                unset($this->occupancy[$key]);
            }
        }

        $moved = Appointment::fromRow([
            'id' => $stored->id,
            'reference' => $stored->reference,
            'referral_id' => $stored->referralId,
            'status' => $stored->status->value,
            'version' => $stored->version + 1,
            'starts_at' => $target->startsAtUtc,
            'ends_at' => $target->endsAtUtc,
            'duration_minutes' => $target->durationMinutes,
            'patient_name' => $stored->patientName,
            'patient_phone' => $stored->patientPhone,
            'created_by_user_id' => $stored->createdByUserId,
            'created_at' => $stored->createdAtUtc,
        ]);

        $this->appointments[$moved->id] = $moved;
        $this->resourceOf[$moved->id] = $target->resourceId;

        $this->reschedules[] = [
            'appointment_id' => $stored->id,
            'from_resource_id' => $previousResourceId,
            'to_resource_id' => $target->resourceId,
            'from_starts_at' => $stored->startsAtUtc,
            'to_starts_at' => $target->startsAtUtc,
            'actor_user_id' => $actor->userId,
            'reason' => $reason,
            'occurred_at' => $occurredAtUtc,
        ];

        return $moved;
    }

    public function occupiedStartsFor(int $appointmentId): array
    {
        $starts = [];

        foreach ($this->occupancy as $key => $owner) {
            if ($owner === $appointmentId) {
                [, $slot] = explode('|', (string) $key, 2);
                $starts[] = $slot;
            }
        }

        sort($starts);

        return $starts;
    }

    public function primaryResourceFor(int $appointmentId): ?int
    {
        return $this->resourceOf[$appointmentId] ?? null;
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

    /** @return list<array<string, mixed>> */
    public function reschedules(): array
    {
        return $this->reschedules;
    }

    /** @return list<int> */
    public function lockedResources(): array
    {
        return $this->locks;
    }

    public function count(): int
    {
        return count($this->appointments);
    }
}
