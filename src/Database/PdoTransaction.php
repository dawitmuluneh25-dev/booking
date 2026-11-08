<?php

declare(strict_types=1);

namespace App\Database;

use App\Domain\Transaction;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PDO unit of work with savepoint-based nesting.
 *
 * One instance is shared by every repository and command in a request, which is
 * what makes nesting work: the depth counter belongs to the unit of work, not to
 * whoever happens to call it. A second instance over the same PDO would try to
 * open a second transaction and MySQL would refuse it — the same failure the
 * referral integration test hit before this existed.
 *
 * Savepoint names are derived from the depth, never from input.
 */
final class PdoTransaction implements Transaction
{
    private int $depth = 0;


    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(callable $work): mixed
    {
        $this->begin();

        try {
            $result = $work();
        } catch (Throwable $exception) {
            $this->rollback();

            throw $exception;
        }

        $this->commit();

        return $result;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    private function begin(): void
    {
        if ($this->depth === 0) {
            if ($this->pdo->inTransaction()) {
                // Somebody opened a transaction without going through this object.
                throw new RuntimeException(
                    'A database transaction is already open outside the unit of work.'
                );
            }

            $this->pdo->beginTransaction();
        } else {
            // Nested scope: MySQL has no nested transactions, so mark a point we
            // can return to if this scope fails on its own.
            $this->pdo->exec('SAVEPOINT ' . $this->savepoint($this->depth));
        }

        $this->depth++;
    }

    private function commit(): void
    {
        $this->depth--;

        if ($this->depth > 0) {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint($this->depth));

            return;
        }

        $this->pdo->commit();
    }

    private function rollback(): void
    {
        $this->depth--;

        if ($this->depth > 0) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $this->savepoint($this->depth));
            $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepoint($this->depth));

            return;
        }

        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function savepoint(int $depth): string
    {
        return 'sp_' . $depth;
    }
}
