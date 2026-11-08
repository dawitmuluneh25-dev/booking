<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentRepository;
use App\Domain\AppointmentStatus;
use App\Domain\AppointmentTransitionException;
use App\Domain\ResourceNotFoundException;
use App\Domain\SlotUnavailableException;
use App\Domain\Transaction;
use PDO;
use PDOException;

/**
 * MySQL implementation of the scheduling boundary.
 *
 * The occupancy rows are the guard, not a mirror of one. `resource_slots` has
 * `(resource_id, slot_start)` as its primary key, so the second booking of a
 * quantum fails on the key. A "SELECT free slots then INSERT" would leave a
 * window between the two where two schedulers both see the slot free; this does
 * not, whatever the application layer believes.
 *
 * Everything happens inside the caller's unit of work, so a failure at any point
 * — an occupied quantum, an unknown appointment status, a stale referral version
 * — leaves neither the appointment nor a partial set of occupancy rows.
 */
final class PdoAppointmentRepository implements AppointmentRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Transaction $transaction,
    ) {
    }

    public function book(
        Appointment $appointment,
        int $resourceId,
        array $slotStarts,
        Actor $actor,
    ): Appointment {
        return $this->transaction->run(
            function () use ($appointment, $resourceId, $slotStarts): Appointment {
                $this->assertResourceExists($resourceId);

                $insert = $this->pdo->prepare(
                    'INSERT INTO appointments
                        (reference, referral_id, status, version, starts_at, ends_at,
                         duration_minutes, patient_name, patient_phone, created_by_user_id,
                         created_at, updated_at)
                     VALUES
                        (:reference, :referral_id, :status, 1, :starts_at, :ends_at,
                         :duration, :patient_name, :patient_phone, :created_by,
                         :created_at, :updated_at)'
                );
                $insert->execute([
                    'reference' => $appointment->reference,
                    'referral_id' => $appointment->referralId,
                    'status' => $appointment->status->value,
                    'starts_at' => $appointment->startsAtUtc,
                    'ends_at' => $appointment->endsAtUtc,
                    'duration' => $appointment->durationMinutes,
                    'patient_name' => $appointment->patientName,
                    'patient_phone' => $appointment->patientPhone,
                    'created_by' => $appointment->createdByUserId,
                    'created_at' => $appointment->createdAtUtc,
                    'updated_at' => $appointment->createdAtUtc,
                ]);

                $id = (int) $this->pdo->lastInsertId();

                $link = $this->pdo->prepare(
                    'INSERT INTO appointment_resources (appointment_id, resource_id, role)
                     VALUES (:appointment_id, :resource_id, :role)'
                );
                $link->execute([
                    'appointment_id' => $id,
                    'resource_id' => $resourceId,
                    'role' => 'primary',
                ]);

                $slot = $this->pdo->prepare(
                    'INSERT INTO resource_slots (resource_id, slot_start, appointment_id, created_at)
                     VALUES (:resource_id, :slot_start, :appointment_id, :created_at)'
                );

                foreach ($slotStarts as $slotStart) {
                    try {
                        $slot->execute([
                            'resource_id' => $resourceId,
                            'slot_start' => $slotStart,
                            'appointment_id' => $id,
                            'created_at' => $appointment->createdAtUtc,
                        ]);
                    } catch (PDOException $exception) {
                        if (($exception->errorInfo[0] ?? '') === '23000') {
                            throw new SlotUnavailableException($resourceId, $slotStart);
                        }

                        throw $exception;
                    }
                }

                return $appointment->withIdentity($id);
            },
        );
    }

    public function findByReference(string $reference): ?Appointment
    {
        $statement = $this->pdo->prepare(
            'SELECT id, reference, referral_id, status, version, starts_at, ends_at,
                    duration_minutes, patient_name, patient_phone, created_by_user_id, created_at
               FROM appointments WHERE reference = :reference LIMIT 1'
        );
        $statement->execute(['reference' => $reference]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : Appointment::fromRow($row);
    }

    public function findById(int $id): ?Appointment
    {
        $statement = $this->pdo->prepare(
            'SELECT id, reference, referral_id, status, version, starts_at, ends_at,
                    duration_minutes, patient_name, patient_phone, created_by_user_id, created_at
               FROM appointments WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : Appointment::fromRow($row);
    }

    public function transition(
        Appointment $appointment,
        AppointmentStatus $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): Appointment {
        if (!$appointment->status->canTransitionTo($target)) {
            throw new AppointmentTransitionException($appointment->status, $target);
        }

        // A cancellation or a no-show must give the room back, not keep it. The
        // occupancy rows are deleted in the same transaction as the status change,
        // which is why this cannot be a plain column update.
        $releasesSlots = !$target->holdsResources();

        return $this->transaction->run(
            function () use ($appointment, $target, $actor, $reason, $occurredAtUtc, $releasesSlots): Appointment {
                $update = $this->pdo->prepare(
                    'UPDATE appointments
                        SET status = :target, version = version + 1, updated_at = :updated_at
                      WHERE id = :id AND version = :version AND status = :expected'
                );
                $update->execute([
                    'target' => $target->value,
                    'updated_at' => $occurredAtUtc,
                    'id' => $appointment->id,
                    'version' => $appointment->version,
                    'expected' => $appointment->status->value,
                ]);

                if ($update->rowCount() !== 1) {
                    throw new AppointmentTransitionException(
                        $appointment->status,
                        $target,
                        'The appointment changed while you were working on it. Reload and try again.',
                    );
                }

                if ($releasesSlots) {
                    $release = $this->pdo->prepare(
                        'DELETE FROM resource_slots WHERE appointment_id = :id'
                    );
                    $release->execute(['id' => $appointment->id]);
                }

                $history = $this->pdo->prepare(
                    'INSERT INTO appointment_status_history
                        (appointment_id, from_status, to_status, actor_user_id, actor_role, reason, occurred_at)
                     VALUES (:appointment_id, :from_status, :to_status, :actor_id, :actor_role, :reason, :occurred_at)'
                );
                $history->execute([
                    'appointment_id' => $appointment->id,
                    'from_status' => $appointment->status->value,
                    'to_status' => $target->value,
                    'actor_id' => $actor->userId,
                    'actor_role' => $actor->role->value,
                    'reason' => $reason,
                    'occurred_at' => $occurredAtUtc,
                ]);

                return Appointment::fromRow([
                    'id' => $appointment->id,
                    'reference' => $appointment->reference,
                    'referral_id' => $appointment->referralId,
                    'status' => $target->value,
                    'version' => $appointment->version + 1,
                    'starts_at' => $appointment->startsAtUtc,
                    'ends_at' => $appointment->endsAtUtc,
                    'duration_minutes' => $appointment->durationMinutes,
                    'patient_name' => $appointment->patientName,
                    'patient_phone' => $appointment->patientPhone,
                    'created_by_user_id' => $appointment->createdByUserId,
                    'created_at' => $appointment->createdAtUtc,
                ]);
            },
        );
    }

    private function assertResourceExists(int $resourceId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM resources WHERE id = :id AND kind IN ('suite', 'room')"
        );
        $statement->execute(['id' => $resourceId]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new ResourceNotFoundException($resourceId);
        }
    }
}
