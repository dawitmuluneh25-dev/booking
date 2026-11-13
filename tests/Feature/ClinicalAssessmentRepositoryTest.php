<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use App\Domain\Actor;
use App\Domain\AllergyStatus;
use App\Domain\ClinicalAssessment;
use App\Domain\Medication;
use App\Domain\MedicationReviewStatus;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Repositories\PdoClinicalAssessmentRepository;
use App\Repositories\PdoReferralRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The assessment adapter against MySQL.
 *
 * The medication list lives in a child table, so the properties worth checking are
 * the ones a child table can get wrong: the rows come back in the order they were
 * entered, they stay attached to the assessment revision they belong to, and the
 * status vocabulary is enforced by the column.
 */
final class ClinicalAssessmentRepositoryTest extends TestCase
{
    private const NOW = '2026-11-13 11:00:00';

    private ?PDO $pdo = null;

    private ?PdoClinicalAssessmentRepository $repository = null;

    private int $actorUserId = 0;

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

        $this->actorUserId = $this->insertUser();
        $this->repository = new PdoClinicalAssessmentRepository($this->pdo);
        $this->referralId = $this->createReferral();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->referralId > 0) {
            $this->pdo->exec(
                'DELETE m FROM referral_medications m
                   JOIN referral_clinical_assessments a ON a.id = m.assessment_id
                  WHERE a.referral_id = ' . $this->referralId
            );
            $this->pdo->exec(
                'UPDATE referral_clinical_assessments SET supersedes_id = NULL WHERE referral_id = '
                . $this->referralId
            );
            $this->pdo->exec('DELETE FROM referral_clinical_assessments WHERE referral_id = ' . $this->referralId);
            $this->pdo->exec('DELETE FROM notification_outbox WHERE referral_id = ' . $this->referralId);
            $this->pdo->exec('DELETE FROM referral_status_history WHERE referral_id = ' . $this->referralId);
            $this->pdo->exec('DELETE FROM referrals WHERE id = ' . $this->referralId);
        }

        if ($this->pdo !== null && $this->actorUserId > 0) {
            $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->actorUserId);
        }

        $this->pdo = null;
        $this->repository = null;
        $this->referralId = 0;
    }

    public function testAMedicationListComesBackInTheOrderItWasEntered(): void
    {
        $stored = $this->repository()->record($this->assessment([
            new Medication('Warfarin', '5 mg', 'once daily'),
            new Medication('Amlodipine', null, 'once daily'),
            new Medication('Metformin'),
        ]));

        $reloaded = $this->repository()->current($this->referralId);

        self::assertNotNull($reloaded);
        self::assertSame($stored->id, $reloaded->id);
        self::assertSame(MedicationReviewStatus::Recorded, $reloaded->medicationReview);
        self::assertCount(3, $reloaded->medications);
        self::assertSame('Warfarin', $reloaded->medications[0]->name);
        self::assertSame('Metformin', $reloaded->medications[2]->name);
        self::assertSame('Amlodipine — once daily', $reloaded->medications[1]->describe());

        $statement = $this->pdo()->prepare(
            'SELECT position FROM referral_medications WHERE assessment_id = :id ORDER BY id'
        );
        $statement->execute(['id' => $stored->id]);

        /** @var list<int|string> $positions */
        $positions = $statement->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame([1, 2, 3], array_map(
            static fn ($position): int => is_numeric($position) ? (int) $position : 0,
            $positions,
        ));
    }

    public function testAStatusWithoutAListReadsBackWithNoRows(): void
    {
        $this->repository()->record($this->assessment([], MedicationReviewStatus::Unknown));

        $reloaded = $this->repository()->current($this->referralId);

        self::assertNotNull($reloaded);
        self::assertSame(MedicationReviewStatus::Unknown, $reloaded->medicationReview);
        self::assertSame([], $reloaded->medications);
        self::assertFalse($reloaded->isCompleteForProcedure());
    }

    public function testTheRevisionKeepsItsOwnListWhenANewerAssessmentSupersedesIt(): void
    {
        $first = $this->repository()->record($this->assessment([new Medication('Warfarin', '5 mg')]));
        $second = $this->repository()->record(
            $this->assessment([], MedicationReviewStatus::NoneReportedConfirmed, $first->id),
        );

        self::assertSame($first->id, $second->supersedesId);
        self::assertSame($second->id, $this->repository()->current($this->referralId)?->id);

        $history = $this->repository()->history($this->referralId);

        self::assertCount(2, $history);
        self::assertSame(MedicationReviewStatus::Recorded, $history[0]['assessment']->medicationReview);
    }

    public function testTheColumnRefusesAStatusOutsideTheVocabulary(): void
    {
        $stored = $this->repository()->record($this->assessment([], MedicationReviewStatus::Unknown));

        $this->expectException(PDOException::class);

        $this->pdo()->exec(
            "UPDATE referral_clinical_assessments SET medication_review_status = 'reviewed'
              WHERE id = " . $stored->id
        );
    }

    /** @param list<Medication> $medications */
    private function assessment(
        array $medications,
        MedicationReviewStatus $status = MedicationReviewStatus::Recorded,
        ?int $supersedesId = null,
    ): ClinicalAssessment {
        return new ClinicalAssessment(
            id: 0,
            referralId: $this->referralId,
            anticoagulant: null,
            anticoagulantDetails: null,
            allergy: AllergyStatus::Unknown,
            allergyDetails: null,
            pregnancy: null,
            pregnancyDetails: null,
            relevantHistory: null,
            priorProcedure: null,
            examinationContext: null,
            imagingModality: null,
            imagingDate: null,
            imagingReference: null,
            recordedByUserId: $this->actorUserId,
            recordedAtUtc: self::NOW,
            supersedesId: $supersedesId,
            medicationReview: $status,
            medications: $medications,
        );
    }

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active, auth_version, created_at, updated_at)
             VALUES (:name, :email, :hash, :role, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'name' => 'Assessment Test Clinician',
            'email' => 'assessment-' . bin2hex(random_bytes(4)) . '@example.test',
            'hash' => password_hash('correct horse', PASSWORD_DEFAULT),
            'role' => Role::Radiologist->value,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function createReferral(): int
    {
        $referral = (new PdoReferralRepository($this->pdo(), new PdoTransaction($this->pdo())))->create(
            new Referral(
                id: 0,
                reference: 'RF-ASSESS-' . bin2hex(random_bytes(3)),
                status: ReferralStatus::ClinicalReview,
                version: 1,
                patientName: 'Selamawit Girma',
                patientPhone: '+251911000333',
                patientDob: '1991-04-12',
                guardianName: null,
                guardianPhone: null,
                guardianRelationship: null,
                procedureCode: 'image_guided_biopsy',
                requestedUrgency: 'routine',
                clinicalSummary: 'Six weeks of right upper quadrant pain; 2 cm hepatic lesion.',
                targetSite: 'Liver, segment VI',
                referringPhysicianName: null,
                referringFacility: null,
                referringPhysicianPhone: null,
                submittedByUserId: $this->actorUserId,
                submittedOnBehalfOf: 'self',
                createdAtUtc: self::NOW,
            ),
            new Actor($this->actorUserId, Role::Patient),
            self::NOW,
        );

        return $referral->id;
    }

    private function repository(): PdoClinicalAssessmentRepository
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
}
