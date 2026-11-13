<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Pregnancy-related assessment (MED-005).
 *
 * `not_relevant` is a clinical judgement, not a default. §6.2 forbids inferring it
 * from age, sex or marital status, which is why the value has to be chosen
 * explicitly and why `assessment_required` and `unresolved` exist: a question that
 * has been raised but not answered is not the same as one that does not apply.
 *
 * What "assessed per local policy" means is deliberately not encoded here — the
 * modality-specific policy is local, and inventing it would be inventing a rule.
 */
enum PregnancyStatus: string
{
    case NotRelevant = 'not_relevant';
    case AssessmentRequired = 'assessment_required';
    case AssessedPerLocalPolicy = 'assessed_per_local_policy';
    case Unresolved = 'unresolved';

    public function label(): string
    {
        return match ($this) {
            self::NotRelevant => 'Not relevant to this patient or procedure',
            self::AssessmentRequired => 'Assessment required',
            self::AssessedPerLocalPolicy => 'Assessed under local policy',
            self::Unresolved => 'Unresolved',
        };
    }

    /** Whether the question is still open, so a reader must not treat it as settled. */
    public function isOpen(): bool
    {
        return $this === self::AssessmentRequired || $this === self::Unresolved;
    }
}
