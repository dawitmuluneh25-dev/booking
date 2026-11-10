<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use App\Domain\Role;
use App\Repositories\PdoReferralRepository;
use App\Repositories\PdoUserRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The queue at the size the first release expects.
 *
 * The acceptance criterion is a wall-clock bound, so this measures one — but the
 * rows are inserted inside a transaction that is rolled back, so the test leaves
 * nothing behind and the bound applies to the query rather than to a fixture.
 *
 * 500 referrals with a 300 ms budget is a generous margin: the point is to catch
 * a query that has lost its index, not to benchmark the host.
 */
final class ReferralQueuePerformanceTest extends TestCase
{
    private const ROWS = 500;

    private const BUDGET_SECONDS = 0.3;

    private ?PDO $pdo = null;

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

        $this->pdo()->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        $this->pdo = null;
    }

    public function testTheQueueStaysWithinItsBudgetAtFiveHundredReferrals(): void
    {
        $queue = new PdoReferralRepository($this->pdo(), new PdoTransaction($this->pdo()));

        $started = microtime(true);
        $rows = $queue->queued(100);
        $elapsed = microtime(true) - $started;

        self::assertCount(100, $rows, 'The queue must honour its limit.');
        self::assertLessThan(
            self::BUDGET_SECONDS,
            $elapsed,
            sprintf('The queue took %.0f ms for %d rows.', $elapsed * 1000, self::ROWS),
        );
    }

    public function testTheQueueQueryUsesTheStatusIndex(): void
    {
        $statement = $this->pdo()->query(
            "EXPLAIN SELECT id FROM referrals
             WHERE status IN ('submitted', 'received', 'administrative_review', 'awaiting_information')
             ORDER BY created_at ASC, id ASC
             LIMIT 100"
        );

        $plan = $statement === false ? [] : $statement->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($plan);

        $possibleKeys = $plan['possible_keys'] ?? '';

        self::assertIsString($possibleKeys);
        self::assertStringContainsString(
            'ix_referrals_status_created',
            $possibleKeys,
            'The queue no longer has an index to filter on.',
        );
    }

    private function seed(): void
    {
        $users = new PdoUserRepository($this->pdo());
        $userId = $users->create('queue-probe@example.test', 'Queue Probe', Role::Staff, str_repeat('0', 60));

        $insert = $this->pdo()->prepare(
            "INSERT INTO referrals
                (reference, status, version, patient_name, patient_phone, procedure_code,
                 requested_urgency, submitted_by_user_id, submitted_on_behalf_of, created_at, updated_at)
             VALUES
                (:reference, 'submitted', 1, 'Queue Probe', '+251911000000', 'image_guided_fnac',
                 'routine', :user, 'referrer', :created_at, :updated_at)"
        );

        for ($index = 0; $index < self::ROWS; $index++) {
            $createdAt = gmdate('Y-m-d H:i:s', time() - $index * 60);

            $insert->execute([
                'reference' => sprintf('RF-QUEUE-%04d', $index),
                'user' => $userId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('No database connection.');
        }

        return $this->pdo;
    }
}
