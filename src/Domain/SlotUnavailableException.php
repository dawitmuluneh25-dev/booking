<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Raised when the requested slot was claimed by another booking between the
 * availability check and the insert. Expected under concurrency, not a bug.
 */
final class SlotUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The requested slot is no longer available.');
    }
}
