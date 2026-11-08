<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The unit of work exists so a command can compose writes across repositories
 * without hitting "there is already an active transaction".
 *
 * These tests use real rows and real commits, because the whole point is what
 * MySQL does, not what a fake says it does. Cleanup is explicit: the committed
 * cases have to be deleted, the rolled-back ones are already gone.
 */
final class PdoTransactionTest extends TestCase
{
    private const PREFIX = 'tx-probe-';

    private ?PDO $pdo = null;

    private ?PdoTransaction $transaction = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $config = new Config($root);

        try {
            $this->pdo = Connection::create($config->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
        }

        $this->transaction = new PdoTransaction($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec("DELETE FROM users WHERE email LIKE '" . self::PREFIX . "%'");
        }

        $this->pdo = null;
        $this->transaction = null;
    }

    public function testWorkIsCommittedAndTheDepthReturnsToZero(): void
    {
        $transaction = $this->transaction();
        self::assertSame(0, $transaction->depth());

        $result = $transaction->run(
            function (): int {
                self::assertSame(1, $this->transaction()->depth());
                $this->insertUser('committed');

                return 42;
            },
        );

        self::assertSame(42, $result);
        self::assertSame(0, $transaction->depth());
        self::assertSame(1, $this->countRows('committed'));
    }

    public function testAFailedScopeLeavesNothingBehind(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            $this->transaction()->run(
                function (): void {
                    $this->insertUser('rolled-back');

                    throw new RuntimeException('Something went wrong half way through.');
                },
            );
        } finally {
            self::assertSame(0, $this->transaction()->depth());
            self::assertSame(0, $this->countRows('rolled-back'));
        }
    }

    public function testANestedFailureRollsBackOnlyItsOwnWork(): void
    {
        $this->transaction()->run(
            function (): void {
                $this->insertUser('outer-before');

                try {
                    $this->transaction()->run(
                        function (): void {
                            self::assertSame(2, $this->transaction()->depth());
                            $this->insertUser('inner-failed');

                            throw new RuntimeException('Inner scope failed.');
                        },
                    );
                } catch (RuntimeException) {
                    // The outer scope decides to carry on.
                }

                $this->insertUser('outer-after');
            },
        );

        self::assertSame(1, $this->countRows('outer-before'));
        self::assertSame(1, $this->countRows('outer-after'));
        self::assertSame(0, $this->countRows('inner-failed'));
        self::assertSame(0, $this->transaction()->depth());
    }

    public function testAnOuterFailureDiscardsCommittedInnerScopes(): void
    {
        $message = null;

        try {
            $this->transaction()->run(
                function (): void {
                    $this->transaction()->run(function (): void {
                        $this->insertUser('inner-ok');
                    });

                    throw new RuntimeException('The outer scope failed after the inner one succeeded.');
                },
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        // Asserting the message rather than calling fail() keeps the check honest:
        // if the closure did not throw, the null message fails the test anyway.
        self::assertSame('The outer scope failed after the inner one succeeded.', $message);
        self::assertSame(0, $this->countRows('inner-ok'));
        self::assertSame(0, $this->transaction()->depth());
    }

    public function testATransactionOpenedBehindTheUnitOfWorkIsRefused(): void
    {
        // Otherwise the depth counter would lie and a rollback would discard work
        // the caller believed was committed.
        $this->pdo()->beginTransaction();

        try {
            $this->transaction()->run(static fn (): int => 1);
            self::fail('The unit of work started a second transaction.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already open', $exception->getMessage());
        } finally {
            $this->pdo()->rollBack();
        }
    }

    private function transaction(): PdoTransaction
    {
        if ($this->transaction === null) {
            throw new RuntimeException('The transaction was not initialised.');
        }

        return $this->transaction;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The connection was not initialised.');
        }

        return $this->pdo;
    }

    private function insertUser(string $suffix): void
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => self::PREFIX . $suffix . '@example.test',
            'name' => 'Transaction Probe',
            'role' => 'staff',
            'hash' => str_repeat('0', 60),
        ]);
    }

    private function countRows(string $suffix): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $statement->execute(['email' => self::PREFIX . $suffix . '@example.test']);

        return (int) $statement->fetchColumn();
    }
}
