<?php

declare(strict_types=1);

namespace App\Domain;

interface UserRepository
{
    /**
     * Credentials for authentication. The hash is returned separately from the
     * User so it only exists inside the login flow.
     *
     * @return array{user: User, hash: string}|null
     */
    public function findCredentialsByEmail(string $email): ?array;

    public function findById(int $id): ?User;

    /** @return list<User> */
    public function all(): array;

    public function create(string $email, string $name, Role $role, string $passwordHash): int;

    public function updatePassword(int $userId, string $passwordHash): void;

    public function touchLastLogin(int $userId): void;

    public function recordAttempt(string $email, string $ip, bool $succeeded): void;

    public function recentFailures(string $email, string $ip, int $minutes): int;

    public function createResetToken(int $userId, string $tokenHash, string $expiresAtUtc): void;

    /** Returns the user id when the token is unused and unexpired. */
    public function consumeResetToken(string $tokenHash): ?int;

    public function resetTokenIsValid(string $tokenHash): bool;
}
