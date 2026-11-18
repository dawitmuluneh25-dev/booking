<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * The public form refused a submission because the network has used its allowance.
 *
 * Carries a retry hint so the page can say something useful. It reveals nothing
 * about any account or request — the cap is per network, not per address — so it is
 * safe to show a stranger.
 */
final class AccountRequestThrottledException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterMinutes)
    {
        parent::__construct(
            'Too many requests from this network. Try again in ' . $retryAfterMinutes . ' minutes.',
        );
    }
}
