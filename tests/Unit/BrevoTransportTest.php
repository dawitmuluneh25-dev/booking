<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\OutboundMessage;
use App\Notifications\BrevoTransport;
use App\Notifications\PermanentNotificationFailure;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FakeHttpClient;

/**
 * The transactional email adapter approved by O-3-DEC-01.
 *
 * These tests are about what leaves the building and what is kept afterwards:
 * the endpoint, the credential header, the payload, the identifier that comes
 * back, and — just as importantly — the fact that a rejected message does not
 * write a patient's address into an error column.
 */
final class BrevoTransportTest extends TestCase
{
    public function testItPostsToTheTransactionalEndpoint(): void
    {
        $client = new FakeHttpClient(201, '{"messageId":"<202611111200.abc@brevo>"}');

        $id = $this->transport($client)->send($this->message());

        self::assertCount(1, $client->requests);
        self::assertSame(BrevoTransport::ENDPOINT, $client->requests[0]['url']);
        self::assertSame('https://api.brevo.com/v3/smtp/email', $client->requests[0]['url']);
        self::assertSame('<202611111200.abc@brevo>', $id);
    }

    public function testItAuthenticatesWithTheApiKeyHeaderAndNoCredentialInTheUrl(): void
    {
        $client = new FakeHttpClient();

        $this->transport($client)->send($this->message());

        self::assertSame('test-api-key', $client->requests[0]['headers']['api-key']);
        self::assertStringNotContainsString('test-api-key', $client->requests[0]['url']);
    }

    public function testItSendsTheApprovedSenderAndRecipient(): void
    {
        $client = new FakeHttpClient();

        $this->transport($client)->send($this->message());

        $payload = $client->decoded();

        self::assertSame(['email' => 'notifications@hakimerad.com', 'name' => 'HakimeRAD'], $payload['sender']);

        $to = $payload['to'] ?? [];
        self::assertIsArray($to);

        $first = $to[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('patient@example.test', $first['email'] ?? null);
    }

    public function testItRefusesANonEmailChannelWithoutCallingTheProvider(): void
    {
        $client = new FakeHttpClient();

        try {
            $this->transport($client)->send($this->message(NotificationChannel::Sms));
            self::fail('An SMS message must not be handed to an email provider.');
        } catch (PermanentNotificationFailure $exception) {
            self::assertStringContainsString('sms', $exception->getMessage());
        }

        self::assertSame([], $client->requests);
    }

    public function testItDoesNotSendWhenTheCredentialIsMissing(): void
    {
        $client = new FakeHttpClient();

        $this->expectException(PermanentNotificationFailure::class);

        try {
            (new BrevoTransport($client, '', 'notifications@hakimerad.com'))->send($this->message());
        } finally {
            self::assertSame([], $client->requests, 'Nothing may be attempted without a credential.');
        }
    }

    public function testItDoesNotSendWhenTheSenderIsNotAnAddress(): void
    {
        $client = new FakeHttpClient();

        $this->expectException(PermanentNotificationFailure::class);

        (new BrevoTransport($client, 'key', 'notifications'))->send($this->message());
    }

    public function testARejectionIsPermanentAndKeepsOnlyTheProviderCode(): void
    {
        $client = new FakeHttpClient(
            400,
            '{"code":"invalid_parameter","message":"Invalid email: patient@example.test"}',
        );

        try {
            $this->transport($client)->send($this->message());
            self::fail('A 4xx rejection must not be treated as a send.');
        } catch (PermanentNotificationFailure $exception) {
            $message = $exception->getMessage();

            self::assertStringContainsString('invalid_parameter', $message);
            self::assertStringNotContainsString('patient@example.test', $message);
            self::assertStringNotContainsString('test-api-key', $message);
        }
    }

    public function testAServerErrorIsTransientSoTheOutboxRetriesIt(): void
    {
        $client = new FakeHttpClient(503, 'upstream unavailable');

        try {
            $this->transport($client)->send($this->message());
            self::fail('A 5xx must be reported as a failure.');
        } catch (PermanentNotificationFailure $exception) {
            self::fail('A 5xx is not permanent: ' . $exception->getMessage());
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('503', $exception->getMessage());
        }
    }

    public function testAnUnactivatedTransportRefusesToSend(): void
    {
        $client = new FakeHttpClient();

        $transport = new BrevoTransport(
            $client,
            'key',
            'notifications@hakimerad.com',
            'HakimeRAD',
            BrevoTransport::ENDPOINT,
            10,
            new DateTimeZone('Africa/Addis_Ababa'),
            false,
        );

        try {
            $transport->send($this->message());
            self::fail('Production sending is not yet authorized by O-3.');
        } catch (PermanentNotificationFailure $exception) {
            self::assertStringContainsString('not activated', $exception->getMessage());
        }

        self::assertSame([], $client->requests);
    }

    private function transport(FakeHttpClient $client): BrevoTransport
    {
        return new BrevoTransport(
            $client,
            'test-api-key',
            'notifications@hakimerad.com',
            'HakimeRAD',
            BrevoTransport::ENDPOINT,
            10,
            new DateTimeZone('Africa/Addis_Ababa'),
        );
    }

    private function message(NotificationChannel $channel = NotificationChannel::Email): OutboundMessage
    {
        return new OutboundMessage(
            $channel,
            NotificationTemplate::AppointmentBooked,
            'patient@example.test',
            [
                'reference' => 'BK-261111-ABC123',
                'scheduled_at_utc' => '2026-11-12 06:00:00',
            ],
        );
    }
}
