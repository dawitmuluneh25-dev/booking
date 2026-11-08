<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Role;
use App\Domain\User;
use App\Domain\UserRepository;

/**
 * In-memory auth store that mirrors the PDO contract, including single-use
 * reset tokens and failure counting.
 */
final class InMemoryUserRepository implements UserRepository
{
    /** @var array<int, array{user: User, hash: string}> */
    private array $rows = [];

    /** @var list<array{email: string, ip: string, ok: bool, at: int}> */
    private array $attempts = [];

    /** @var array<string, array{user_id: int, expires: int, used: bool}> */
    private array $resets = [];

    private int $nextId = 1;

    public function seed(string $email, string $name, Role $role, string $hash, bool $active = true): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'user' => new User($id, $email, $name, $role, $active),
            'hash' => $hash,
        ];

        return $id;
    }

    public function findCredentialsByEmail(string $email): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['user']->email === $email) {
                return ['user' => $row['user'], 'hash' => $row['hash']];
            }
        }

        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->rows[$id]['user'] ?? null;
    }

    /** @return list<User> */
    public function all(): array
    {
        return array_values(array_map(static fn (array $row): User => $row['user'], $this->rows));
    }

    public function create(string $email, string $name, Role $role, string $passwordHash): int
    {
        return $this->seed($email, $name, $role, $passwordHash);
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        if (isset($this->rows[$userId])) {
            $this->rows[$userId]['hash'] = $passwordHash;
        }
    }

    public function touchLastLogin(int $userId): void
    {
        if (!isset($this->rows[$userId])) {
            return;
        }

        $user = $this->rows[$userId]['user'];
        $this->rows[$userId]['user'] = new User(
            $user->id,
            $user->email,
            $user->name,
            $user->role,
            $user->active,
            gmdate('Y-m-d H:i:s'),
        );
    }

    public function recordAttempt(string $email, string $ip, bool $succeeded): void
    {
        $this->attempts[] = ['email' => $email, 'ip' => $ip, 'ok' => $succeeded, 'at' => time()];
    }

    public function recentFailures(string $email, string $ip, int $minutes): int
    {
        $cutoff = time() - ($minutes * 60);
        $count = 0;

        foreach ($this->attempts as $attempt) {
            $sameSubject = $attempt['email'] === $email && $attempt['ip'] === $ip;
            if (!$attempt['ok'] && $sameSubject && $attempt['at'] >= $cutoff) {
                $count++;
            }
        }

        return $count;
    }

    public function createResetToken(int $userId, string $tokenHash, string $expiresAtUtc): void
    {
        $this->resets[$tokenHash] = [
            'user_id' => $userId,
            'expires' => (int) strtotime($expiresAtUtc . ' UTC'),
            'used' => false,
        ];
    }

    public function resetTokenIsValid(string $tokenHash): bool
    {
        return isset($this->resets[$tokenHash])
            && !$this->resets[$tokenHash]['used']
            && $this->resets[$tokenHash]['expires'] >= time();
    }

    public function consumeResetToken(string $tokenHash): ?int
    {
        $reset = $this->resets[$tokenHash] ?? null;

        if ($reset === null || $reset['used'] || $reset['expires'] < time()) {
            return null;
        }

        $this->resets[$tokenHash]['used'] = true;

        return $reset['user_id'];
    }
}
