<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Application;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * HEAD is GET without the body (RFC 9110 §9.3.2).
 *
 * This was wrong in a way that only shows up in production: the router answered
 * 405 to HEAD while its own `Allow` header listed HEAD as permitted, because a GET
 * route never matched a HEAD request and the fall-through then helpfully added
 * "HEAD" to the allowed set. Uptime monitors probe with HEAD by default, so the
 * first thing a monitor would have seen of this application is a 405 — a site that
 * reports itself down while serving every page normally.
 */
final class HeadRequestTest extends TestCase
{
    private static function send(string $method, string $path): Response
    {
        return Application::boot(dirname(__DIR__, 2))->handle(Request::create($method, $path));
    }

    public function testHeadAnswersWithTheSameStatusAsGetAndNoBody(): void
    {
        $get = self::send('GET', '/');
        $head = self::send('HEAD', '/');

        self::assertSame(200, $get->status);
        self::assertSame($get->status, $head->status);
        self::assertNotSame('', $get->body(), 'The GET response must still carry its body.');
        self::assertSame('', $head->body(), 'HEAD must not send a body.');
    }

    public function testHeadKeepsTheHeadersThatDescribeThePage(): void
    {
        $head = self::send('HEAD', '/');

        // A bodyless 200 with no Content-Type tells a monitor nothing; the point of
        // the rule is that HEAD describes the GET response.
        self::assertSame('text/html; charset=UTF-8', $head->header('Content-Type'));
        self::assertNotNull($head->header('Content-Security-Policy'));
    }

    public function testHeadOnTheHealthEndpointMatchesItsGet(): void
    {
        $get = self::send('GET', '/healthz');
        $head = self::send('HEAD', '/healthz');

        // The status depends on whether MySQL is reachable; head and get must agree
        // either way, and the body must be empty only on the head.
        self::assertSame($get->status, $head->status);
        self::assertContains($head->status, [200, 503]);
        self::assertSame('', $head->body());
        self::assertSame('application/json; charset=UTF-8', $head->header('Content-Type'));
    }

    public function testHeadOnAMissingPageIsStill404AndStillBodyless(): void
    {
        $head = self::send('HEAD', '/no-such-page');

        // The error path is rendered inside the Kernel, which is why the body is
        // stripped there rather than in the router.
        self::assertSame(404, $head->status);
        self::assertSame('', $head->body());
    }

    public function testAWriteOnlyRouteStillRefusesHead(): void
    {
        // /logout is POST-only. The HEAD mapping is a GET fallback, not a blanket
        // "accept anything", so a route with no GET must still refuse the method
        // and say which one it offers.
        $head = self::send('HEAD', '/logout');

        self::assertSame(405, $head->status);
        self::assertSame('POST', $head->header('Allow'));
    }
}
