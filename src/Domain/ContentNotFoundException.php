<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** No content record matches the identifier the caller asked for. */
final class ContentNotFoundException extends RuntimeException
{
    public function __construct(public readonly int $contentId)
    {
        parent::__construct('No content record matches #' . $contentId . '.');
    }
}
