<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Repositories\PdoDispatchRunRetentionRepository;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The dispatch-ledger sweep against the real schema.
 *
 * This is the first class whose retention act is a **delete**, which makes two questions the only ones
 * that matter: does it remove what is past the period, and does it leave alone what is not. A `WHERE`
 * clause that lost its cutoff would empty the ledger and take `notify:dispatch-check` with it — the
 * check reads the newest row, and no rows means `NEVER`, which is exactly the alert it exists to raise.
 * A false `NEVER` every night is how a real `NEVER` gets ignored.
 *
 * The test therefore asserts both halves, plus the batch ceiling: a sweep that stops at `maxBatches`
 * must say so, or an operator reads "removed 500" as "there were 500".
 */
final class DispatchRunRetentionTest extends TestCase
{
    /** Marks rows this test owns, so a shared development database is never emptied by accident. */
    private const HOST = 'test:retention:dispatch';

    private ?PDO $pdo = null;

    private ?PdoDispatchRunRetentionRepository $retention = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $config = new Config($root);

        try {
            $this->pdo = Connection::create($config->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('No database available: ' . $exception->getMessage());
        }

        $this->retention = new PdoDispatchRunRetentionRepository($this->pdo);
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    public function testARowPastThePeriodIsRemovedAndAFreshOneIsKept(): void
    {
        $old = $this->insert(time() - (2000 * 86400));
        $fresh = $this->insert(time() - 60);
        $cutoff = gmdate('Y-m-d H:i:s', time() - (1825 * 86400));

        self::assertSame(1, $this->retention()->eligibleCount($cutoff));
        self::assertSame(1, $this->retention()->removeBefore($cutoff, 10));

        self::assertFalse($this->exists($old), 'A row past 1,825 days should be gone.');
        self::assertTrue($this->exists($fresh), 'A row from a minute ago must survive.');
    }

    public function testTheCutoffIsStrictSoTheBoundaryRowStays(): void
    {
        $boundary = time() - (1825 * 86400);
        $atCutoff = $this->insert($boundary);
        $cutoff = gmdate('Y-m-d H:i:s', $boundary);

        // `<` and not `<=`: a row written exactly on the cutoff is the oldest thing still inside the
        // period, and an off-by-one here deletes a day of ledger at the edge of every run.
        self::assertSame(0, $this->retention()->eligibleCount($cutoff));
        self::assertTrue($this->exists($atCutoff));
    }

    public function testASecondSweepDoesNotCountTheSameRowTwice(): void
    {
        $this->insert(time() - (2000 * 86400));
        $cutoff = gmdate('Y-m-d H:i:s', time() - (1825 * 86400));

        self::assertSame(1, $this->retention()->removeBefore($cutoff, 10));
        self::assertSame(0, $this->retention()->removeBefore($cutoff, 10));
    }

    public function testTheOldestRowsGoFirstWhenTheBatchIsFull(): void
    {
        $oldest = $this->insert(time() - (2100 * 86400));
        $newer = $this->insert(time() - (2000 * 86400));
        $cutoff = gmdate('Y-m-d H:i:s', time() - (1825 * 86400));

        self::assertSame(1, $this->retention()->removeBefore($cutoff, 1));

        self::assertFalse($this->exists($oldest));
        self::assertTrue($this->exists($newer), 'A batch of one takes the oldest, not an arbitrary row.');
    }

    public function testTheRunIsRecordedAgainstTheDispatchColumn(): void
    {
        $this->retention()->recordRun(gmdate('Y-m-d'), 7, 'cli:test:dispatch');

        $row = $this->query(
            'SELECT dispatch_runs_removed FROM retention_runs WHERE actor = :actor ORDER BY id DESC LIMIT 1',
            ['actor' => 'cli:test:dispatch'],
        )->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        $recorded = $row['dispatch_runs_removed'] ?? null;

        self::assertTrue(is_numeric($recorded) && (int) $recorded === 7);

        // The other counters stay zero rather than null: the columns are unsigned and a null would be
        // a row that says "this sweep did not look at bookings", which is not what happened.
        $zeros = $this->query(
            'SELECT bookings_redacted, referrals_redacted, documents_redacted, notifications_redacted
               FROM retention_runs WHERE actor = :actor ORDER BY id DESC LIMIT 1',
            ['actor' => 'cli:test:dispatch'],
        )->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($zeros);

        $counters = ['bookings_redacted', 'referrals_redacted', 'documents_redacted', 'notifications_redacted'];

        foreach ($counters as $column) {
            $value = $zeros[$column] ?? null;
            self::assertTrue(
                is_numeric($value) && (int) $value === 0,
                $column . ' should be an explicit zero, not a null and not a number.',
            );
        }
    }

    private function insert(int $startedAt): int
    {
        $stamp = gmdate('Y-m-d H:i:s', $startedAt);

        $this->query(
            'INSERT INTO dispatch_runs
                (started_at, finished_at, claimed, sent, retrying, dead, host)
             VALUES
                (:started_at, :finished_at, 1, 1, 0, 0, :host)',
            ['started_at' => $stamp, 'finished_at' => $stamp, 'host' => self::HOST],
        );

        return (int) $this->pdo()->lastInsertId();
    }

    private function exists(int $id): bool
    {
        $statement = $this->query('SELECT COUNT(*) FROM dispatch_runs WHERE id = :id', ['id' => $id]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function clean(): void
    {
        $this->pdo?->prepare('DELETE FROM dispatch_runs WHERE host = :host')
            ->execute(['host' => self::HOST]);
        $this->pdo?->prepare('DELETE FROM retention_runs WHERE actor = :actor')
            ->execute(['actor' => 'cli:test:dispatch']);
    }

    /** @param array<string, mixed> $parameters */
    private function query(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    private function retention(): PdoDispatchRunRetentionRepository
    {
        if ($this->retention === null) {
            self::fail('The repository was not built; the database was skipped.');
        }

        return $this->retention;
    }

    /**
     * The same guard as `retention()`, for the same reason: after a skip there is no connection, and a
     * nullable property read in a helper produces an error that reads like a broken test.
     */
    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('No database connection; the test was skipped.');
        }

        return $this->pdo;
    }
}
