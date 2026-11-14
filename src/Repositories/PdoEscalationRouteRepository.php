<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\EscalationRoute;
use App\Domain\EscalationRouteKind;
use App\Domain\EscalationRouteRepository;
use PDO;

/**
 * MySQL implementation of the escalation roster (Decision 4).
 *
 * The ordering is by kind in the order a caller uses them — MySQL would sort the
 * strings alphabetically, which puts the receiving team before the primary contact
 * and reads like a ladder that starts at the top. The order is imposed in PHP from
 * the enum's own case order so the list cannot drift from the decision.
 */
final class PdoEscalationRouteRepository implements EscalationRouteRepository
{
    private const COLUMNS = 'id, kind, label, contact_name, contact_phone, is_staffed,
        verified_by_user_id, verified_at, verification_note, is_active, created_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<EscalationRoute> */
    public function all(): array
    {
        return $this->fetch('SELECT ' . self::COLUMNS . ' FROM escalation_routes');
    }

    /** @return list<EscalationRoute> */
    public function active(): array
    {
        return $this->fetch(
            'SELECT ' . self::COLUMNS . ' FROM escalation_routes WHERE is_active = 1'
        );
    }

    public function find(int $id): ?EscalationRoute
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM escalation_routes WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? EscalationRoute::fromRow($row) : null;
    }

    public function create(EscalationRoute $route): EscalationRoute
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO escalation_routes
                (kind, label, contact_name, contact_phone, is_staffed, verified_by_user_id,
                 verified_at, verification_note, is_active, created_at, updated_at)
             VALUES
                (:kind, :label, :contact_name, :contact_phone, :is_staffed, NULL,
                 NULL, NULL, 1, :created_at, :updated_at)'
        );

        $statement->execute([
            'kind' => $route->kind->value,
            'label' => $route->label,
            'contact_name' => $route->contactName,
            'contact_phone' => $route->contactPhone,
            'is_staffed' => $route->isStaffed ? 1 : 0,
            'created_at' => $route->createdAtUtc,
            'updated_at' => $route->createdAtUtc,
        ]);

        return $route->withIdentity((int) $this->pdo->lastInsertId());
    }

    public function verify(int $id, int $verifiedByUserId, string $note, string $verifiedAtUtc): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE escalation_routes
                SET verified_by_user_id = :verifier,
                    verified_at = :verified_at,
                    verification_note = :note,
                    updated_at = :updated_at
              WHERE id = :id AND is_active = 1'
        );
        $statement->execute([
            'verifier' => $verifiedByUserId,
            'verified_at' => $verifiedAtUtc,
            'note' => $note,
            'updated_at' => $verifiedAtUtc,
            'id' => $id,
        ]);
    }

    public function retire(int $id, string $retiredAtUtc): void
    {
        // Guarded on the current state so a second press cannot overwrite the first
        // one's date: the roster's history is the only record of when a route stopped.
        $statement = $this->pdo->prepare(
            'UPDATE escalation_routes SET is_active = 0, updated_at = :at
              WHERE id = :id AND is_active = 1'
        );
        $statement->execute(['at' => $retiredAtUtc, 'id' => $id]);
    }

    /** @return list<EscalationRoute> */
    private function fetch(string $sql): array
    {
        $statement = $this->pdo->query($sql);

        if ($statement === false) {
            return [];
        }

        $routes = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $routes[] = EscalationRoute::fromRow($row);
            }
        }

        $order = array_flip(array_map(
            static fn (EscalationRouteKind $kind): string => $kind->value,
            EscalationRouteKind::cases(),
        ));

        usort($routes, static fn (EscalationRoute $a, EscalationRoute $b): int
            => [$order[$a->kind->value] ?? 0, $a->label] <=> [$order[$b->kind->value] ?? 0, $b->label]);

        return $routes;
    }
}
