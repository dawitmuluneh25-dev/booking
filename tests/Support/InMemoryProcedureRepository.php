<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Procedure;
use App\Domain\ProcedureRepository;

final class InMemoryProcedureRepository implements ProcedureRepository
{
    /** @var array<string, Procedure> */
    private array $procedures = [];

    public function add(Procedure $procedure): void
    {
        $this->procedures[$procedure->code] = $procedure;
    }

    /** @return list<Procedure> */
    public function elective(): array
    {
        $elective = array_values(array_filter(
            $this->procedures,
            static fn (Procedure $procedure): bool => $procedure->isElective(),
        ));

        usort($elective, static fn (Procedure $a, Procedure $b): int => strcmp($a->label, $b->label));

        return $elective;
    }

    public function find(string $code): ?Procedure
    {
        return $this->procedures[$code] ?? null;
    }
}
