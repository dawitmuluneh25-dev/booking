<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Role;
use App\Domain\User;
use App\Domain\UserRepository;
use PDO;
use Throwable;

final class PdoUserRepository implements UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findCredentialsByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, name, role, is_active, last_login_at, password_hash
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'user' => $this->hydrate($row),
            'hash' => self::text($row, 'password_hash'),
        ];
    }

    public function findById(int $id): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, name, role, is_active, last_login_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<User> */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, email, name, role, is_active, last_login_at FROM users ORDER BY id'
        );

        if ($statement === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        $users = [];
        foreach ($rows as $row) {
            $users[] = $this->hydrate($row);
        }

        return $users;
    }

    public function create(string $email, string $name, Role $role, string $passwordHash): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (email, name, role, password_hash, is_active, created_at, updated_at)
             VALUES (:email, :name, :role, :password_hash, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => $email,
            'name' => $name,
            'role' => $role->value,
            'password_hash' => $passwordHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = :password_hash, updated_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $statement->execute(['password_hash' => $passwordHash, 'id' => $userId]);
    }

    public function changeRole(int $userId, Role $role): void
    {
        // auth_version is what signs the person out: Auth compares the session's
        // copy with the row on every request, so bumping it here ends every live
        // session for this account on its next request (SEC 6.7).
        $statement = $this->pdo->prepare(
            'UPDATE users
                SET role = :role, auth_version = auth_version + 1, updated_at = UTC_TIMESTAMP()
              WHERE id = :id'
        );
        $statement->execute(['role' => $role->value, 'id' => $userId]);
    }

    public function touchLastLogin(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    public function recordAttempt(string $email, string $ip, bool $succeeded): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, ip, succeeded, created_at)
             VALUES (:email, :ip, :succeeded, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => $email,
            'ip' => $ip,
            'succeeded' => $succeeded ? 1 : 0,
        ]);
    }

    public function recentFailures(string $email, string $ip, int $minutes): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($minutes * 60));

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND ip = :ip AND succeeded = 0 AND created_at >= :cutoff'
        );
        $statement->execute(['email' => $email, 'ip' => $ip, 'cutoff' => $cutoff]);

        return (int) $statement->fetchColumn();
    }

    public function createResetToken(int $userId, string $tokenHash, string $expiresAtUtc): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (:user_id, :token_hash, :expires_at, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAtUtc,
        ]);
    }

    public function resetTokenIsValid(string $tokenHash): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM password_resets
             WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at >= UTC_TIMESTAMP()
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);

        return $statement->fetchColumn() !== false;
    }

    public function consumeResetToken(string $tokenHash): ?int
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'SELECT id, user_id FROM password_resets
                 WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at >= UTC_TIMESTAMP()
                 LIMIT 1
                 FOR UPDATE'
            );
            $statement->execute(['token_hash' => $tokenHash]);

            /** @var array<string, mixed>|false $row */
            $row = $statement->fetch();

            if ($row === false) {
                $this->pdo->commit();

                return null;
            }

            $mark = $this->pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = :id');
            $mark->execute(['id' => self::integer($row, 'id')]);

            $this->pdo->commit();

            return self::integer($row, 'user_id');
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        $lastLogin = $row['last_login_at'] ?? null;

        return new User(
            id: self::integer($row, 'id'),
            email: self::text($row, 'email'),
            name: self::text($row, 'name'),
            role: Role::from(self::text($row, 'role')),
            active: self::integer($row, 'is_active') === 1,
            lastLoginAt: is_string($lastLogin) && $lastLogin !== '' ? $lastLogin : null,
            authVersion: self::integer($row, 'auth_version'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            default => '',
        };
    }

    /** @param array<string, mixed> $row */
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return match (true) {
            is_int($value) => $value,
            is_float($value) => (int) $value,
            is_string($value) && is_numeric($value) => (int) $value,
            default => 0,
        };
    }
}
