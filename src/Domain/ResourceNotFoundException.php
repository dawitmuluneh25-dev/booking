<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** The resource a booking names does not exist, or is not bookable. */
final class ResourceNotFoundException extends RuntimeException
{
    public function __construct(public readonly int $resourceId)
    {
        parent::__construct('No resource matches #' . $resourceId . '.');
    }
}
