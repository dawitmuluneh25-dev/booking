<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\LaboratoryDestination;
use App\Domain\LaboratoryDestinationRepository;
use PDO;

/** MySQL implementation of the laboratory catalogue. */
final class PdoLaboratoryDestinationRepository implements LaboratoryDestinationRepository
{
    private const COLUMNS = 'id, serial_no, is_active';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM laboratory_destinations ORDER BY serial_no ASC'
        );
        $statement->execute();

        return $this->hydrate($statement);
    }

    /** @return list<LaboratoryDestination> */
    public function active(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM laboratory_destinations
              WHERE is_active = 1 ORDER BY serial_no ASC'
        );
        $statement->execute();

        return $this->hydrate($statement);
    }

    public function findById(int $id): ?LaboratoryDestination
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM laboratory_destinations WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : LaboratoryDestination::fromRow($row);
    }

    public function nextSerial(): int
    {
        // MAX, not COUNT: a deactivated laboratory keeps its number, and reissuing
        // it would make two different laboratories share one identifier in history.
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(serial_no), 0) FROM laboratory_destinations');
        $statement->execute();
        $highest = $statement->fetchColumn();

        return (is_numeric($highest) ? (int) $highest : 0) + 1;
    }

    public function create(int $serialNo, string $occurredAtUtc): LaboratoryDestination
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO laboratory_destinations (serial_no, is_active, created_at, updated_at)
             VALUES (:serial_no, 1, :created_at, :updated_at)'
        );
        $insert->execute([
            'serial_no' => $serialNo,
            'created_at' => $occurredAtUtc,
            'updated_at' => $occurredAtUtc,
        ]);

        return new LaboratoryDestination(
            id: (int) $this->pdo->lastInsertId(),
            serialNo: $serialNo,
            isActive: true,
        );
    }

    public function setActive(int $id, bool $active, string $occurredAtUtc): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE laboratory_destinations SET is_active = :active, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            'active' => $active ? 1 : 0,
            'updated_at' => $occurredAtUtc,
            'id' => $id,
        ]);
    }

    /** @return list<LaboratoryDestination> */
    private function hydrate(\PDOStatement $statement): array
    {
        $destinations = [];

        while (($row = $statement->fetch()) !== false) {
            /** @var array<string, mixed> $row */
            $destinations[] = LaboratoryDestination::fromRow($row);
        }

        return $destinations;
    }
}
