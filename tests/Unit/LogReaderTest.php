<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LogReader;
use PHPUnit\Framework\TestCase;

final class LogReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logs-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testRecentReturnsOnlyMatchingLevelsNewestFirst(): void
    {
        $this->write('app-2099-01-01.log', [
            ['ts' => '2099-01-01T10:00:00.000+00:00', 'level' => 'info', 'message' => 'started'],
            ['ts' => '2099-01-01T11:00:00.000+00:00', 'level' => 'error', 'message' => 'first failure'],
            ['ts' => '2099-01-01T12:00:00.000+00:00', 'level' => 'error', 'message' => 'second failure'],
        ]);

        $records = (new LogReader($this->directory))->recent('error', 10);

        self::assertCount(2, $records);
        self::assertSame('second failure', $records[0]->message);
        self::assertSame('first failure', $records[1]->message);
    }

    public function testRecentRespectsTheLimit(): void
    {
        $this->write('app-2099-01-02.log', [
            ['ts' => '1', 'level' => 'error', 'message' => 'a'],
            ['ts' => '2', 'level' => 'error', 'message' => 'b'],
            ['ts' => '3', 'level' => 'error', 'message' => 'c'],
        ]);

        self::assertCount(2, (new LogReader($this->directory))->recent('error', 2));
    }

    public function testMalformedLinesAreSkipped(): void
    {
        file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . 'app-2099-01-03.log',
            "not json\n{\"level\":\"error\",\"message\":\"kept\"}\n",
        );

        $records = (new LogReader($this->directory))->recent('debug', 10);

        self::assertCount(1, $records);
        self::assertSame('kept', $records[0]->message);
    }

    public function testContextIsRenderedAsKeyValuePairs(): void
    {
        $this->write('app-2099-01-04.log', [
            [
                'ts' => '2099-01-04T10:00:00.000+00:00',
                'level' => 'error',
                'message' => 'unhandled_exception',
                'context' => ['type' => 'RuntimeException', 'location' => 'src/x.php:1'],
            ],
        ]);

        $record = (new LogReader($this->directory))->recent('error', 1)[0];

        self::assertSame('type=RuntimeException location=src/x.php:1', $record->contextLine());
    }

    /** @param list<array<string, mixed>> $records */
    private function write(string $name, array $records): void
    {
        $lines = '';
        foreach ($records as $record) {
            $lines .= json_encode($record) . PHP_EOL;
        }

        file_put_contents($this->directory . DIRECTORY_SEPARATOR . $name, $lines);
    }
}
