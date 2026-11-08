<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Phase 2 acceptance: no controller assigns a status, and no controller writes SQL.
 *
 * This is a structural test rather than a behavioural one on purpose. A single
 * forgotten `$booking->status = 'confirmed'` in a controller reintroduces exactly
 * the defect this architecture exists to prevent — rules that live in the routing
 * layer and apply to one screen only — and it would pass every behavioural test
 * that exercises the other screens.
 */
final class ControllerStatusWriteTest extends TestCase
{
    private const FORBIDDEN = [
        '/->status\s*=(?!=)/' => 'assigns a model status directly',
        '/\bUPDATE\s+\w+\s+SET\b/i' => 'writes SQL',
        '/\bINSERT\s+INTO\b/i' => 'writes SQL',
        '/\bDELETE\s+FROM\b/i' => 'writes SQL',
    ];

    public function testControllersDoNotAssignStatus(): void
    {
        $violations = [];

        foreach ($this->controllerFiles() as $path => $source) {
            foreach (self::FORBIDDEN as $pattern => $description) {
                if (preg_match($pattern, $source) === 1) {
                    $violations[] = $path . ' ' . $description;
                }
            }
        }

        self::assertSame([], $violations, "Controllers must delegate status changes to commands:\n"
            . implode("\n", $violations));
    }

    public function testTheGuardActuallySeesControllers(): void
    {
        // A guard that silently scans nothing is worse than no guard.
        self::assertGreaterThan(5, count($this->controllerFiles()));
    }

    /** @return array<string, string> */
    private function controllerFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/src/Controllers';
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $files[$file->getFilename()] = $source === false ? '' : $source;
        }

        return $files;
    }
}
