<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * An urgent intussusception case.
 *
 * Two status axes, neither of which implies the other: `urgency` is the clinical
 * question, `coordination` is whether anybody has reached the family or the
 * receiving team. A case can be accepted clinically while still unacknowledged,
 * and acknowledged while still under review.
 *
 * This object is not an appointment and carries no time or resource (WF-04): the
 * urgent pathway coordinates a place in another hospital, it does not book a slot
 * in this clinic's schedule.
 */
final class UrgentCase
{
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly UrgentStatus $urgency,
        public readonly UrgentCoordination $coordination,
        public readonly int $version,
        public readonly string $procedureCode,
        public readonly string $patientName,
        public readonly ?string $patientDob,
        public readonly ?int $patientAgeMonths,
        public readonly ?string $guardianName,
        public readonly string $guardianPhone,
        public readonly ?string $guardianRelationship,
        public readonly ?int $referringUserId,
        public readonly ?string $referringPhysicianName,
        public readonly ?string $referringFacility,
        public readonly ?string $referringPhysicianPhone,
        public readonly ?string $receivingHospitalName,
        public readonly string $clinicalSummary,
        public readonly ?string $imagingSummary,
        public readonly string $receivedAtUtc,
        public readonly string $createdAtUtc,
    ) {
    }

    public function withIdentity(int $id): self
    {
        return $this->copy(['id' => $id]);
    }

    public function withUrgency(UrgentStatus $status, int $version): self
    {
        return $this->copy(['urgency' => $status, 'version' => $version]);
    }

    public function withCoordination(UrgentCoordination $status, int $version): self
    {
        return $this->copy(['coordination' => $status, 'version' => $version]);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::intOf($row['id'] ?? null),
            reference: self::stringOf($row['reference'] ?? null),
            urgency: UrgentStatus::from(self::stringOf($row['urgency_status'] ?? null)),
            coordination: UrgentCoordination::from(self::stringOf($row['coordination_status'] ?? null)),
            version: self::intOf($row['version'] ?? null),
            procedureCode: self::stringOf($row['procedure_code'] ?? null),
            patientName: self::stringOf($row['patient_name'] ?? null),
            patientDob: self::nullable($row['patient_dob'] ?? null),
            patientAgeMonths: is_numeric($row['patient_age_months'] ?? null)
                ? (int) $row['patient_age_months']
                : null,
            guardianName: self::nullable($row['guardian_name'] ?? null),
            guardianPhone: self::stringOf($row['guardian_phone'] ?? null),
            guardianRelationship: self::nullable($row['guardian_relationship'] ?? null),
            referringUserId: is_numeric($row['referring_user_id'] ?? null)
                ? (int) $row['referring_user_id']
                : null,
            referringPhysicianName: self::nullable($row['referring_physician_name'] ?? null),
            referringFacility: self::nullable($row['referring_facility'] ?? null),
            referringPhysicianPhone: self::nullable($row['referring_physician_phone'] ?? null),
            receivingHospitalName: self::nullable($row['receiving_hospital_name'] ?? null),
            clinicalSummary: self::stringOf($row['clinical_summary'] ?? null),
            imagingSummary: self::nullable($row['imaging_summary'] ?? null),
            receivedAtUtc: self::stringOf($row['received_at'] ?? null),
            createdAtUtc: self::stringOf($row['created_at'] ?? null),
        );
    }

    /**
     * @param array{id?: int, urgency?: UrgentStatus, coordination?: UrgentCoordination, version?: int} $changes
     */
    private function copy(array $changes): self
    {
        return new self(
            id: $changes['id'] ?? $this->id,
            reference: $this->reference,
            urgency: $changes['urgency'] ?? $this->urgency,
            coordination: $changes['coordination'] ?? $this->coordination,
            version: $changes['version'] ?? $this->version,
            procedureCode: $this->procedureCode,
            patientName: $this->patientName,
            patientDob: $this->patientDob,
            patientAgeMonths: $this->patientAgeMonths,
            guardianName: $this->guardianName,
            guardianPhone: $this->guardianPhone,
            guardianRelationship: $this->guardianRelationship,
            referringUserId: $this->referringUserId,
            referringPhysicianName: $this->referringPhysicianName,
            referringFacility: $this->referringFacility,
            referringPhysicianPhone: $this->referringPhysicianPhone,
            receivingHospitalName: $this->receivingHospitalName,
            clinicalSummary: $this->clinicalSummary,
            imagingSummary: $this->imagingSummary,
            receivedAtUtc: $this->receivedAtUtc,
            createdAtUtc: $this->createdAtUtc,
        );
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullable(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
