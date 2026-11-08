<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A booked slot for a patient.
 *
 * The appointment is created only from an approved referral, and it carries the
 * patient details it was booked with rather than reading them back through the
 * referral: a later correction to the referral must not silently rewrite the name
 * on an appointment that has already been confirmed to a family.
 */
final class Appointment
{
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly int $referralId,
        public readonly AppointmentStatus $status,
        public readonly int $version,
        public readonly string $startsAtUtc,
        public readonly string $endsAtUtc,
        public readonly int $durationMinutes,
        public readonly string $patientName,
        public readonly string $patientPhone,
        public readonly int $createdByUserId,
        public readonly string $createdAtUtc,
    ) {
    }

    public function withIdentity(int $id): self
    {
        return new self(
            id: $id,
            reference: $this->reference,
            referralId: $this->referralId,
            status: $this->status,
            version: $this->version,
            startsAtUtc: $this->startsAtUtc,
            endsAtUtc: $this->endsAtUtc,
            durationMinutes: $this->durationMinutes,
            patientName: $this->patientName,
            patientPhone: $this->patientPhone,
            createdByUserId: $this->createdByUserId,
            createdAtUtc: $this->createdAtUtc,
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            reference: is_scalar($row['reference'] ?? null) ? (string) $row['reference'] : '',
            referralId: is_numeric($row['referral_id'] ?? null) ? (int) $row['referral_id'] : 0,
            status: AppointmentStatus::from(
                is_scalar($row['status'] ?? null) ? (string) $row['status'] : 'scheduled',
            ),
            version: is_numeric($row['version'] ?? null) ? (int) $row['version'] : 1,
            startsAtUtc: is_scalar($row['starts_at'] ?? null) ? (string) $row['starts_at'] : '',
            endsAtUtc: is_scalar($row['ends_at'] ?? null) ? (string) $row['ends_at'] : '',
            durationMinutes: is_numeric($row['duration_minutes'] ?? null)
                ? (int) $row['duration_minutes']
                : 0,
            patientName: is_scalar($row['patient_name'] ?? null) ? (string) $row['patient_name'] : '',
            patientPhone: is_scalar($row['patient_phone'] ?? null) ? (string) $row['patient_phone'] : '',
            createdByUserId: is_numeric($row['created_by_user_id'] ?? null)
                ? (int) $row['created_by_user_id']
                : 0,
            createdAtUtc: is_scalar($row['created_at'] ?? null) ? (string) $row['created_at'] : '',
        );
    }
}
