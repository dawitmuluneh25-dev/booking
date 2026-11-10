<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One decision as the history shows it.
 *
 * The decision row records who decided by id and when by UTC timestamp. A page
 * that surfaces only the ids asks the reader to hold a second table in their head,
 * and a page that surfaces only the newest decision hides the fact that a
 * judgement was ever replaced. Both facts are read here, next to the decision.
 *
 * `supersededById` is the whole point of the type: a decision that a later one
 * replaced is still part of the record (WF-09), but it must never be mistaken for
 * the judgement that stands. Commands already ask the repository for the standing
 * decision rather than the newest row; this makes the same distinction visible.
 */
final class ClinicalDecisionRecord
{
    public function __construct(
        public readonly ClinicalDecision $decision,
        public readonly string $reviewerName,
        public readonly ?int $supersededById,
    ) {
    }

    /** True when no later decision replaced this one. */
    public function isCurrent(): bool
    {
        return $this->supersededById === null;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $name = $row['reviewer_name'] ?? null;

        return new self(
            decision: ClinicalDecision::fromRow($row),
            reviewerName: is_scalar($name) ? (string) $name : '',
            supersededById: is_numeric($row['superseded_by_id'] ?? null)
                ? (int) $row['superseded_by_id']
                : null,
        );
    }
}
