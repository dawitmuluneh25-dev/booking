<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Domain\AppointmentStatus;
use App\Domain\ProcedureMilestone;
use App\Domain\ProcedureMilestoneRecord;
use App\Domain\Role;
use App\Repositories\PdoProcedureMilestoneRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The procedure-day log against MySQL (BL-C3, §15).
 *
 * The constraints are the part of AC-4 that survives a writer which never read the
 * command: an unknown kind, a state outside the appointment vocabulary, a "reason"
 * of three characters and a consent reference on an event that has no consent are
 * all refused by the database, not by good intentions.
 */
final class ProcedureMilestoneRepositoryTest extends TestCase
{
    private const NOW = '2026-11-20 09:12:00';

    private ?PDO $pdo = null;

    private ?PdoProcedureMilestoneRepository $repository = null;

    private int $userId = 0;

    private int $referralId = 0;

    private int $appointmentId = 0;

    /** @var list<int> */
    private array $extraAppointments = [];

    /** @var list<int> */
    private array $consentIds = [];

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
        $this->userId = $this->insertUser();
        $this->referralId = $this->insertReferral();
        $this->appointmentId = $this->insertAppointment($this->referralId);
        $this->repository = new PdoProcedureMilestoneRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $pdo = $this->pdo;

            // Milestones first: they are RESTRICT on both parents, which is the point.
            $pdo->exec('DELETE FROM procedure_milestones WHERE appointment_id = ' . $this->appointmentId);

            foreach ($this->extraAppointments as $id) {
                $pdo->exec('DELETE FROM procedure_milestones WHERE appointment_id = ' . $id);
                $pdo->exec('DELETE FROM appointments WHERE id = ' . $id);
            }

            foreach ($this->consentIds as $id) {
                $pdo->exec('DELETE FROM procedure_consents WHERE id = ' . $id);
            }

            $pdo->exec('DELETE FROM appointments WHERE id = ' . $this->appointmentId);
            $pdo->exec('DELETE FROM referrals WHERE id = ' . $this->referralId);

