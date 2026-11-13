<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ClinicalAssessment;
use App\Domain\ClinicalAssessmentRepository;

/** In-memory assessment boundary: current is the row nothing supersedes. */
final class InMemoryClinicalAssessmentRepository implements ClinicalAssessmentRepository
{
    /** @var array<int, ClinicalAssessment> */
    private array $assessments = [];

    private int $nextId = 1;

    public function record(ClinicalAssessment $assessment): ClinicalAssessment
    {
        $stored = $assessment->withIdentity($this->nextId);
        $this->nextId++;
        $this->assessments[$stored->id] = $stored;

        return $stored;
    }

    public function findById(int $id): ?ClinicalAssessment
    {
        return $this->assessments[$id] ?? null;
    }

    public function current(int $referralId): ?ClinicalAssessment
    {
        $superseded = [];

        foreach ($this->assessments as $assessment) {
            if ($assessment->supersedesId !== null) {
                $superseded[$assessment->supersedesId] = true;
            }
        }

        $current = null;

        foreach ($this->assessments as $assessment) {
            if ($assessment->referralId !== $referralId || isset($superseded[$assessment->id])) {
                continue;
            }

            $current = $assessment;
        }

        return $current;
    }

    public function history(int $referralId): array
    {
        $history = [];

        foreach ($this->assessments as $assessment) {
            if ($assessment->referralId === $referralId) {
                $history[] = ['assessment' => $assessment, 'recordedBy' => 'Dr Selam Tesfaye'];
            }
        }

        usort(
            $history,
            static fn (array $left, array $right): int => [$left['assessment']->recordedAtUtc, $left['assessment']->id]
                <=> [$right['assessment']->recordedAtUtc, $right['assessment']->id],
        );

        return $history;
    }
}
