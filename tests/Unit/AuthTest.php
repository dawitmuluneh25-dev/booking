<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Role;
use App\Domain\ValidationException;
use App\Security\Auth;
use App\Security\InvalidCredentialsException;
use App\Security\InvalidResetTokenException;
use App\Security\PasswordHasher;
use App\Security\TooManyAttemptsException;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUserRepository;

final class AuthTest extends TestCase
{
    private const EMAIL = 'admin@example.test';
    private const PASSWORD = 'correct horse battery';

    private InMemoryUserRepository $users;
    private PasswordHasher $hasher;
    private Auth $auth;

    protected function setUp(): void
    {
        $_SESSION = [];

        $this->hasher = new PasswordHasher();
        $this->users = new InMemoryUserRepository();
        $this->users->seed(self::EMAIL, 'Admin', Role::Admin, $this->hasher->hash(self::PASSWORD));
        $this->auth = new Auth($this->users, $this->hasher, 7200);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testItSignsInWithValidCredentials(): void
    {
        $user = $this->auth->attempt(self::EMAIL, self::PASSWORD, '127.0.0.1');

        self::assertSame(self::EMAIL, $user->email);
        self::assertTrue($this->auth->check());
        self::assertSame(self::EMAIL, $this->auth->user()?->email);
    }

    public function testItRejectsAWrongPassword(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        try {
            $this->auth->attempt(self::EMAIL, 'wrong password', '127.0.0.1');
        } finally {
            self::assertFalse($this->auth->check());
        }
    }

    public function testItRejectsAnUnknownEmail(): void
    {
        $this->expectException(InvalidCredentialsException::class);

        $this->auth->attempt('nobody@example.test', self::PASSWORD, '127.0.0.1');
    }

    public function testItRejectsAnInactiveAccount(): void
    {
        $this->users->seed('gone@example.test', 'Gone', Role::Staff, $this->hasher->hash(self::PASSWORD), false);

        $this->expectException(InvalidCredentialsException::class);

        $this->auth->attempt('gone@example.test', self::PASSWORD, '127.0.0.1');
    }

    public function testItLocksOutAfterRepeatedFailures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->auth->attempt(self::EMAIL, 'wrong', '10.0.0.1');
            } catch (InvalidCredentialsException) {
                // expected
            }
        }

        $this->expectException(TooManyAttemptsException::class);

        $this->auth->attempt(self::EMAIL, self::PASSWORD, '10.0.0.1');
    }

    public function testItIgnoresEmailCase(): void
    {
        $user = $this->auth->attempt('ADMIN@Example.test', self::PASSWORD, '127.0.0.1');

        self::assertSame(self::EMAIL, $user->email);
    }

    public function testItSignsOut(): void
    {
        $this->auth->attempt(self::EMAIL, self::PASSWORD, '127.0.0.1');
        $this->auth->logout();

        self::assertFalse($this->auth->check());
        self::assertNull($this->auth->user());
    }

    public function testResetTokenIsSingleUse(): void
    {
        $token = $this->link();
        self::assertTrue($this->auth->resetTokenIsValid($token));

        $this->auth->resetPassword($token, 'a-brand-new-password', 'a-brand-new-password');

        self::assertFalse($this->auth->resetTokenIsValid($token));

        $this->expectException(InvalidResetTokenException::class);
        $this->auth->resetPassword($token, 'another-password-here', 'another-password-here');
    }

    public function testResetPasswordReplacesTheOldPassword(): void
    {
        $token = $this->link();
        $this->auth->resetPassword($token, 'a-brand-new-password', 'a-brand-new-password');

        $this->expectException(InvalidCredentialsException::class);
        $this->auth->attempt(self::EMAIL, self::PASSWORD, '127.0.0.1');
    }

    public function testResetRejectsAShortPassword(): void
    {
        $token = $this->link();

        $this->expectException(ValidationException::class);
        $this->auth->resetPassword($token, 'short', 'short');
    }

    public function testResetRejectsMismatchedConfirmation(): void
    {
        $token = $this->link();

        $this->expectException(ValidationException::class);
        $this->auth->resetPassword($token, 'a-brand-new-password', 'different-password');
    }

    public function testUnknownEmailGetsNoResetToken(): void
    {
        self::assertNull($this->auth->issueResetToken('nobody@example.test'));
    }

    public function testAResetLinkNamesItsAccountAndWhenItStopsWorking(): void
    {
        $link = $this->auth->issueResetToken(self::EMAIL);

        self::assertNotNull($link);
        self::assertNotSame('', $link->token);
        self::assertTrue($this->auth->resetTokenIsValid($link->token));

        $expires = strtotime($link->expiresAtUtc . ' UTC');

        self::assertNotFalse($expires);
        // One hour, with room for the second it takes to run the assertion.
        self::assertLessThanOrEqual(5, abs(($expires - time()) - 3600));
    }

    /** The raw token, as the tests used to get it before the link carried more. */
    private function link(): string
    {
        $link = $this->auth->issueResetToken(self::EMAIL);
        self::assertNotNull($link);

        return $link->token;
    }
}
