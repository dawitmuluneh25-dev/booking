<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A radiologist takes a newly received urgent case into clinical review.
 *
 * The missing first step. `UrgentStatus` has allowed `Received → UnderClinicalReview`
 * since the aggregate was written, and `allowedActors()` names only the radiologist,
 * but nothing performed it — which meant every new case sat in `received` forever,
 * and `RecordUrgentDecision` refuses every choice from there because a case cannot be
 * accepted before it has been reviewed. The decision screen was therefore unreachable
 * for exactly the cases that need it.
 *
 * It is a separate command rather than a target on the decision command because it
 * says something different: not "here is the answer", but "a clinician has picked
 * this up". On a board that shows whether a case is being worked, that distinction is
 * the whole point.
 */
final class OpenUrgentReview
{
    public function __construct(private readonly UrgentCaseRepository $repository)
    {
    }

    /**
     * @throws AuthorisationException      the actor is not a clinician
     * @throws UrgentCaseNotFoundException the case does not exist
     * @throws UrgentTransitionException   the case is not awaiting review
     */
    public function execute(Actor $actor, int $caseId, ?string $nowUtc = null): UrgentCase
    {
        if (!$actor->can(Permission::ClinicalReview)) {
            throw new AuthorisationException($actor->role, Permission::ClinicalReview);
        }

        $case = $this->repository->findById($caseId);

        if ($case === null) {
            throw new UrgentCaseNotFoundException($caseId);
        }

        return $this->repository->transitionUrgency(
            $case,
            UrgentStatus::UnderClinicalReview,
            $actor,
            null,
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
