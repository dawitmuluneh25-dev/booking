<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\Role;
use App\Repositories\PdoNotificationQueueRepository;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The snapshot the queue-health check reasons about, against the real table.
 *
 * The unit tests prove the rule; this proves the numbers feeding it. Nothing here asserts absolute counts,
 * because the snapshot is an aggregate over whatever the database already holds — this suite runs against
 * a schema that has real queued messages in it. So the assertions are **deltas** and **invariants**, which
 * is also what makes them meaningful: a count that ignores status would still produce the right delta, but
 * a count that included `failed` rows would not.
 *
 * Three defects this targets, none of which would be visible from the command's output afterwards:
 * pending including rows already sent, "oldest" coming from a failed row, and "last sent" reading
 * `updated_at` — which retention redaction also touches, so a stalled queue would look like it had moved
 * on the day retention ran.
 */
final class NotificationQueueSnapshotTest extends TestCase
{
    private const PREFIX = 'test:queue:snapshot:';

    private ?PDO $pdo = null;

    private ?PdoNotificationQueueRepository $repository = null;

    private int $userId = 0;

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

        $this->repository = new PdoNotificationQueueRepository($this->pdo);
        $this->clean();
        $this->userId = $this->insertUser();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    public function testPendingAndFailedCountsMoveByWhatWasInserted(): void
    {
        $before = $this->repository()->snapshot();

        $this->insert('pending-one', 'pending', gmdate('Y-m-d H:i:s', time() - 3600), null);
        $this->insert('pending-two', 'pending', gmdate('Y-m-d H:i:s', time() - 7200), null);
        $this->insert('sent-one', 'sent', gmdate('Y-m-d H:i:s', time() - 9000), gmdate('Y-m-d H:i:s'));
        $this->insert('failed-one', 'failed', gmdate('Y-m-d H:i:s', time() - 9000), null);

        $after = $this->repository()->snapshot();

        self::assertSame($before['pending'] + 2, $after['pending'], 'Sent rows are not pending.');
        self::assertSame($before['failed'] + 1, $after['failed']);
    }

    public function testTheOldestPendingRowIsTheOldestPendingRow(): void
    {
        // Four hundred days old, so it is the earliest pending row in any database this runs against.
        $ancient = gmdate('Y-m-d H:i:s', time() - (400 * 86400));

        $this->insert('ancient', 'pending', $ancient, null);
        // A failed row older still, which must not become "oldest pending".
        $this->insert('ancient-failed', 'failed', gmdate('Y-m-d H:i:s', time() - (500 * 86400)), null);

        $snapshot = $this->repository()->snapshot();

        self::assertSame($ancient, $snapshot['oldestPending']);
    }

    public function testLastSentDoesNotReadUpdatedAt(): void
    {
        $sentAt = gmdate('Y-m-d H:i:s', time() - (6 * 3600));
        $touchedAt = gmdate('Y-m-d H:i:s');

        // Sent six hours ago, touched a moment ago: the shape retention leaves behind when it redacts.
        $this->insert('sent-touched', 'sent', $sentAt, $sentAt, $touchedAt);

        $snapshot = $this->repository()->snapshot();

        self::assertNotSame(
            $touchedAt,
            $snapshot['lastSent'],
            'Reading updated_at would make a stalled queue look active after a retention sweep.',
        );
    }

    public function testTheSnapshotKeepsItsInvariants(): void
    {
        $snapshot = $this->repository()->snapshot();

        self::assertGreaterThanOrEqual(0, $snapshot['pending']);
        self::assertGreaterThanOrEqual(0, $snapshot['failed']);

        if ($snapshot['pending'] === 0) {
            self::assertNull($snapshot['oldestPending'], 'Nothing pending means no oldest.');
        } else {
            self::assertNotNull($snapshot['oldestPending'], 'Something pending must have an age.');
        }
    }

    private function insert(
        string $suffix,
        string $status,
        string $createdAt,
        ?string $sentAt,
        ?string $updatedAt = null,
    ): void {
        $this->query(
            'INSERT INTO notification_outbox
                (user_id, dedupe_key, channel, template, recipient, payload, status, attempts,
                 available_at, sent_at, created_at, updated_at)
             VALUES
                (:user_id, :dedupe_key, :channel, :template, :recipient, :payload, :status, 1,
                 :available_at, :sent_at, :created_at, :updated_at)',
            [
                'user_id' => $this->userId,
                'dedupe_key' => self::PREFIX . $suffix,
                'channel' => 'sms',
                'template' => 'referral_received',
                'recipient' => '[redacted]',
                'payload' => '{"redacted": true}',
                'status' => $status,
                'available_at' => $createdAt,
                'sent_at' => $sentAt,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt ?? $createdAt,
            ],
        );
    }

    private function insertUser(): int
    {
        $this->query(
            'INSERT INTO users
                (name, email, password_hash, role, is_active, auth_version, created_at, updated_at)
             VALUES (:name, :email, :hash, :role, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'name' => 'Queue Snapshot Subject',
                'email' => 'queue-' . bin2hex(random_bytes(4)) . '@example.test',
                'hash' => password_hash('correct horse', PASSWORD_DEFAULT),
                'role' => Role::Admin->value,
            ],
        );

        $id = $this->pdo()->lastInsertId();

        return is_numeric($id) ? (int) $id : 0;
    }

    private function clean(): void
    {
        $this->query('DELETE FROM notification_outbox WHERE dedupe_key LIKE :prefix', [
            'prefix' => self::PREFIX . '%',
        ]);

        if ($this->userId > 0) {
            $this->query('DELETE FROM users WHERE id = :id', ['id' => $this->userId]);
            $this->userId = 0;
        }
    }

    /** @param array<string, mixed> $parameters */
    private function query(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);

        if ($statement === false) {
            self::fail('The statement could not be prepared.');
        }

        $statement->execute($parameters);

        return $statement;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('No database connection: this test skipped itself in setUp().');
        }

        return $this->pdo;
    }

    private function repository(): PdoNotificationQueueRepository
    {
        if ($this->repository === null) {
            self::fail('No repository: this test skipped itself in setUp().');
        }

        return $this->repository;
    }
}
