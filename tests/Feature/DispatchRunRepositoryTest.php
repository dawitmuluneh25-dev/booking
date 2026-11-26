<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\DispatchRun;
use App\Repositories\PdoDispatchRunRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The dispatch ledger, against the real table.
 *
 * The assertions are about ordering, because ordering is the whole point: the
 * check asks for the *newest completed* run, and a query that returns any other
 * row would report a dead schedule as healthy or the reverse. Each test writes
 * its own host marker so a real run recorded in the same table cannot be mistaken
 * for the fixture.
 */
final class DispatchRunRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;

    private ?PdoDispatchRunRepository $repository = null;

    private string $host = '';

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

        $this->repository = new PdoDispatchRunRepository($this->pdo);
        $this->host = 'test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->host !== '') {
            $statement = $this->pdo->prepare('DELETE FROM dispatch_runs WHERE host = :host');
            $statement->execute(['host' => $this->host]);
        }
    }

    public function testARunIsRecordedWithItsCounts(): void
    {
        $this->repository?->record($this->sample('2026-11-26 09:00:00', sent: 3, retrying: 1, dead: 2));

        $last = $this->repository?->lastRun();

        self::assertNotNull($last);
        self::assertSame($this->host, $last->host);
        self::assertSame(6, $last->claimed);
        self::assertSame(3, $last->sent);
        self::assertSame(1, $last->retrying);
        self::assertSame(2, $last->dead);
    }

    public function testTheNewestRunIsTheOneReported(): void
    {
        $this->repository?->record($this->sample('2026-11-26 09:00:00', sent: 1));
        $this->repository?->record($this->sample('2026-11-26 09:05:00', sent: 7));

        $last = $this->repository?->lastRun();

        self::assertNotNull($last);
        self::assertSame(7, $last->sent, 'The check must read the latest completed run, not the first row.');
        self::assertSame('2026-11-26 09:05:00', $last->finishedAtUtc);
    }

    public function testAnEmptyLedgerReportsNoRunRatherThanAStub(): void
    {
        // Null is the fact the check needs: "nothing has ever drained this queue" is
        // a different claim from "the last run was old", and only the first one
        // describes a host where cron was never installed.
        $this->pdo?->exec('DELETE FROM dispatch_runs');

        self::assertNull($this->repository?->lastRun());
    }

    private function sample(string $at, int $sent = 0, int $retrying = 0, int $dead = 0): DispatchRun
    {
        return new DispatchRun(
            startedAtUtc: $at,
            finishedAtUtc: $at,
            claimed: $sent + $retrying + $dead,
            sent: $sent,
            retrying: $retrying,
            dead: $dead,
            host: $this->host,
        );
    }
}
