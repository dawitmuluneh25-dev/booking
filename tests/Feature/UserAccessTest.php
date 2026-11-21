<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use App\Domain\Actor;
use App\Domain\ChangeUserAccess;
use App\Domain\Role;
use App\Repositories\PdoAuditTrail;
use App\Repositories\PdoUserRepository;
use App\Security\Auth;
use App\Security\InvalidCredentialsException;
use App\Security\PasswordHasher;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Suspending an account, against a real MySQL server.
 *
 * The unit suite proves the command asks for the right thing. Only the database can
 * prove the things that decide whether suspension actually works: that `is_active` and
 * `auth_version` move in the same statement, that the audit row survives with the actor
 * joined back at read time, and that `Auth::attempt` refuses the account afterwards.
 */
final class UserAccessTest extends TestCase
{
    private const ADMIN_EMAIL = 'access-probe-admin@example.test';

    private const STAFF_EMAIL = 'access-probe-staff@example.test';

    private const PASSWORD = 'correct horse battery staple';

    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $config = new Config($root);

        try {
            $this->pdo = Connection::create($config->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
        }

        $this->clean();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $this->clean();
        $this->pdo = null;
        $_SESSION = [];
    }

    public function testSuspensionFlipsTheFlagAndRevokesSessionsInOneStep(): void
    {
        [$adminId, $staffId] = $this->seedAccounts();
        $before = $this->authVersion($staffId);

        $this->command()->execute(new Actor($adminId, Role::Admin), $staffId, false);

        $row = $this->userRow($staffId);

        self::assertSame(0, self::number($row['is_active']), 'the account must be inactive');
        self::assertSame($before + 1, self::number($row['auth_version']), 'auth_version must move with the flag');
    }

    public function testASuspendedAccountCanNoLongerSignIn(): void
    {
        [$adminId, $staffId] = $this->seedAccounts();

        $this->command()->execute(new Actor($adminId, Role::Admin), $staffId, false);

        $this->expectException(InvalidCredentialsException::class);

        (new Auth($this->users(), new PasswordHasher()))->attempt(self::STAFF_EMAIL, self::PASSWORD, '127.0.0.1');
    }

    public function testTheSuspensionIsInTheAuditTrailWithItsActor(): void
    {
        [$adminId, $staffId] = $this->seedAccounts();

        $this->command()->execute(new Actor($adminId, Role::Admin), $staffId, false);

        $rows = $this->auditRows((string) $staffId);

        self::assertCount(1, $rows, 'exactly one row, not one per statement');
        self::assertSame('user.deactivated', $rows[0]['action']);
        self::assertSame($adminId, self::number($rows[0]['actor_user_id']));
        self::assertSame('admin', $rows[0]['actor_role']);
        self::assertSame(self::ADMIN_EMAIL, $rows[0]['actor_email']);
    }

    public function testReactivationPutsTheAccountBack(): void
    {
        [$adminId, $staffId] = $this->seedAccounts();
        $actor = new Actor($adminId, Role::Admin);

        $this->command()->execute($actor, $staffId, false);
        $this->command()->execute($actor, $staffId, true);

        self::assertSame(1, self::number($this->userRow($staffId)['is_active']));

        // And the restored account can sign in again, which is the half that matters to
        // the person on the other end of it.
        $user = (new Auth($this->users(), new PasswordHasher()))
            ->attempt(self::STAFF_EMAIL, self::PASSWORD, '127.0.0.1');

        self::assertSame($staffId, $user->id);

        $actions = array_column($this->auditRows((string) $staffId), 'action');
        self::assertSame(['user.deactivated', 'user.reactivated'], $actions);
    }

    private function command(): ChangeUserAccess
    {
        return new ChangeUserAccess($this->users(), $this->trail(), new PdoTransaction($this->pdo()));
    }

    private function users(): PdoUserRepository
    {
        return new PdoUserRepository($this->pdo());
    }

    private function trail(): PdoAuditTrail
    {
        return new PdoAuditTrail($this->pdo());
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::markTestSkipped('no database connection');
        }

        return $this->pdo;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function seedAccounts(): array
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash(self::PASSWORD);
        $users = $this->users();

        $adminId = $users->create(self::ADMIN_EMAIL, 'Access Probe Admin', Role::Admin, $hash);
        $staffId = $users->create(self::STAFF_EMAIL, 'Access Probe Staff', Role::Staff, $hash);

        return [$adminId, $staffId];
    }

    /**
     * @return array<string, mixed>
     */
    private function userRow(int $id): array
    {
        $statement = $this->pdo()->prepare('SELECT is_active, auth_version FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        return $row;
    }

    private function authVersion(int $id): int
    {
        return self::number($this->userRow($id)['auth_version']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(string $objectRef): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT e.action, e.object_ref, e.actor_user_id, e.actor_role, u.email AS actor_email
               FROM audit_events e
               LEFT JOIN users u ON u.id = e.actor_user_id
              WHERE e.object_type = \'user\' AND e.object_ref = :ref
           ORDER BY e.id'
        );
        $statement->execute(['ref' => $objectRef]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * MySQL hands back integers as strings through PDO, so every comparison here goes
     * through one place rather than a `(int)` cast on a `mixed` row value.
     */
    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function clean(): void
    {
        if ($this->pdo === null) {
            return;
        }

        $emails = [self::ADMIN_EMAIL, self::STAFF_EMAIL];

        $ids = $this->pdo->prepare(
            'SELECT id FROM users WHERE email IN (?, ?)'
        );
        $ids->execute($emails);
        $userIds = $ids->fetchAll(PDO::FETCH_COLUMN);

        $deleteAudit = $this->pdo->prepare(
            'DELETE FROM audit_events
              WHERE actor_user_id = :id
                 OR (object_type = \'user\' AND object_ref = :ref)'
        );

        foreach ($userIds as $id) {
            $deleteAudit->execute(['id' => $id, 'ref' => (string) $id]);
        }

        $this->pdo->prepare('DELETE FROM users WHERE email IN (?, ?)')->execute($emails);
    }
}
