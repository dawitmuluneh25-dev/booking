<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\Actor;
use App\Domain\Idempotency;
use App\Domain\IdempotencyState;
use App\Domain\Role;
use App\Repositories\PdoIdempotencyStore;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The store's job is to make a second identical request harmless without making a
 * different request look identical. Both halves are checked against MySQL, because
 * the uniqueness that makes the first half work is a database constraint.
 */
final class PdoIdempotencyStoreTest extends TestCase
{
    private const SCOPE = 'test.submit';

    private const NOW = '2026-11-08 09:00:00';

    private ?PDO $pdo = null;

    private ?PdoIdempotencyStore $store = null;

    private int $actorUserId = 0;

    /** @var list<string> */
    private array $tokens = [];

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

        $this->actorUserId = $this->insertUser();
        $this->store = new PdoIdempotencyStore($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo === null) {
            $this->pdo = null;

            return;
        }

        foreach ($this->tokens as $token) {
            $delete = $this->pdo->prepare('DELETE FROM idempotency_keys WHERE key_hash = :hash');
            $delete->execute(['hash' => Idempotency::hashKey($token)]);
        }

        $this->pdo->exec("DELETE FROM users WHERE email LIKE 'idem-probe-%'");
        $this->pdo = null;
        $this->store = null;
    }

    public function testAnUnseenKeyIsFresh(): void
    {
        $claim = $this->store()->claim(self::SCOPE, $this->token('fresh'), 'fingerprint-a', $this->actor(), self::NOW);

        self::assertSame(IdempotencyState::New, $claim->state);
        self::assertTrue($claim->shouldProceed());
    }

    public function testACompletedClaimReplaysWithItsReference(): void
    {
        $token = $this->token('replay');

        $this->store()->claim(self::SCOPE, $token, 'fingerprint-a', $this->actor(), self::NOW);
        $this->store()->complete(self::SCOPE, $token, 'RF-260108-ABC123', self::NOW);

        $claim = $this->store()->claim(self::SCOPE, $token, 'fingerprint-a', $this->actor(), self::NOW);

        self::assertSame(IdempotencyState::Replay, $claim->state);
        self::assertSame('RF-260108-ABC123', $claim->reference);
        self::assertFalse($claim->shouldProceed());
    }

    public function testTheSameKeyWithADifferentRequestIsRefused(): void
    {
        $token = $this->token('conflict');

        $this->store()->claim(self::SCOPE, $token, 'fingerprint-a', $this->actor(), self::NOW);
        $this->store()->complete(self::SCOPE, $token, 'RF-260108-ABC123', self::NOW);

        $claim = $this->store()->claim(self::SCOPE, $token, 'fingerprint-b', $this->actor(), self::NOW);

        self::assertSame(IdempotencyState::Conflict, $claim->state);
        self::assertStringContainsString('different request', $claim->reason);
    }

    public function testAClaimThatNeverFinishedBlocksASecondAttempt(): void
    {
        $token = $this->token('in-progress');

        $this->store()->claim(self::SCOPE, $token, 'fingerprint-a', $this->actor(), self::NOW);

        // No complete(): the first attempt is presumed to still be running.
        $claim = $this->store()->claim(self::SCOPE, $token, 'fingerprint-a', $this->actor(), self::NOW);

        self::assertSame(IdempotencyState::Conflict, $claim->state);
        self::assertStringContainsString('still running', $claim->reason);
    }

    public function testTheSameKeyInAnotherScopeIsIndependent(): void
    {
        $token = $this->token('scoped');

        $this->store()->claim(self::SCOPE, $token, 'fingerprint-a', $this->actor(), self::NOW);
        $this->store()->complete(self::SCOPE, $token, 'RF-1', self::NOW);

        $claim = $this->store()->claim('test.other', $token, 'fingerprint-b', $this->actor(), self::NOW);

        self::assertSame(IdempotencyState::New, $claim->state);
    }

    public function testTheRawTokenIsNeverStored(): void
    {
        $token = $this->token('hashed');
        $this->store()->claim(self::SCOPE, $token, 'fingerprint-a', $this->actor(), self::NOW);

        $leak = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM idempotency_keys WHERE key_hash = :raw OR CAST(key_hash AS CHAR) LIKE :like'
        );
        $leak->execute(['raw' => $token, 'like' => '%' . substr($token, 0, 6) . '%']);
        self::assertSame(0, (int) $leak->fetchColumn());

        $stored = $this->pdo()->prepare('SELECT key_hash FROM idempotency_keys WHERE key_hash = :hash');
        $stored->execute(['hash' => Idempotency::hashKey($token)]);
        self::assertSame(Idempotency::hashKey($token), $stored->fetchColumn());
    }

    public function testAnInvalidTokenIsRefusedBeforeTheDatabaseIsTouched(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store()->claim(self::SCOPE, 'short', 'fingerprint-a', $this->actor(), self::NOW);
    }

    private function store(): PdoIdempotencyStore
    {
        if ($this->store === null) {
            throw new RuntimeException('The store was not initialised.');
        }

        return $this->store;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The connection was not initialised.');
        }

        return $this->pdo;
    }

    private function actor(): Actor
    {
        return new Actor($this->actorUserId, Role::Staff);
    }

    private function token(string $suffix): string
    {
        $token = 'idem-token-' . $suffix . '-' . bin2hex(random_bytes(8));
        $this->tokens[] = $token;

        return $token;
    }

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => 'idem-probe-' . bin2hex(random_bytes(6)) . '@example.test',
            'name' => 'Idempotency Probe',
            'role' => 'staff',
            'hash' => str_repeat('0', 60),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }
}
