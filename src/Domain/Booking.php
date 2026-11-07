<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A booking request as stored. Times are UTC in persistence and rendered in
 * the application timezone at the edges.
 */
final class Booking
{
    public function __construct(
        public readonly string $reference,
        public readonly string $patientName,
        public readonly string $patientPhone,
        public readonly string $service,
        public readonly string $scheduledAtUtc,
        public readonly ?string $notes,
        public readonly string $status,
        public readonly string $createdAtUtc,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['reference'],
            (string) $row['patient_name'],
            (string) $row['patient_phone'],
            (string) $row['service'],
            (string) $row['scheduled_at'],
            isset($row['notes']) ? (string) $row['notes'] : null,
            (string) $row['status'],
            (string) $row['created_at'],
        );
    }

    public function scheduledAt(string $timezone): DateTimeImmutable
    {
        return (new DateTimeImmutable($this->scheduledAtUtc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($timezone));
    }

    public function serviceLabel(): string
    {
        return BookingService::SERVICES[$this->service] ?? $this->service;
    }
}
