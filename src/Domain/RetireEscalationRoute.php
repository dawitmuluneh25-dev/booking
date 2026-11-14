<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Withdraw a route from the roster without deleting it (Decision 4).
 *
 * The row keeps its verification and its dates. A case escalated through it last
 * month still has to explain where it went, and a deleted route would leave that
 * question unanswerable. Retirement takes the route out of the activation count and
 * out of the list a coordinator is offered.
 */
final class RetireEscalationRoute
{
    public function __construct(
        private readonly EscalationRouteRepository $routes,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
    ) {
    }

    /** @throws AuthorisationException|EscalationRouteNotFoundException */
    public function execute(Actor $actor, int $routeId, ?string $nowUtc = null): EscalationRoute
    {
        if (!$actor->can(Permission::ClinicConfigure)) {
            throw new AuthorisationException($actor->role, Permission::ClinicConfigure);
        }

        $route = $this->routes->find($routeId);

        if ($route === null) {
            throw new EscalationRouteNotFoundException('No escalation route matches #' . $routeId . '.');
        }

        if (!$route->isActive) {
            return $route;
        }

        $when = $nowUtc ?? gmdate('Y-m-d H:i:s');

        return $this->transaction->run(function () use ($actor, $route, $when): EscalationRoute {
            $this->routes->retire($route->id, $when);

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: AuditAction::EscalationRouteRetired,
                objectType: 'escalation_route',
                objectRef: (string) $route->id,
                before: ['is_active' => true],
                after: ['is_active' => false, 'verified_at' => $route->verifiedAtUtc],
                occurredAtUtc: $when,
            ));

            return $route->retired();
        });
    }
}
