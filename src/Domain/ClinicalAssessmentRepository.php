<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Persistence boundary for pre-procedure assessments (§6.5, §6.6).
 *
 * Append-only. Nothing here updates a row: a revision is a new record naming the one
 * it replaces, so an assessment made in March is still readable after the June
 * revision, and the reader can see what was known at the time a decision was taken.
 */
interface ClinicalAssessmentRepository
{
    public function record(ClinicalAssessment $assessment): ClinicalAssessment;

    public function findById(int $id): ?ClinicalAssessment;

    /** The assessment nothing supersedes — the one that currently stands. */
    public function current(int $referralId): ?ClinicalAssessment;

    /**
     * @return list<array{assessment: ClinicalAssessment, recordedBy: string}>
     */
    public function history(int $referralId): array;
}
