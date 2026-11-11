<?php

declare(strict_types=1);

namespace App\Database;

/**
 * The two locks on the demo seed, kept apart from the data it writes.
 *
 * They fail for different reasons and must not be conflated. The environment check
 * answers "is this database allowed to hold fictional patients at all", and nothing
 * an operator types can change its answer: being certain does not make a production
 * database a safe place for demo records. The confirmation check answers "did
 * somebody mean to do this", and it is the one a flag satisfies.
 *
 * Keeping the policy here rather than inside the seeder means it can be proven
 * without a database, which is the only way to be sure the refusal happens before
 * the connection is opened rather than after.
 */
final class DemoSeedGuard
{
    /** Environments where a demonstration dataset is a reasonable thing to want. */
    public const PERMITTED_ENVIRONMENTS = ['local', 'development', 'dev', 'testing', 'test', 'staging'];

    /**
     * Names that mean real records. Checked first and separately so the message an
     * operator sees names the actual danger rather than "unknown environment".
     */
    public const PRODUCTION_ENVIRONMENTS = ['production', 'prod'];

    public function __construct(private readonly string $environment)
    {
    }

    /**
     * @throws DemoSeedRefused the environment is production, or is not a recognised
     *                         development environment
     */
    public function requirePermittedEnvironment(): void
    {
        $environment = strtolower(trim($this->environment));

        if (in_array($environment, self::PRODUCTION_ENVIRONMENTS, true)) {
            throw DemoSeedRefused::production($environment);
        }

        if (!in_array($environment, self::PERMITTED_ENVIRONMENTS, true)) {
            throw DemoSeedRefused::unknownEnvironment($environment);
        }
    }

    /** @throws DemoSeedRefused no explicit confirmation was given */
    public function requireConfirmation(bool $confirmed): void
    {
        if (!$confirmed) {
            throw DemoSeedRefused::unconfirmed();
        }
    }
}
