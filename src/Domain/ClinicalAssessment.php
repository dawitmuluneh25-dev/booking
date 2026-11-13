<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One recorded pre-procedure assessment (§6.5, §6.6).
 *
 * Every clinical field is nullable, and null means "nobody recorded it" — not
 * "normal", not "none", not "not applicable" (§6.2). The controlled statuses carry
 * their own absence so that a reader never has to interpret a blank, and this class
 * offers no method that turns a null into a negative. `standingLabel()` is the one
 * place that has to name the state, and it says "Not recorded".
 *
 * Immutable and append-only: an assessment is a judgement made at a time, and a
 * revision names the row it replaces so "the allergy was unknown before the
 * procedure" survives "it was confirmed afterwards".
 */
final class ClinicalAssessment
{
    public function __construct(
        public readonly int $id,
        public readonly int $referralId,
        public readonly ?AnticoagulantStatus $anticoagulant,
        public readonly ?string $anticoagulantDetails,
        public readonly ?AllergyStatus $allergy,
        public readonly ?string $allergyDetails,
        public readonly ?PregnancyStatus $pregnancy,
        public readonly ?string $pregnancyDetails,
        public readonly ?string $relevantHistory,
        public readonly ?string $priorProcedure,
        public readonly ?string $examinationContext,
        public readonly ?ImagingModality $imagingModality,
        public readonly ?string $imagingDate,
        public readonly ?string $imagingReference,
        public readonly int $recordedByUserId,
        public readonly string $recordedAtUtc,
        public readonly ?int $supersedesId = null,
        public readonly ?MedicationReviewStatus $medicationReview = null,
        /** @var list<Medication> */
        public readonly array $medications = [],
    ) {
    }

    public function withIdentity(int $id): self
    {
        return new self(
            id: $id,
            referralId: $this->referralId,
            anticoagulant: $this->anticoagulant,
            anticoagulantDetails: $this->anticoagulantDetails,
            allergy: $this->allergy,
            allergyDetails: $this->allergyDetails,
            pregnancy: $this->pregnancy,
            pregnancyDetails: $this->pregnancyDetails,
            relevantHistory: $this->relevantHistory,
            priorProcedure: $this->priorProcedure,
            examinationContext: $this->examinationContext,
            imagingModality: $this->imagingModality,
            imagingDate: $this->imagingDate,
            imagingReference: $this->imagingReference,
            recordedByUserId: $this->recordedByUserId,
            recordedAtUtc: $this->recordedAtUtc,
            supersedesId: $this->supersedesId,
            medicationReview: $this->medicationReview,
            medications: $this->medications,
        );
    }

    /**
     * The same assessment with its medication rows attached.
     *
     * Loading is deliberately separate from reading the row: the list lives in a
     * child table, and a caller that only needs the statuses (a history table, for
     * instance) should not pay for the rows.
     *
     * @param list<Medication> $medications
     */
    public function withMedications(array $medications): self
    {
        return new self(
            id: $this->id,
            referralId: $this->referralId,
            anticoagulant: $this->anticoagulant,
            anticoagulantDetails: $this->anticoagulantDetails,
            allergy: $this->allergy,
            allergyDetails: $this->allergyDetails,
            pregnancy: $this->pregnancy,
            pregnancyDetails: $this->pregnancyDetails,
            relevantHistory: $this->relevantHistory,
            priorProcedure: $this->priorProcedure,
            examinationContext: $this->examinationContext,
            imagingModality: $this->imagingModality,
            imagingDate: $this->imagingDate,
            imagingReference: $this->imagingReference,
            recordedByUserId: $this->recordedByUserId,
            recordedAtUtc: $this->recordedAtUtc,
            supersedesId: $this->supersedesId,
            medicationReview: $this->medicationReview,
            medications: $medications,
        );
    }
    /**
     * Whether anything at all has been established that a procedure could rely on.
     *
     * Both bleeding-relevant questions have to be answered — one of them unknown is
     * enough to make the assessment incomplete, which is a state worth showing rather
     * than hiding behind a green tick.
     */
    public function isCompleteForProcedure(): bool
    {
        return $this->allergy?->isEstablished() === true
            && $this->anticoagulant?->isEstablished() === true
            && $this->pregnancy?->isOpen() !== true
            && $this->medicationReview?->isReconciled() === true;
    }

    /** What the record says about a status, saying "not recorded" rather than a negative. */
    public static function describe(?\BackedEnum $status): string
    {
        return $status instanceof AnticoagulantStatus
            || $status instanceof AllergyStatus
            || $status instanceof PregnancyStatus
                ? (string) $status->label()
                : 'Not recorded';
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::intOf($row['id'] ?? null),
            referralId: self::intOf($row['referral_id'] ?? null),
            anticoagulant: AnticoagulantStatus::tryFrom(self::stringOf($row['anticoagulant_status'] ?? null)),
            anticoagulantDetails: self::nullable($row['anticoagulant_details'] ?? null),
            allergy: AllergyStatus::tryFrom(self::stringOf($row['allergy_status'] ?? null)),
            allergyDetails: self::nullable($row['allergy_details'] ?? null),
            pregnancy: PregnancyStatus::tryFrom(self::stringOf($row['pregnancy_status'] ?? null)),
            pregnancyDetails: self::nullable($row['pregnancy_details'] ?? null),
            relevantHistory: self::nullable($row['relevant_history'] ?? null),
            priorProcedure: self::nullable($row['prior_procedure'] ?? null),
            examinationContext: self::nullable($row['examination_context'] ?? null),
            imagingModality: ImagingModality::tryFrom(self::stringOf($row['imaging_modality'] ?? null)),
            imagingDate: self::nullable($row['imaging_date'] ?? null),
            imagingReference: self::nullable($row['imaging_reference'] ?? null),
            recordedByUserId: self::intOf($row['recorded_by_user_id'] ?? null),
            recordedAtUtc: self::stringOf($row['recorded_at'] ?? null),
            supersedesId: is_numeric($row['supersedes_id'] ?? null) ? (int) $row['supersedes_id'] : null,
            medicationReview: MedicationReviewStatus::tryFrom(self::stringOf($row['medication_review_status'] ?? null)),
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
