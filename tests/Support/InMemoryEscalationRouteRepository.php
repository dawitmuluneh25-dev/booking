<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\EscalationRoute;
use App\Domain\EscalationRouteKind;
use App\Domain\EscalationRouteRepository;

/**
 * In-memory escalation roster.
 *
 * Ordered the way the adapter orders — by the enum's case order, then label — because
 * the board lists routes in the order a caller uses them, and a double that ordered
 * differently would let a screen pass that the real roster would render wrongly.
 */
final class InMemoryEscalationRouteRepository implements EscalationRouteRepository
{
    /** @var array<int, EscalationRoute> */
    private array $routes = [];

    private int $nextId = 1;

    /** @return list<EscalationRoute> */
    public function all(): array
    {
        return $this->ordered(array_values($this->routes));
    }

    /** @return list<EscalationRoute> */
    public function active(): array
    {
        return $this->ordered(array_values(array_filter(
            $this->routes,
            static fn (EscalationRoute $route): bool => $route->isActive,
        )));
    }

    public function find(int $id): ?EscalationRoute
    {
        return $this->routes[$id] ?? null;
    }

    public function create(EscalationRoute $route): EscalationRoute
    {
        $stored = $route->withIdentity($this->nextId++);
        $this->routes[$stored->id] = $stored;

        return $stored;
    }

    public function verify(int $id, int $verifiedByUserId, string $note, string $verifiedAtUtc): void
    {
        $route = $this->routes[$id] ?? null;

        if ($route === null || !$route->isActive) {
            return;
        }

        $this->routes[$id] = $route->verifiedBy($verifiedByUserId, $note, $verifiedAtUtc);
    }

    public function retire(int $id, string $retiredAtUtc): void
    {
        $route = $this->routes[$id] ?? null;

        if ($route === null || !$route->isActive) {
            return;
        }

        $this->routes[$id] = $route->retired();
    }

    /**
     * Convenience for fixtures: a verified, active route of the given kind.
     */
    public function seedVerified(
        EscalationRouteKind $kind,
        string $label = 'On-call',
        ?int $verifierId = 1,
        bool $isActive = true,
    ): EscalationRoute {
        $stored = $this->create(new EscalationRoute(
            id: 0,
            kind: $kind,
            label: $label,
            contactName: 'Duty officer',
            contactPhone: '+251911000111',
            isStaffed: true,
            isActive: true,
            verifiedByUserId: null,
            verifiedAtUtc: null,
            verificationNote: null,
            createdAtUtc: '2026-11-01 08:00:00',
        ));

        $this->verify(
            $stored->id,
            $verifierId ?? 1,
            'Called the switchboard and confirmed the rota.',
            '2026-11-02 09:00:00',
        );

        if (!$isActive) {
            $this->retire($stored->id, '2026-11-10 09:00:00');
        }

        return $this->routes[$stored->id] ?? $stored;
    }

    /**
     * @param list<EscalationRoute> $routes
     *
     * @return list<EscalationRoute>
     */
    private function ordered(array $routes): array
    {
        $order = array_flip(array_map(
            static fn (EscalationRouteKind $kind): string => $kind->value,
            EscalationRouteKind::cases(),
        ));

        usort($routes, static function (EscalationRoute $a, EscalationRoute $b) use ($order): int {
            return [$order[$a->kind->value] ?? 0, $a->label] <=> [$order[$b->kind->value] ?? 0, $b->label];
        });

        return $routes;
    }
}
