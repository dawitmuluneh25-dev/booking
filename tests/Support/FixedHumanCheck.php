<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\HumanCheckVerifier;

/** Answers the challenge the way the test wants, without a session. */
final class FixedHumanCheck implements HumanCheckVerifier
{
    public function __construct(private readonly bool $accept = true)
    {
    }

    public function verify(string $token, string $answer): bool
    {
        return $this->accept;
    }
}
