<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\NotificationController;
use App\Domain\Role;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryNotificationFeed;
use Tests\Support\InMemoryUserRepository;

/**
 * The in-app notification list, rendered through the real template.
 *
 * Two things worth a page test: that an empty list says so rather than showing an
 * empty table, and that the page prints a reference and a time and nothing that
 * could identify a patient. The second is asserted by printing only what the feed
 * gives the view — there is no other column a template could reach for.
 */
final class NotificationPageTest extends TestCase
{
    private InMemoryUserRepository $users;

    private InMemoryNotificationFeed $feed;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->users = new InMemoryUserRepository();
        $this->users->seed(
            'clerk@example.test',
            'Clerk',
            Role::Staff,
            (new PasswordHasher())->hash('correct horse'),
        );
        $this->users->seed(
            'referrer@example.test',
            'Referrer',
            Role::Physician,
            (new PasswordHasher())->hash('correct horse'),
        );

        $this->feed = new InMemoryNotificationFeed();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAnEmptyListSaysNothingHasBeenSent(): void
    {
        $body = $this->body($this->controllerFor('clerk@example.test'));

        self::assertStringContainsString('Nothing has been sent yet', $body);
    }

    public function testTheListShowsTheReferenceAndTheTime(): void
    {
        $this->feed->add('BK-260113-ABC123', '2026-11-13 09:14:00');

        $body = $this->body($this->controllerFor('clerk@example.test'));

        self::assertStringContainsString('BK-260113-ABC123', $body);
        self::assertStringContainsString('2026-11-13 09:14:00', $body);
    }

    /**
     * A referring physician's relationship is to their own patients, not to the
     * clinic's traffic, so the list is not theirs to read.
     */
    public function testAReferringPhysicianCannotOpenTheList(): void
    {
        $this->expectException(HttpException::class);

        $this->controllerFor('referrer@example.test')->index(Request::create('GET', '/notifications'));
    }

    public function testASignedOutVisitorIsSentToSignIn(): void
    {
        $controller = new NotificationController(
            new View(dirname(__DIR__, 2) . '/templates'),
            new Auth($this->users, new PasswordHasher()),
            new Gate(new Auth($this->users, new PasswordHasher())),
            $this->feed,
        );

        $response = $controller->index(Request::create('GET', '/notifications'));

        self::assertSame(303, $response->status);
        self::assertSame('/login?next=%2Fnotifications', $response->header('Location'));
    }

    private function controllerFor(string $email): NotificationController
    {
        $auth = new Auth($this->users, new PasswordHasher());
        $auth->attempt($email, 'correct horse', '127.0.0.1');

        return new NotificationController(
            new View(dirname(__DIR__, 2) . '/templates'),
            $auth,
            new Gate($auth),
            $this->feed,
        );
    }

    private function body(NotificationController $controller): string
    {
        $response = $controller->index(Request::create('GET', '/notifications'));

        self::assertInstanceOf(Response::class, $response);

        return $response->body();
    }
}
