<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Application;
use App\Http\Request;
use App\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * The public funnel, asserted as links rather than as copy.
 *
 * On 18 November 2026 the account-request form shipped and returned 200, and no
 * page linked to it: the landing page's only button pointed at `/bookings/new`,
 * which needs `bookings.manage` and answers 403 to every visitor who is not
 * signed in. The feature was reachable only by typing the URL.
 *
 * A rendered-page test would not have caught that, because the page rendered
 * perfectly. These assertions are about reachability, so a future template edit
 * that removes the link fails here instead of being discovered by a clinician
 * who cannot find the form.
 *
 * Boots the real composition root and needs no database, like ApplicationTest:
 * these pages must be servable before MySQL is provisioned.
 */
final class PublicFunnelTest extends TestCase
{
    private static function get(string $path): Response
    {
        return Application::boot(dirname(__DIR__, 2))->handle(Request::create('GET', $path));
    }

    public function testTheLandingPageLeadsToTheAccountRequest(): void
    {
        $response = self::get('/');

        self::assertSame(200, $response->status);
        self::assertStringContainsString(
            'href="/request-account"',
            $response->body(),
            'A visitor who is not signed in must be able to reach the request form from the landing page.',
        );
    }

    public function testTheLandingPageDoesNotAdvertiseAStaffOnlyRoute(): void
    {
        $response = self::get('/');

        // `/bookings/new` requires Permission::BookingsManage. Advertising it to
        // anonymous visitors sends them to a 403 and reads as a broken site.
        self::assertStringNotContainsString(
            'href="/bookings/new"',
            $response->body(),
            'The landing page must not link to a route anonymous visitors cannot open.',
        );
    }

    public function testTheSignInPageLeadsToTheAccountRequest(): void
    {
        $response = self::get('/login');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('href="/request-account"', $response->body());
    }
}
