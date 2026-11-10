<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuditAction;
use App\Domain\AuthorisationException;
use App\Domain\ChangeUserRole;
use App\Domain\Role;
use App\Domain\UserNotFoundException;
use App\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * Phase 3 acceptance 3: no role is self-assignable, and a role change revokes
 * sessions. The revocation is expressed as `auth_version` moving with the role,
 * because the two must not be able to disagree.
 */
final class ChangeUserRoleTest extends TestCase
{
    private InMemoryUserRepository $users;

    private ChangeUserRole $command;

    private RecordingAuditTrail $audit;

    private int $adminId;

    private int $staffId;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $hash = (new PasswordHasher())->hash('correct horse battery staple');

        $this->adminId = $this->users->seed('admin@example.test', 'Admin', Role::Admin, $hash);
        $this->staffId = $this->users->seed('staff@example.test', 'Staff', Role::Staff, $hash);

        $this->audit = new RecordingAuditTrail();
        $this->command = new ChangeUserRole($this->users, $this->audit, new InlineTransaction());
    }

    public function testAnAdministratorChangesAnotherUsersRole(): void
    {
        $updated = $this->command->execute(new Actor($this->adminId, Role::Admin), $this->staffId, Role::Radiologist);

        self::assertSame(Role::Radiologist, $updated->role);
    }

    public function testTheChangeAlsoRevokesTheirSessions(): void
    {
        $before = $this->user($this->staffId)->authVersion;

        $this->command->execute(new Actor($this->adminId, Role::Admin), $this->staffId, Role::Radiologist);

        self::assertSame($before + 1, $this->user($this->staffId)->authVersion);
    }

    public function testNobodyChangesTheirOwnRole(): void
    {
        // Otherwise an administrator can promote themselves, and the role column
        // stops being a control at all.
        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessageMatches('/own role/i');

        $this->command->execute(new Actor($this->adminId, Role::Admin), $this->adminId, Role::Radiologist);
    }

    public function testAReceptionistCannotChangeRoles(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->command->execute(new Actor($this->staffId, Role::Staff), $this->adminId, Role::Staff);
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->command->execute(new Actor($this->adminId, Role::Admin), 999, Role::Staff);
    }

    public function testAnUnchangedRoleIsANoOp(): void
    {
        $before = $this->user($this->staffId)->authVersion;

        $this->command->execute(new Actor($this->adminId, Role::Admin), $this->staffId, Role::Staff);

        self::assertSame($before, $this->user($this->staffId)->authVersion);
    }

    /**
     * Acceptance 1 of BL-B2, at command level: one role change, one row, naming
     * the actor, the target and both roles.
     */
    public function testTheChangeProducesExactlyOneAuditEntry(): void
    {
        $this->command->execute(new Actor($this->adminId, Role::Admin), $this->staffId, Role::Radiologist);

        $entries = $this->audit->entries();

        self::assertCount(1, $entries);
        self::assertSame(AuditAction::UserRoleChanged, $entries[0]->action);
        self::assertSame('user', $entries[0]->objectType);
        self::assertSame((string) $this->staffId, $entries[0]->objectRef);
        self::assertSame($this->adminId, $entries[0]->actorUserId);
        self::assertSame(Role::Admin, $entries[0]->actorRole);
        self::assertSame('staff', ($entries[0]->before ?? [])['role'] ?? null);
        self::assertSame('radiologist', ($entries[0]->after ?? [])['role'] ?? null);
    }

    public function testARefusedChangeWritesNothing(): void
    {
        try {
            $this->command->execute(new Actor($this->adminId, Role::Admin), $this->adminId, Role::Radiologist);
            self::fail('An administrator changed their own role.');
        } catch (AuthorisationException) {
            // Expected.
        }

        self::assertSame([], $this->audit->entries());
    }

    public function testAnUnchangedRoleWritesNothing(): void
    {
        $this->command->execute(new Actor($this->adminId, Role::Admin), $this->staffId, Role::Staff);

        self::assertSame([], $this->audit->entries());
    }

    private function user(int $id): \App\Domain\User
    {
        $user = $this->users->findById($id);
        self::assertNotNull($user);

        return $user;
    }
}
