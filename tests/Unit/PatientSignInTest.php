<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Role;
use App\Security\Auth;
use App\Security\InvalidCredentialsException;
use App\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUserRepository;

/**
 * A patient account is a record, not a login (ADR-016).
 *
 * The decision is worth a test rather than a comment because the ordinary sign-in
 * form would otherwise accept the account: a password is a password, and nothing in
 * the form knows which role it belongs to.
 */
final class PatientSignInTest extends TestCase
{
    public function testAPatientWithTheRightPasswordCannotOpenASession(): void
    {
        $_SESSION = [];

        $users = new InMemoryUserRepository();
        $users->seed(
            'patient@example.test',
            'Abebe Bekele',
            Role::Patient,
            (new PasswordHasher())->hash('correct horse'),
        );

        $auth = new Auth($users, new PasswordHasher());

        $this->expectException(InvalidCredentialsException::class);

        try {
            $auth->attempt('patient@example.test', 'correct horse', '127.0.0.1');
        } finally {
            self::assertArrayNotHasKey('_auth_user_id', $_SESSION);
        }
    }

    public function testTheRefusalIsIndistinguishableFromAWrongPassword(): void
    {
        $_SESSION = [];

        $users = new InMemoryUserRepository();
        $users->seed(
            'patient@example.test',
            'Abebe Bekele',
            Role::Patient,
            (new PasswordHasher())->hash('correct horse'),
        );

        $auth = new Auth($users, new PasswordHasher());

        $wrongPassword = null;
        $blockedRole = null;

        try {
            $auth->attempt('nobody@example.test', 'correct horse', '127.0.0.1');
        } catch (InvalidCredentialsException $exception) {
            $wrongPassword = $exception->getMessage();
        }

        try {
            $auth->attempt('patient@example.test', 'correct horse', '127.0.0.1');
        } catch (InvalidCredentialsException $exception) {
            $blockedRole = $exception->getMessage();
        }

        // A distinct message would confirm the address exists, which is the
        // enumeration oracle the login path is built to avoid.
        self::assertSame($wrongPassword, $blockedRole);
    }

    public function testStaffStillSignIn(): void
    {
        $_SESSION = [];

        $users = new InMemoryUserRepository();
        $users->seed(
            'clerk@example.test',
            'Clerk',
            Role::Staff,
            (new PasswordHasher())->hash('correct horse'),
        );

        $user = (new Auth($users, new PasswordHasher()))->attempt('clerk@example.test', 'correct horse', '127.0.0.1');

        self::assertSame(Role::Staff, $user->role);
    }

    public function testOnlyThePatientRoleIsBarred(): void
    {
        foreach (Role::cases() as $role) {
            self::assertSame($role !== Role::Patient, $role->canSignIn(), $role->value);
        }
    }
}
