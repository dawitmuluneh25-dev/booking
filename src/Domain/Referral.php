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
        /**
         * The clinical priority that stands, assigned by an authorized clinician.
         *
         * Null until a clinician assigns one, and null is the honest initial state:
         * §12.4 forbids defaulting missing information to a reassuring value, so no
         * "routine" is written on submission. `requestedUrgency` is a separate
         * field holding the submitter's own request, and nothing copies one to the
         * other (AC-010).
         */
        public readonly ?string $clinicalPriority = null,
        /**
         * The stated age, its unit and who stated it (`PAT-003`, §6.3).
         *
         * All three or none. The CHECK constraints in migration 0036 enforce the
         * same thing; this is the shape that makes an age without a unit, or an age
         * with nobody standing behind it, unrepresentable rather than merely
         * discouraged.
         */
        public readonly ?int $patientAgeValue = null,
        public readonly ?string $patientAgeUnit = null,
        public readonly ?string $patientAgeSource = null,
        /** `PAT-004`: a number that is unique only inside the facility that issued it. */
        public readonly ?string $patientIdentifier = null,
        public readonly ?string $patientIdentifierType = null,
        public readonly ?string $patientIdentifierFacility = null,
        /** `PAT-007`. */
        public readonly ?string $patientEmail = null,
        /** `PAT-008`. See the traceability note: no configured language list exists yet. */
        public readonly ?string $preferredLanguage = null,
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
            clinicalPriority: self::nullableText($row, 'clinical_priority'),
            patientAgeValue: is_numeric($row['patient_age_value'] ?? null)
                ? (int) $row['patient_age_value']
                : null,
            patientAgeUnit: self::nullableText($row, 'patient_age_unit'),
            patientAgeSource: self::nullableText($row, 'patient_age_source'),
            patientIdentifier: self::nullableText($row, 'patient_identifier'),
            patientIdentifierType: self::nullableText($row, 'patient_identifier_type'),
            patientIdentifierFacility: self::nullableText($row, 'patient_identifier_facility'),
            patientEmail: self::nullableText($row, 'patient_email'),
            preferredLanguage: self::nullableText($row, 'preferred_language'),
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

    /**
     * Attach the category a clinician assigned.
     *
     * There is no `withoutClinicalPriority()`: the approved model describes
     * assignment and reassignment by an authorized clinician, never erasure. A
     * value is replaced by the next assignment, and the decision rows keep both.
     */
    public function withClinicalPriority(string $clinicalPriority): self
    {
        return $this->copyWith(clinicalPriority: $clinicalPriority);
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

    /**
     * The assigned clinical category, or null when no clinician has assigned one.
     *
     * Null is not "routine". A referral nobody has reviewed has no clinical
     * priority, and a screen that prints "Routine" for it has replaced a missing
     * clinical judgement with a reassuring word (§12.4, AC-010).
     */
    public function clinicalPriorityLabel(): ?string
    {
        return $this->clinicalPriority === null ? null : Priority::labelFor($this->clinicalPriority);
    }

    /** Whether an authorized clinician has removed this case from the ordinary queue. */
    public function requiresDirectCoordination(): bool
    {
        return $this->clinicalPriority !== null
            && Priority::tryFrom($this->clinicalPriority)?->leavesTheOrdinaryQueue() === true;
    }

    private function copyWith(
        ?int $id = null,
        ?ReferralStatus $status = null,
        ?int $version = null,
        ?string $clinicalPriority = null,
    ): self {
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
            clinicalPriority: $clinicalPriority ?? $this->clinicalPriority,
            // These are part of the patient's identity, not of the transition being
            // applied. Every one of them has to be carried across, or a status
            // change would quietly delete an age, an identifier or a language —
            // and nothing would fail, because all of them are allowed to be absent.
            patientAgeValue: $this->patientAgeValue,
            patientAgeUnit: $this->patientAgeUnit,
            patientAgeSource: $this->patientAgeSource,
            patientIdentifier: $this->patientIdentifier,
            patientIdentifierType: $this->patientIdentifierType,
            patientIdentifierFacility: $this->patientIdentifierFacility,
            patientEmail: $this->patientEmail,
            preferredLanguage: $this->preferredLanguage,
        );
    }

    /** The stated age as one object, or null when no age was recorded. */
    public function patientAge(): ?PatientAge
    {
        $unit = $this->patientAgeUnit === null ? null : AgeUnit::tryFrom($this->patientAgeUnit);
        $source = $this->patientAgeSource === null ? null : AgeSource::tryFrom($this->patientAgeSource);

        if ($this->patientAgeValue === null || $unit === null || $source === null) {
            return null;
        }

        return new PatientAge($this->patientAgeValue, $unit, $source);
    }

    /** The identifier's type as one object, or null when no identifier was recorded. */
    public function identifierType(): ?PatientIdentifierType
    {
        return $this->patientIdentifierType === null
            ? null
            : PatientIdentifierType::tryFrom($this->patientIdentifierType);
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
