<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * `phpcs.xml` is a list of intentions, and it can be wrong in a way that produces no
 * error at all: a `<file>` entry for a path without a `.php` extension is accepted,
 * satisfied-looking, and never linted. That is how 1,219 lines of `bin/console` were
 * declared covered and were not — found by planting a 160-character line in them and
 * watching the gate pass.
 *
 * This test holds the configuration to two facts a machine can check: every listed path
 * exists, and every PHP file under `bin/` is actually reachable by the gate.
 */
final class StyleGateCoverageTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private static function configuredPaths(): array
    {
        $xml = simplexml_load_file(self::root() . '/phpcs.xml');
        self::assertNotFalse($xml, 'phpcs.xml is not readable XML');

        $paths = [];
        foreach ($xml->file as $file) {
            $paths[] = trim((string) $file);
        }

        return $paths;
    }

    public function testEveryConfiguredPathExists(): void
    {
        self::assertNotEmpty(self::configuredPaths());

        foreach (self::configuredPaths() as $path) {
            self::assertFileExists(self::root() . '/' . $path, "phpcs.xml lists $path, which does not exist");
        }
    }

    /**
     * The trap this file exists for. `bin/console` sat here for a day looking checked.
     */
    public function testNoConfiguredPathIsAFileTheGateWouldSkip(): void
    {
        foreach (self::configuredPaths() as $path) {
            $full = self::root() . '/' . $path;

            if (!is_file($full)) {
                continue;
            }

            self::assertSame(
                'php',
                strtolower(pathinfo($full, PATHINFO_EXTENSION)),
                "$path is listed in phpcs.xml, but phpcs takes a file's extension from its name and "
                . 'will skip this one without reporting anything',
            );
        }
    }

    public function testEveryPhpScriptInBinIsCovered(): void
    {
        $configured = self::configuredPaths();
        $scripts = glob(self::root() . '/bin/*.php') ?: [];

        self::assertNotEmpty($scripts, 'no PHP scripts found in bin/, which cannot be right');

        foreach ($scripts as $script) {
            $relative = 'bin/' . basename($script);

            self::assertContains(
                $relative,
                $configured,
                "$relative is not listed in phpcs.xml, so nothing lints it",
            );
        }
    }
}
