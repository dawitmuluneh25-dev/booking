<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Booking;
use App\Domain\BookingRepository;
use App\Domain\SlotUnavailableException;
use PDO;
use PDOException;
use Throwable;

final class PdoBookingRepository implements BookingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(Booking $booking, string $slotStartUtc): void
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO bookings
                    (reference, patient_name, patient_phone, service, scheduled_at,
                     notes, status, created_at, updated_at)
                 VALUES
                    (:reference, :patient_name, :patient_phone, :service,
                     :scheduled_at, :notes, :status, :created_at, :updated_at)'
            );

            $statement->execute([
                'reference' => $booking->reference,
                'patient_name' => $booking->patientName,
                'patient_phone' => $booking->patientPhone,
                'service' => $booking->service,
                'scheduled_at' => $booking->scheduledAtUtc,
                'notes' => $booking->notes,
                'status' => $booking->status,
                'created_at' => $booking->createdAtUtc,
                'updated_at' => $booking->createdAtUtc,
            ]);

            $bookingId = (int) $this->pdo->lastInsertId();

            $slot = $this->pdo->prepare(
                'INSERT INTO booking_slots (slot_start, booking_id, created_at)
                 VALUES (:slot_start, :booking_id, :created_at)'
            );
            $slot->execute([
                'slot_start' => $slotStartUtc,
                'booking_id' => $bookingId,
                'created_at' => $booking->createdAtUtc,
            ]);

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->rollback();

            // 1062 = duplicate entry: the slot primary key rejected the claim.
            if (($exception->errorInfo[1] ?? null) === 1062) {
                throw new SlotUnavailableException();
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->rollback();

            throw $exception;
        }
    }

    public function findByReference(string $reference): ?Booking
    {
        $statement = $this->pdo->prepare(
            'SELECT reference, patient_name, patient_phone, service, scheduled_at, notes, status, created_at
             FROM bookings
             WHERE reference = :reference
             LIMIT 1'
        );
        $statement->execute(['reference' => $reference]);

        $row = $statement->fetch();

        return is_array($row) ? Booking::fromRow($row) : null;
    }

    /** @return list<Booking> */
    public function upcoming(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT reference, patient_name, patient_phone, service, scheduled_at, notes,
                    status, created_at
             FROM bookings
             WHERE scheduled_at >= UTC_TIMESTAMP() AND status <> :cancelled
             ORDER BY scheduled_at ASC
             LIMIT :limit'
        );
        $statement->bindValue('cancelled', 'cancelled');
        $statement->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();

        $bookings = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $bookings[] = Booking::fromRow($row);
            }
        }

        return $bookings;
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
