<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Booking;
use App\Domain\BookingRepository;
use PDO;

final class PdoBookingRepository implements BookingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(Booking $booking): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO bookings
                (reference, patient_name, patient_phone, service, scheduled_at, notes, status, created_at, updated_at)
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
}
