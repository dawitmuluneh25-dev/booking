<?php

declare(strict_types=1);

namespace App\Database;

use RuntimeException;

/**
 * Raised when the demo seed declines to run.
 *
 * A separate type from the failures that mean "the seed is broken", because the
 * distinction matters to whoever reads the console output: this one means the seed
 * did as it was told. Refusals are the expected path on a real installation, so
 * they exit cleanly with an explanation rather than looking like a crash.
 */
final class DemoSeedRefused extends RuntimeException
{
    public static function production(string $environment): self
    {
        return new self(sprintf(
            'Refusing to seed demo data: APP_ENV is "%s". A demo dataset must never be '
            . 'written to an environment that holds real patient records. No flag overrides this.',
            $environment,
        ));
    }

    public static function unknownEnvironment(string $environment): self
    {
        return new self(sprintf(
            'Refusing to seed demo data: APP_ENV is "%s", which is not a known '
            . 'non-production environment. Set APP_ENV to one of local, development, '
            . 'testing or staging, or leave the seed alone.',
            $environment,
        ));
    }

    public static function unconfirmed(): self
    {
        return new self(
            'Refusing to seed demo data without explicit confirmation. '
            . 'Re-run with --yes once you are certain this database holds no real records.',
        );
    }

    /**
     * The seed can create patients, but it must never be the thing that lets
     * fictional ones sit beside real ones. A database with any referral already in
     * it is somebody's record, and mixing is not reversible by eye.
     */
    public static function databaseNotEmpty(int $referrals, int $appointments): self
    {
        return new self(sprintf(
            'Refusing to seed demo data: this database already contains %d referral(s) and '
            . '%d appointment(s) that the seed did not create. Seeding here would place '
            . 'fictional patients among real records.',
            $referrals,
            $appointments,
        ));
    }
}
