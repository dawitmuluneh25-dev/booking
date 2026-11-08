<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** No appointment matches the reference the caller supplied. */
final class AppointmentNotFoundException extends RuntimeException
{
    public function __construct(string $reference)
    {
        parent::__construct('No appointment matches ' . $reference . '.');
    }
}
