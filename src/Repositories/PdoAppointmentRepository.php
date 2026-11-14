<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Actor;
use App\Domain\Appointment;
use App\Domain\AppointmentRepository;
use App\Domain\AppointmentStatus;
use App\Domain\AppointmentTransitionException;
use App\Domain\ResourceNotFoundException;
use App\Domain\RescheduleTarget;
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
 * Two writers are kept apart before they reach that key by `lockResource()`:
 * every operation that changes occupancy locks the affected resource rows in
 * ascending id order, so a multi-quantum booking cannot half-insert and wait for
 * a row another transaction is holding.
 *
 * Everything happens inside the caller's unit of work, so a failure at any point
 * — an occupied quantum, an unknown appointment status, a stale version — leaves
 * neither the appointment nor a partial set of occupancy rows.
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
                $this->assertNotBlocked($resourceId, $appointment->startsAtUtc, $appointment->endsAtUtc);

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

                $this->insertSlots($id, $resourceId, $slotStarts, $appointment->createdAtUtc);

                return $appointment->withIdentity($id);
            },
        );
    }

    public function lockResource(int $resourceId): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM resources WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $resourceId]);

        if ($statement->fetchColumn() === false) {
            throw new ResourceNotFoundException($resourceId);
        }
    }

    public function occupiedStartsFor(int $appointmentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT slot_start FROM resource_slots WHERE appointment_id = :id ORDER BY slot_start'
        );
        $statement->execute(['id' => $appointmentId]);

        $starts = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row) && isset($row['slot_start']) && is_scalar($row['slot_start'])) {
                $starts[] = (string) $row['slot_start'];
            }
        }

        return $starts;
    }

    public function primaryResourceFor(int $appointmentId): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT resource_id FROM appointment_resources
              WHERE appointment_id = :id AND role = 'primary' LIMIT 1"
        );
        $statement->execute(['id' => $appointmentId]);

        $value = $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : null;
    }

    public function reschedule(
        Appointment $appointment,
        RescheduleTarget $target,
        Actor $actor,
        ?string $reason,
        string $occurredAtUtc,
    ): Appointment {
        $previousResourceId = $this->primaryResourceFor($appointment->id);

        if ($previousResourceId === null) {
            throw new AppointmentTransitionException(
                $appointment->status,
                $appointment->status,
                'This appointment has no resource recorded, so it cannot be moved.',
            );
        }

        return $this->transaction->run(function () use (
            $appointment,
            $target,
            $actor,
            $reason,
            $occurredAtUtc,
            $previousResourceId,
        ): Appointment {
            $lockOrder = array_values(array_unique([$previousResourceId, $target->resourceId]));
            sort($lockOrder);

            foreach ($lockOrder as $resourceId) {
                $this->lockResource($resourceId);
            }

            // Moving into a closure is the same mistake as booking into one, and
            // it arrives through the same door: a POST that names a time the
            // picker would not have offered.
            $this->assertNotBlocked($target->resourceId, $target->startsAtUtc, $target->endsAtUtc);

            $held = [];
            foreach ($this->occupiedStartsFor($appointment->id) as $start) {
                $held[$start] = true;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO resource_slots (resource_id, slot_start, appointment_id, created_at)
                 VALUES (:resource_id, :slot_start, :appointment_id, :created_at)'
            );

            // Acquire first: every quantum the new window needs is taken before a
            // single old one is given back.
            foreach ($target->slotStarts as $slotStart) {
                // A quantum this appointment already holds on the same resource
                // needs no second row; inserting it would collide with itself.
                if ($previousResourceId === $target->resourceId && isset($held[$slotStart])) {
                    continue;
                }

                try {
                    $insert->execute([
                        'resource_id' => $target->resourceId,
                        'slot_start' => $slotStart,
                        'appointment_id' => $appointment->id,
                        'created_at' => $occurredAtUtc,
                    ]);
                } catch (PDOException $exception) {
                    if ($this->isDuplicateKey($exception)) {
                        throw new SlotUnavailableException($target->resourceId, $slotStart);
                    }

                    throw $exception;
                }
            }

            $this->releaseSlotsNotIn($appointment->id, $target->resourceId, $target->slotStarts);

            $update = $this->pdo->prepare(
                'UPDATE appointments
                    SET starts_at = :starts_at,
                        ends_at = :ends_at,
                        duration_minutes = :duration,
                        version = version + 1,
                        updated_at = :updated_at
                  WHERE id = :id AND version = :version AND status = :expected'
            );
            $update->execute([
                'starts_at' => $target->startsAtUtc,
                'ends_at' => $target->endsAtUtc,
                'duration' => $target->durationMinutes,
                'updated_at' => $occurredAtUtc,
                'id' => $appointment->id,
                'version' => $appointment->version,
                'expected' => $appointment->status->value,
            ]);

            if ($update->rowCount() !== 1) {
                throw new AppointmentTransitionException(
                    $appointment->status,
                    $appointment->status,
                    'The appointment changed while you were moving it. Reload and try again.',
                );
            }

            if ($previousResourceId !== $target->resourceId) {
                $unlink = $this->pdo->prepare(
                    "DELETE FROM appointment_resources WHERE appointment_id = :id AND role = 'primary'"
                );
                $unlink->execute(['id' => $appointment->id]);

                $relink = $this->pdo->prepare(
                    "INSERT INTO appointment_resources (appointment_id, resource_id, role)
                     VALUES (:id, :resource_id, 'primary')"
                );
                $relink->execute(['id' => $appointment->id, 'resource_id' => $target->resourceId]);
            }

            $record = $this->pdo->prepare(
                'INSERT INTO appointment_reschedules
                    (appointment_id, from_resource_id, to_resource_id, from_starts_at, from_ends_at,
                     to_starts_at, to_ends_at, actor_user_id, actor_role, reason, occurred_at)
                 VALUES
                    (:appointment_id, :from_resource, :to_resource, :from_starts, :from_ends,
                     :to_starts, :to_ends, :actor_id, :actor_role, :reason, :occurred_at)'
            );
            $record->execute([
                'appointment_id' => $appointment->id,
                'from_resource' => $previousResourceId,
                'to_resource' => $target->resourceId,
                'from_starts' => $appointment->startsAtUtc,
                'from_ends' => $appointment->endsAtUtc,
                'to_starts' => $target->startsAtUtc,
                'to_ends' => $target->endsAtUtc,
                'actor_id' => $actor->userId,
                'actor_role' => $actor->role->value,
                'reason' => $reason,
                'occurred_at' => $occurredAtUtc,
            ]);

            return Appointment::fromRow([
                'id' => $appointment->id,
                'reference' => $appointment->reference,
                'referral_id' => $appointment->referralId,
                'status' => $appointment->status->value,
                'version' => $appointment->version + 1,
                'starts_at' => $target->startsAtUtc,
                'ends_at' => $target->endsAtUtc,
                'duration_minutes' => $target->durationMinutes,
                'patient_name' => $appointment->patientName,
                'patient_phone' => $appointment->patientPhone,
                'created_by_user_id' => $appointment->createdByUserId,
                'created_at' => $appointment->createdAtUtc,
            ]);
        });
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

    public function historyFor(int $appointmentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT h.from_status, h.to_status, h.actor_role, h.reason, h.occurred_at, u.name AS actor_name
               FROM appointment_status_history h
               LEFT JOIN users u ON u.id = h.actor_user_id
              WHERE h.appointment_id = :id
              ORDER BY h.occurred_at ASC, h.id ASC'
        );
        $statement->execute(['id' => $appointmentId]);

        $history = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $history[] = [
                'from' => is_scalar($row['from_status'] ?? null) ? (string) $row['from_status'] : null,
                'to' => is_scalar($row['to_status'] ?? null) ? (string) $row['to_status'] : '',
                'actorRole' => is_scalar($row['actor_role'] ?? null) ? (string) $row['actor_role'] : '',
                'actorName' => is_scalar($row['actor_name'] ?? null) ? (string) $row['actor_name'] : null,
                'reason' => is_scalar($row['reason'] ?? null) ? (string) $row['reason'] : null,
                'occurredAtUtc' => is_scalar($row['occurred_at'] ?? null) ? (string) $row['occurred_at'] : '',
            ];
        }

        return $history;
    }

    public function resourcesFor(int $appointmentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.name, r.modality, ar.role
               FROM appointment_resources ar
               JOIN resources r ON r.id = ar.resource_id
              WHERE ar.appointment_id = :id
              ORDER BY ar.role ASC, r.name ASC'
        );
        $statement->execute(['id' => $appointmentId]);

        $resources = [];

        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $resources[] = [
                'name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
                'modality' => is_scalar($row['modality'] ?? null) ? (string) $row['modality'] : null,
                'role' => is_scalar($row['role'] ?? null) ? (string) $row['role'] : 'primary',
            ];
        }

        return $resources;
    }

    public function forDay(string $fromUtc, string $toUtc): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, reference, referral_id, status, version, starts_at, ends_at,
                    duration_minutes, patient_name, patient_phone, created_by_user_id, created_at
               FROM appointments
              WHERE starts_at >= :from_date AND starts_at < :to_date
                AND status NOT IN ('cancelled', 'no_show')
              ORDER BY starts_at ASC, id ASC"
        );
        $statement->execute(['from_date' => $fromUtc, 'to_date' => $toUtc]);

        $appointments = [];

        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $appointments[] = Appointment::fromRow($row);
            }
        }

        return $appointments;
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

    /** @param list<string> $slotStarts */
    private function insertSlots(int $appointmentId, int $resourceId, array $slotStarts, string $createdAtUtc): void
    {
        $slot = $this->pdo->prepare(
            'INSERT INTO resource_slots (resource_id, slot_start, appointment_id, created_at)
             VALUES (:resource_id, :slot_start, :appointment_id, :created_at)'
        );

        foreach ($slotStarts as $slotStart) {
            try {
                $slot->execute([
                    'resource_id' => $resourceId,
                    'slot_start' => $slotStart,
                    'appointment_id' => $appointmentId,
                    'created_at' => $createdAtUtc,
                ]);
            } catch (PDOException $exception) {
                if ($this->isDuplicateKey($exception)) {
                    throw new SlotUnavailableException($resourceId, $slotStart);
                }

                throw $exception;
            }
        }
    }

    /** @param list<string> $keep */
    private function releaseSlotsNotIn(int $appointmentId, int $resourceId, array $keep): void
    {
        $parameters = ['id' => $appointmentId, 'resource_id' => $resourceId];
        $placeholders = [];

        foreach ($keep as $index => $slotStart) {
            $placeholders[] = ':keep' . $index;
            $parameters['keep' . $index] = $slotStart;
        }

        // Every row this appointment owns that the new window does not include,
        // plus anything left on a resource it is moving away from.
        $release = $this->pdo->prepare(
            'DELETE FROM resource_slots
              WHERE appointment_id = :id
                AND (resource_id <> :resource_id OR slot_start NOT IN (' . implode(',', $placeholders) . '))'
        );
        $release->execute($parameters);
    }

    /**
     * Whether the driver refused a write because the row already exists.
     *
     * Deliberately not "SQLSTATE 23000": that class covers every integrity
     * violation, including a foreign key pointing at nothing. Reporting a broken
     * foreign key to a scheduler as "that time was just taken" would hide a real
     * fault behind a plausible message. MySQL's duplicate-entry code is 1062.
     */
    private function isDuplicateKey(PDOException $exception): bool
    {
        return ($exception->errorInfo[0] ?? '') === '23000'
            && (string) ($exception->errorInfo[1] ?? '') === '1062';
    }

    /**
     * Refuse a window that is closed, whatever the caller believes.
     *
     * The picker already hides these windows, so reaching this with a valid window
     * means a hand-crafted POST or a closure entered between the form being drawn
     * and submitted. Both deserve the same answer: not bookable.
     */
    private function assertNotBlocked(int $resourceId, string $startsAtUtc, string $endsAtUtc): void
    {
        $statement = $this->pdo->prepare(
            'SELECT reason FROM resource_blocks
              WHERE resource_id = :resource_id
                AND starts_at < :ends_at
                AND ends_at > :starts_at
              ORDER BY starts_at ASC
              LIMIT 1'
        );
        $statement->execute([
            'resource_id' => $resourceId,
            'starts_at' => $startsAtUtc,
            'ends_at' => $endsAtUtc,
        ]);

        if ($statement->fetchColumn() !== false) {
            throw new SlotUnavailableException($resourceId, $startsAtUtc);
        }
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
