<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Application;
use App\Http\RouteAccess;
use PHPUnit\Framework\TestCase;

/**
 * README §12 is a promise to whoever reads it next: this path exists, and this is
 * what it demands before it answers. The route manifest is the truth it promises
 * about. Nothing held the two together, so they drifted — twice in one week, both
 * times in the same direction, a staff-only screen documented as public.
 *
 * This test parses the table and checks the three things a reader would rely on:
 *
 *   1. the path is registered — a documented URL that 404s is worse than an
 *      undocumented one, because it is trusted;
 *   2. the method is registered on that path;
 *   3. the access description matches the declaration: a backticked permission is
 *      the permission, "public" means reachable without a session, "any
 *      signed-in" means authentication without a permission.
 *
 * What it deliberately does not do is demand completeness. The table is curated —
 * it documents the routes somebody needs to know about, not all 103 — and a test
 * that forced 103 rows would be answered by padding the table, not by documenting
 * anything. Prose after the structured part of the access cell is free text and is
 * not compared; only the claim a reader could act on is.
 *
 * Placeholders are compared by position, not by name: `{id}` and `{reference}`
 * both normalise to `{}`. A renamed parameter is not a lie about the route.
 */
final class DocumentedRoutesTest extends TestCase
{
    /** Fewer rows than this means the table moved or the parser stopped matching. */
    private const MINIMUM_ROWS = 25;

    public function testEveryDocumentedPathIsARegisteredRoute(): void
    {
        $manifest = self::manifestIndex();
        $problems = [];

        foreach (self::documentedRows() as $row) {
            foreach (self::claims($row) as [$method, $path]) {
                $key = $method . ' ' . self::normalise($path);

                if (!isset($manifest[$key])) {
                    $problems[] = $method . ' ' . $path . ' is documented but no route serves it';
                }
            }
        }

        self::assertSame([], $problems, self::report($problems));
    }

    public function testEveryDocumentedAccessMatchesTheDeclaration(): void
    {
        $manifest = self::manifestIndex();
        $problems = [];

        foreach (self::documentedRows() as $row) {
            $expected = self::expectedAccess($row['access'], $row['line']);

            foreach (self::claims($row) as [$method, $path]) {
                $key = $method . ' ' . self::normalise($path);

                if (!isset($manifest[$key])) {
                    // Reported by the path test; repeating it here would bury the
                    // access differences this test exists to find.
                    continue;
                }

                $actual = $manifest[$key];

                if (self::kindOf($actual) !== $expected['kind']) {
                    $problems[] = $method . ' ' . $path . ' is documented ' . $expected['kind']
                        . ' but declared ' . self::kindOf($actual);

                    continue;
                }

                if ($expected['kind'] === 'permission' && $actual->permission?->value !== $expected['value']) {
                    $requires = $actual->permission === null ? 'nothing' : $actual->permission->value;

                    $problems[] = $method . ' ' . $path . ' is documented ' . $expected['value']
                        . ' but requires ' . $requires;
                }
            }
        }

        self::assertSame([], $problems, self::report($problems));
    }

    /**
     * The table is the last thing anybody updates, so the cheapest failure is the
     * one where it is silently not parsed at all and both tests above pass over an
     * empty list. This makes that a failure instead.
     */
    public function testTheTableIsStillThereToBeChecked(): void
    {
        $rows = self::documentedRows();

        $paths = 0;
        foreach ($rows as $row) {
            $paths += count($row['paths']);
        }

        self::assertGreaterThanOrEqual(
            self::MINIMUM_ROWS,
            count($rows),
            'README §12 parsed to ' . count($rows) . ' rows; the table has moved, changed its columns, '
            . 'or lost its "## 12. Routes" heading.',
        );

        self::assertGreaterThanOrEqual(
            self::MINIMUM_ROWS,
            $paths,
            'README §12 parsed to ' . $paths . ' backticked paths; the Path column has changed shape.',
        );
    }

