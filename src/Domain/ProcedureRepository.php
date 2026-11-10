<?php

declare(strict_types=1);

namespace App\Domain;

interface ProcedureRepository
{
    /** @return list<Procedure> elective and active only, ordered by label */
    public function elective(): array;

    public function find(string $code): ?Procedure;
}
