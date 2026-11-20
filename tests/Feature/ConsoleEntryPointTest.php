<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * The operator console is split in two: `bin/console` is the name the host calls, and
 * `bin/console.php` is the code. The split exists because `phpcs` takes a file's
 * extension from its name, finds none in `console`, and skips it without a word.
 *
 * Splitting a working entry point is the kind of change that can look fine in a diff and
 * be broken on the host, so these tests do three things: they assert the loader still
 * loads, they run the real entry point, and they fail if the loader starts growing logic
 * of its own — which would quietly move code back out of the gate's sight.
 */
final class ConsoleEntryPointTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testTheEntryPointLoadsTheConsoleAndNothingElse(): void
    {
        $loader = (string) file_get_contents(self::root() . '/bin/console');

        self::assertStringContainsString("require __DIR__ . '/console.php';", $loader);
        self::assertStringNotContainsString('function ', $loader);
        self::assertStringNotContainsString('class ', $loader);
        self::assertStringNotContainsString('Application::boot', $loader);
        self::assertStringNotContainsString('switch', $loader);
    }

    /**
     * A limit rather than a style preference: the loader is the only PHP in the project
     * the style gate cannot read, so its size is the size of the unread surface.
     */
    public function testTheLoaderStaysSmall(): void
    {
        $lines = count(file(self::root() . '/bin/console') ?: []);

        self::assertLessThanOrEqual(40, $lines, 'bin/console is the unlinted part; keep it a loader');
    }

    public function testTheConsoleBodyIsWhereTheGateCanSeeIt(): void
    {
        $body = self::root() . '/bin/console.php';

        self::assertFileExists($body);
        self::assertGreaterThan(1000, count(file($body) ?: []), 'the console body moved, it did not shrink');
        self::assertStringContainsString('phpcs:disable PSR1.Files.SideEffects', (string) file_get_contents($body));
    }

    /**
     * The loader is named `console` because the host depends on that name; this is the
     * cheap proof that the split did not change what an operator sees.
     */
    public function testTheEntryPointStillRuns(): void
    {
        if (!function_exists('exec')) {
            self::markTestSkipped('exec() is unavailable, so the entry point cannot be run here');
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::root() . '/bin/console') . ' help 2>&1';
        $output = [];
        $status = 0;
        exec($command, $output, $status);

        $text = implode("\n", $output);

        self::assertSame(0, $status, 'the console exited non-zero: ' . $text);
        self::assertStringContainsString('Booking console', $text);
        self::assertStringContainsString('migrate:status', $text);
    }
}
