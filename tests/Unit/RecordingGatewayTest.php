<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notifications\Bridge\RecordingGateway;
use PHPUnit\Framework\TestCase;

/**
 * The reference gateway writes the message it was given, with one exception.
 *
 * The exception is the point of the file. Everything else here is a smoke test;
 * the redaction case is the one where a mistake would leave a working reset link
 * in a text file on a bridge host, where nothing expires it and nothing scrubs it.
 */
final class RecordingGatewayTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'record-' . bin2hex(random_bytes(6)) . '.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testItRecordsTheMessageAndReturnsAnIdentifier(): void
    {
        $id = (new RecordingGateway($this->file))->deliver($this->message('booking_confirmed'), 'msg-1');

        self::assertStringStartsWith('rec-', $id);

        $record = $this->onlyRecord();
        self::assertSame('BK-1', $record['reference']);
        self::assertSame('msg-1', $record['idempotency_key']);
        self::assertSame('Booking BK-1 is confirmed.', $record['body']);
    }

    public function testItDoesNotWriteAResetLinkToTheRecordFile(): void
    {
        $message = $this->message('password_reset');
        $message['body'] = 'Use this link to set a new password: https://example.test/reset/abc123';

        (new RecordingGateway($this->file))->deliver($message, 'msg-2');

        $body = $this->onlyRecord()['body'] ?? '';
        $body = is_string($body) ? $body : '';

        self::assertStringNotContainsString('abc123', $body, 'A live reset link must not reach the record file.');
        self::assertStringContainsString('redacted', $body);
    }

    /** @return array<string, mixed> */
    private function onlyRecord(): array
    {
        $contents = trim((string) file_get_contents($this->file));
        $decoded = json_decode($contents, true);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array{channel: string, template: string, to: string, body: string, reference: string} */
    private function message(string $template): array
    {
        return [
            'channel' => 'sms',
            'template' => $template,
            'to' => '+251911000111',
            'body' => 'Booking BK-1 is confirmed.',
            'reference' => 'BK-1',
        ];
    }
}
