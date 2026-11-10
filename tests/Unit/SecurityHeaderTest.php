<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\RouteAccess;
use App\Http\Router;
use App\Support\Logger;
use App\Support\View;
use PHPUnit\Framework\TestCase;

/**
 * The headers are a control, and a control nobody asserts is a control that leaves
 * with the next refactor.
 *
 * The check is behavioural: a request goes through the kernel and the response is
 * inspected, so removing a header from the kernel — rather than editing this list —
 * is what breaks the build.
 */
final class SecurityHeaderTest extends TestCase
{
    private const REQUIRED = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => null,
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Permissions-Policy' => null,
    ];

    public function testEveryResponseCarriesTheSecurityHeaders(): void
    {
        $response = $this->kernel()->handle(Request::create('GET', '/'));

        foreach (self::REQUIRED as $header => $expected) {
            $value = $response->header($header);

            self::assertNotNull($value, sprintf('%s is missing from a normal response.', $header));
            self::assertNotSame('', $value);

            if ($expected !== null) {
                self::assertSame($expected, $value, $header);
            }
        }
    }

    public function testTheErrorPathCarriesThemToo(): void
    {
        // A 404 is where a missing header is easiest to miss, and it is still a
        // response a browser renders.
        $kernel = $this->kernel();

        $response = $kernel->handle(Request::create('GET', '/no-such-page'));

        self::assertSame(404, $response->status);
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        self::assertNotNull($response->header('Content-Security-Policy'));
    }

    public function testTheContentSecurityPolicyForbidsInlineScript(): void
    {
        $policy = $this->kernel()->handle(Request::create('GET', '/'))->header('Content-Security-Policy');

        self::assertIsString($policy);

        // 'unsafe-inline' in script-src would undo the point of the policy, and it is
        // the exemption most likely to be added to make one template work.
        self::assertStringContainsString("script-src 'self'", $policy);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
    }

    public function testTransportSecurityIsOnlyClaimedOverHttps(): void
    {
        $plain = $this->kernel()->handle(Request::create('GET', '/'));
        self::assertNull(
            $plain->header('Strict-Transport-Security'),
            'HSTS over plain HTTP is ignored by browsers and misleading in a header dump.',
        );

        $secure = $this->kernel()->handle(
            Request::create('GET', '/', [], [], ['HTTPS' => 'on']),
        );

        self::assertIsString($secure->header('Strict-Transport-Security'));
        self::assertStringContainsString('max-age=', (string) $secure->header('Strict-Transport-Security'));
    }

    private function kernel(): Kernel
    {
        $router = new Router();
        $router->get(
            '/',
            static fn (): Response => Response::html('<p>ok</p>'),
            RouteAccess::public('header test only'),
        );

        return new Kernel(
            $router,
            new View(dirname(__DIR__, 2) . '/templates'),
            new Logger(sys_get_temp_dir() . '/header-tests'),
        );
    }
}
