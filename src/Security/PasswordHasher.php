<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Password hashing and policy in one place, so the web flow and the CLI cannot
 * drift apart.
 */
final class PasswordHasher
{
    public const MIN_LENGTH = 12;

    private static ?string $dummyHash = null;

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Burn a comparable amount of time for unknown accounts, so a failed login
     * does not reveal whether the email exists.
     */
    public function verifyAgainstDummy(string $password): void
    {
        self::$dummyHash ??= $this->hash('timing-equalisation-placeholder');
        password_verify($password, self::$dummyHash);
    }

    /**
     * @return array<string, string> field => message, empty when acceptable
     */
    public function policyErrors(string $password, ?string $confirmation = null): array
    {
        $errors = [];

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $errors['password'] = 'Use at least ' . self::MIN_LENGTH . ' characters.';
        }

        if ($confirmation !== null && !hash_equals($password, $confirmation)) {
            $errors['password_confirmation'] = 'The two passwords do not match.';
        }

        return $errors;
    }

    private function algorithm(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? (int) constant('PASSWORD_ARGON2ID') : PASSWORD_DEFAULT;
    }
}
