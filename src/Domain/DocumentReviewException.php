<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * A quarantine verdict could not be recorded.
 *
 * Separate from `ValidationException` because this is not bad form input: it is
 * a document that is no longer awaiting review, usually because a colleague
 * decided it first. The route answers 409, not 422.
 */
final class DocumentReviewException extends RuntimeException
{
    public function __construct(public readonly DocumentScanStatus $current, string $reason)
    {
        parent::__construct(sprintf('This document is %s. %s', $current->label(), $reason));
    }
}
