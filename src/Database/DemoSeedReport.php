<?php

declare(strict_types=1);

namespace App\Database;

/**
 * What the seed did, in a shape the console can print and a test can assert on.
 *
 * `already_seeded` is a distinct outcome rather than a zero-count success, because
 * the two mean different things to whoever ran it: one says the demonstration now
 * exists, the other says it already did and nothing was touched.
 */
final class DemoSeedReport
{
    public const SEEDED = 'seeded';

    public const ALREADY_SEEDED = 'already_seeded';

    public function __construct(
        public readonly string $state,
        public readonly int $users = 0,
        public readonly int $resources = 0,
        public readonly int $referrals = 0,
        public readonly int $appointments = 0,
        public readonly int $urgentCases = 0,
    ) {
    }

    public static function alreadySeeded(): self
    {
        return new self(self::ALREADY_SEEDED);
    }

    public function seeded(): bool
    {
        return $this->state === self::SEEDED;
    }
}
