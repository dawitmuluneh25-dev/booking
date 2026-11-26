<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notifications\Bridge\BridgeDeliveryFailed;
use App\Notifications\Bridge\BridgeGateway;
use App\Notifications\Bridge\NotificationBridge;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingGatewaySpy;

/**
 * The contract the `http` driver depends on, tested where it is cheap.
 *
 * Every case here is a way the application could be told something untrue: a
 * message accepted without a token, a malformed message accepted, or the same
 * outbox row delivered twice because a dispatcher retried after a crash. Each one
 * ends with the outbox recording a send that a patient cannot corroborate.
 */
final class NotificationBridgeTest extends TestCase
{
    private string $stateDirectory;

    protected function setUp(): void
    {
        $this->stateDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bridge-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->stateDirectory);
    }

    public function testARequestWithoutTheTokenIsRefused(): void
    {
        $gateway = new RecordingGatewaySpy();
        $result = $this->bridge($gateway)->handle([], $this->payload());

        self::assertSame(401, $result->status);
        self::assertSame([], $gateway->delivered, 'Nothing may be sent before the caller is identified.');
    }

    public function testAWrongTokenIsRefused(): void
    {
        $gateway = new RecordingGatewaySpy();
        $result = $this->bridge($gateway)->handle(['Authorization' => 'Bearer guess'], $this->payload());

        self::assertSame(401, $result->status);
        self::assertSame([], $gateway->delivered);
    }

    public function testAnUnconfiguredBridgeRefusesRatherThanAcceptingEverything(): void
    {
        $gateway = new RecordingGatewaySpy();
        $bridge = new NotificationBridge($gateway, '', $this->stateDirectory);

        $result = $bridge->handle(['Authorization' => 'Bearer '], $this->payload());

        self::assertSame(503, $result->status);
        self::assertSame([], $gateway->delivered);
    }

    public function testAMalformedBodyIsRejected(): void
    {
        $gateway = new RecordingGatewaySpy();
        $result = $this->bridge($gateway)->handle($this->auth(), 'not json');

        self::assertSame(400, $result->status);
        self::assertSame([], $gateway->delivered);
    }

    public function testARecipientThatIsNotE164IsRejected(): void
    {
        $gateway = new RecordingGatewaySpy();
        $result = $this->bridge($gateway)->handle(
            $this->auth(),
            (string) json_encode([
                'channel' => 'sms',
                'template' => 'booking_confirmed',
                'to' => '0911000111',
                'body' => 'x',
            ]),
        );

        self::assertSame(422, $result->status);
        self::assertStringContainsString('E.164', (string) $result->body['error']);
        self::assertSame([], $gateway->delivered);
    }

    public function testAnAcceptedMessageReturnsTheProviderIdentifier(): void
    {
        $gateway = new RecordingGatewaySpy('sms-42');
        $result = $this->bridge($gateway)->handle($this->auth(), $this->payload());

        self::assertSame(202, $result->status);
        self::assertSame('sms-42', $result->body['messageId']);
        self::assertSame('accepted', $result->body['status']);
        self::assertCount(1, $gateway->delivered);
    }

    public function testTheSameIdempotencyKeyIsDeliveredOnlyOnce(): void
    {
        $gateway = new RecordingGatewaySpy('sms-42');
        $bridge = $this->bridge($gateway);

        $first = $bridge->handle($this->auth(), $this->payload());
        $second = $bridge->handle($this->auth(), $this->payload());

        self::assertSame(202, $first->status);
        self::assertSame(200, $second->status);
        self::assertSame('duplicate', $second->body['status']);
        self::assertSame($first->body['messageId'], $second->body['messageId']);
        self::assertCount(1, $gateway->delivered, 'A retried row is a repeat, not a second message.');
    }

    public function testTheKeyIsReadFromTheHeaderWhenTheBodyOmitsIt(): void
    {
        $gateway = new RecordingGatewaySpy();
        $bridge = $this->bridge($gateway);
        $body = (string) json_encode([
            'channel' => 'sms',
            'template' => 'booking_confirmed',
            'to' => '+251911000111',
            'body' => 'Booking BK-1 is confirmed.',
            'reference' => 'BK-1',
        ]);

        $bridge->handle($this->auth() + ['Idempotency-Key' => 'msg-7'], $body);
        $second = $bridge->handle($this->auth() + ['Idempotency-Key' => 'msg-7'], $body);

        self::assertSame(200, $second->status);
        self::assertCount(1, $gateway->delivered);
    }

    public function testWithoutAKeyEveryRequestIsDelivered(): void
    {
        $gateway = new RecordingGatewaySpy();
        $bridge = $this->bridge($gateway);
        $body = (string) json_encode([
            'channel' => 'sms',
            'template' => 'booking_confirmed',
            'to' => '+251911000111',
            'body' => 'Booking BK-1 is confirmed.',
            'reference' => 'BK-1',
        ]);

        $bridge->handle($this->auth(), $body);
        $bridge->handle($this->auth(), $body);

        self::assertCount(2, $gateway->delivered);
    }

    public function testAProviderFailureIsReportedRatherThanSwallowed(): void
    {
        $gateway = new RecordingGatewaySpy();
        $gateway->failWith('provider rejected the sender id');

        $result = $this->bridge($gateway)->handle($this->auth(), $this->payload());

        self::assertSame(502, $result->status);
        self::assertStringContainsString('sender id', (string) $result->body['error']);
    }

    public function testAFailedSendIsNotRememberedAsDelivered(): void
    {
        $gateway = new RecordingGatewaySpy();
        $gateway->failWith('provider timeout');
        $bridge = $this->bridge($gateway);

        self::assertSame(502, $bridge->handle($this->auth(), $this->payload())->status);

        $gateway->failure = null;
        $retry = $bridge->handle($this->auth(), $this->payload());

        self::assertSame(202, $retry->status, 'A failure must leave the key free to try again.');
        self::assertCount(2, $gateway->attempts, 'One failed attempt and one success.');
        self::assertCount(1, $gateway->delivered);
    }

    public function testAnEmailRecipientIsValidatedAsAnAddress(): void
    {
        $gateway = new RecordingGatewaySpy();
        $result = $this->bridge($gateway)->handle(
            $this->auth(),
            (string) json_encode([
                'channel' => 'email',
                'template' => 'password_reset',
                'to' => 'not-an-address',
                'body' => 'x',
            ]),
        );

        self::assertSame(422, $result->status);
    }

    private function bridge(BridgeGateway $gateway): NotificationBridge
    {
        return new NotificationBridge($gateway, 'shared-secret', $this->stateDirectory);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer shared-secret'];
    }

    private function payload(): string
    {
        $encoded = json_encode([
            'channel' => 'sms',
            'template' => 'booking_confirmed',
            'to' => '+251911000111',
            'body' => 'Booking BK-1 is confirmed for Tue 6 Jan 2027, 09:00 EAT.',
            'reference' => 'BK-1',
            'idempotency_key' => 'msg-1',
        ]);

        self::assertIsString($encoded);

        return $encoded;
    }
}
