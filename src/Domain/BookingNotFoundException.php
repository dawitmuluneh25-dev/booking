<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

final class BookingNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct('No booking exists with reference ' . $reference . '.');
    }
}
