<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** The account does not exist, or the caller may not see it. */
final class UserNotFoundException extends RuntimeException
{
    public function __construct(int $userId)
    {
        parent::__construct('No account matches #' . $userId . '.');
    }
}
