<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Actor;
use App\Domain\OperationalAppointmentRepository;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * The five appointments, as rows.
 *
 * `save()` upserts rather than updating, because the row is created by the migration and might not be:
 * a restored database from before 0048, or a deployment where the seeder was skipped, should still be
 * fillable without an error page. `updated_by` records the actor's id and role rather than a name,
 * because the user row already holds the name and duplicating it would let the two disagree.
 */
final class PdoOperationalAppointmentRepository implements OperationalAppointmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        /** @var array<string, array{holder: ?string, deputy: ?string, updatedBy: ?string, updatedAt: ?string}> $rows */
        $rows = [];

        $found = $this->query(
            'SELECT role_key, holder_name, deputy_name, updated_by, updated_at
               FROM operational_appointments
              ORDER BY role_key'
        );

        foreach ($found as $row) {
            $key = $row['role_key'] ?? null;

            if (!is_string($key) || $key === '') {
                continue;
            }

            $rows[$key] = [
                'holder' => $this->text($row['holder_name'] ?? null),
                'deputy' => $this->text($row['deputy_name'] ?? null),
                'updatedBy' => $this->text($row['updated_by'] ?? null),
                'updatedAt' => $this->text($row['updated_at'] ?? null),
            ];
        }

        return $rows;
    }

    public function save(string $roleKey, ?string $holder, ?string $deputy, Actor $actor, string $nowUtc): void
    {
        $statement = $this->prepare(
            'INSERT INTO operational_appointments
                (role_key, holder_name, deputy_name, updated_by, updated_at)
             VALUES (:role_key, :holder, :deputy, :updated_by, :updated_at)
             ON DUPLICATE KEY UPDATE
                holder_name = VALUES(holder_name),
                deputy_name = VALUES(deputy_name),
                updated_by = VALUES(updated_by),
                updated_at = VALUES(updated_at)'
        );

        $statement->execute([
            'role_key' => $roleKey,
            'holder' => $holder,
            'deputy' => $deputy,
            'updated_by' => 'user:' . $actor->userId . ':' . $actor->role->value,
            'updated_at' => $nowUtc,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function query(string $sql): array
    {
        $statement = $this->prepare($sql);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if ($statement === false) {
            throw new RuntimeException('The driver refused to prepare an appointment statement.');
        }

        return $statement;
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
