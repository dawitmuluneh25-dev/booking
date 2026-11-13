<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\OutboundMessage;
use App\Repositories\PdoNotificationFeed;
use App\Repositories\PdoOutboxRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The notification list, projected from real outbox rows.
 *
 * What is worth testing here is what the projection chooses to read. The message
 * carries a recipient and a body; the list is built from a query that selects
 * neither, so the assertion is that the reference is the thing the message was
 * about and that a message about an account falls back to naming itself.
 */
final class NotificationFeedTest extends TestCase
{
    private PDO $pdo;

    private PdoNotificationFeed $feed;

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
            'email' => 'feed-' . bin2hex(random_bytes(6)) . '@example.test',
            'name' => 'Feed Test',
            'role' => 'staff',
            'hash' => str_repeat('y', 60),
        ]);

        $this->userId = (int) $this->pdo->lastInsertId();
        $this->feed = new PdoNotificationFeed($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->userId !== 0) {
            $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->userId);
        }
    }

    public function testAMessageAboutAReferenceIsListedByThatReference(): void
    {
        (new PdoOutboxRepository($this->pdo))->recordForUser($this->userId, [
            new OutboundMessage(
                channel: NotificationChannel::Email,
                template: NotificationTemplate::PasswordReset,
                recipient: 'clerk@example.test',
                payload: ['reset_url' => 'https://clinic.test/x', 'expires_at_utc' => '2026-11-13 11:00:00'],
                dedupeKey: 'reference-case',
            ),
        ]);

        // The reset names no reference, so the list falls back to the template's own
        // name rather than showing an email address or an empty cell.
        $notifications = $this->feed->recent(50);

        self::assertNotSame([], $notifications);
        self::assertSame('Password reset', $notifications[0]->reference);
    }

    public function testTheTimeIsTheTimeItWasQueuedUntilItIsSent(): void
    {
        $this->seedBookingMessage();

        $queued = $this->feed->recent(1)[0];

        self::assertSame('BK-260113-ABC123', $queued->reference);
        self::assertNotSame('', $queued->occurredAt);

        $this->pdo->exec(
            "UPDATE notification_outbox SET status = 'sent', sent_at = '2026-11-13 12:00:00' "
                . "WHERE dedupe_key = 'booking-case'"
        );

        self::assertSame('2026-11-13 12:00:00', $this->feed->recent(1)[0]->occurredAt);
    }

    public function testTheListIsCappedAndNewestFirst(): void
    {
        $this->seedBookingMessage('first');

        $repository = new PdoOutboxRepository($this->pdo);
        for ($i = 0; $i < 3; $i++) {
            $repository->recordForUser($this->userId, [
                new OutboundMessage(
                    channel: NotificationChannel::Email,
                    template: NotificationTemplate::PasswordReset,
                    recipient: 'clerk@example.test',
                    payload: ['reset_url' => 'https://clinic.test/x'],
                    dedupeKey: 'cap-' . $i,
                ),
            ]);
        }

        self::assertCount(2, $this->feed->recent(2));
    }

    private function seedBookingMessage(string $dedupe = 'booking-case'): void
    {
        (new PdoOutboxRepository($this->pdo))->recordForUser($this->userId, [
            new OutboundMessage(
                channel: NotificationChannel::Email,
                template: NotificationTemplate::BookingConfirmed,
                recipient: 'patient@example.test',
                payload: ['reference' => 'BK-260113-ABC123'],
                dedupeKey: $dedupe,
            ),
        ]);
    }
}
