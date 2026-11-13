<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\OutboundMessage;
use App\Repositories\PdoOutboxRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The outbox against MySQL: an account subject, a delivery receipt, and a body that
 * does not outlive the send.
 *
 * These are the parts a test double cannot prove — a CHECK constraint, a foreign key
 * that removes a message with its account, and an UPDATE that must not touch a row
 * that was never sent.
 */
final class OutboxReceiptTest extends TestCase
{
    private const NOW = '2026-11-13 10:00:00';

    private PDO $pdo;

    private PdoOutboxRepository $outbox;

    private int $userId = 0;

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

        $insert = $this->pdo->prepare(
            'INSERT INTO users (email, name, role, password_hash, is_active, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $insert->execute([
            'email' => 'receipt-' . bin2hex(random_bytes(6)) . '@example.test',
            'name' => 'Receipt Test',
            'role' => 'staff',
            'hash' => str_repeat('x', 60),
        ]);

        $this->userId = (int) $this->pdo->lastInsertId();
        $this->outbox = new PdoOutboxRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->userId !== 0) {
            // The outbox row goes with the account: ON DELETE CASCADE is the reason a
            // pending reset can never be left behind for a deleted user.
            $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->userId);
        }
    }

    public function testAMessageCanNameAnAccountAndIsRemovedWithIt(): void
    {
        $this->outbox->recordForUser($this->userId, [$this->resetMessage()]);

        self::assertSame(1, $this->countRows(), 'The account-subject message was not written.');

        $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->userId);

        self::assertSame(0, $this->countRows(), 'The message outlived the account it was about.');
        $this->userId = 0;
    }

    public function testAMessageWithNoSubjectAtAllIsStillRefused(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo->exec(
            "INSERT INTO notification_outbox
                (dedupe_key, channel, template, recipient, payload, status, attempts,
                 available_at, created_at, updated_at)
             VALUES
                ('no-subject', 'email', 'password_reset', 'x@example.test', '{}', 'pending', 0,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
    }

    public function testAReceiptIsRecordedAgainstASentMessage(): void
    {
        $this->outbox->recordForUser($this->userId, [$this->resetMessage()]);
        $this->markSent('provider-1');

        self::assertTrue($this->outbox->markDelivered('provider-1', '2026-11-13 10:05:00'));
        self::assertSame('2026-11-13 10:05:00', $this->deliveredAt());

        // A provider retries its callback. The moment of delivery does not move.
        self::assertTrue($this->outbox->markDelivered('provider-1', '2026-11-13 11:00:00'));
        self::assertSame('2026-11-13 10:05:00', $this->deliveredAt());
    }

    public function testAReceiptForAMessageWeNeverSentIsRefused(): void
    {
        $this->outbox->recordForUser($this->userId, [$this->resetMessage()]);

        // The row is queued, not sent: no provider has accepted it, so nothing can
        // have been delivered.
        $this->markProviderIdOnly('provider-queued');

        self::assertFalse($this->outbox->markDelivered('provider-queued', '2026-11-13 10:05:00'));
        self::assertNull($this->deliveredAt());

        self::assertFalse($this->outbox->markDelivered('provider-never-seen', '2026-11-13 10:05:00'));
        self::assertNull($this->deliveredAt());
    }

    public function testScrubbingEmptiesASentBodyAndLeavesAQueuedOneAlone(): void
    {
        $this->outbox->recordForUser($this->userId, [$this->resetMessage()]);

        $id = $this->rowId();
        $this->outbox->scrubPayload($id);

        self::assertStringContainsString('reset_url', $this->payloadOf($id), 'A queued body was scrubbed.');

        $this->markSent('provider-2');
        $this->outbox->scrubPayload($id);

        self::assertSame('{}', $this->payloadOf($id), 'The sent body was not emptied.');
    }

    private function resetMessage(): OutboundMessage
    {
        return new OutboundMessage(
            channel: NotificationChannel::Email,
            template: NotificationTemplate::PasswordReset,
            recipient: 'clerk@example.test',
            payload: [
                'reset_url' => 'https://clinic.test/password/reset/' . str_repeat('b', 64),
                'expires_at_utc' => '2026-11-13 11:00:00',
            ],
            dedupeKey: 'password-reset:' . $this->userId . ':' . bin2hex(random_bytes(6)),
        );
    }

    private function markSent(string $providerMessageId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE notification_outbox
             SET status = 'sent', sent_at = :now, provider_message_id = :provider
             WHERE user_id = :user_id"
        );
        $statement->execute([
            'now' => self::NOW,
            'provider' => $providerMessageId,
            'user_id' => $this->userId,
        ]);
    }

    /** Give the row a provider id without claiming it was sent. */
    private function markProviderIdOnly(string $providerMessageId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE notification_outbox SET provider_message_id = :provider WHERE user_id = :user_id'
        );
        $statement->execute(['provider' => $providerMessageId, 'user_id' => $this->userId]);
    }

    private function rowId(): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM notification_outbox WHERE user_id = :user_id LIMIT 1');
        $statement->execute(['user_id' => $this->userId]);

        return (int) $statement->fetchColumn();
    }

    private function payloadOf(int $id): string
    {
        $statement = $this->pdo->prepare('SELECT payload FROM notification_outbox WHERE id = :id');
        $statement->execute(['id' => $id]);

        $payload = $statement->fetchColumn();

        return is_string($payload) ? $payload : '';
    }

    private function deliveredAt(): ?string
    {
        $statement = $this->pdo->prepare('SELECT delivered_at FROM notification_outbox WHERE user_id = :user_id');
        $statement->execute(['user_id' => $this->userId]);

        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function countRows(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM notification_outbox WHERE user_id = :user_id');
        $statement->execute(['user_id' => $this->userId]);

        return (int) $statement->fetchColumn();
    }
}
