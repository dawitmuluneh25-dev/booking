<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use App\Domain\AccountRequestStatus;
use App\Domain\AccountRequestThrottle;
use App\Domain\Actor;
use App\Domain\ApproveAccountRequest;
use App\Domain\CreateUserAccount;
use App\Domain\PhysicianStatus;
use App\Domain\RejectAccountRequest;
use App\Domain\RequestAccount;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Repositories\PdoAccountRequestAttempts;
use App\Repositories\PdoAccountRequestRepository;
use App\Repositories\PdoAuditTrail;
use App\Repositories\PdoPhysicianProfileRepository;
use App\Repositories\PdoUserRepository;
use App\Security\PasswordHasher;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FixedHumanCheck;

/**
 * The whole path against MySQL: a stranger submits, an administrator approves, and
 * the account that appears holds no referral authority until a clinical approver
 * verifies it.
 *
 * The negative cases are the point. "Approved twice" must not produce a second
 * account, and "approved" must not quietly mean "may refer".
 */
final class AccountRequestFlowTest extends TestCase
{
    private const PREFIX = 'acct-req-probe-';

    private ?PDO $pdo = null;

    private ?PdoAccountRequestRepository $requests = null;

    private ?Actor $admin = null;

    private ?ApproveAccountRequest $approve = null;

    private ?RejectAccountRequest $reject = null;

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

        $this->requests = new PdoAccountRequestRepository($this->pdo);

        $transaction = new PdoTransaction($this->pdo);
        $users = new PdoUserRepository($this->pdo);
        $audit = new PdoAuditTrail($this->pdo);

        // The acting administrator is inserted directly: `CreateUserAccount` is the
        // subject of one of these tests, and it cannot be the thing that creates the
        // actor allowed to call it.
        $insert = $this->pdo->prepare(
            'INSERT INTO users (email, name, role, password_hash, is_active, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        );
        $insert->execute([
            'email' => self::PREFIX . 'admin-' . bin2hex(random_bytes(4)) . '@example.test',
            'name' => 'Probe Administrator',
            'role' => Role::Admin->value,
            'hash' => 'not-a-login',
        ]);

        $this->admin = new Actor((int) $this->pdo->lastInsertId(), Role::Admin);

        $this->approve = new ApproveAccountRequest(
            $this->requests,
            new CreateUserAccount($users, new PasswordHasher(), $audit, $transaction),
            new PdoPhysicianProfileRepository($this->pdo, $transaction),
            $audit,
            $transaction,
        );

        $this->reject = new RejectAccountRequest($this->requests, $audit);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            // Requests first: they reference the created account with ON DELETE RESTRICT.
            $this->pdo->exec(
                "DELETE FROM account_requests WHERE email LIKE '" . self::PREFIX . "%'",
            );
            $this->pdo->exec(
                'DELETE FROM audit_events WHERE actor_user_id IN '
                . "(SELECT id FROM users WHERE email LIKE '" . self::PREFIX . "%')",
            );
            $this->pdo->exec(
                "DELETE FROM users WHERE email LIKE '" . self::PREFIX . "%'",
            );
        }

