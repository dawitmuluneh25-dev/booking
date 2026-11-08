<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * The same key arrived with a different request, or the first attempt is still
 * running. Neither is a replay, so the caller is refused rather than served a
 * result that belongs to different input.
 */
final class IdempotencyConflictException extends RuntimeException
{
    public function __construct(public readonly string $scope, string $reason)
    {
        parent::__construct('This request conflicts with an earlier one (' . $scope . '): ' . $reason);
    }
}
