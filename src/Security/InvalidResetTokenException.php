<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class InvalidResetTokenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That reset link is invalid or has expired. Request a new one.');
    }
}
