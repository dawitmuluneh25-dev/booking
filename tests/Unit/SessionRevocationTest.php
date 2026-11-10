<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\ChangeUserRole;
use App\Domain\Role;
use App\Security\Auth;
use App\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * A role change must end the live session, not just the next sign-in.
 *
 * The check runs through `Auth` rather than the repository, because the mechanism
 * is the comparison between the version recorded at sign-in and the one stored on
 * the account: if that comparison is removed, the role change silently becomes
 * effective only for new sessions, which is the bug this test exists to catch.
 */
final class SessionRevocationTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private InMemoryUserRepository $users;

    private PasswordHasher $hasher;

    private int $adminId;

    private int $staffId;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->hasher = new PasswordHasher();

        $hash = $this->hasher->hash(self::PASSWORD);
        $this->adminId = $this->users->seed('admin@example.test', 'Admin', Role::Admin, $hash);
        $this->staffId = $this->users->seed('staff@example.test', 'Staff', Role::Staff, $hash);

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testASessionSurvivesAnUnrelatedRequest(): void
    {
        $this->signIn('staff@example.test');

        self::assertNotNull($this->freshAuth()->user());
    }

    public function testTheSessionsEndsWhenTheRoleChanges(): void
    {
        $this->signIn('staff@example.test');

        (new ChangeUserRole($this->users, new RecordingAuditTrail(), new InlineTransaction()))->execute(
            new Actor($this->adminId, Role::Admin),
            $this->staffId,
            Role::Radiologist,
        );

        self::assertNull($this->freshAuth()->user(), 'A role change left the old session signed in.');
    }

    public function testTheUserSignsInAgainWithTheNewRole(): void
    {
        $this->signIn('staff@example.test');

        (new ChangeUserRole($this->users, new RecordingAuditTrail(), new InlineTransaction()))->execute(
            new Actor($this->adminId, Role::Admin),
            $this->staffId,
            Role::Radiologist,
        );

        $user = $this->freshAuth()->attempt('staff@example.test', self::PASSWORD, '127.0.0.1');

        self::assertSame(Role::Radiologist, $user->role);
        self::assertNotNull($this->freshAuth()->user());
    }

    private function signIn(string $email): void
    {
        $this->freshAuth()->attempt($email, self::PASSWORD, '127.0.0.1');
    }

    /** A new instance per call, because Auth caches the resolved user. */
    private function freshAuth(): Auth
    {
        return new Auth($this->users, $this->hasher);
    }
}
