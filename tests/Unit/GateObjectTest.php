<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\HttpException;
use App\Security\Auth;
use App\Security\Gate;
use App\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUserRepository;

/**
 * Phase 3 acceptance 2: a refused object answers 404, never 403 (SEC 8.4).
 *
 * Showing 403 would confirm that the referral exists and is somebody else's, which
 * on this system is a statement about a patient. The route should look exactly like
 * one that was never there.
 */
final class GateObjectTest extends TestCase
{
    private Gate $gate;

    protected function setUp(): void
    {
        $users = new InMemoryUserRepository();
        $hash = (new PasswordHasher())->hash('correct horse battery staple');
        $users->seed('staff@example.test', 'Staff', \App\Domain\Role::Staff, $hash);

        $_SESSION = [];
        $auth = new Auth($users, new PasswordHasher());
        $auth->attempt('staff@example.test', 'correct horse battery staple', '127.0.0.1');

        $this->gate = new Gate($auth);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testAnObjectTheActorMayNotSeeIsNotFound(): void
    {
        try {
            $this->gate->authorizeObject(false);
            self::fail('A refused object was allowed.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
            self::assertStringContainsString('not found', strtolower($exception->getMessage()));
        }
    }

    public function testAnAllowedObjectPasses(): void
    {
        $this->gate->authorizeObject(true);

        self::assertNotNull($this->gate->user());
    }

    public function testAnAnonymousCallerIsRefusedBeforeTheObjectIsConsidered(): void
    {
        $_SESSION = [];
        $gate = new Gate(new Auth(new InMemoryUserRepository(), new PasswordHasher()));

        $this->expectException(HttpException::class);
        $gate->authorizeObject(true);
    }
}
