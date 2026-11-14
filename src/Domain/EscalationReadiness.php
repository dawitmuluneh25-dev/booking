<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Whether urgent escalation may be used at all (Decision 4).
 *
 * The decision is explicit: until the actual primary, secondary and receiving-team
 * arrangements are supplied *and verified*, urgent coordination remains disabled.
 * "Verified" is the operative word — a roster nobody has checked is not an
 * arrangement, it is a list of telephone numbers, and an urgent surgical case
 * escalated to a number that rings out has been escalated nowhere.
 *
 * The gate does not close the urgent board. Registering a case, acknowledging it and
 * closing it stay available when escalation does not, because refusing those would
 * leave a suspected intussusception with no record at all — less safe, not more.
 *
 * Missing kinds are named rather than counted, so the person reading the board knows
 * what to go and confirm instead of being told the capability is unavailable.
 */
final class EscalationReadiness
{
    public function __construct(private readonly EscalationRouteRepository $routes)
    {
    }

    public function assess(): EscalationReadinessReport
    {
        $verified = [];

        foreach ($this->routes->active() as $route) {
            if ($route->isVerified()) {
                $verified[$route->kind->value] = true;
            }
        }

        $missing = [];

        foreach (EscalationRouteKind::cases() as $kind) {
            if ($kind->requiredForActivation() && !isset($verified[$kind->value])) {
                $missing[] = $kind;
            }
        }

        return new EscalationReadinessReport($missing === [], $missing);
    }
}
