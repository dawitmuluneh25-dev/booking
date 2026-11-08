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
        public readonly BookingStatus $status,
        public readonly string $createdAtUtc,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            reference: self::text($row, 'reference'),
            patientName: self::text($row, 'patient_name'),
            patientPhone: self::text($row, 'patient_phone'),
            service: self::text($row, 'service'),
            scheduledAtUtc: self::text($row, 'scheduled_at'),
            notes: self::nullableText($row, 'notes'),
            status: BookingStatus::from(self::text($row, 'status')),
            createdAtUtc: self::text($row, 'created_at'),
        );
    }

    public function withStatus(BookingStatus $status): self
    {
        return new self(
            reference: $this->reference,
            patientName: $this->patientName,
            patientPhone: $this->patientPhone,
            service: $this->service,
            scheduledAtUtc: $this->scheduledAtUtc,
            notes: $this->notes,
            status: $status,
            createdAtUtc: $this->createdAtUtc,
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

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }

    /** @param array<string, mixed> $row */
    private static function nullableText(array $row, string $key): ?string
    {
        if (!isset($row[$key])) {
            return null;
        }

        $value = self::text($row, $key);

        return $value === '' ? null : $value;
    }
}
