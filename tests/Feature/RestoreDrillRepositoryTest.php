<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Repositories\PdoRestoreDrillRepository;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Drill evidence, against the real table.
 *
 * The assertions that matter are about what the gate is allowed to conclude: only a passing drill counts,
 * the newest one wins, and a failure does not erase an earlier success — the gate asks "is there a
 * passing drill inside 90 days", and a failed attempt on Tuesday should not hide the passing one from
 * Monday.
 */
final class RestoreDrillRepositoryTest extends TestCase
{
    /** A name per run, so a real drill record in the same table can never be returned instead. */
    private string $archive = '';

    private ?PDO $pdo = null;

    private ?PdoRestoreDrillRepository $repository = null;

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

        $this->archive = 'test-drill-' . bin2hex(random_bytes(4)) . '.sql.gz.enc';
        $this->repository = new PdoRestoreDrillRepository($this->pdo);
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    public function testAPassingDrillIsReturnedWithItsArchiveAndCounts(): void
    {
        $this->record('ok', '2026-11-25 21:00:00', 53, 243);

        $latest = $this->repository()->latestSuccessful($this->archive);

        self::assertIsArray($latest);
        self::assertSame($this->archive, $latest['archive']);
        self::assertSame(53, $latest['tables']);
        self::assertSame(243, $latest['rows']);
        self::assertTrue($latest['encrypted']);
    }

    public function testAFailedDrillDoesNotCountAsASuccessfulOne(): void
    {
        $this->record('failed', '2026-11-25 21:00:00', 0, 0);

        self::assertNull($this->repository()->latestSuccessful($this->archive));
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM restore_drills WHERE archive_name = :archive');
        self::assertNotFalse($statement, 'The count statement could not be prepared.');
        $statement->execute(['archive' => $this->archive]);

        self::assertSame(1, (int) $statement->fetchColumn(), 'The failure is still recorded.');
    }

    public function testTheNewestPassingDrillWins(): void
    {
        $this->record('ok', '2026-11-20 09:00:00', 50, 200);
        $this->record('ok', '2026-11-25 21:00:00', 53, 243);

        $latest = $this->repository()->latestSuccessful($this->archive);

        self::assertIsArray($latest);
        self::assertSame('2026-11-25 21:00:00', $latest['verifiedAt']);
        self::assertSame(243, $latest['rows']);
    }

    private function record(string $verdict, string $verifiedAt, int $tables, int $rows): void
    {
        $this->repository()->record([
            'archive' => $this->archive,
            'encrypted' => true,
            'target' => 'booking_test_drill',
            'tables' => $tables,
            'rows' => $rows,
            'verdict' => $verdict,
            'actor' => 'test:drill',
        ], $verifiedAt);
    }

    private function clean(): void
    {
        $statement = $this->pdo()->prepare('DELETE FROM restore_drills WHERE archive_name = :archive');

        if ($statement === false) {
            self::fail('The cleanup statement could not be prepared.');
        }

        $statement->execute(['archive' => $this->archive]);
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('No database connection: this test skipped itself in setUp().');
        }

        return $this->pdo;
    }

    private function repository(): PdoRestoreDrillRepository
    {
        if ($this->repository === null) {
            self::fail('No repository: this test skipped itself in setUp().');
        }

        return $this->repository;
    }
}
