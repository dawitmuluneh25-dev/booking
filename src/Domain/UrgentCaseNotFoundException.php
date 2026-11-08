<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** The urgent case does not exist, or the caller may not see it. */
final class UrgentCaseNotFoundException extends RuntimeException
{
    public function __construct(int $caseId)
    {
        parent::__construct('No urgent case matches #' . $caseId . '.');
    }
}
