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

    /**
     * O-3 requires that the provider's identifier is kept. Acceptance is not
     * delivery, but without the identifier there is no way to ask the provider
     * what became of a message — recipient and time is a guess that fails the
     * moment somebody is sent two notifications in an afternoon.
     */
    public function testItKeepsTheProviderMessageIdOnAcceptance(): void
    {
        $outbox = new InMemoryOutboxRepository();
        $id = $outbox->enqueue($this->message());

        $transport = new RecordingTransport();
        $transport->providerMessageId = '<202611111200.abc@brevo>';

        (new OutboxDispatcher($outbox, $transport))->dispatch();

        self::assertSame('<202611111200.abc@brevo>', $outbox->providerMessageIdOf($id));
    }

    public function testATransportWithoutAnIdentifierLeavesItEmpty(): void
    {
        $outbox = new InMemoryOutboxRepository();
        $id = $outbox->enqueue($this->message());

        (new OutboxDispatcher($outbox, new RecordingTransport()))->dispatch();

        self::assertNull($outbox->providerMessageIdOf($id));
        self::assertSame(OutboxStatus::Sent, $outbox->statusOf($id));
    }

    /**
     * A rejected payload is not a transient outage. Retrying it five times over an
     * hour and a half delays nothing, hides the real problem behind a rising attempt
     * count, and dead-letters anyway — so it is dead on the first refusal.
     */
    public function testAPermanentFailureIsDeadImmediatelyWithoutTheRetryLadder(): void
    {
        $outbox = new InMemoryOutboxRepository();
        $id = $outbox->enqueue($this->message());

        $transport = new RecordingTransport();
        $transport->permanentFailuresRemaining = 1;

        $result = (new OutboxDispatcher($outbox, $transport))->dispatch();

        self::assertSame(1, $result['dead']);
        self::assertSame(0, $result['retrying']);
        self::assertSame(0, $result['sent']);
        self::assertSame(OutboxStatus::Dead, $outbox->statusOf($id));
        self::assertCount(0, $transport->sent);
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
