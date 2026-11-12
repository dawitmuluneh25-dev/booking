<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One recorded coordination decision on an urgent case.
 *
 * Immutable, like the clinical decision it parallels on the elective side. The
 * row is the record a clinician is accountable for; the status trail records only
 * that something moved.
 */
final class UrgentDecision
{
    public function __construct(
        public readonly int $id,
        public readonly int $urgentCaseId,
        public readonly UrgentDecisionType $type,
        public readonly string $rationale,
        public readonly int $decidedByUserId,
        public readonly string $decidedAtUtc,
        public readonly ?string $resultingStatus = null,
        public readonly ?int $supersedesId = null,
    ) {
    }

    public function resultingStatusLabel(): ?string
    {
        return $this->resultingStatus === null
            ? null
            : (UrgentStatus::tryFrom($this->resultingStatus)?->label() ?? $this->resultingStatus);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $type = UrgentDecisionType::tryFrom(self::stringOf($row['decision_type'] ?? null))
            // A stored value outside the vocabulary is shown as `other` rather
            // than hidden: the row exists, and the screen must not describe the
            // record less completely than the database does.
            ?? UrgentDecisionType::Other;

        return new self(
            id: self::intOf($row['id'] ?? null),
            urgentCaseId: self::intOf($row['urgent_case_id'] ?? null),
            type: $type,
            rationale: self::stringOf($row['rationale'] ?? null),
            decidedByUserId: self::intOf($row['decided_by_user_id'] ?? null),
            decidedAtUtc: self::stringOf($row['decided_at'] ?? null),
            resultingStatus: self::nullable($row['resulting_status'] ?? null),
            supersedesId: is_numeric($row['supersedes_decision_id'] ?? null)
                ? (int) $row['supersedes_decision_id']
                : null,
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
