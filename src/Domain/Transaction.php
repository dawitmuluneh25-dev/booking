<?php

declare(strict_types=1);

namespace App\Domain;

use Throwable;

/**
 * Unit of work.
 *
 * Commands and repositories both need atomicity, and both need it at the same
 * time: a command that converts a referral writes the referral, the appointment
 * and the occupancy rows, and the repository methods it calls must join that
 * transaction rather than start a second one. MySQL has no nested transactions,
 * so the implementation turns an inner scope into a savepoint.
 *
 * Failure of an inner scope rolls back only that scope; failure of the outer one
 * rolls back everything.
 */
interface Transaction
{
    /**
     * Run the work atomically and return its result.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     *
     * @throws Throwable whatever the work throws, after rolling its scope back
     */
    public function run(callable $work): mixed;

    /** 0 when no transaction is open, 1 for the outermost scope, 2 for a nested one. */
    public function depth(): int;
}
