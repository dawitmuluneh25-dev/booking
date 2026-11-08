<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Booking;
use App\Domain\BookingRepository;
use App\Domain\BookingStatus;
use App\Domain\InvalidTransitionException;
use App\Domain\OutboundMessage;
use App\Domain\SlotUnavailableException;
use PDO;
use PDOException;
use Throwable;

final class PdoBookingRepository implements BookingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(Booking $booking, string $slotStartUtc, array $messages = []): void
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
                'status' => $booking->status->value,
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

            $this->enqueue($bookingId, $messages);

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

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : Booking::fromRow($row);
    }

    public function transition(
        Booking $booking,
        BookingStatus $target,
        string $actor,
        ?string $reason,
        array $messages = [],
    ): void {
        $this->pdo->beginTransaction();

        try {
            $update = $this->pdo->prepare(
                'UPDATE bookings
                 SET status = :target, updated_at = UTC_TIMESTAMP()
                 WHERE reference = :reference AND status = :expected'
            );
            $update->execute([
                'target' => $target->value,
                'reference' => $booking->reference,
                'expected' => $booking->status->value,
            ]);

            // 0 rows: someone else changed the status between read and write.
            if ($update->rowCount() !== 1) {
                throw new InvalidTransitionException($booking->status, $target);
            }

            $event = $this->pdo->prepare(
                'INSERT INTO booking_events (booking_id, from_status, to_status, actor, reason, created_at)
                 SELECT id, :from_status, :to_status, :actor, :reason, UTC_TIMESTAMP()
                 FROM bookings
                 WHERE reference = :reference'
            );
            $event->execute([
                'from_status' => $booking->status->value,
                'to_status' => $target->value,
                'actor' => $actor,
                'reason' => $reason,
                'reference' => $booking->reference,
            ]);

            if ($target === BookingStatus::Cancelled) {
                $release = $this->pdo->prepare(
                    'DELETE s FROM booking_slots AS s
                     JOIN bookings AS b ON b.id = s.booking_id
                     WHERE b.reference = :reference'
                );
                $release->execute(['reference' => $booking->reference]);
            }

            $this->enqueueForReference($booking->reference, $messages);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollback();

            throw $exception;
        }
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
        $statement->bindValue('cancelled', BookingStatus::Cancelled->value);
        $statement->bindValue('limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        $bookings = [];
        foreach ($rows as $row) {
            $bookings[] = Booking::fromRow($row);
        }

        return $bookings;
    }

    /**
     * @return array<string, Booking>
     */
    public function slotMap(string $fromUtc, string $toUtc): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.slot_start, b.reference, b.patient_name, b.patient_phone, b.service,
                    b.scheduled_at, b.notes, b.status, b.created_at
             FROM booking_slots AS s
             JOIN bookings AS b ON b.id = s.booking_id
             WHERE s.slot_start >= :from_utc AND s.slot_start < :to_utc
             ORDER BY s.slot_start'
        );
        $statement->execute(['from_utc' => $fromUtc, 'to_utc' => $toUtc]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $slot = self::scalarText($row, 'slot_start');
            $map[$slot] = Booking::fromRow($row);
        }

        return $map;
    }

    /** @param list<OutboundMessage> $messages */
    private function enqueue(int $bookingId, array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            "INSERT IGNORE INTO notification_outbox
                (booking_id, channel, template, recipient, payload, status, attempts,
                 available_at, created_at, updated_at)
             VALUES
                (:booking_id, :channel, :template, :recipient, :payload, 'pending', 0,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );

        foreach ($messages as $message) {
            $payload = json_encode($message->payload, JSON_UNESCAPED_SLASHES);

            $statement->execute([
                'booking_id' => $bookingId,
                'channel' => $message->channel->value,
                'template' => $message->template->value,
                'recipient' => $message->recipient,
                'payload' => $payload === false ? '{}' : $payload,
            ]);
        }
    }

    /** @param list<OutboundMessage> $messages */
    private function enqueueForReference(string $reference, array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $lookup = $this->pdo->prepare('SELECT id FROM bookings WHERE reference = :reference');
        $lookup->execute(['reference' => $reference]);
        $bookingId = (int) $lookup->fetchColumn();

        if ($bookingId > 0) {
            $this->enqueue($bookingId, $messages);
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @param array<string, mixed> $row */
    private static function scalarText(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }
}
