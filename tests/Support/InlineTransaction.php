<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Transaction;

/**
 * Runs the work immediately.
 *
 * The unit suite is about decisions, not about MySQL's transaction semantics;
 * `PdoTransactionTest` covers the real unit of work. This double exists so a
 * command that requires a `Transaction` can be tested without a database.
 */
final class InlineTransaction implements Transaction
{
    private int $depth = 0;

    public function run(callable $work): mixed
    {
        $this->depth++;

        try {
            return $work();
        } finally {
            $this->depth--;
        }
    }

    public function depth(): int
    {
        return $this->depth;
    }
}
