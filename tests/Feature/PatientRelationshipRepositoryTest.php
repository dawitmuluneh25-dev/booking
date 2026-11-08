<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Repositories\PdoPatientRelationshipRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Guardian authority against MySQL: the filters are in the query, so the query is
 * what has to be tested.
 */
final class PatientRelationshipRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;

    private ?PdoPatientRelationshipRepository $repository = null;

    /** @var list<int> */
    private array $userIds = [];

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

        $this->repository = new PdoPatientRelationshipRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach ($this->userIds as $id) {
                $this->pdo->exec('DELETE FROM patient_relationships WHERE patient_user_id = ' . $id);
                $this->pdo->exec('DELETE FROM users WHERE id = ' . $id);
            }
        }

        $this->userIds = [];
        $this->pdo = null;
        $this->repository = null;
    }

    public function testAnActingGuardianIsResolvedForTheirPatient(): void
    {
        $patient = $this->insertUser('patient');
        $guardian = $this->insertUser('guardian');

        $this->relate($patient, $guardian, 'act', null);

        self::assertSame([$patient], $this->repository()->patientsActedForBy($guardian));
        self::assertTrue($this->repository()->actsFor($guardian, $patient));
    }

    public function testAViewOnlyRelationshipDoesNotGrantActingAuthority(): void
    {
        $patient = $this->insertUser('patient');
        $relative = $this->insertUser('relative');

        $this->relate($patient, $relative, 'view', null);

        self::assertSame([], $this->repository()->patientsActedForBy($relative));
        self::assertFalse($this->repository()->actsFor($relative, $patient));
    }

    public function testAnEndedRelationshipGrantsNothing(): void
    {
        $patient = $this->insertUser('patient');
        $former = $this->insertUser('former');

        $this->relate($patient, $former, 'act', '2026-10-01 00:00:00');

        self::assertFalse($this->repository()->actsFor($former, $patient));
    }

    private function relate(int $patient, int $related, string $authority, ?string $endedAt): void
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO patient_relationships
                (patient_user_id, related_user_id, relationship, authority, verified_at, ended_at, created_at)
             VALUES (:patient, :related, :relationship, :authority, NULL, :ended_at, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'patient' => $patient,
            'related' => $related,
            'relationship' => 'guardian',
            'authority' => $authority,
            'ended_at' => $endedAt,
        ]);
    }

    private function repository(): PdoPatientRelationshipRepository
    {
        if ($this->repository === null) {
            throw new RuntimeException('The repository was not initialised.');
        }

        return $this->repository;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('The connection was not initialised.');
        }

        return $this->pdo;
    }

    private function insertUser(string $kind): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, is_active, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => $kind . '-probe-' . bin2hex(random_bytes(6)) . '@example.test',
            'name' => 'Probe ' . $kind,
            'role' => 'patient',
            'hash' => str_repeat('0', 60),
        ]);

        $id = (int) $this->pdo()->lastInsertId();
        $this->userIds[] = $id;

        return $id;
    }
}