    /**
     * @return list<array{method: string, paths: list<string>, access: string, line: string}>
     */
    private static function documentedRows(): array
    {
        $file = dirname(__DIR__, 2) . '/README.md';
        self::assertFileExists($file);

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, 'README.md could not be read.');

        $rows = [];
        $inSection = false;

        foreach ($lines as $line) {
            $line = trim($line);

            if (str_starts_with($line, '## 12.')) {
                $inSection = true;
                continue;
            }

            if (!$inSection) {
                continue;
            }

            // The table ends at the next heading of any level. `## 12. Routes` is
            // matched above, so a heading here means the section is over.
            if (str_starts_with($line, '#')) {
                break;
            }

            if (preg_match('/^\|\s*(GET\/POST|GET|POST)\s*\|(.*)\|(.*)\|$/', $line, $matches) !== 1) {
                continue;
            }

            preg_match_all('/`([^`]+)`/', $matches[2], $tokens);

            $paths = [];
            foreach ($tokens[1] as $token) {
                if (!str_starts_with($token, '/')) {
                    self::fail(
                        'README §12 contains a path that is not absolute: "' . $token . '". Write the '
                        . 'whole path, so a reader does not have to reconstruct it: ' . $line,
                    );
                }

                $paths[] = $token;
            }

            self::assertNotSame([], $paths, 'README §12 row has no backticked path: ' . $line);

            $rows[] = [
                'method' => $matches[1],
                'paths' => $paths,
                'access' => trim($matches[3]),
                'line' => $line,
            ];
        }

        return $rows;
    }

    /**
     * @return array{kind: string, value: string|null}
     */
    private static function expectedAccess(string $cell, string $row): array
    {
        if (preg_match('/`([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)`/', $cell, $matches) === 1) {
            return ['kind' => 'permission', 'value' => $matches[1]];
        }

        if (preg_match('/\bpublic\b/i', $cell) === 1) {
            return ['kind' => 'public', 'value' => null];
        }

        if (preg_match('/any signed-in/i', $cell) === 1) {
            return ['kind' => 'authenticated', 'value' => null];
        }

        self::fail(
            'README §12 has an access description this test cannot read: "' . $cell . '". Use a '
            . 'backticked permission, the word "public", or "any signed-in" — so the claim stays '
            . 'checkable: ' . $row,
        );
    }

    private static function kindOf(RouteAccess $access): string
    {
        if ($access->isPublic) {
            return 'public';
        }

        return $access->permission !== null ? 'permission' : 'authenticated';
    }

    /** @return list<string> */
    private static function methods(string $cell): array
    {
        return $cell === 'GET/POST' ? ['GET', 'POST'] : [$cell];
    }

    /**
     * One row, expanded into the individual method/path pairs it claims.
     *
     * @param array{method: string, paths: list<string>, access: string, line: string} $row
     * @return list<array{0: string, 1: string}>
     */
    private static function claims(array $row): array
    {
        $claims = [];

        foreach (self::methods($row['method']) as $method) {
            foreach ($row['paths'] as $path) {
                $claims[] = [$method, $path];
            }
        }

        return $claims;
    }

    /** @param list<string> $problems */
    private static function report(array $problems): string
    {
        if ($problems === []) {
            return '';
        }

        return 'README §12 and the route manifest disagree in ' . count($problems) . " place(s):\n  - "
            . implode("\n  - ", $problems)
            . "\nCorrect the table — or, if the code is what is wrong, the manifest. Do not delete the row.";
    }

    /** @return array<string, RouteAccess> */
    private static function manifestIndex(): array
    {
        $app = Application::boot(dirname(__DIR__, 2));
        $index = [];

        foreach ($app->router()->manifest() as $route) {
            $index[$route['method'] . ' ' . self::normalise($route['pattern'])] = $route['access'];
        }

        return $index;
    }

    private static function normalise(string $path): string
    {
        return (string) preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '{}', $path);
    }
}
