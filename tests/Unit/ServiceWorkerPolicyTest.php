<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The service worker may cache the static shell and nothing else (ADR-006).
 *
 * This is the test the backlog asked for in executable form: adding a page to
 * PRECACHE cannot be done quietly. A cached referral page would survive a sign-out
 * and be shown to the next person at that desk, which is why the precache list is
 * an allowlist of files rather than a pattern.
 */
final class ServiceWorkerPolicyTest extends TestCase
{
    /** Files that cannot contain patient data, and cannot vary per user. */
    private const ALLOWED_PREFIXES = [
        '/assets/',
        '/manifest.webmanifest',
        '/offline.html',
    ];

    public function testEveryPrecachedEntryIsAStaticAsset(): void
    {
        foreach ($this->precacheEntries() as $entry) {
            self::assertTrue(
                $this->isAllowed($entry),
                sprintf('"%s" is not a static asset; patient data must never be precached.', $entry),
            );
        }
    }

    public function testNoPageRouteIsPrecached(): void
    {
        // The specific failure this test exists for: adding '/' because it seemed
        // harmless. The home page is public today and would be the wrong thing to
        // cache the moment it renders anything user-specific.
        foreach ($this->precacheEntries() as $entry) {
            self::assertDoesNotMatchRegularExpression(
                '#^/(referrals|review|admin|bookings|schedule|appointments|urgent|r)/#',
                $entry,
                sprintf('"%s" is a page route and must not be precached.', $entry),
            );

            self::assertNotSame('/', $entry, 'The home page must not be precached.');
        }
    }

    public function testTheWorkerNeverCachesARequestItDidNotList(): void
    {
        $source = $this->source();

        // A runtime write is legitimate, but only for assets: the guard has to come
        // first, and there must be exactly one write site, so a second one cannot be
        // added somewhere the guard does not reach.
        self::assertSame(
            1,
            substr_count($source, 'cache.put('),
            'The worker writes to the cache in more than one place.',
        );

        $guard = strpos($source, "startsWith('/assets/')");
        $write = strpos($source, 'cache.put(');

        self::assertIsInt($guard, 'The assets-only guard is missing.');
        self::assertIsInt($write);
        self::assertGreaterThan($guard, $write, 'A cache write happens outside the assets-only branch.');

        // A form post must never be answered from cache, in either direction.
        self::assertStringContainsString("request.method !== 'GET'", $source);
        self::assertStringNotContainsString('stale-while-revalidate', $source);
    }

    /** @return list<string> */
    private function precacheEntries(): array
    {
        $matched = preg_match('/const PRECACHE = \[(.*?)\];/s', $this->source(), $matches);

        self::assertSame(1, $matched, 'The service worker has no PRECACHE list.');

        $list = $matches[1] ?? '';

        preg_match_all("/'([^']+)'/", $list, $entries);

        self::assertNotEmpty($entries[1], 'PRECACHE is empty, so the shell would not work offline.');

        return array_values($entries[1]);
    }

    private function isAllowed(string $entry): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($entry, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function source(): string
    {
        $path = dirname(__DIR__, 2) . '/public/sw.js';

        self::assertFileExists($path);

        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }
}
