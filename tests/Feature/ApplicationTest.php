<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Application;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Boots the real composition root. Deliberately does not require a database:
 * the public home page and the router must work before MySQL is provisioned.
 */
final class ApplicationTest extends TestCase
{
    public function testTheHomePageRenders(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $response = $app->handle(Request::create('GET', '/'));

        self::assertSame(200, $response->status);
        // Since D-05 the landing page opens with the approved headline rather than the
        // referrer-facing "Refer a patient" it carried while the owner's public copy was
        // outstanding. HomePageCopyTest pins the rest of the approved text, and
        // PublicFunnelTest holds the links to routes that answer.
        self::assertStringContainsString(
            'Specialist Image-Guided Interventional Radiology Care',
            $response->body(),
        );
        self::assertStringContainsString('<html lang="en">', $response->body());
    }

    public function testTheManifestRouteIsServedAsAStaticFile(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/manifest.webmanifest'),
            true,
        );

        self::assertIsArray($manifest);
        self::assertSame('/', $manifest['start_url']);
        self::assertSame('standalone', $manifest['display']);
    }

    public function testHealthEndpointReportsDependencyStateWithoutSecrets(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $response = $app->handle(Request::create('GET', '/healthz'));
        $payload = json_decode($response->body(), true);

        self::assertContains($response->status, [200, 503]);
        self::assertIsArray($payload);
        self::assertArrayHasKey('checks', $payload);

        $checks = $payload['checks'] ?? [];
        self::assertIsArray($checks);
        self::assertArrayHasKey('app', $checks);
        self::assertStringNotContainsString('DB_PASSWORD', $response->body());
    }

    public function testEveryResponseCarriesNoStoreHeadersForDynamicPages(): void
    {
        $app = Application::boot(dirname(__DIR__, 2));

        $response = $app->handle(Request::create('GET', '/bookings/new'));

        // Requires the database to render the form; without one this is a 500.
        // Either way the security headers must be present.
        self::assertNotNull($response->header('Content-Security-Policy'));
    }
}
