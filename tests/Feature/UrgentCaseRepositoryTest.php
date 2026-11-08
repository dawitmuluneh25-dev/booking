<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use App\Domain\Actor;
use App\Domain\Role;
use App\Domain\UrgentCase;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTransitionException;
use App\Repositories\PdoUrgentCaseRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The urgent adapter against MySQL: the version guard, the history row and the
 * single acknowledgement are database behaviour, not in-memory bookkeeping.
 */
final class UrgentCaseRepositoryTest extends TestCase
{
    private const NOW = '2026-11-08 13:00:00';

    private ?PDO $pdo = null;

    private ?PdoUrgentCaseRepository $repository = null;

    private int $actorUserId = 0;

    /** @var list<int> */
    private array $createdIds = [];

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
        $this->repository = new PdoUrgentCaseRepository($this->pdo, new PdoTransaction($this->pdo));
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach ($this->createdIds as $id) {
                $tables = [
                    'urgent_status_history',
                    'urgent_acknowledgements',
                    'urgent_contact_attempts',
                    'urgent_escalations',
                    'urgent_events',
                ];

                foreach ($tables as $table) {
                    $delete = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE urgent_case_id = :id');
                    $delete->execute(['id' => $id]);
                }

                $this->pdo->exec('DELETE FROM urgent_cases WHERE id = ' . $id);
            }

            if ($this->actorUserId > 0) {
                $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->actorUserId);
            }
        }

        $this->createdIds = [];
        $this->pdo = null;
        $this->repository = null;
    }

    public function testCreateStoresTheCaseAndItsOpeningHistoryRow(): void
    {
        $case = $this->create();

        self::assertGreaterThan(0, $case->id);
        self::assertSame(UrgentStatus::Received, $case->urgency);
        self::assertSame(UrgentCoordination::Unacknowledged, $case->coordination);
        self::assertSame(1, $this->countHistory($case->id));
    }

    public function testAcknowledgeWritesTheRecordAndMovesCoordination(): void
    {
        $case = $this->create();

        $acknowledged = $this->repository()->acknowledge(
            $case,
            new Actor($this->actorUserId, Role::Staff),
            'telephone',
            'Called the referring hospital; child is stable and being transferred.',
            self::NOW,
        );

        self::assertSame(UrgentCoordination::Acknowledged, $acknowledged->coordination);
        self::assertSame($case->version + 1, $acknowledged->version);

        $statement = $this->pdo()->prepare(
            'SELECT acknowledged_by_user_id, acknowledgement_channel FROM urgent_acknowledgements
              WHERE urgent_case_id = :id'
        );
        $statement->execute(['id' => $case->id]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        $recordedBy = is_numeric($row['acknowledged_by_user_id'] ?? null)
            ? (int) $row['acknowledged_by_user_id']
            : 0;
        $channel = is_scalar($row['acknowledgement_channel'] ?? null)
            ? (string) $row['acknowledgement_channel']
            : '';

        self::assertSame($this->actorUserId, $recordedBy);
        self::assertSame('telephone', $channel);
    }

    public function testASecondAcknowledgementIsRefused(): void
    {
        $case = $this->create();
        $staff = new Actor($this->actorUserId, Role::Staff);

        $this->repository()->acknowledge($case, $staff, 'application', null, self::NOW);

        $stale = $this->repository()->findById($case->id);
        self::assertNotNull($stale);

        $this->expectException(UrgentTransitionException::class);
        $this->repository()->acknowledge($stale, $staff, 'application', null, self::NOW);
    }

    public function testAStaleUrgencyTransitionIsRefused(): void
    {
        $case = $this->create();
        $radiologist = new Actor($this->actorUserId, Role::Radiologist);

        $this->repository()->transitionUrgency(
            $case,
            UrgentStatus::UnderClinicalReview,
            $radiologist,
            null,
            self::NOW,
        );

        // The original object still says "received"; the row has moved on.
        $this->expectException(UrgentTransitionException::class);
        $this->repository()->transitionUrgency(
            $case,
            UrgentStatus::UnderClinicalReview,
            $radiologist,
            null,
            self::NOW,
        );
    }

    public function testAnUncontrolledUrgentTransitionIsRefused(): void
    {
        $case = $this->create();

        $this->expectException(UrgentTransitionException::class);
        $this->repository()->transitionUrgency(
            $case,
            UrgentStatus::Completed,
            new Actor($this->actorUserId, Role::Radiologist),
            'Skipping straight to completed.',
            self::NOW,
        );
    }

    public function testTheUrgentVocabularyIsEnforcedByTheColumn(): void
    {
        $case = $this->create();
        $refused = false;

        try {
            $this->pdo()->exec(
                "UPDATE urgent_cases SET urgency_status = 'approved_for_scheduling' WHERE id = " . $case->id
            );
        } catch (PDOException) {
            $refused = true;
        }

        // An elective state is not part of the urgent vocabulary, and the row would
        // otherwise be read back as an enum case that does not exist.
        self::assertTrue($refused, 'The column accepted an elective status.');
        self::assertSame(UrgentStatus::Received, $this->repository()->findById($case->id)?->urgency);
    }

    private function create(): UrgentCase
    {
        $case = new UrgentCase(
            id: 0,
            reference: 'UR-TEST-' . bin2hex(random_bytes(4)),
            urgency: UrgentStatus::Received,
            coordination: UrgentCoordination::Unacknowledged,
            version: 1,
            procedureCode: 'intussusception_reduction',
            patientName: 'Bereket Tesfaye',
            patientDob: '2025-09-02',
            patientAgeMonths: 14,
            guardianName: 'Tesfaye Alemu',
            guardianPhone: '+251911222444',
            guardianRelationship: 'Father',
            referringUserId: null,
            referringPhysicianName: 'Dr Hana',
            referringFacility: 'Adama Hospital',
            referringPhysicianPhone: null,
            receivingHospitalName: 'Black Lion Hospital',
            clinicalSummary: 'Twelve hours of colicky pain with redcurrant stool.',
            imagingSummary: 'Target sign on transverse view.',
            receivedAtUtc: self::NOW,
            createdAtUtc: self::NOW,
        );

        $stored = $this->repository()->create($case, new Actor($this->actorUserId, Role::Physician), self::NOW);
        $this->createdIds[] = $stored->id;

        return $stored;
    }

    private function countHistory(int $caseId): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM urgent_status_history WHERE urgent_case_id = :id'
        );
        $statement->execute(['id' => $caseId]);

        return (int) $statement->fetchColumn();
    }

    private function repository(): PdoUrgentCaseRepository
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

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => 'urgent-probe-' . bin2hex(random_bytes(6)) . '@example.test',
            'name' => 'Urgent Probe',
            'role' => 'staff',
            'hash' => str_repeat('0', 60),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }
}
