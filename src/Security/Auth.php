<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User;
use App\Domain\UserRepository;
use App\Domain\ValidationException;

/**
 * Session-backed authentication.
 *
 * The session stores only the user id and the last-seen timestamp; identity is
 * re-read from the database on every request so deactivation and role changes
 * take effect immediately.
 */
final class Auth
{
    private const SESSION_USER = '_auth_user_id';
    private const SESSION_SEEN = '_auth_last_activity';

    private const MAX_FAILURES = 5;
    private const LOCK_MINUTES = 15;
    private const RESET_TTL_SECONDS = 3600;

    private ?User $cached = null;

    private bool $resolved = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly int $idleTimeout = 7200,
    ) {
    }

    /**
     * @throws InvalidCredentialsException|TooManyAttemptsException
     */
    public function attempt(string $email, string $password, string $ip): User
    {
        $email = self::normalizeEmail($email);

        if ($this->users->recentFailures($email, $ip, self::LOCK_MINUTES) >= self::MAX_FAILURES) {
            throw new TooManyAttemptsException(self::LOCK_MINUTES);
        }

        $credentials = $this->users->findCredentialsByEmail($email);

        if ($credentials === null) {
            $this->hasher->verifyAgainstDummy($password);
            $this->users->recordAttempt($email, $ip, false);

            throw new InvalidCredentialsException();
        }

        $valid = $this->hasher->verify($password, $credentials['hash']);
        $ok = $valid && $credentials['user']->active;

        $this->users->recordAttempt($email, $ip, $ok);

        if (!$ok) {
            throw new InvalidCredentialsException();
        }

        $this->users->touchLastLogin($credentials['user']->id);

        // New session id on privilege change: defeats session fixation.
        Session::regenerate();
        Session::put(self::SESSION_USER, (string) $credentials['user']->id);
        Session::put(self::SESSION_SEEN, (string) time());

        $this->cached = $credentials['user'];
        $this->resolved = true;

        return $credentials['user'];
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->cached;
        }

        $this->resolved = true;

        $id = Session::get(self::SESSION_USER);
        if ($id === null || !ctype_digit($id)) {
            return $this->cached = null;
        }

        $seen = (int) Session::get(self::SESSION_SEEN, '0');
        if ($seen > 0 && time() - $seen > $this->idleTimeout) {
            $this->logout();

            return null;
        }

        $user = $this->users->findById((int) $id);
        if ($user === null || !$user->active) {
            $this->logout();

            return null;
        }

        Session::put(self::SESSION_SEEN, (string) time());

        return $this->cached = $user;
    }

    public function logout(): void
    {
        $this->cached = null;
        $this->resolved = true;

        Session::forget(self::SESSION_USER);
        Session::forget(self::SESSION_SEEN);
        Session::regenerate();
    }

    /** Raw token, returned once; only its SHA-256 is stored. */
    public function issueResetToken(string $email): ?string
    {
        $credentials = $this->users->findCredentialsByEmail(self::normalizeEmail($email));
        if ($credentials === null || !$credentials['user']->active) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $this->users->createResetToken(
            $credentials['user']->id,
            self::hashToken($token),
            gmdate('Y-m-d H:i:s', time() + self::RESET_TTL_SECONDS),
        );

        return $token;
    }

    public function resetTokenIsValid(string $token): bool
    {
        return $this->users->resetTokenIsValid(self::hashToken($token));
    }

    /**
     * @throws ValidationException|InvalidResetTokenException
     */
    public function resetPassword(string $token, string $password, string $confirmation): void
    {
        $errors = $this->hasher->policyErrors($password, $confirmation);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $userId = $this->users->consumeResetToken(self::hashToken($token));
        if ($userId === null) {
            throw new InvalidResetTokenException();
        }

        $this->users->updatePassword($userId, $this->hasher->hash($password));
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
