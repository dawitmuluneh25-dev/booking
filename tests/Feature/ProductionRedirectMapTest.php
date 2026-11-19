<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Application;
use PHPUnit\Framework\TestCase;

/**
 * The production redirect map, held to the routes the application actually serves.
 *
 * `deploy/htaccess.production` is the only thing standing between a printed
 * referral card from the old site and a 404 on the new one. It is also the one file
 * in this release that no test touches, because it is Apache configuration rather
 * than PHP — it never loads in CI, and it is applied by hand at cutover.
 *
 * That makes two failures possible, and both are silent until the day:
 *
 *   1. a redirect points at a path the router does not have, so the old URL lands
 *      on a 404 anyway — the redirect made it look handled;
 *   2. a redirect is added for `/privacy`, `/booking-policy` or
 *      `/referral-guidance`, which would shadow the page the application serves at
 *      that exact path. The comment in the file already warns about this; a comment
 *      is not a constraint.
 *
 * These tests cannot prove what Apache does with the file. They prove what the file
 * *says*, against the route table, which is the part that can be checked without a
 * live server.
 */
final class ProductionRedirectMapTest extends TestCase
{
    /** The legacy URLs that must keep working, and where each one belongs. */
    private const EXPECTED = [
        '/request' => '/request-account',
        '/referrers' => '/my-referrals',
        '/procedures' => '/services',
        '/procedures/biopsy-fnac' => '/services/fnac',
        '/procedures/abscess-drainage' => '/services/abscess_drainage',
        '/contact' => 'https://hakimerad.com/contact.html',
        '/how-it-works' => 'https://hakimerad.com/',
    ];

    /** Paths the application serves itself. A redirect here would win before PHP runs. */
    private const SERVED_BY_THE_APPLICATION = ['/privacy', '/booking-policy', '/referral-guidance'];

    /**
     * @return list<array{line: int, pattern: string, target: string, flags: string}>
     */
    private static function rules(): array
    {
        $path = dirname(__DIR__, 2) . '/deploy/htaccess.production';
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, 'The production redirect map must be readable.');

        $rules = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*RewriteRule\s+(\S+)\s+(\S+)(?:\s+(\[[^\]]*\]))?\s*$/', $line, $m) !== 1) {
                continue;
            }

            $rules[] = [
                'line' => $index + 1,
                'pattern' => $m[1],
                'target' => $m[2],
                'flags' => $m[3] ?? '',
            ];
        }

        return $rules;
    }

    /**
     * Only the rules that actually redirect. The front-controller rule (`^`) matches
     * every path there is, so a naive scan would "find" it for `/privacy` and report
     * a shadowing that does not exist.
     *
     * @return list<array{line: int, pattern: string, target: string, flags: string}>
     */
    private static function redirectRules(): array
    {
        return array_values(array_filter(
            self::rules(),
            static fn (array $rule): bool => str_contains($rule['flags'], 'R='),
        ));
    }

    /**
     * The first rule that would answer this path.
     *
     * mod_rewrite in a per-directory file (`.htaccess`) matches against the path
     * *without* the leading slash, which is why every pattern here starts with `^request`
     * rather than `^/request`. Testing the leading-slash form would find nothing and
     * report a redirect map that is actually correct as broken.
     *
     * @param list<array{line: int, pattern: string, target: string, flags: string}> $rules
     *
     * @return array{line: int, pattern: string, target: string, flags: string}|null
     */
    private static function firstMatch(array $rules, string $path): ?array
    {
        $bare = ltrim($path, '/');

        foreach ($rules as $rule) {
            $regex = '#' . $rule['pattern'] . '#';

            foreach ([$bare, $bare . '/'] as $candidate) {
                if (preg_match($regex, $candidate) === 1) {
                    return $rule;
                }
            }
        }

        return null;
    }

    public function testEveryLegacyPathStillRedirectsWhereItWasSent(): void
    {
        $rules = self::redirectRules();

        foreach (self::EXPECTED as $legacy => $expectedTarget) {
            $rule = self::firstMatch($rules, $legacy);

            self::assertNotNull($rule, 'No rewrite rule matches the legacy path ' . $legacy . '.');
            self::assertSame(
                $expectedTarget,
                $rule['target'],
                'The redirect for ' . $legacy . ' was changed. Line ' . $rule['line'] . '.',
            );
            self::assertStringContainsString(
                'R=301',
                $rule['flags'],
                'A legacy redirect must be permanent, so bookmarks update. ' . $legacy,
            );
            self::assertStringContainsString(
                'L',
                $rule['flags'],
                'A legacy redirect must stop processing, or the front controller runs too. ' . $legacy,
            );
        }
    }

    public function testNothingShadowsThePagesTheApplicationServesItself(): void
    {
        $rules = self::redirectRules();

        foreach (self::SERVED_BY_THE_APPLICATION as $path) {
            self::assertNull(
                self::firstMatch($rules, $path),
                $path . ' is served by the application. A redirect here would make the page unreachable '
                . 'at its own URL, because mod_rewrite answers before index.php does.',
            );
        }
    }

    public function testRedirectsAreEvaluatedBeforeTheFrontController(): void
    {
        $rules = self::rules();

        $frontController = null;
        foreach ($rules as $rule) {
            if ($rule['target'] === 'index.php') {
                $frontController = $rule;
                break;
            }
        }

        self::assertNotNull($frontController, 'The front-controller rewrite is missing.');
        self::assertSame('^', $frontController['pattern'], 'The front-controller rule must match last.');

        $redirects = self::redirectRules();

        foreach (self::EXPECTED as $legacy => $_) {
            $rule = self::firstMatch($redirects, $legacy);

            self::assertNotNull($rule);
            self::assertLessThan(
                $frontController['line'],
                $rule['line'],
                'The rule for ' . $legacy . ' sits after the front controller, so it never runs.',
            );
        }
    }

    /**
     * A redirect to a path the router does not know is a 404 with extra steps.
     */
    public function testEveryInternalRedirectTargetIsAServedPath(): void
    {
        $patterns = array_map(
            static fn (array $route): string => '#^'
                . preg_replace('#\{[a-zA-Z_][a-zA-Z0-9_]*\}#', '[^/]+', $route['pattern'])
                . '$#',
            Application::boot(dirname(__DIR__, 2))->router()->manifest(),
        );

        // Read the targets out of the file rather than out of the expectation table:
        // a test that only checks its own constant cannot notice the file changing.
        foreach (self::redirectRules() as $rule) {
            $target = $rule['target'];

            if (!str_starts_with($target, '/')) {
                continue; // an external target is the institutional site's business
            }

            $target = strtok($target, '?') ?: $target;

            $matched = false;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, rtrim($target, '/')) === 1) {
                    $matched = true;
                    break;
                }
            }

            self::assertTrue(
                $matched,
                'Line ' . $rule['line'] . ' redirects to ' . $target . ', which the router does not serve. '
                . 'The visitor would get a 404 from the redirect that was meant to prevent one.',
            );
        }
    }
}
