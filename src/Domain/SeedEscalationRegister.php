<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Put the five route categories D-06 names into the register, empty and inactive.
 *
 * The decision is explicit about two things that pull in opposite directions: "The escalation
 * register shall contain these five route categories" and "Do not invent contact names, telephone
 * numbers, email addresses, or URLs." A register that only contains what Operations has already
 * filled in cannot say what it is waiting for — the answer to "which routes exist?" would be "none",
 * which is indistinguishable from "nobody thought about it". A register of five named, empty,
 * inactive categories can say precisely what is missing.
 *
 * So this seeds categories, never contacts. Each row carries the receiving role from the decision
 * and nothing else: no name, no number, no hours, no verification. It is inactive, and migration
 * 0046's CHECK refuses to let an incomplete route be anything else.
 *
 * Idempotent by kind: a category that already exists is left exactly as it is, filled in or not.
 * Running this on a host where Operations has already recorded a primary contact must not blank it,
 * and the test for that is the reason this is a class rather than a loop in the console.
 */
final class SeedEscalationRegister
{
    public function __construct(private readonly EscalationRouteRepository $routes)
    {
    }

    /**
     * @return array{created: list<EscalationRouteKind>, existing: list<EscalationRouteKind>}
     */
    public function execute(?string $nowUtc = null): array
    {
        $present = [];

        foreach ($this->routes->all() as $route) {
            // A category counts as present whether it is active, retired or unfilled. Retiring a
            // route is a decision about a post; recreating it as a blank one would erase that.
            $present[$route->kind->value] = true;
        }

        $when = $nowUtc ?? gmdate('Y-m-d H:i:s');
        $created = [];
        $existing = [];

        foreach (EscalationRouteKind::cases() as $kind) {
            if (isset($present[$kind->value])) {
                $existing[] = $kind;
                continue;
            }

            $this->routes->create(new EscalationRoute(
                id: 0,
                kind: $kind,
                label: $kind->receivingRole(),
                contactName: '',
                contactPhone: '',
                isStaffed: false,
                isActive: false,
                verifiedByUserId: null,
                verifiedAtUtc: null,
                verificationNote: null,
                createdAtUtc: $when,
            ));

            $created[] = $kind;
        }

        return ['created' => $created, 'existing' => $existing];
    }
}
