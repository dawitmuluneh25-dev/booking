<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** No consent record matches the identifier the caller asked for. */
final class ConsentNotFoundException extends RuntimeException
{
    public function __construct(public readonly int $consentId)
    {
        parent::__construct('No consent record matches #' . $consentId . '.');
    }
}
