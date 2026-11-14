<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\PractitionerCredential;
use App\Domain\PractitionerCredentialStatus;
use App\Domain\PractitionerPrivilege;
use App\Domain\PractitionerPrivilegeBasis;
use App\Repositories\PdoPractitionerCredentialRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The credential register against MySQL (Decision 6, AC-009).
 *
 * The constraints are the point of this test, not the round trip: a temporary
 * privilege with no end, a registration number verified twice and a privilege for a
 * procedure the clinic does not perform are all refused by the schema, so a bug that
 * bypassed the commands would still not produce a register that lies.
 *
 * Rows are cleaned up explicitly because the adapter owns no transaction of its own.
 */
final class PractitionerCredentialRepositoryTest extends TestCase
{
    private const NOW = '2026-11-14 09:00:00';

    private ?PDO $pdo = null;

    private ?PdoPractitionerCredentialRepository $repository = null;

    private int $userId = 0;

    private int $verifierId = 0;

    /** @var list<int> */
    private array $credentialIds = [];

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        Env::load($root . '/.env');
        $config = new Config($root);

        try {
            $pdo = Connection::create($config->database());
        } catch (PDOException | RuntimeException $exception) {
            self::markTestSkipped('MySQL is not reachable: ' . $exception->getMessage());
        }

        $this->pdo = $pdo;
        $this->userId = $this->insertUser('credential-probe@example.test', 'Credential Probe');
        $this->verifierId = $this->insertUser('credential-verifier@example.test', 'Verifier Probe');
        $this->repository = new PdoPractitionerCredentialRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach ($this->credentialIds as $id) {
                // Privileges cascade from the credential.
                $this->pdo->exec('DELETE FROM practitioner_credentials WHERE id = ' . $id);
            }

