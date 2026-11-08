<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A named administrative step with one fixed destination.
 *
 * WFS 15 names the steps (receive, start review, start clinical review, request
 * information) and a route should read like the step it performs. Each of these
 * classes is that name; the rules stay in `TriageReferral`, so there is one state
 * table and one permission check rather than four copies that drift.
 */
abstract class AdministrativeReferralCommand
{
    public function __construct(protected readonly TriageReferral $triage)
    {
    }

    abstract protected function target(): ReferralStatus;

    /**
     * @throws AuthorisationException
     * @throws ReferralNotFoundException
     * @throws ValidationException
     * @throws ReferralTransitionException
     */
    public function execute(
        Actor $actor,
        int $referralId,
        ?string $reason = null,
        ?string $nowUtc = null,
    ): Referral {
        return $this->triage->execute($actor, $referralId, $this->target(), $reason, $nowUtc);
    }
}
