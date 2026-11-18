<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The seam between the command and whatever is holding the challenge.
 *
 * The real implementation keeps a one-time question in the session, which is a
 * web concern; the command only needs to know whether the answer was right. Without
 * this seam every unit test of the submission rules would need a live session.
 */
interface HumanCheckVerifier
{
    public function verify(string $token, string $answer): bool;
}
