<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The escalation roster (Decision 4).
 *
 * Reads are whole-roster: the activation gate asks "is there a verified route of
 * each required kind", which is a question about the set, not about one row. There
 * is no delete — a retired route keeps its verification and its history.
 */
interface EscalationRouteRepository
{
    /**
     * Every route, retired ones included, ordered by kind then label.
     *
     * @return list<EscalationRoute>
     */
    public function all(): array;

    /**
     * Active routes only, for the roster a coordinator may use.
     *
     * @return list<EscalationRoute>
     */
    public function active(): array;

    public function find(int $id): ?EscalationRoute;

    public function create(EscalationRoute $route): EscalationRoute;

    /** Record who checked the route, when, and how. */
    public function verify(int $id, int $verifiedByUserId, string $note, string $verifiedAtUtc): void;

    public function retire(int $id, string $retiredAtUtc): void;
}