        $this->pdo = null;
        $this->requests = null;
        $this->admin = null;
        $this->approve = null;
        $this->reject = null;
    }

    public function testASubmissionIsStoredAndFindableByReference(): void
    {
        $reference = $this->submit();

        $pending = $this->requests()->pending();

        self::assertCount(1, $pending);
        self::assertSame($reference, $pending[0]->reference);
        self::assertSame(AccountRequestStatus::Pending, $pending[0]->status);
    }

    public function testTheSamePersonAndPracticeCollapsesToTheFirstRequest(): void
    {
        $email = self::PREFIX . 'duplicate@example.test';

        $first = $this->submit($email);
        $second = $this->submit($email);

        self::assertSame($first, $second);
        self::assertCount(1, $this->requests()->pending());
    }

    public function testApprovalCreatesOnePhysicianAccountWithAPendingProfile(): void
    {
        $reference = $this->submit();
        $request = $this->requests()->pending()[0];

        $result = $this->approve()->execute($this->admin(), $request->id);

        self::assertSame(Role::Physician, $result['user']->role);
        self::assertSame(PhysicianStatus::Pending, $result['profile']->status);

        $decided = $this->requests()->findById($request->id);
        self::assertNotNull($decided);
        self::assertSame(AccountRequestStatus::Approved, $decided->status);
        self::assertSame($result['user']->id, $decided->createdUserId);
        self::assertSame($reference, $decided->reference);

        // Referral authority is the second act: the account exists, the profile does not
        // permit a referral yet.
        self::assertNull($result['profile']->verifiedAtUtc);
    }

    public function testApprovingTwiceCreatesNothingTheSecondTime(): void
    {
        $this->submit();
        $request = $this->requests()->pending()[0];

        $this->approve()->execute($this->admin(), $request->id);

        $this->expectException(ValidationException::class);

        try {
            $this->approve()->execute($this->admin(), $request->id);
        } finally {
            $statement = $this->pdo()->prepare(
                'SELECT COUNT(*) FROM users WHERE email LIKE :prefix',
            );
            $statement->execute(['prefix' => self::PREFIX . '%']);

            // The probe administrator plus exactly one approved physician.
            self::assertSame(2, (int) $statement->fetchColumn());
        }
    }

    public function testARejectionNeedsAReasonAndCreatesNoAccount(): void
    {
        $this->submit();
        $request = $this->requests()->pending()[0];

        try {
            $this->reject()->execute($this->admin(), $request->id, 'no');
            self::fail('A one-word reason should be refused.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reason', $exception->errors);
        }

        $rejected = $this->reject()->execute(
            $this->admin(),
            $request->id,
            'Registration number could not be confirmed with the council.',
        );

        self::assertSame(AccountRequestStatus::Rejected, $rejected->status);
        self::assertNull($rejected->createdUserId);
        self::assertNotNull($rejected->decidedAtUtc);
        self::assertSame($this->admin()->userId, $rejected->decidedByUserId);

        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM users WHERE email LIKE :prefix AND role = :role',
        );
        $statement->execute(['prefix' => self::PREFIX . '%', 'role' => Role::Physician->value]);

        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testExpiryClosesOnlyTheRequestsOlderThanTheCutoff(): void
    {
        $this->submit();

        // A cutoff before anything was created leaves the queue alone.
        self::assertSame(0, $this->requests()->expirePendingOlderThan('2000-01-01 00:00:00'));
        self::assertSame(1, $this->requests()->countPending());

        self::assertSame(1, $this->requests()->expirePendingOlderThan('2099-01-01 00:00:00'));
        self::assertSame(0, $this->requests()->countPending());
    }

    private function submit(?string $email = null): string
    {
        $command = new RequestAccount(
            $this->requests(),
            new AccountRequestThrottle(new PdoAccountRequestAttempts($this->pdo())),
            new FixedHumanCheck(),
            new PdoAuditTrail($this->pdo()),
        );

        return $command->execute([
            'full_name' => 'Dr. Probe Referrer',
            'email' => $email ?? self::PREFIX . 'referrer-' . bin2hex(random_bytes(5)) . '@example.test',
            'practice_name' => 'Probe Practice',
            'practice_city' => 'Addis Ababa',
            'registration_no' => '',
            'note' => '',
            'website' => '',
            'check_token' => 'token',
            'check_answer' => '11',
        ], '203.0.113.' . random_int(1, 250));
    }

    private function pdo(): PDO
    {
        \assert($this->pdo instanceof PDO);

        return $this->pdo;
    }

    private function requests(): PdoAccountRequestRepository
    {
        \assert($this->requests instanceof PdoAccountRequestRepository);

        return $this->requests;
    }

    private function admin(): Actor
    {
        \assert($this->admin instanceof Actor);

        return $this->admin;
    }

    private function approve(): ApproveAccountRequest
    {
        \assert($this->approve instanceof ApproveAccountRequest);

        return $this->approve;
    }

    private function reject(): RejectAccountRequest
    {
        \assert($this->reject instanceof RejectAccountRequest);

        return $this->reject;
    }
}
