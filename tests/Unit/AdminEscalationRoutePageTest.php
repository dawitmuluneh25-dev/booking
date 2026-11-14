<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminEscalationRouteController;
use App\Domain\EscalationReadiness;
use App\Domain\RecordEscalationRoute;
use App\Domain\RetireEscalationRoute;
use App\Domain\Role;
use App\Domain\VerifyEscalationRoute;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryEscalationRouteRepository;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * The roster screen (Decision 4).
 *
 * The page's job is to make the gap between "recorded" and "verified" visible before
 * somebody relies on the roster during a case, so these tests render the templates
 * and check what a reader is actually told.
 */
final class AdminEscalationRoutePageTest extends TestCase
{
    private InMemoryUserRepository $users;

    private InMemoryEscalationRouteRepository $routes;

    private AdminEscalationRouteController $controller;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->users = new InMemoryUserRepository();
        $hash = (new PasswordHasher())->hash('correct horse');
        $this->users->seed('admin@example.test', 'Clinic Administrator', Role::Admin, $hash);
        $this->users->seed('clerk@example.test', 'Sam Reception', Role::Staff, $hash);

        $this->routes = new InMemoryEscalationRouteRepository();
        $this->controller = $this->build();
        $this->signIn('admin@example.test');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testTheScreenSaysEscalationIsDisabledUntilSomethingIsVerified(): void
    {
        $body = $this->body($this->controller->index(Request::create('GET', '/admin/escalation-routes')));

        self::assertStringContainsString('Escalation is disabled', $body);
        self::assertStringContainsString('no verified primary contact', $body);
        self::assertStringContainsString('Record a route', $body);
    }

    public function testTheDeskCannotOpenTheRoster(): void
    {
        $this->signIn('clerk@example.test');

        $this->expectException(HttpException::class);

        $this->controller->index(Request::create('GET', '/admin/escalation-routes'));
    }

    public function testRecordingAndVerifyingThroughTheScreenActivatesEscalation(): void
    {
        foreach (['primary', 'secondary', 'receiving_team'] as $kind) {
            $this->controller->store(
                Request::create('POST', '/admin/escalation-routes', [], [
                    'kind' => $kind,
                    'label' => $kind . ' route',
                    'contact_name' => 'Duty officer',
                    'contact_phone' => '+251911000777',
                    'is_staffed' => '1',
                    Csrf::FIELD => Csrf::token(),
                ]),
            );
        }

        $body = $this->body($this->controller->index(Request::create('GET', '/admin/escalation-routes')));
        self::assertStringContainsString('Escalation is disabled', $body);
        self::assertStringContainsString('Awaiting verification', $body);

        foreach ($this->routes->all() as $route) {
            $this->controller->verify(
                Request::create('POST', '/admin/escalation-routes/' . $route->id . '/verify', [], [
                    'verification_note' => 'Rang the number and the named person answered.',
                    Csrf::FIELD => Csrf::token(),
                ]),
                ['id' => (string) $route->id],
            );
        }

        $after = $this->body($this->controller->index(Request::create('GET', '/admin/escalation-routes')));

        self::assertStringContainsString('Escalation is available', $after);
        self::assertStringContainsString('Verified', $after);
        self::assertStringNotContainsString('Escalation is disabled', $after);
    }

    public function testAnUnstaffedAfterHoursRouteIsRefusedWithTheReason(): void
    {
        $response = $this->controller->store(
            Request::create('POST', '/admin/escalation-routes', [], [
                'kind' => 'after_hours',
                'label' => 'Night line',
                'contact_name' => 'Switchboard',
                'contact_phone' => '+251911000888',
                'is_staffed' => '0',
                Csrf::FIELD => Csrf::token(),
            ]),
        );

        self::assertSame(422, $response->status);
        self::assertStringContainsString('coverage genuinely exists', $this->body($response));
        self::assertSame([], $this->routes->all());
    }

    private function build(): AdminEscalationRouteController
    {
        $auth = new Auth($this->users, new PasswordHasher());
        $audit = new RecordingAuditTrail();
        $transaction = new InlineTransaction();

        return new AdminEscalationRouteController(
            new View(dirname(__DIR__, 2) . '/templates'),
            $auth,
            new Gate($auth),
            $this->routes,
            new EscalationReadiness($this->routes),
            $this->users,
            new RecordEscalationRoute($this->routes, new Validator(), $transaction, $audit),
            new VerifyEscalationRoute($this->routes, $transaction, $audit),
            new RetireEscalationRoute($this->routes, $transaction, $audit),
            'Africa/Addis_Ababa',
        );
    }

    private function signIn(string $email): void
    {
        $_SESSION = [];
        (new Auth($this->users, new PasswordHasher()))->attempt($email, 'correct horse', '127.0.0.1');
        $this->controller = $this->build();
    }

    private function body(Response $response): string
    {
        return $response->body();
    }
}
