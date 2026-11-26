<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\OutboundMessage;
use App\Notifications\CurlHttpClient;
use App\Notifications\HttpTransport;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The `http` driver, end to end, over a real socket.
 *
 * Every other test in this path uses FakeHttpClient, which proves what the
 * transport *decides* and nothing about whether a request leaves the process.
 * CurlHttpClient builds headers, honours a timeout and reads a response body; the
 * reference bridge parses those headers and answers with a message id. Neither
 * side can be checked by a fake standing in for the other, and a contract that is
 * only ever exercised against a stub is a contract nobody has run.
 *
 * So this starts the reference bridge on a loopback port and posts to it with the
 * real client. It is the shape of the deployment: same transport, same JSON, same
 * bearer token, same idempotency key.
 */
final class HttpTransportRoundTripTest extends TestCase
{
    private const TOKEN = 'round-trip-secret';

    private string $directory;

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bridge-rt-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0770, true));

        $this->port = $this->freePort();
        $this->startBridge();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory . DIRECTORY_SEPARATOR . 'state');
        @rmdir($this->directory);
    }

    public function testTheMessageReachesTheBridgeAndTheProviderIdentifierComesBack(): void
    {
        $messageId = (new HttpTransport(
            new CurlHttpClient(),
            $this->endpoint(),
            self::TOKEN,
            5,
            new DateTimeZone('Africa/Addis_Ababa'),
        ))->send($this->message(9_001));

        self::assertNotNull($messageId);
        self::assertStringStartsWith('rec-', $messageId);

        $records = $this->records();

        self::assertCount(1, $records);
        self::assertSame('BK-RT-1', $records[0]['reference']);
        self::assertSame('sms', $records[0]['channel']);
        self::assertSame('msg-9001', $records[0]['idempotency_key']);

        // The rendered body, not the UTC instant: a patient reading 09:00 when the
        // department means 12:00 has been given the wrong appointment.
        $body = $records[0]['body'] ?? '';
        self::assertIsString($body);
        self::assertStringContainsString('EAT', $body);
    }

    public function testARetriedRowIsAcceptedByTheBridgeWithoutASecondMessage(): void
    {
        $transport = new HttpTransport(
            new CurlHttpClient(),
            $this->endpoint(),
            self::TOKEN,
            5,
            new DateTimeZone('Africa/Addis_Ababa'),
        );

        $first = $transport->send($this->message(9_002));
        $second = $transport->send($this->message(9_002));

        self::assertSame($first, $second, 'A repeat answers with the identifier of the first send.');
        self::assertCount(1, $this->records(), 'The bridge delivered one message, not two.');
    }

    public function testAnUnauthenticatedCallIsRefusedOverTheWire(): void
    {
        $transport = new HttpTransport(
            new CurlHttpClient(),
            $this->endpoint(),
            'wrong-token',
            5,
            new DateTimeZone('Africa/Addis_Ababa'),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');

        $transport->send($this->message(9_003));
    }

    private function endpoint(): string
    {
        return 'http://127.0.0.1:' . $this->port . '/send';
    }

    private function message(int $id): OutboundMessage
    {
        return new OutboundMessage(
            channel: NotificationChannel::Sms,
            template: NotificationTemplate::BookingConfirmed,
            recipient: '+251911000111',
            payload: ['reference' => 'BK-RT-1', 'scheduled_at_utc' => '2030-01-01 06:00:00'],
            id: $id,
        );
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'accepted.jsonl';
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $records = [];

        foreach (explode("\n", trim($contents)) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        /** @var list<array<string, mixed>> $records */
        return $records;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            self::fail('Cannot reserve a loopback port: ' . $error);
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $position = strrpos($name, ':');
        self::assertNotFalse($position);

        return (int) substr($name, $position + 1);
    }

    private function startBridge(): void
    {
        $root = dirname(__DIR__, 2);
        $log = $this->directory . DIRECTORY_SEPARATOR . 'server.log';

        $environment = getenv();
        $environment['BRIDGE_TOKEN'] = self::TOKEN;
        $environment['BRIDGE_STATE_DIR'] = $this->directory . DIRECTORY_SEPARATOR . 'state';
        $environment['BRIDGE_RECORD_FILE'] = $this->directory . DIRECTORY_SEPARATOR . 'accepted.jsonl';
        $environment['BRIDGE_GATEWAY'] = 'recording';

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, $root . '/tools/notify-bridge/index.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $root,
            $environment,
        );

        if (!is_resource($server)) {
            self::fail('Cannot start the reference bridge.');
        }

        $this->server = $server;

        // Poll rather than sleep: the server needs a moment to bind, and a fixed
        // sleep is either flaky or slow depending on the machine.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            $status = proc_get_status($server);

            if ($status['running'] === false) {
                self::fail('The reference bridge exited: ' . (string) @file_get_contents($log));
            }

            usleep(50_000);
        }

        self::fail('The reference bridge did not accept a connection: ' . (string) @file_get_contents($log));
    }
}