            if ($this->userId > 0) {
                $pdo->exec('DELETE FROM users WHERE id = ' . $this->userId);
            }
        }

        $this->extraAppointments = [];
        $this->consentIds = [];
        $this->pdo = null;
        $this->repository = null;
    }

    public function testARecordIsStoredWithItsActorAndReadBackOldestFirst(): void
    {
        $this->repository()->record(
            $this->recordFor($this->appointmentId, ProcedureMilestone::ProcedureStarted, '2026-11-20 09:30:00'),
        );
        $this->repository()->record($this->recordFor($this->appointmentId, ProcedureMilestone::Arrival, self::NOW));

        $stored = $this->repository()->forAppointment($this->appointmentId);

        self::assertCount(2, $stored);
        self::assertSame(ProcedureMilestone::Arrival, $stored[0]->kind);
        self::assertSame(ProcedureMilestone::ProcedureStarted, $stored[1]->kind);
        self::assertSame($this->userId, $stored[0]->recordedByUserId);
        // The recorder's name is joined, not copied onto the row.
        self::assertSame('Milestone Probe', $stored[0]->recordedByName);
    }

    public function testTheLogIsQueriedForSeveralAppointmentsInOneGo(): void
    {
        $second = $this->insertAppointment($this->referralId);
        $this->extraAppointments[] = $second;

        $this->repository()->record($this->recordFor($this->appointmentId, ProcedureMilestone::Arrival, self::NOW));
        $this->repository()->record($this->recordFor($second, ProcedureMilestone::ConsentVerified, self::NOW));

        $grouped = $this->repository()->forAppointments([$this->appointmentId, $second, 999999]);

        self::assertArrayHasKey($this->appointmentId, $grouped);
        self::assertArrayHasKey($second, $grouped);
        self::assertArrayNotHasKey(999999, $grouped);
        self::assertCount(1, $grouped[$this->appointmentId]);
    }

    public function testAnUnknownKindIsRefusedByTheSchema(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo()->exec(
            "INSERT INTO procedure_milestones
                (appointment_id, kind, occurred_at, recorded_by_user_id, recorded_by_role, created_at)
             VALUES (" . $this->appointmentId . ", 'patient_left', '" . self::NOW . "', "
            . $this->userId . ", 'staff', '" . self::NOW . "')"
        );
    }

    public function testAStateOutsideTheAppointmentVocabularyIsRefused(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo()->exec(
            "INSERT INTO procedure_milestones
                (appointment_id, kind, occurred_at, recorded_by_user_id, recorded_by_role,
                 resulting_status, created_at)
             VALUES (" . $this->appointmentId . ", 'arrival', '" . self::NOW . "', "
            . $this->userId . ", 'staff', 'closed', '" . self::NOW . "')"
        );
    }

    /**
     * AC-4's meaning of "a reason": ten characters is the smallest thing that can
     * say why, and the schema refuses anything shorter even from a writer that never
     * saw the command.
     */
    public function testAnOutOfOrderReasonMustActuallySaySomething(): void
    {
        $this->expectException(PDOException::class);

        $this->pdo()->exec(
            "INSERT INTO procedure_milestones
                (appointment_id, kind, occurred_at, recorded_by_user_id, recorded_by_role,
                 out_of_order_reason, created_at)
             VALUES (" . $this->appointmentId . ", 'procedure_started', '" . self::NOW . "', "
            . $this->userId . ", 'radiologist', 'busy', '" . self::NOW . "')"
        );
    }

    public function testOnlyAConsentCheckMayNameAConsentRecord(): void
    {
        $consentId = $this->insertConsent();
        $this->consentIds[] = $consentId;

        $this->expectException(PDOException::class);

        $this->pdo()->exec(
            "INSERT INTO procedure_milestones
                (appointment_id, kind, occurred_at, recorded_by_user_id, recorded_by_role,
                 consent_id, created_at)
             VALUES (" . $this->appointmentId . ", 'arrival', '" . self::NOW . "', "
            . $this->userId . ", 'staff', " . $consentId . ", '" . self::NOW . "')"
        );
    }

    public function testAConsentCheckStoresTheRecordItVerified(): void
    {
        $consentId = $this->insertConsent();
        $this->consentIds[] = $consentId;

        $record = new ProcedureMilestoneRecord(
            id: 0,
            appointmentId: $this->appointmentId,
            kind: ProcedureMilestone::ConsentVerified,
            occurredAtUtc: self::NOW,
            recordedByUserId: $this->userId,
            recordedByRole: Role::Radiologist,
            note: null,
            consentId: $consentId,
            resultingStatus: null,
            outOfOrderReason: null,
            createdAtUtc: self::NOW,
        );

        $stored = $this->repository()->record($record);

        self::assertSame($consentId, $stored->consentId);
        self::assertNull($stored->resultingStatus);
    }

    public function testDeletingTheAppointmentWouldTakeItsHistoryWithItAndIsRefused(): void
    {
        $this->repository()->record($this->recordFor($this->appointmentId, ProcedureMilestone::Arrival, self::NOW));

        $this->expectException(PDOException::class);

        $this->pdo()->exec('DELETE FROM appointments WHERE id = ' . $this->appointmentId);
    }

    private function recordFor(int $appointmentId, ProcedureMilestone $kind, string $at): ProcedureMilestoneRecord
    {
        return new ProcedureMilestoneRecord(
            id: 0,
            appointmentId: $appointmentId,
            kind: $kind,
            occurredAtUtc: $at,
            recordedByUserId: $this->userId,
            recordedByRole: Role::Radiologist,
            note: null,
            consentId: null,
            resultingStatus: null,
            outOfOrderReason: null,
            createdAtUtc: $at,
        );
    }

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, is_active, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, 1, :created_at, :updated_at)'
        );
        $statement->execute([
            'email' => 'milestone-probe@example.test',
            'name' => 'Milestone Probe',
            'role' => 'radiologist',
            'hash' => '$2y$04$notarealhashthecolumnonlyneedswidth',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertReferral(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO referrals (reference, status, patient_name, patient_phone, procedure_code,
                submitted_by_user_id, submitted_on_behalf_of, created_at, updated_at)
             VALUES (:reference, :status, :name, :phone, :procedure, :submitted_by, :on_behalf_of,
                :created_at, :updated_at)'
        );
        $statement->execute([
            'reference' => 'RF-MILESTONE-1',
            'status' => 'converted_to_appointment',
            'name' => 'Selamawit Girma',
            'phone' => '+251911000333',
            'procedure' => 'image_guided_fnac',
            'submitted_by' => $this->userId,
            'on_behalf_of' => 'self',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertAppointment(int $referralId): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO appointments (reference, referral_id, status, version, starts_at, ends_at,
                duration_minutes, patient_name, patient_phone, created_by_user_id, created_at, updated_at)
             VALUES (:reference, :referral_id, :status, 1, :starts_at, :ends_at,
                :duration, :name, :phone, :created_by, :created_at, :updated_at)'
        );
        $statement->execute([
            'reference' => 'BK-MILE-' . substr((string) random_int(100000, 999999), 0, 6),
            'referral_id' => $referralId,
            'status' => AppointmentStatus::Scheduled->value,
            'starts_at' => '2026-11-20 09:00:00',
            'ends_at' => '2026-11-20 09:30:00',
            'duration' => 30,
            'name' => 'Selamawit Girma',
            'phone' => '+251911000333',
            'created_by' => $this->userId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function insertConsent(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO procedure_consents (referral_id, participant_relationship, participant_name,
                information_version, information_language, discussion_record, outcome,
                attestation_reference, responsible_practitioner_user_id, recorded_by_user_id,
                consented_at, created_at)
             VALUES (:referral, :relationship, :name, :version, :language, :discussion, :outcome,
                :attestation, :practitioner, :recorder, :consented_at, :created_at)'
        );
        $statement->execute([
            'referral' => $this->referralId,
            'relationship' => 'self',
            'name' => 'Selamawit Girma',
            'version' => 'HR-IR-CLIN-R1-001 §17.1',
            'language' => 'en',
            'discussion' => 'Benefits, risks and alternatives discussed; questions answered.',
            'outcome' => 'agreed',
            'attestation' => 'Consent form CF-2026-0417.',
            'practitioner' => $this->userId,
            'recorder' => $this->userId,
            'consented_at' => '2026-11-19 15:00:00',
            'created_at' => '2026-11-19 15:00:00',
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function repository(): PdoProcedureMilestoneRepository
    {
        $repository = $this->repository;
        self::assertInstanceOf(PdoProcedureMilestoneRepository::class, $repository);

        return $repository;
    }

    private function pdo(): PDO
    {
        $pdo = $this->pdo;
        self::assertInstanceOf(PDO::class, $pdo);

        return $pdo;
    }
}
