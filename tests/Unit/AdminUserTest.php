<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminUserController;
use App\Domain\ChangeUserRole;
use App\Domain\CreateUserAccount;
use App\Domain\Role;
use App\Http\HttpException;
use App\Http\Request;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\PasswordHasher;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * The two account writes, through the real commands and the real template.
 *
 * The template matters here: the temporary password is shown once on the
 * creating response, and "once" is only true if a reload does not show it again.
 */
final class AdminUserTest extends TestCase
{
    private InMemoryUserRepository $users;

    private AdminUserController $controller;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->users = new InMemoryUserRepository();
        $this->users->seed(
            'admin@example.test',
            'Ada Admin',
            Role::Admin,
            (new PasswordHasher())->hash('correct horse'),
        );
        $this->users->seed('clerk@example.test', 'Clerk', Role::Staff, (new PasswordHasher())->hash('correct horse'));

        $auth = new Auth($this->users, new PasswordHasher());
        $auth->attempt('admin@example.test', 'correct horse', '127.0.0.1');

        $audit = new RecordingAuditTrail();

        $this->controller = new AdminUserController(
            new View(dirname(__DIR__, 2) . '/templates'),
            $this->users,
            $auth,
            new Gate($auth),
            new CreateUserAccount($this->users, new PasswordHasher(), $audit, new InlineTransaction()),
            new ChangeUserRole($this->users, $audit, new InlineTransaction()),
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testCreatingAnAccountShowsTheTemporaryPasswordOnceAndNeverOnReload(): void
    {
        $response = $this->controller->store(Request::create('POST', '/admin/users', [], [
            Csrf::FIELD => Csrf::token(),
            'name' => 'Hanna Radiologist',
            'email' => 'hanna@example.test',
            'role' => Role::Radiologist->value,
        ]));

        self::assertSame(200, $response->status);
        $body = $response->body();
        self::assertStringContainsString('hanna@example.test', $body);
        self::assertStringContainsString('Temporary password, shown once', $body);

        // The account exists with the role the command chose.
        $created = $this->users->findCredentialsByEmail('hanna@example.test');
        self::assertNotNull($created);
        self::assertSame(Role::Radiologist, $created['user']->role);

        // A reload is a fresh GET: the password is gone.
        $reload = $this->controller->index(Request::create('GET', '/admin/users'));
        self::assertStringNotContainsString('Temporary password', $reload->body());
    }

    public function testADuplicateEmailIsRefusedAndNoSecondAccountIsMade(): void
    {
        $response = $this->controller->store(Request::create('POST', '/admin/users', [], [
            Csrf::FIELD => Csrf::token(),
            'name' => 'Someone Else',
            'email' => 'clerk@example.test',
            'role' => Role::Staff->value,
        ]));

        self::assertSame(422, $response->status);
        self::assertStringContainsString('already uses that email address', $response->body());
        self::assertCount(2, $this->users->all());
    }

    public function testARoleChangeTakesEffectAndSaysTheSessionsEnd(): void
    {
        $clerk = $this->users->findCredentialsByEmail('clerk@example.test');
        self::assertNotNull($clerk);

        $response = $this->controller->changeRole(Request::create('POST', '/admin/users/role', [], [
            Csrf::FIELD => Csrf::token(),
            'user_id' => (string) $clerk['user']->id,
            'role' => Role::Coordinator->value,
        ]));

        self::assertSame(303, $response->status);
        self::assertSame(Role::Coordinator, $this->users->findById($clerk['user']->id)?->role);

        $page = $this->controller->index(Request::create('GET', '/admin/users'));
        self::assertStringContainsString('existing sessions end', $page->body());

        // The claim on the page is tested, not just printed: a session opened
        // before the change no longer resolves.
        $theirSession = new Auth($this->users, new PasswordHasher());
        $_SESSION['_auth_user_id'] = (string) $clerk['user']->id;
        $_SESSION['_auth_version'] = '1';
        $_SESSION['_auth_last_activity'] = (string) time();
        self::assertNull($theirSession->user(), 'A revoked session was still accepted.');
    }

    public function testAdministratorsCannotChangeTheirOwnRole(): void
    {
        $self = $this->users->findCredentialsByEmail('admin@example.test');
        self::assertNotNull($self);

        $response = $this->controller->changeRole(Request::create('POST', '/admin/users/role', [], [
            Csrf::FIELD => Csrf::token(),
            'user_id' => (string) $self['user']->id,
            'role' => Role::Staff->value,
        ]));

        self::assertSame(403, $response->status);
        self::assertStringContainsString('cannot change your own role', $response->body());
        self::assertSame(Role::Admin, $this->users->findById($self['user']->id)?->role);
    }

    public function testARoleChangeWithoutACsrfTokenIsRejected(): void
    {
        $this->expectException(HttpException::class);

        $this->controller->changeRole(Request::create('POST', '/admin/users/role', [], [
            'user_id' => '2',
            'role' => Role::Staff->value,
        ]));
    }
}
