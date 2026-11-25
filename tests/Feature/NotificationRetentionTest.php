<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\Role;
use App\Repositories\PdoNotificationRetentionRepository;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The outbox sweep against the real schema.
 *
 * What a notification retention rule has to get right is not the delete: it is **what survives**. The
 * row must stay, because the outbox is the delivery record; the unique `dedupe_key` must stay, because
 * it is what stops a retried dispatch sending twice; the template, channel, status and timestamps must
 * stay, because they are the accounting. What must not stay is the phone number, the payload that names
 * the procedure and case, and the provider error that sometimes echoes the recipient back.
 *
 * The test about a fresh row is the one that would fail silently in production if it were wrong: a
 * `WHERE` clause that lost its cutoff would retire the whole outbox, and nobody would notice until a
 * patient reported a missing confirmation.
 */
final class NotificationRetentionTest extends TestCase
{
    private const PREFIX = 'test:retention:outbox:';

    private ?PDO $pdo = null;

    private ?PdoNotificationRetentionRepository $retention = null;

    /**
     * `ck_outbox_subject` requires the row to belong to a booking, a referral or a user, so the fixture
     * needs a real subject rather than a bare row. A throwaway user is the cheapest of the three.
     */
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

        $this->retention = new PdoNotificationRetentionRepository($this->pdo);
        $this->clean();
        $this->userId = $this->insertUser();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    public function testOldRowsAreRetiredAndEverythingTheOutboxExistsForSurvives(): void
    {
        $old = $this->insert('old-one', gmdate('Y-m-d H:i:s', time() - (400 * 86400)));
        $cutoff = gmdate('Y-m-d H:i:s', time() - (365 * 86400));

        self::assertSame(1, $this->retention()->eligibleCount($cutoff));
        self::assertSame(1, $this->retention()->redactBefore($cutoff, 10));

        $row = $this->row($old);

        self::assertSame('[redacted]', $row['recipient']);
        self::assertSame('{"redacted": true}', $row['payload']);
        self::assertNull($row['last_error']);
        self::assertNotNull($row['redacted_at']);

        // The accounting survives.
        self::assertSame(self::PREFIX . 'old-one', $row['dedupe_key']);
        self::assertSame('sms', $row['channel']);
        self::assertSame('referral_received', $row['template']);
        self::assertSame('sent', $row['status']);
    }

    public function testARowInsideTheWindowIsLeftAlone(): void
    {
        $fresh = $this->insert('fresh', gmdate('Y-m-d H:i:s', time() - 10));
        $cutoff = gmdate('Y-m-d H:i:s', time() - (365 * 86400));

        self::assertSame(0, $this->retention()->eligibleCount($cutoff));
        self::assertSame(0, $this->retention()->redactBefore($cutoff, 10));

        $row = $this->row($fresh);

        self::assertSame('+251 000 000 999', $row['recipient']);
        self::assertNull($row['redacted_at']);
    }

    public function testASecondSweepDoesNotCountTheSameRowTwice(): void
    {
        $this->insert('old-two', gmdate('Y-m-d H:i:s', time() - (400 * 86400)));
        $cutoff = gmdate('Y-m-d H:i:s', time() - (365 * 86400));

        self::assertSame(1, $this->retention()->redactBefore($cutoff, 10));
        self::assertSame(0, $this->retention()->redactBefore($cutoff, 10), 'redacted_at makes it idempotent.');
    }

    public function testTheRunIsRecordedAgainstTheNotificationColumn(): void
    {
        $this->insert('old-three', gmdate('Y-m-d H:i:s', time() - (400 * 86400)));

        $this->retention()->recordRun(gmdate('Y-m-d'), 1, 'cli:test');

        $row = $this->query(
            'SELECT notifications_redacted FROM retention_runs WHERE actor = :actor ORDER BY id DESC LIMIT 1',
            ['actor' => 'cli:test'],
        )->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        $recorded = $row['notifications_redacted'] ?? null;

        self::assertTrue(is_numeric($recorded) && (int) $recorded === 1);
    }

    private function insert(string $suffix, string $createdAt): int
    {
        $this->query(
            'INSERT INTO notification_outbox
                (user_id, dedupe_key, channel, template, recipient, payload, status, attempts,
                 available_at, last_error, created_at, updated_at)
             VALUES
                (:user_id, :dedupe_key, :channel, :template, :recipient, :payload, :status, 1,
                 :available_at, :last_error, :created_at, :updated_at)',
            [
                'user_id' => $this->userId,
                'dedupe_key' => self::PREFIX . $suffix,
                'channel' => 'sms',
                'template' => 'referral_received',
                'recipient' => '+251 000 000 999',
                'payload' => '{"procedure": "image_guided_fnac", "reference": "RF-TEST-0001"}',
                'status' => 'sent',
                'available_at' => $createdAt,
                'last_error' => 'rejected for +251 000 000 999',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ],
        );

        $id = $this->pdo()->lastInsertId();

        return is_numeric($id) ? (int) $id : 0;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $row = $this->query('SELECT * FROM notification_outbox WHERE id = :id', ['id' => $id])
            ->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        return $row;
    }

    private function insertUser(): int
    {
        $this->query(
            'INSERT INTO users (name, email, password_hash, role, is_active, auth_version, created_at, updated_at)
             VALUES (:name, :email, :hash, :role, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'name' => 'Retention Test Subject',
                'email' => 'retention-' . bin2hex(random_bytes(4)) . '@example.test',
                'hash' => password_hash('correct horse', PASSWORD_DEFAULT),
                'role' => Role::Admin->value,
            ],
        );

        $id = $this->pdo()->lastInsertId();

        return is_numeric($id) ? (int) $id : 0;
    }

    private function clean(): void
    {
        $this->query(
            'DELETE FROM notification_outbox WHERE dedupe_key LIKE :prefix',
            ['prefix' => self::PREFIX . '%'],
        );

        $this->query('DELETE FROM retention_runs WHERE actor = :actor', ['actor' => 'cli:test']);

        // The subject user goes after the outbox rows that reference it.
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

    private function retention(): PdoNotificationRetentionRepository
    {
        if ($this->retention === null) {
            self::fail('No repository: this test skipped itself in setUp().');
        }

        return $this->retention;
    }
}
