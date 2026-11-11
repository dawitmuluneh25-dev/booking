<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * An immutable clinical decision.
 *
 * Nothing updates this row. A change of mind is a new decision that names the one
 * it replaces, and the database allows a decision to be superseded once, so the
 * chain is linear rather than a tree of contradictory rows (WF-09).
 *
 * The referral's current decision is the row that nothing supersedes; a reader
 * who wants "what did we decide" must ask for that, not for the newest row by
 * timestamp.
 */
final class ClinicalDecision
{
    public function __construct(
        public readonly int $id,
        public readonly int $referralId,
        public readonly ClinicalDecisionType $type,
        public readonly string $rationale,
        public readonly ?string $technique,
        public readonly ?string $modality,
        public readonly ?string $sedation,
        public readonly ?int $estimatedDurationMinutes,
        public readonly ?string $preprocedureConditions,
        public readonly ?string $redirectDestination,
        public readonly ?string $informationRequested,
        public readonly int $decidedByUserId,
        public readonly string $decidedAtUtc,
        public readonly ?int $supersedesId,
        /**
         * The category this decision assigned, if it assigned one.
         *
         * Kept on the decision rather than inferred from the referral, because the
         * referral only knows what stands now. §12.1 asks the review record to
         * carry the priority it assigned; a reader asking "was this expedited when
         * it was approved" gets the answer from the row that approved it.
         */
        public readonly ?string $clinicalPriority = null,
    ) {
    }

    public function withIdentity(int $id): self
    {
        return new self(
            id: $id,
            referralId: $this->referralId,
            type: $this->type,
            rationale: $this->rationale,
            technique: $this->technique,
            modality: $this->modality,
            sedation: $this->sedation,
            estimatedDurationMinutes: $this->estimatedDurationMinutes,
            preprocedureConditions: $this->preprocedureConditions,
            redirectDestination: $this->redirectDestination,
            informationRequested: $this->informationRequested,
            decidedByUserId: $this->decidedByUserId,
            decidedAtUtc: $this->decidedAtUtc,
            supersedesId: $this->supersedesId,
            clinicalPriority: $this->clinicalPriority,
        );
    }

    /**
     * The assigned category as the approved model names it.
     *
     * Null means this outcome assigned none — a decline or a request for more
     * information does not release anything into a queue, so it has no priority to
     * state. That is different from an unknown string, which `labelFor()` returns
     * verbatim so a legacy row still renders.
     */
    public function clinicalPriorityLabel(): ?string
    {
        return $this->clinicalPriority === null ? null : Priority::labelFor($this->clinicalPriority);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::intOf($row['id'] ?? null),
            referralId: self::intOf($row['referral_id'] ?? null),
            type: ClinicalDecisionType::from(self::stringOf($row['decision_type'] ?? null)),
            rationale: self::stringOf($row['rationale'] ?? null),
            technique: self::nullable($row['approved_technique'] ?? null),
            modality: self::nullable($row['modality'] ?? null),
            sedation: self::nullable($row['sedation'] ?? null),
            estimatedDurationMinutes: is_numeric($row['estimated_duration_minutes'] ?? null)
                ? (int) $row['estimated_duration_minutes']
                : null,
            preprocedureConditions: self::nullable($row['preprocedure_conditions'] ?? null),
            redirectDestination: self::nullable($row['redirect_destination'] ?? null),
            informationRequested: self::nullable($row['information_requested'] ?? null),
            decidedByUserId: self::intOf($row['decided_by_user_id'] ?? null),
            decidedAtUtc: self::stringOf($row['decided_at'] ?? null),
            supersedesId: is_numeric($row['supersedes_decision_id'] ?? null)
                ? (int) $row['supersedes_decision_id']
                : null,
            clinicalPriority: self::nullable($row['clinical_priority'] ?? null),
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
