<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use App\Domain\Actor;
use App\Domain\AuditAction;
use App\Domain\AuditEntry;
use App\Domain\AuditQuery;
use App\Domain\ChangeUserRole;
use App\Domain\CreateUserAccount;
use App\Domain\Role;
use App\Repositories\PdoAuditTrail;
use App\Repositories\PdoUserRepository;
use App\Security\PasswordHasher;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The reading and writing half of `audit_events`, against a real MySQL server.
 *
 * The unit suite can prove that a command asked for the right row; only the
 * database can prove that the row is stored, that the actor's email is joined
 * back at read time, and that a filter does not quietly scan-and-drop.
 */
final class AuditTrailRepositoryTest extends TestCase
{
    private const REQUEST_ID = 'auditprobe000001';

    private const ADMIN_EMAIL = 'audit-probe-admin@example.test';

    private const STAFF_EMAIL = 'audit-probe-staff@example.test';

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
    }

    protected function tearDown(): void
    {
        $this->clean();
        $this->pdo = null;
    }

    public function testARoleChangeWritesExactlyOneRowNamingActorTargetAndBothRoles(): void
    {
        [$adminId, $targetId] = $this->seedAccounts();

        $this->changeRole($adminId, $targetId, Role::Radiologist);

        $rows = $this->rows();

        self::assertCount(1, $rows, 'A role change must produce exactly one audit row.');
        self::assertSame('user.role_changed', $rows[0]['action']);
        self::assertSame((string) $targetId, $rows[0]['object_ref']);
        self::assertSame($adminId, self::number($rows[0]['actor_user_id']));
        self::assertSame('admin', $rows[0]['actor_role']);
        self::assertSame(self::ADMIN_EMAIL, $rows[0]['actor_email']);

        $before = $this->json($rows[0]['before_json']);
        $after = $this->json($rows[0]['after_json']);

        self::assertSame('staff', $before['role'] ?? null);
        self::assertSame('radiologist', $after['role'] ?? null);
    }

    public function testAnAccountCreationIsRecordedWithoutTheTemporaryPassword(): void
    {
        $this->seedAccounts();
        $adminId = $this->userId(self::ADMIN_EMAIL);

        $command = new CreateUserAccount(
            $this->users(),
            new PasswordHasher(),
            $this->trail(),
            new PdoTransaction($this->pdo()),
        );

        $created = $command->execute(new Actor($adminId, Role::Admin), [
            'email' => 'audit-probe-created@example.test',
            'name' => 'Created Probe',
            'role' => 'staff',
        ]);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('user.created', $rows[0]['action']);

        $after = $this->json($rows[0]['after_json']);
        self::assertSame('audit-probe-created@example.test', $after['email'] ?? null);

        // The temporary password is shown once to the administrator and stored
        // nowhere; the audit trail is readable, so it must not appear here.
        $password = $created['temporary_password'];
        self::assertNotSame('', $password);
        self::assertStringNotContainsString($password, self::text($rows[0]['after_json']));
    }

    public function testTheFiltersNarrowByActionActorObjectAndDate(): void
    {
        [$adminId, $targetId] = $this->seedAccounts();
        $this->changeRole($adminId, $targetId, Role::Radiologist);

        $trail = $this->trail();

        self::assertCount(1, $trail->search(new AuditQuery(action: AuditAction::UserRoleChanged->value)));
        self::assertCount(0, $trail->search(new AuditQuery(action: AuditAction::UserCreated->value)));

        // An email fragment is what an operator has when reading a log line.
        self::assertCount(1, $trail->search(new AuditQuery(actor: 'audit-probe-admin')));
        self::assertCount(0, $trail->search(new AuditQuery(actor: 'nobody-by-that-name')));

        self::assertCount(1, $trail->search(new AuditQuery(objectRef: (string) $targetId)));
        self::assertCount(0, $trail->search(new AuditQuery(objectRef: (string) ($targetId + 500))));

        self::assertSame(1, $trail->count(new AuditQuery(action: AuditAction::UserRoleChanged->value)));
        self::assertSame(0, $trail->count(new AuditQuery(actor: 'nobody-by-that-name')));
    }

    public function testTheDateRangeUsesUtcBounds(): void
    {
        [$adminId, $targetId] = $this->seedAccounts();
        $this->changeRole($adminId, $targetId, Role::Radiologist);

        $trail = $this->trail();
        $yesterday = gmdate('Y-m-d H:i:s', time() - 86400);
        $tomorrow = gmdate('Y-m-d H:i:s', time() + 86400);
        $nextWeek = gmdate('Y-m-d H:i:s', time() + 7 * 86400);

        self::assertCount(1, $trail->search(new AuditQuery(fromUtc: $yesterday, toUtc: $tomorrow)));
        self::assertCount(0, $trail->search(new AuditQuery(fromUtc: $nextWeek)));
        self::assertCount(0, $trail->search(new AuditQuery(toUtc: $yesterday)));
    }

    public function testTheStoredRowsCarryNoPatientIdentifiers(): void
    {
        [$adminId, $targetId] = $this->seedAccounts();
        $this->changeRole($adminId, $targetId, Role::Radiologist);

        $rows = $this->rows();
        self::assertNotSame([], $rows);

        foreach ($rows as $row) {
            // Only the columns that could carry content. `occurred_at` and `id` are
            // digits by nature and would match a naive phone pattern.
            $serialised = implode('|', [
                self::text($row['action'] ?? null),
                self::text($row['object_type'] ?? null),
                self::text($row['object_ref'] ?? null),
                self::text($row['before_json'] ?? null),
                self::text($row['after_json'] ?? null),
            ]);

            // No phone number, no clinical free text, no patient name: the table
            // stores identifiers and vocabulary values, and this asserts the
            // promise rather than trusting the comment that makes it.
            self::assertDoesNotMatchRegularExpression('/\+?[0-9][0-9 ()-]{7,}/', $serialised);
            self::assertStringNotContainsStringIgnoringCase('patient', $serialised);
            self::assertStringNotContainsStringIgnoringCase('history of', $serialised);
        }
    }

    /** @return array{0: int, 1: int} */
    private function seedAccounts(): array
    {
        $users = $this->users();
        $hash = str_repeat('0', 60);

        $adminId = $users->create(self::ADMIN_EMAIL, 'Audit Probe Admin', Role::Admin, $hash);
        $targetId = $users->create(self::STAFF_EMAIL, 'Audit Probe Staff', Role::Staff, $hash);

        return [$adminId, $targetId];
    }

    private function changeRole(int $adminId, int $targetId, Role $role): void
    {
        (new ChangeUserRole(
            $this->users(),
            $this->trail(),
            new PdoTransaction($this->pdo()),
        ))->execute(new Actor($adminId, Role::Admin), $targetId, $role);
    }

    private function users(): PdoUserRepository
    {
        return new PdoUserRepository($this->pdo());
    }

    private function trail(): PdoAuditTrail
    {
        return new PdoAuditTrail($this->pdo(), self::REQUEST_ID);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT a.*, u.email AS actor_email
             FROM audit_events AS a
             LEFT JOIN users AS u ON u.id = a.actor_user_id
             WHERE a.request_id = :request_id
             ORDER BY a.id'
        );
        $statement->execute(['request_id' => self::REQUEST_ID]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        /** @var array<string, mixed> $decoded */
        return is_array($decoded) ? $decoded : [];
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function userId(string $email): int
    {
        $statement = $this->pdo()->prepare('SELECT id FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);

        return (int) $statement->fetchColumn();
    }

    private function clean(): void
    {
        if ($this->pdo === null) {
            return;
        }

        // Audit rows first: the actor reference is RESTRICT, so a user with
        // history cannot be deleted and the order is not optional.
        $statement = $this->pdo->prepare('DELETE FROM audit_events WHERE request_id = :request_id');
        $statement->execute(['request_id' => self::REQUEST_ID]);

        $this->pdo->exec("DELETE FROM users WHERE email LIKE 'audit-probe-%'");
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('No database connection.');
        }

        return $this->pdo;
    }
}
