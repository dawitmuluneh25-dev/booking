<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use App\Domain\ReferralTrackingLink;
use App\Domain\Role;
use App\Domain\User;
use App\Repositories\PdoReferralRepository;
use App\Repositories\PdoTrackingTokenRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The single-use status link against MySQL.
 *
 * This exists because the unit tests replace the token store with an array, and
 * an array cannot reproduce what the driver does with a prepared statement. The
 * adapter reused one named placeholder for two positions in a single UPDATE;
 * with emulated prepares off that is invalid at bind time, so every public status
 * link answered 500 while the whole unit suite stayed green. Consuming a token is
 * therefore asserted here, against the real table and the real binding rules.
 */
final class TrackingTokenTest extends TestCase
{
    private const NOW = '2026-11-09 09:00:00';

    private ?PDO $pdo = null;

    private ?PdoTrackingTokenRepository $tokens = null;

    private int $userId = 0;

    private int $referralId = 0;

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

        $this->userId = $this->insertUser();
        $this->referralId = $this->insertReferral();
        $this->tokens = new PdoTrackingTokenRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DELETE FROM referral_tracking_tokens WHERE referral_id = ' . $this->referralId);
            $this->pdo->exec('DELETE FROM referrals WHERE id = ' . $this->referralId);
            $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->userId);
        }

        $this->pdo = null;
        $this->tokens = null;
    }

    public function testATokenIsConsumedExactlyOnce(): void
    {
        $hash = hash('sha256', 'first-link-' . bin2hex(random_bytes(6)));

        $this->tokens()->issue($this->referralId, $hash, '2026-11-10 09:00:00', $this->userId, self::NOW);

        self::assertSame($this->referralId, $this->tokens()->consume($hash, self::NOW));
        self::assertNull($this->tokens()->consume($hash, self::NOW), 'A spent link was redeemed twice.');
    }

    public function testAnExpiredTokenIsNotConsumed(): void
    {
        $hash = hash('sha256', 'stale-link-' . bin2hex(random_bytes(6)));

        $this->tokens()->issue($this->referralId, $hash, '2026-11-09 08:59:59', $this->userId, '2026-11-09 08:00:00');

        self::assertNull($this->tokens()->consume($hash, self::NOW));
    }

    public function testAnUnknownTokenIsNotConsumed(): void
    {
        self::assertNull($this->tokens()->consume(hash('sha256', 'never-issued'), self::NOW));
    }

    /**
     * The whole path the browser takes: issue through the domain service, redeem
     * the token, follow it to the referral.
     */
    public function testTheDomainServiceIssuesAReusableOnlyOnceLink(): void
    {
        $referrals = new PdoReferralRepository($this->pdo(), new PdoTransaction($this->pdo()));
        $link = new ReferralTrackingLink($this->tokens(), $referrals, 60);

        $referral = $referrals->findById($this->referralId);
        self::assertNotNull($referral);

        $token = $link->issue($this->user(), $referral, self::NOW);
        self::assertSame(64, strlen($token));

        $redeemed = $link->redeem($token, self::NOW);
        self::assertNotNull($redeemed);
        self::assertSame($this->referralId, $redeemed->id);

        self::assertNull($link->redeem($token, self::NOW), 'The link survived being opened.');
    }

    private function tokens(): PdoTrackingTokenRepository
    {
        if ($this->tokens === null) {
            throw new RuntimeException('The token store was not initialised.');
        }

        return $this->tokens;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The connection was not initialised.');
        }

        return $this->pdo;
    }

    private function user(): User
    {
        return new User($this->userId, 'token-probe@example.test', 'Token Probe', Role::Staff, true);
    }

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => 'token-probe-' . bin2hex(random_bytes(6)) . '@example.test',
            'name' => 'Token Probe',
            'role' => 'staff',
            'hash' => str_repeat('0', 60),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertReferral(): int
    {
        $statement = $this->pdo()->prepare(
            "INSERT INTO referrals
                (reference, status, patient_name, patient_phone, procedure_code,
                 submitted_by_user_id, submitted_on_behalf_of, created_at, updated_at)
             VALUES (:reference, 'submitted', 'Token Probe', '+251911000444',
                 'image_guided_biopsy', :user_id, 'staff', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute([
            'reference' => 'RF-TOKEN-' . bin2hex(random_bytes(4)),
            'user_id' => $this->userId,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }
}
