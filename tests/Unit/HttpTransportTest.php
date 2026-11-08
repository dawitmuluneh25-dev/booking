<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\OutboundMessage;
use App\Domain\OutboxStatus;
use App\Notifications\HttpTransport;
use App\Notifications\OutboxDispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FakeHttpClient;
use Tests\Support\InMemoryOutboxRepository;

final class HttpTransportTest extends TestCase
{
    public function testItPostsTheMessageAsJson(): void
    {
        $client = new FakeHttpClient(202);

        (new HttpTransport($client, 'https://bridge.test/send', 'secret-token', 4))->send($this->message());

        self::assertCount(1, $client->requests);
        self::assertSame('https://bridge.test/send', $client->requests[0]['url']);
        self::assertSame(4, $client->requests[0]['timeout']);
        self::assertSame('application/json', $client->requests[0]['headers']['Content-Type']);

        $payload = $client->decoded();
        self::assertSame('sms', $payload['channel']);
        self::assertSame('booking_requested', $payload['template']);
        self::assertSame('+251911000111', $payload['to']);
        self::assertSame('BK-1', $payload['reference']);

        $body = $payload['body'] ?? '';
        self::assertIsString($body);
        self::assertStringContainsString('BK-1', $body);
    }

    public function testItSendsABearerTokenOnlyWhenConfigured(): void
    {
        $withToken = new FakeHttpClient();
        (new HttpTransport($withToken, 'https://bridge.test/send', 'secret-token'))->send($this->message());
        self::assertSame('Bearer secret-token', $withToken->requests[0]['headers']['Authorization']);

        $withoutToken = new FakeHttpClient();
        (new HttpTransport($withoutToken, 'https://bridge.test/send'))->send($this->message());
        self::assertArrayNotHasKey('Authorization', $withoutToken->requests[0]['headers']);
    }

    public function testItTreatsANonSuccessStatusAsAFailure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');

        (new HttpTransport(new FakeHttpClient(500), 'https://bridge.test/send'))->send($this->message());
    }

    public function testItRefusesToSendWithoutAConfiguredUrl(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NOTIFY_HTTP_URL');

        (new HttpTransport(new FakeHttpClient(), ''))->send($this->message());
    }

    public function testTheOutboxMarksAMessageSentOnlyAfterTheGatewayAcceptsIt(): void
    {
        $outbox = new InMemoryOutboxRepository();
        $id = $outbox->enqueue($this->message());

        $result = (new OutboxDispatcher($outbox, new HttpTransport(new FakeHttpClient(202), 'https://x.test')))
            ->dispatch();

        self::assertSame(1, $result['sent']);
        self::assertSame(OutboxStatus::Sent, $outbox->statusOf($id));
    }

    public function testAGatewayRejectionSchedulesARetry(): void
    {
        $outbox = new InMemoryOutboxRepository();
        $id = $outbox->enqueue($this->message());

        $result = (new OutboxDispatcher($outbox, new HttpTransport(new FakeHttpClient(429), 'https://x.test')))
            ->dispatch();

        self::assertSame(1, $result['retrying']);
        self::assertSame(OutboxStatus::Failed, $outbox->statusOf($id));
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
