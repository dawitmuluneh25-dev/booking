<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * The referral the caller asked about does not exist, or is not visible to them.
 *
 * Both cases answer the same way on purpose: an object-level authorisation
 * failure must not tell the caller that a record exists (SEC 6.9).
 */
final class ReferralNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $lookup)
    {
        parent::__construct('No referral matches ' . $lookup . '.');
    }
}