            foreach ([$this->userId, $this->verifierId] as $id) {
                if ($id > 0) {
                    $this->pdo->exec('DELETE FROM users WHERE id = ' . $id);
                }
            }
        }

        $this->credentialIds = [];
        $this->pdo = null;
        $this->repository = null;
    }

    public function testACredentialRoundTripsWithThePrivilegeUnderIt(): void
    {
        $credential = $this->repository()->create($this->credential());
        $this->credentialIds[] = $credential->id;

        $this->repository()->grantPrivilege($this->privilege($credential->id));

        $stored = $this->repository()->forUser($this->userId);

        self::assertCount(1, $stored);
        self::assertSame('ET-IR-0042', $stored[0]->registrationNumber);
        self::assertSame('2026-01-01', $stored[0]->startsOn());
        self::assertSame('2027-01-01', $stored[0]->expiresOnDay());
        self::assertSame(PractitionerCredentialStatus::Verified, $stored[0]->status);
        self::assertSame($this->verifierId, $stored[0]->verifiedByUserId);

        self::assertCount(1, $stored[0]->privileges);
        self::assertSame('image_guided_fnac', $stored[0]->privileges[0]->procedureCode);
        self::assertSame(PractitionerPrivilegeBasis::Own, $stored[0]->privileges[0]->basis);
        self::assertNull($stored[0]->privileges[0]->endsOn());
    }

    public function testTheSameRegistrationNumberCannotBeVerifiedTwiceUnderOneType(): void
    {
        $credential = $this->repository()->create($this->credential());
        $this->credentialIds[] = $credential->id;

        $this->expectException(PDOException::class);

        $this->repository()->create($this->credential());
    }

    public function testAPrivilegeForAProcedureTheClinicDoesNotPerformIsRefused(): void
    {
        $credential = $this->repository()->create($this->credential());
        $this->credentialIds[] = $credential->id;

        $this->expectException(PDOException::class);

        $this->repository()->grantPrivilege(new PractitionerPrivilege(
            id: 0,
            credentialId: $credential->id,
            procedureCode: 'teleportation',
            basis: PractitionerPrivilegeBasis::Own,
            delegatedFromUserId: null,
            supervision: null,
            effectiveFrom: '2026-01-01',
            effectiveTo: null,
            grantedByUserId: $this->verifierId,
            grantedAtUtc: self::NOW,
            note: null,
        ));
    }

    /** §16.3: temporary coverage has to end, and the schema says so. */
    public function testTemporaryCoverageWithNoEndDateIsRefusedByTheSchema(): void
    {
        $credential = $this->repository()->create($this->credential());
        $this->credentialIds[] = $credential->id;

        $this->expectException(PDOException::class);

        $this->repository()->grantPrivilege(new PractitionerPrivilege(
            id: 0,
            credentialId: $credential->id,
            procedureCode: 'image_guided_fnac',
            basis: PractitionerPrivilegeBasis::TemporaryCoverage,
            delegatedFromUserId: $this->verifierId,
            supervision: null,
            effectiveFrom: '2026-01-01',
            effectiveTo: null,
            grantedByUserId: $this->verifierId,
            grantedAtUtc: self::NOW,
            note: null,
        ));
    }

    public function testACredentialThatExpiresBeforeItStartsIsRefused(): void
    {
        $this->expectException(PDOException::class);

        $this->repository()->create(new PractitionerCredential(
            id: 0,
            userId: $this->userId,
            credentialType: 'Specialist registration',
            registrationNumber: 'ET-IR-0001',
            qualifications: null,
            evidenceReference: 'Register entry checked on the day.',
            institutionalPrivilegeStatus: null,
            supervisionRestriction: null,
            status: PractitionerCredentialStatus::Verified,
            verifiedByUserId: $this->verifierId,
            verifiedAtUtc: self::NOW,
            effectiveFrom: '2026-06-01',
            expiresOn: '2026-05-01',
            createdAtUtc: self::NOW,
        ));
    }

    public function testStatusChangesAreStoredAndReadBack(): void
    {
        $credential = $this->repository()->create($this->credential());
        $this->credentialIds[] = $credential->id;

        $this->repository()->changeStatus($credential->id, PractitionerCredentialStatus::Suspended, self::NOW);

        $stored = $this->repository()->findCredential($credential->id);
        self::assertNotNull($stored);
        self::assertSame(PractitionerCredentialStatus::Suspended, $stored->status);
    }

    public function testEndingAPrivilegeIsGuardedAgainstASecondWrite(): void
    {
        $credential = $this->repository()->create($this->credential());
        $this->credentialIds[] = $credential->id;
        $privilege = $this->repository()->grantPrivilege($this->privilege($credential->id));

        $this->repository()->endPrivilege($privilege->id, '2026-12-31');
        // A second administrator pressing the same button must not move the date.
        $this->repository()->endPrivilege($privilege->id, '2027-06-30');

        $stored = $this->repository()->findPrivilege($privilege->id);
        self::assertNotNull($stored);
        self::assertSame('2026-12-31', $stored->endsOn());
    }

    public function testACredentialWithNoPrivilegeReadsAsOneWithNone(): void
    {
        $credential = $this->repository()->create($this->credential());
        $this->credentialIds[] = $credential->id;

        $stored = $this->repository()->findCredential($credential->id);

        self::assertNotNull($stored);
        self::assertSame([], $stored->privileges);
    }

    private function credential(): PractitionerCredential
    {
        return new PractitionerCredential(
            id: 0,
            userId: $this->userId,
            credentialType: 'Specialist registration',
            registrationNumber: 'ET-IR-0042',
            qualifications: 'Interventional radiology fellowship.',
            evidenceReference: 'Registration register entry checked 14 November 2026.',
            institutionalPrivilegeStatus: null,
            supervisionRestriction: null,
            status: PractitionerCredentialStatus::Verified,
            verifiedByUserId: $this->verifierId,
            verifiedAtUtc: self::NOW,
            effectiveFrom: '2026-01-01',
            expiresOn: '2027-01-01',
            createdAtUtc: self::NOW,
        );
    }

    private function privilege(int $credentialId): PractitionerPrivilege
    {
        return new PractitionerPrivilege(
            id: 0,
            credentialId: $credentialId,
            procedureCode: 'image_guided_fnac',
            basis: PractitionerPrivilegeBasis::Own,
            delegatedFromUserId: null,
            supervision: null,
            effectiveFrom: '2026-01-01',
            effectiveTo: null,
            grantedByUserId: $this->verifierId,
            grantedAtUtc: self::NOW,
            note: null,
        );
    }

    private function insertUser(string $email, string $name): int
    {
        // Native prepares refuse a named placeholder used twice, so the two
        // timestamps carry separate names rather than one reused one.
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, is_active, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, 1, :created_at, :updated_at)'
        );
        $statement->execute([
            'email' => $email,
            'name' => $name,
            'role' => 'radiologist',
            'hash' => '$2y$04$notarealhashthecolumnonlyneedswidth',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function repository(): PdoPractitionerCredentialRepository
    {
        $repository = $this->repository;
        self::assertInstanceOf(PdoPractitionerCredentialRepository::class, $repository);

        return $repository;
    }

    private function pdo(): PDO
    {
        $pdo = $this->pdo;
        self::assertInstanceOf(PDO::class, $pdo);

        return $pdo;
    }
}
