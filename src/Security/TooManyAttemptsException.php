<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class TooManyAttemptsException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterMinutes)
    {
        parent::__construct('Too many sign-in attempts. Try again in ' . $retryAfterMinutes . ' minutes.');
    }
}
