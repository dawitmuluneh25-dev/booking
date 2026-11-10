<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\OutboundMessage;
use App\Domain\OutboxStatus;
use App\Notifications\OutboxDispatcher;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryOutboxRepository;
use Tests\Support\RecordingTransport;

final class OutboxDispatcherTest extends TestCase
{
    public function testItDeliversPendingMessages(): void
    {
        $outbox = new InMemoryOutboxRepository();
        $id = $outbox->enqueue($this->message());
        $transport = new RecordingTransport();

        $result = (new OutboxDispatcher($outbox, $transport))->dispatch();

        self::assertSame(1, $result['sent']);
        self::assertSame(OutboxStatus::Sent, $outbox->statusOf($id));
        self::assertCount(1, $transport->sent);
        self::assertStringContainsString('BK-1', $transport->sent[0]->body(new DateTimeZone('UTC')));
    }

    public function testItSchedulesARetryWhenDeliveryFails(): void
    {
        $outbox = new InMemoryOutboxRepository();
        $id = $outbox->enqueue($this->message());
        $transport = new RecordingTransport();
        $transport->failuresRemaining = 1;

        $result = (new OutboxDispatcher($outbox, $transport))->dispatch();

        self::assertSame(1, $result['retrying']);
        self::assertSame(0, $result['sent']);
        self::assertSame(OutboxStatus::Failed, $outbox->statusOf($id));
    }

    public function testItGivesUpAfterMaxAttempts(): void
    {
        $outbox = new InMemoryOutboxRepository();
        $id = $outbox->enqueue($this->message());
        $outbox->forceAttempts($id, OutboxDispatcher::MAX_ATTEMPTS);

        $transport = new RecordingTransport();
        $transport->failuresRemaining = 1;

        $result = (new OutboxDispatcher($outbox, $transport))->dispatch();

        self::assertSame(1, $result['dead']);
        self::assertSame(OutboxStatus::Dead, $outbox->statusOf($id));
        self::assertCount(0, $transport->sent);
    }

    public function testItSkipsMessagesScheduledForLater(): void
    {
        $outbox = new InMemoryOutboxRepository();
        $outbox->enqueue($this->message(), gmdate('Y-m-d H:i:s', time() + 600));

        $transport = new RecordingTransport();
        $result = (new OutboxDispatcher($outbox, $transport))->dispatch();

        self::assertSame(0, $result['sent']);
        self::assertSame(0, $result['retrying']);
        self::assertCount(0, $transport->sent);
    }

    public function testItRespectsTheBatchLimit(): void
    {
        $outbox = new InMemoryOutboxRepository();
        $outbox->enqueue($this->message());
        $outbox->enqueue($this->message());
        $outbox->enqueue($this->message());

        $result = (new OutboxDispatcher($outbox, new RecordingTransport()))->dispatch(2);

        self::assertSame(2, $result['sent']);
    }

    public function testBackoffGrowsWithAttempts(): void
    {
        self::assertSame(1, OutboxDispatcher::backoffMinutes(1));
        self::assertSame(5, OutboxDispatcher::backoffMinutes(2));
        self::assertSame(15, OutboxDispatcher::backoffMinutes(3));
        self::assertSame(60, OutboxDispatcher::backoffMinutes(4));
        self::assertSame(60, OutboxDispatcher::backoffMinutes(5));
    }

    private function message(): OutboundMessage
    {
        return new OutboundMessage(
            channel: NotificationChannel::Sms,
            template: NotificationTemplate::BookingRequested,
            recipient: '+251911000111',
            payload: ['reference' => 'BK-1', 'scheduled_at' => '2030-01-01 08:00:00'],
        );
    }
}
