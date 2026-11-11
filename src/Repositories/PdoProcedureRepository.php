<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Procedure;
use App\Domain\ProcedureRepository;
use PDO;

final class PdoProcedureRepository implements ProcedureRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function elective(): array
    {
        $statement = $this->pdo->query(
            "SELECT code, label, clinical_code, category, default_duration_minutes, required_fields,
                    field_set_version, approved_by, approved_at, active
             FROM procedures
             WHERE category = 'elective' AND active = 1
             ORDER BY label"
        );

        return $this->collect($statement === false ? [] : $statement->fetchAll());
    }

    public function find(string $code): ?Procedure
    {
        $statement = $this->pdo->prepare(
            'SELECT code, label, clinical_code, category, default_duration_minutes, required_fields,
                    field_set_version, approved_by, approved_at, active
             FROM procedures
             WHERE code = :code'
        );
        $statement->execute(['code' => $code]);

        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return Procedure::fromRow($row);
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<Procedure>
     */
    private function collect(array $rows): array
    {
        $procedures = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $procedures[] = Procedure::fromRow($row);
            }
        }

        return $procedures;
    }
}
