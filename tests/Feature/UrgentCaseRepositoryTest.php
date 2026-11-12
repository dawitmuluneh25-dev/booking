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
use App\Domain\UrgentContactAttempt;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentDecision;
use App\Domain\UrgentDecisionType;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTimelineEntry;
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
                    'urgent_decisions',
                    'urgent_coordination_history',
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

    public function testAContactAttemptIsStoredAndTheCaseBecomesContacting(): void
    {
        $case = $this->create();
        $staff = new Actor($this->actorUserId, Role::Staff);
        $case = $this->repository()->acknowledge($case, $staff, 'application', null, self::NOW);

        $moved = $this->repository()->recordContactAttempt(
            $case,
            new UrgentContactAttempt(
                caseId: $case->id,
                attemptedByUserId: $this->actorUserId,
                contactRole: 'patient_family',
                contactName: 'Tesfaye Alemu',
                contactPhone: '+251911222444',
                channel: 'telephone',
                outcome: 'answered',
                notes: null,
                attemptedAtUtc: self::NOW,
            ),
        );

        self::assertSame(UrgentCoordination::Contacting, $moved->coordination);

        $statement = $this->pdo()->prepare(
            'SELECT contact_phone, outcome FROM urgent_contact_attempts WHERE urgent_case_id = :id'
        );
        $statement->execute(['id' => $case->id]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        $phone = is_scalar($row['contact_phone'] ?? null) ? (string) $row['contact_phone'] : '';
        $outcome = is_scalar($row['outcome'] ?? null) ? (string) $row['outcome'] : '';

        self::assertSame('+251911222444', $phone);
        self::assertSame('answered', $outcome);
    }

    public function testTheDatabaseRefusesATelephoneAttemptWithNoNumber(): void
    {
        $case = $this->create();
        $refused = false;

        try {
            $this->pdo()->exec(
                'INSERT INTO urgent_contact_attempts
                    (urgent_case_id, attempted_by_user_id, contact_role, channel, outcome, attempted_at)
                 VALUES (' . $case->id . ', ' . $this->actorUserId
                . ", 'hospital', 'telephone', 'no_answer', UTC_TIMESTAMP())"
            );
        } catch (PDOException) {
            $refused = true;
        }

        self::assertTrue($refused, 'A telephone attempt without a number was stored.');
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

    public function testACoordinationMoveIsRecordedWithItsActorAndAppearsOnceOnTheTimeline(): void
    {
        $case = $this->contacted();

        $moved = $this->repository()->transitionCoordination(
            $case,
            UrgentCoordination::Coordinating,
            new Actor($this->actorUserId, Role::Coordinator),
            'The surgical registrar has agreed to take the case this afternoon.',
            self::NOW,
        );

        self::assertSame(UrgentCoordination::Coordinating, $moved->coordination);
        self::assertSame($case->version + 1, $moved->version);

        // Reloaded from MySQL, not from the returned object.
        self::assertSame(UrgentCoordination::Coordinating, $this->repository()->findById($case->id)?->coordination);

        $coordination = array_values(array_filter(
            $this->repository()->timeline($case->id),
            static fn (UrgentTimelineEntry $entry): bool => $entry->kind === UrgentTimelineEntry::COORDINATION,
        ));

        self::assertCount(1, $coordination);
        self::assertSame('contacting → coordinating', $coordination[0]->label);
        self::assertNotSame('', $coordination[0]->actorName);
    }

    public function testAStaleCaseCannotMoveTheCoordinationAxis(): void
    {
        $case = $this->contacted();
        $actor = new Actor($this->actorUserId, Role::Coordinator);

        $this->repository()->transitionCoordination($case, UrgentCoordination::Coordinating, $actor, null, self::NOW);

        // The object still thinks it is `acknowledged`; the row is `coordinating`.
        $this->expectException(UrgentTransitionException::class);

        $this->repository()->transitionCoordination($case, UrgentCoordination::Coordinating, $actor, null, self::NOW);
    }

    public function testADecisionIsStoredWithItsChoiceAndItsResultingState(): void
    {
        $case = $this->create();

        $stored = $this->repository()->recordUrgentDecision(new UrgentDecision(
            id: 0,
            urgentCaseId: $case->id,
            type: UrgentDecisionType::ProceedToResourceCoordination,
            rationale: 'Reducible on ultrasound with no ischaemia; the surgical team is on site.',
            decidedByUserId: $this->actorUserId,
            decidedAtUtc: self::NOW,
            resultingStatus: UrgentStatus::AcceptedForCoordination->value,
        ));

        self::assertGreaterThan(0, $stored->id);

        $current = $this->repository()->currentUrgentDecision($case->id);

        self::assertNotNull($current);
        self::assertSame(UrgentDecisionType::ProceedToResourceCoordination, $current->type);
        self::assertSame('accepted_for_coordination', $current->resultingStatus);
        self::assertSame($this->actorUserId, $current->decidedByUserId);
    }

    public function testASupersededDecisionIsKeptAndTheLaterOneIsCurrent(): void
    {
        $case = $this->create();

        $first = $this->repository()->recordUrgentDecision(new UrgentDecision(
            id: 0,
            urgentCaseId: $case->id,
            type: UrgentDecisionType::DeferPendingReview,
            rationale: 'Waiting for the repeat film before committing to a technique.',
            decidedByUserId: $this->actorUserId,
            decidedAtUtc: self::NOW,
        ));

        $this->repository()->recordUrgentDecision(new UrgentDecision(
            id: 0,
            urgentCaseId: $case->id,
            type: UrgentDecisionType::DeclineIrReduction,
            rationale: 'The repeat film shows spontaneous reduction, so no intervention is needed.',
            decidedByUserId: $this->actorUserId,
            decidedAtUtc: self::NOW,
            resultingStatus: UrgentStatus::Declined->value,
            supersedesId: $first->id,
        ));

        $history = $this->repository()->urgentDecisionHistory($case->id);

        self::assertCount(2, $history);
        self::assertTrue($history[0]['superseded']);
        self::assertFalse($history[1]['superseded']);
        self::assertSame(UrgentDecisionType::DeclineIrReduction, $history[1]['decision']->type);

        // The superseded row is still there: nothing rewrites a decision.
        self::assertSame(
            UrgentDecisionType::DeclineIrReduction,
            $this->repository()->currentUrgentDecision($case->id)?->type,
        );
    }

    public function testTheSchemaRefusesADecisionWithoutAUsableRationale(): void
    {
        $case = $this->create();

        $this->expectException(PDOException::class);

        $statement = $this->pdo()->prepare(
            'INSERT INTO urgent_decisions
                (urgent_case_id, decision_type, rationale, decided_by_user_id, decided_at)
             VALUES (:case_id, :type, :rationale, :decided_by, :decided_at)'
        );
        $statement->execute([
            'case_id' => $case->id,
            'type' => 'defer_pending_review',
            'rationale' => 'wait',
            'decided_by' => $this->actorUserId,
            'decided_at' => self::NOW,
        ]);
    }

    public function testTheSchemaRefusesAChoiceOutsideTheApprovedVocabulary(): void
    {
        $case = $this->create();

        $this->expectException(PDOException::class);

        $statement = $this->pdo()->prepare(
            'INSERT INTO urgent_decisions
                (urgent_case_id, decision_type, rationale, decided_by_user_id, decided_at)
             VALUES (:case_id, :type, :rationale, :decided_by, :decided_at)'
        );
        $statement->execute([
            'case_id' => $case->id,
            'type' => 'approve',
            'rationale' => 'A decision type borrowed from the elective vocabulary.',
            'decided_by' => $this->actorUserId,
            'decided_at' => self::NOW,
        ]);
    }

    public function testACaseIsFoundByItsReferenceAndNotByAnotherOne(): void
    {
        $case = $this->create();

        self::assertSame($case->id, $this->repository()->findByReference($case->reference)?->id);
        self::assertNull($this->repository()->findByReference('UR-000000-NOSUCH'));
    }

    public function testTheBoardPutsAnUnclaimedCaseAboveAnOlderClaimedOne(): void
    {
        $older = $this->create();
        $newer = $this->create();

        $acknowledged = $this->repository()->acknowledge(
            $older,
            new Actor($this->actorUserId, Role::Staff),
            'application',
            null,
            self::NOW,
        );
        self::assertSame(UrgentCoordination::Acknowledged, $acknowledged->coordination);

        $open = $this->repository()->openCases(50);
        $ours = array_values(array_filter(
            $open,
            static fn (UrgentCase $case): bool => in_array($case->id, [$older->id, $newer->id], true),
        ));

        self::assertCount(2, $ours);
        self::assertSame($newer->id, $ours[0]->id, 'A case nobody has claimed was not listed first.');
    }

    /**
     * A case somebody has claimed and then tried to call.
     *
     * `Acknowledged` does not transition straight to `Coordinating`: contact comes
     * first, so a case cannot report the receiving team engaged with no attempt
     * behind it.
     */
    private function contacted(): UrgentCase
    {
        $actor = new Actor($this->actorUserId, Role::Coordinator);
        $acknowledged = $this->repository()->acknowledge($this->create(), $actor, 'telephone', null, self::NOW);

        return $this->repository()->recordContactAttempt($acknowledged, new UrgentContactAttempt(
            caseId: $acknowledged->id,
            attemptedByUserId: $this->actorUserId,
            contactRole: 'referring_clinician',
            contactName: 'Dr Yonas Girma',
            contactPhone: '+251911222000',
            channel: 'telephone',
            outcome: 'connected',
            notes: 'The referring clinician confirmed the child is stable for transfer.',
            attemptedAtUtc: self::NOW,
        ));
    }

    public function testAClosedCaseIsNotOnTheBoard(): void
    {
        $case = $this->create();

        $this->repository()->transitionUrgency(
            $case,
            UrgentStatus::Closed,
            new Actor($this->actorUserId, Role::Coordinator),
            'Closed after the receiving team confirmed transfer.',
            self::NOW,
        );

        $ids = array_map(
            static fn (UrgentCase $open): int => $open->id,
            $this->repository()->openCases(200),
        );

        self::assertNotContains($case->id, $ids);
    }

    /**
     * `closed_at` is written by the same statement as the status, so the row cannot
     * read closed while claiming not to know when.
     */
    public function testClosingWritesTheClosedTimeWithTheStatus(): void
    {
        $case = $this->create();

        $this->repository()->transitionUrgency(
            $case,
            UrgentStatus::Closed,
            new Actor($this->actorUserId, Role::Coordinator),
            'Reduced; child admitted for observation.',
            self::NOW,
        );

        $statement = $this->pdo()->prepare('SELECT closed_at FROM urgent_cases WHERE id = :id');
        $statement->execute(['id' => $case->id]);

        self::assertSame(self::NOW, (string) $statement->fetchColumn());
    }

    /**
     * §10.3 asks for a coordination record at each step; `occurredEventType()` names
     * which moves produce one. Until this slice nothing wrote them, so the timeline
     * had no events to show.
     */
    public function testAClinicalMoveWritesItsCoordinationEvent(): void
    {
        $case = $this->create();

        $this->repository()->transitionUrgency(
            $case,
            UrgentStatus::UnderClinicalReview,
            new Actor($this->actorUserId, Role::Radiologist),
            null,
            self::NOW,
        );

        $statement = $this->pdo()->prepare(
            'SELECT event_type, event_note FROM urgent_events WHERE urgent_case_id = :id'
        );
        $statement->execute(['id' => $case->id]);
        $row = $statement->fetch();
        self::assertIsArray($row);
        $eventType = is_scalar($row['event_type'] ?? null) ? (string) $row['event_type'] : '';
        $eventNote = is_scalar($row['event_note'] ?? null) ? (string) $row['event_note'] : '';

        self::assertSame('clinical_review', $eventType);
        self::assertNotSame('', $eventNote);
    }

    public function testTheTimelineMergesTheStatusTrailAndTheAcknowledgementOldestFirst(): void
    {
        $case = $this->create();

        $case = $this->repository()->acknowledge(
            $case,
            new Actor($this->actorUserId, Role::Staff),
            'telephone',
            'Spoke to the referring registrar.',
            '2026-11-08 13:05:00',
        );

        $entries = $this->repository()->timeline($case->id);

        self::assertNotEmpty($entries);
        // The case arrived at 13:00 and was acknowledged at 13:05, so the arrival
        // comes first even though it lives in a different table.
        self::assertSame(
            [UrgentTimelineEntry::STATUS, UrgentTimelineEntry::ACKNOWLEDGEMENT],
            [$entries[0]->kind, $entries[1]->kind],
            'The timeline was not ordered oldest first.',
        );
        self::assertContains('Acknowledged — telephone', array_map(
            static fn (UrgentTimelineEntry $entry): string => $entry->description(),
            $entries,
        ));
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
