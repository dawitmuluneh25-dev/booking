<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A counter for submissions that were refused before they became requests.
 *
 * Kept separate from `AccountRequestRepository` because it answers a different
 * question — "how often did this network knock" rather than "who asked" — and
 * because it must be purged on a different schedule. It stores a keyed hash of the
 * address, never the address, and the hash is useless outside this installation.
 */
interface AccountRequestAttempts
{
    public function record(string $ipHash, string $occurredAtUtc): void;

    public function countSince(string $ipHash, string $sinceUtc): int;

    /** @return int rows removed */
    public function purgeOlderThan(string $cutoffUtc): int;
}
