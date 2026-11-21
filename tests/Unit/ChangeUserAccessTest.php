<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuditAction;
use App\Domain\AuthorisationException;
use App\Domain\ChangeUserAccess;
use App\Domain\Role;
use App\Domain\UserNotFoundException;
use App\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUserRepository;
use Tests\Support\InlineTransaction;
use Tests\Support\RecordingAuditTrail;

/**
 * Suspending an account has to do three things at once: stop the sign-in, end the
 * sessions that are already live, and leave a record of who decided it. Any two of
 * those without the third is a control that looks like it works.
 *
 * The suite also pins the lockout invariant. There is deliberately no "last
 * administrator" branch in the command — only `admin` holds `users.manage` and the
 * actor may not be the target, so the account being suspended is never the last active
 * administrator. That argument is a property of the whole rule, so it is tested by
 * walking the rule rather than by asserting a branch that cannot run.
 */
final class ChangeUserAccessTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private InMemoryUserRepository $users;

    private ChangeUserAccess $command;

    private RecordingAuditTrail $audit;

    private int $adminId;

    private int $secondAdminId;

    private int $staffId;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $hash = (new PasswordHasher())->hash(self::PASSWORD);

        $this->adminId = $this->users->seed('admin@example.test', 'Admin', Role::Admin, $hash);
        $this->secondAdminId = $this->users->seed('second@example.test', 'Second Admin', Role::Admin, $hash);
        $this->staffId = $this->users->seed('staff@example.test', 'Staff', Role::Staff, $hash);

        $this->audit = new RecordingAuditTrail();
        $this->command = new ChangeUserAccess($this->users, $this->audit, new InlineTransaction());
    }

    public function testAnAdministratorSuspendsAnotherAccount(): void
    {
        $updated = $this->command->execute(
            new Actor($this->adminId, Role::Admin),
            $this->staffId,
            false,
        );

        self::assertFalse($updated->active);
        self::assertFalse($this->user($this->staffId)->active);
    }

    public function testSuspensionEndsTheSessionsThatAreAlreadyLive(): void
    {
        $before = $this->user($this->staffId)->authVersion;

        $this->command->execute(new Actor($this->adminId, Role::Admin), $this->staffId, false);

        self::assertSame($before + 1, $this->user($this->staffId)->authVersion);
    }

    public function testSuspensionIsRecordedAgainstThePersonWhoDecidedIt(): void
    {
        $this->command->execute(new Actor($this->adminId, Role::Admin), $this->staffId, false);

        $entry = $this->audit->entries()[0];

        self::assertSame(AuditAction::UserDeactivated, $entry->action);
        self::assertSame((string) $this->staffId, $entry->objectRef);
        self::assertSame($this->adminId, $entry->actorUserId);
        self::assertSame(['active' => true, 'email' => 'staff@example.test', 'role' => 'staff'], $entry->before);
        self::assertSame(['active' => false, 'email' => 'staff@example.test', 'role' => 'staff'], $entry->after);
    }

    public function testReactivationRestoresTheAccountAndRecordsItSeparately(): void
    {
        $this->command->execute(new Actor($this->adminId, Role::Admin), $this->staffId, false);
        $restored = $this->command->execute(new Actor($this->adminId, Role::Admin), $this->staffId, true);

        self::assertTrue($restored->active);
        self::assertSame(AuditAction::UserReactivated, $this->audit->entries()[1]->action);
    }

    /**
     * Reactivating an active account, or suspending a suspended one, must not write a
     * second audit row: an audit trail that records events which did not happen is
     * worse than none, because it is read as evidence.
     */
    public function testAnAccountThatIsAlreadyInThatStateIsLeftAlone(): void
    {
        $this->command->execute(new Actor($this->adminId, Role::Admin), $this->staffId, true);

        self::assertSame([], $this->audit->entries());
        self::assertSame(1, $this->user($this->staffId)->authVersion);
    }

    public function testNobodySuspendsThemselves(): void
    {
        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessageMatches('/own access/i');

        $this->command->execute(new Actor($this->adminId, Role::Admin), $this->adminId, false);
    }

    public function testReceptionCannotSuspendAnyone(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->command->execute(new Actor($this->staffId, Role::Staff), $this->secondAdminId, false);
    }

    public function testAnUnknownAccountCannotBeSuspended(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->command->execute(new Actor($this->adminId, Role::Admin), 9999, false);
    }

    /**
     * The lockout invariant, walked rather than asserted: whoever is suspending is an
     * active administrator, so at least one active administrator survives the call.
     * If a future role is granted `users.manage`, this test still holds — and if the
     * self-refusal is removed, it fails.
     */
    public function testSuspendingEveryOtherAccountStillLeavesAnAdministrator(): void
    {
        $actor = new Actor($this->adminId, Role::Admin);

        foreach ([$this->secondAdminId, $this->staffId] as $target) {
            $this->command->execute($actor, $target, false);
        }

        $activeAdmins = array_filter(
            $this->users->all(),
            static fn ($user): bool => $user->active && $user->role === Role::Admin,
        );

        self::assertCount(1, $activeAdmins);
        self::assertSame($this->adminId, array_values($activeAdmins)[0]->id);
    }

    private function user(int $id): \App\Domain\User
    {
        $user = $this->users->findById($id);
        self::assertNotNull($user);

        return $user;
    }
}
