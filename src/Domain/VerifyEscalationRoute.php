<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Confirm that somebody answers a recorded route (Decision 4).
 *
 * The note is required and has a minimum length because "verified" without "how" is
 * an assertion. What the decision needs recorded is who checked, when, and what they
 * did — a number that appears in a directory is not the same fact as a switchboard
 * answering at 02:00.
 *
 * Verifying again is allowed and replaces the date: an arrangement that was confirmed
 * last year is not confirmed now, so the latest verification is the one that counts.
 * Retiring is separate, because a route can be un-verified in effect by being
 * withdrawn, and that is a different act from being found not to answer.
 */
final class VerifyEscalationRoute
{
    public function __construct(
        private readonly EscalationRouteRepository $routes,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
    ) {
    }

    /** @throws AuthorisationException|ValidationException|EscalationRouteNotFoundException */
    public function execute(
        Actor $actor,
        int $routeId,
        string $note,
        ?string $nowUtc = null,
    ): EscalationRoute {
        if (!$actor->can(Permission::ClinicConfigure)) {
            throw new AuthorisationException($actor->role, Permission::ClinicConfigure);
        }

        $route = $this->routes->find($routeId);

        if ($route === null) {
            throw new EscalationRouteNotFoundException('No escalation route matches #' . $routeId . '.');
        }

        if (!$route->isActive) {
            throw new ValidationException([
                'route' => 'This route has been retired, so it no longer counts. Record the '
                    . 'arrangement again rather than verifying a withdrawn one.',
            ]);
        }

        $note = trim($note);

        if (mb_strlen($note) < 10) {
            throw new ValidationException([
                'verification_note' => 'Say how the route was confirmed, in at least ten characters — '
                    . 'who was spoken to, or what the confirmation was. A verification without that '
                    . 'is an assertion.',
            ]);
        }

        if ($route->kind->requiresStaffing() && !$route->isStaffed) {
            throw new ValidationException([
                'route' => 'This is an after-hours route that is not staffed, so it cannot be '
                    . 'verified. Decision 4 allows after-hours escalation only where coverage '
                    . 'genuinely exists.',
            ]);
        }

        $when = $nowUtc ?? gmdate('Y-m-d H:i:s');

        return $this->transaction->run(function () use ($actor, $route, $note, $when): EscalationRoute {
            $this->routes->verify($route->id, $actor->userId, $note, $when);

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: AuditAction::EscalationRouteVerified,
                objectType: 'escalation_route',
                objectRef: (string) $route->id,
                before: ['verified_at' => $route->verifiedAtUtc],
                after: ['verified_at' => $when, 'note' => $note],
                occurredAtUtc: $when,
            ));

            return $route->verifiedBy($actor->userId, $note, $when);
        });
    }
}
