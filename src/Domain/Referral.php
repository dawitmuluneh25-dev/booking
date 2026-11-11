<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * An elective referral as stored.
 *
 * A referral is a clinical request, not an appointment: this object has no time,
 * no resource and no room, because those belong to `Appointment` (WF-01, WF-15).
 *
 * `version` is the optimistic concurrency token. A command reads the referral,
 * decides, then asks the repository to apply the change only if the version is
 * still the one it read, so two coordinators clicking at the same moment cannot
 * both win.
 */
final class Referral
{
    public function __construct(
        public readonly int $id,
        public readonly string $reference,
        public readonly ReferralStatus $status,
        public readonly int $version,
        public readonly string $patientName,
        public readonly string $patientPhone,
        public readonly ?string $patientDob,
        public readonly ?string $guardianName,
        public readonly ?string $guardianPhone,
        public readonly ?string $guardianRelationship,
        public readonly string $procedureCode,
        public readonly string $requestedUrgency,
        public readonly ?string $clinicalSummary,
        public readonly ?string $targetSite,
        public readonly ?string $referringPhysicianName,
        public readonly ?string $referringFacility,
        public readonly ?string $referringPhysicianPhone,
        public readonly int $submittedByUserId,
        public readonly string $submittedOnBehalfOf,
        public readonly string $createdAtUtc,
        public readonly ?int $referringPhysicianProfileId = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::number($row, 'id'),
            reference: self::text($row, 'reference'),
            status: ReferralStatus::from(self::text($row, 'status')),
            version: self::number($row, 'version'),
            patientName: self::text($row, 'patient_name'),
            patientPhone: self::text($row, 'patient_phone'),
            patientDob: self::nullableText($row, 'patient_dob'),
            guardianName: self::nullableText($row, 'guardian_name'),
            guardianPhone: self::nullableText($row, 'guardian_phone'),
            guardianRelationship: self::nullableText($row, 'guardian_relationship'),
            procedureCode: self::text($row, 'procedure_code'),
            requestedUrgency: self::text($row, 'requested_urgency'),
            clinicalSummary: self::nullableText($row, 'clinical_summary'),
            targetSite: self::nullableText($row, 'target_site'),
            referringPhysicianName: self::nullableText($row, 'referring_physician_name'),
            referringFacility: self::nullableText($row, 'referring_facility'),
            referringPhysicianPhone: self::nullableText($row, 'referring_physician_phone'),
            submittedByUserId: self::number($row, 'submitted_by_user_id'),
            submittedOnBehalfOf: self::text($row, 'submitted_on_behalf_of'),
            createdAtUtc: self::text($row, 'created_at'),
            referringPhysicianProfileId: is_numeric($row['referring_physician_profile_id'] ?? null)
                ? (int) $row['referring_physician_profile_id']
                : null,
        );
    }

    /** Attach the identity the database assigned on insert. */
    public function withIdentity(int $id): self
    {
        return $this->copyWith(id: $id);
    }

    public function withStatus(ReferralStatus $status, int $version): self
    {
        return $this->copyWith(status: $status, version: $version);
    }

    /** @return list<string> */
    public function missingForClinicalReview(): array
    {
        $missing = [];

        if ($this->clinicalSummary === null) {
            $missing[] = 'clinical summary';
        }

        if ($this->targetSite === null) {
            $missing[] = 'target site';
        }

        return $missing;
    }

    /** "Self" only when the patient submitted for themselves. */
    public function isSelfSubmitted(): bool
    {
        return $this->submittedOnBehalfOf === 'self';
    }

    /**
     * The requested category as the approved model names it.
     *
     * `ucfirst()` used to do this job, which turned the stored token into a word
     * but could not know that "urgent direct coordination" is one category and
     * not a capitalised string (§13.1, A-08).
     */
    public function requestedUrgencyLabel(): string
    {
        return Priority::labelFor($this->requestedUrgency);
    }

    private function copyWith(?int $id = null, ?ReferralStatus $status = null, ?int $version = null): self
    {
        return new self(
            id: $id ?? $this->id,
            reference: $this->reference,
            status: $status ?? $this->status,
            version: $version ?? $this->version,
            patientName: $this->patientName,
            patientPhone: $this->patientPhone,
            patientDob: $this->patientDob,
            guardianName: $this->guardianName,
            guardianPhone: $this->guardianPhone,
            guardianRelationship: $this->guardianRelationship,
            procedureCode: $this->procedureCode,
            requestedUrgency: $this->requestedUrgency,
            clinicalSummary: $this->clinicalSummary,
            targetSite: $this->targetSite,
            referringPhysicianName: $this->referringPhysicianName,
            referringFacility: $this->referringFacility,
            referringPhysicianPhone: $this->referringPhysicianPhone,
            submittedByUserId: $this->submittedByUserId,
            submittedOnBehalfOf: $this->submittedOnBehalfOf,
            createdAtUtc: $this->createdAtUtc,
            referringPhysicianProfileId: $this->referringPhysicianProfileId,
        );
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
        $value = self::text($row, $key);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $row */
    private static function number(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
