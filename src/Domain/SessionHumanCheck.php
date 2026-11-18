<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The web implementation of the challenge seam: the question lives in the session,
 * which is the only per-visitor store an unauthenticated form has.
 */
final class SessionHumanCheck implements HumanCheckVerifier
{
    public function verify(string $token, string $answer): bool
    {
        return HumanCheck::verify($token, $answer);
    }
}
