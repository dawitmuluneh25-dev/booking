<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Application;
use App\Http\RouteAccess;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3 acceptance 4: every route declares its access.
 *
 * The router refuses to register a route without one, so this test is about the
 * *content* of those declarations: that the public set is the small, reviewable
 * list below, and that nothing administrative slipped through as "signed in".
 */
final class RouteAccessManifestTest extends TestCase
{
    /**
     * Routes reachable without a session. Every entry needs a reason, and adding
     * one is a decision somebody has to write down.
     *
     * @var list<string>
     */
    private const PUBLIC_PATTERNS = [
        'GET /',
        'GET /login',
        'POST /login',
        'GET /password/reset/{token}',
        'POST /password/reset/{token}',
        'GET /availability',
        'GET /healthz',
        // The token is the credential; the route has to work without a session
        // because that is the entire point of a status link.
        'GET /r/{token}',
    ];

    public function testEveryRouteDeclaresItsAccess(): void
    {
        $manifest = $this->manifest();

        self::assertSame(67, count($manifest));

        foreach ($manifest as $route) {
            self::assertInstanceOf(RouteAccess::class, $route['access']);
            self::assertNotSame('', $route['access']->describe());
        }
    }

    public function testThePublicRoutesAreExactlyTheDocumentedOnes(): void
    {
        $public = [];

        foreach ($this->manifest() as $route) {
            if ($route['access']->isPublic) {
                $public[] = $route['method'] . ' ' . $route['pattern'];
            }
        }

        sort($public);
        $expected = self::PUBLIC_PATTERNS;
        sort($expected);

        self::assertSame($expected, $public);
    }

    public function testAdministrativeRoutesNameThePermissionTheyNeed(): void
    {
        $guarded = [];

        foreach ($this->manifest() as $route) {
            if ($route['access']->permission !== null) {
                $guarded[$route['method'] . ' ' . $route['pattern']] = $route['access']->permission->value;
            }
        }

        self::assertSame('bookings.manage', $guarded['GET /bookings'] ?? null);
        self::assertSame('users.manage', $guarded['GET /admin/users'] ?? null);
        self::assertSame('clinic.configure', $guarded['POST /admin/hours'] ?? null);
        self::assertSame('system.maintain', $guarded['GET /admin/diagnostics'] ?? null);
        self::assertSame('document.upload', $guarded['POST /referrals/{reference}/documents'] ?? null);
        self::assertSame('document.download', $guarded['GET /documents/{ref}/download'] ?? null);
        self::assertSame('document.quarantine.manage', $guarded['POST /documents/{ref}/review'] ?? null);
        self::assertSame('schedule.manage', $guarded['GET /schedule'] ?? null);
        self::assertSame('referral.triage', $guarded['GET /referrals'] ?? null);
        self::assertSame('referral.submit', $guarded['POST /referrals'] ?? null);
        self::assertSame('referral.triage', $guarded['GET /referrals/{reference}'] ?? null);
        self::assertSame('referral.submit', $guarded['POST /referrals/draft'] ?? null);
        self::assertSame('referral.submit', $guarded['POST /referrals/{reference}/submit'] ?? null);
        self::assertSame('referral.triage', $guarded['POST /referrals/{reference}/advance'] ?? null);
        self::assertSame('referral.triage', $guarded['POST /referrals/{reference}/reply'] ?? null);
        self::assertSame('schedule.manage', $guarded['POST /referrals/{reference}/release'] ?? null);
        self::assertSame('clinical.review', $guarded['GET /review'] ?? null);
        self::assertSame('clinical.approve', $guarded['POST /referrals/{reference}/decision'] ?? null);
        self::assertSame('schedule.manage', $guarded['GET /schedule/{reference}/move'] ?? null);
        self::assertSame('bookings.manage', $guarded['POST /schedule/{reference}/cancel'] ?? null);
        self::assertSame('referral.triage', $guarded['POST /referrals/{reference}/status-link'] ?? null);

        // Changing a priority is a clinical act on a referral that has already left
        // clinical review, so it is not `referral.triage` and not `/decision`.
        self::assertSame('clinical.approve', $guarded['POST /referrals/{reference}/priority'] ?? null);

        // The urgent board: claiming a case is open to whoever is at the desk;
        // escalating and closing it is not.
        self::assertSame('urgent.acknowledge', $guarded['GET /urgent-cases'] ?? null);
        self::assertSame('urgent.acknowledge', $guarded['GET /urgent-cases/{reference}'] ?? null);
        self::assertSame('urgent.acknowledge', $guarded['POST /urgent-cases/{reference}/acknowledge'] ?? null);
        self::assertSame('urgent.acknowledge', $guarded['POST /urgent-cases/{reference}/contact'] ?? null);
        self::assertSame('urgent.coordinate', $guarded['POST /urgent-cases/{reference}/escalate'] ?? null);
        self::assertSame('urgent.coordinate', $guarded['POST /urgent-cases/{reference}/close'] ?? null);
        // Deciding is clinical/coordinating authority; seeing the case is not.
        self::assertSame('urgent.coordinate', $guarded['POST /urgent-cases/{reference}/decision'] ?? null);
        self::assertSame('urgent.coordinate', $guarded['POST /urgent-cases/{reference}/coordination'] ?? null);
        // Intake follows who may submit a referral into the service (§10.3 step 1).
        self::assertSame('referral.submit', $guarded['GET /urgent-cases/new'] ?? null);
        self::assertSame('referral.submit', $guarded['POST /urgent-cases'] ?? null);
    }

    /**
     * `/urgent` is the public urgent-pathway notice (BL-A6) and must stay free of
     * the case board, which carries patient data: the two paths are different
     * audiences and must not be able to drift into one another by a rename.
     */
    public function testTheUrgentBoardIsNotThePublicUrgentPathway(): void
    {
        foreach ($this->manifest() as $route) {
            self::assertNotSame('/urgent', $route['pattern']);

            if (str_starts_with($route['pattern'], '/urgent-cases')) {
                self::assertFalse(
                    $route['access']->isPublic,
                    $route['method'] . ' ' . $route['pattern'] . ' exposes urgent cases without a session.',
                );
            }
        }
    }

    public function testNoGuardedRouteReliesOnAuthenticationAloneExceptLogout(): void
    {
        $authenticatedOnly = [];

        foreach ($this->manifest() as $route) {
            $access = $route['access'];

            if (!$access->isPublic && $access->permission === null) {
                $authenticatedOnly[] = $route['method'] . ' ' . $route['pattern'];
            }
        }

        // Signing out is the only action that every role may perform and that
        // belongs to nobody in particular.
        self::assertSame(['POST /logout'], $authenticatedOnly);
    }

    /** @return list<array{method: string, pattern: string, access: RouteAccess}> */
    private function manifest(): array
    {
        $app = Application::boot(dirname(__DIR__, 2));

        return $app->router()->manifest();
    }
}
