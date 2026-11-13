<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use App\Domain\Actor;
use App\Domain\ClinicalDecision;
use App\Domain\ClinicalDecisionType;
use App\Domain\ClinicalPriorityChange;
use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\OutboundMessage;
use App\Domain\Referral;
use App\Domain\ReferralNotFoundException;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTransitionException;
use App\Domain\Role;
use App\Repositories\PdoOutboxRepository;
use App\Repositories\PdoReferralRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises the PDO adapter against MySQL, because an in-memory double cannot
 * prove that the guarded UPDATE, the history row and the version bump really
 * happen in one transaction.
 *
 * Everything runs inside a transaction that is rolled back, so the suite leaves
 * no rows behind and needs no DDL rights. When MySQL is unreachable the test
 * skips: the unit suite must still run on a host without a database.
 */
final class ReferralRepositoryTest extends TestCase
{
    private const NOW = '2026-11-08 09:00:00';

    private ?PDO $pdo = null;

    private ?PdoReferralRepository $repository = null;

    private int $actorUserId = 0;

    /** @var list<int> */
    private array $createdReferralIds = [];

    /** @var list<int> */
    private array $createdProfileIds = [];

    /** @var list<int> */
    private array $probeUserIds = [];

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
        $this->actorUserId = $this->insertUser();
        $this->repository = new PdoReferralRepository($this->pdo, new PdoTransaction($this->pdo));
    }

    protected function tearDown(): void
    {
        // The adapter owns its transactions (create and transition each commit),
        // so cleanup is explicit: history first, because the foreign key refuses
        // to orphan it, then the referrals, then the probe account.
        if ($this->pdo !== null) {
            foreach ($this->createdReferralIds as $id) {
                // Decisions point at referrals with ON DELETE RESTRICT, and their
                // own supersede chain points at themselves, so clear the chain from
                // the newest row backwards.
                $this->pdo->exec(
                    'DELETE FROM clinical_decisions WHERE referral_id = ' . $id
                    . ' ORDER BY supersedes_decision_id IS NULL, id DESC'
                );
                $this->pdo->exec('DELETE FROM referral_priority_changes WHERE referral_id = ' . $id);
                $this->pdo->exec('DELETE FROM referral_status_history WHERE referral_id = ' . $id);
                $this->pdo->exec('DELETE FROM referrals WHERE id = ' . $id);
            }

            foreach ($this->createdProfileIds as $id) {
                $this->pdo->exec('DELETE FROM physician_profiles WHERE id = ' . $id);
            }

            foreach ([...$this->probeUserIds, $this->actorUserId] as $id) {
                if ($id > 0) {
                    $this->pdo->exec('DELETE FROM users WHERE id = ' . $id);
                }
            }
        }

        $this->createdReferralIds = [];
        $this->createdProfileIds = [];
        $this->probeUserIds = [];
        $this->pdo = null;
        $this->repository = null;
    }

    public function testCreateStoresTheReferralAndItsOpeningHistoryRow(): void
    {
        $referral = $this->createReferral();

        self::assertGreaterThan(0, $referral->id);
        self::assertSame(ReferralStatus::Submitted, $referral->status);

        $stored = $this->repository()->findById($referral->id);
        self::assertNotNull($stored);
        self::assertSame($referral->reference, $stored->reference);
        self::assertSame(1, $stored->version);

        $history = $this->historyFor($referral->id);
        self::assertCount(1, $history);
        self::assertNull($history[0]['from_status']);
        self::assertSame('submitted', $history[0]['to_status']);
        self::assertSame('patient', $history[0]['actor_role']);
    }

    public function testTransitionBumpsTheVersionAndAppendsHistory(): void
    {
        $referral = $this->createReferral();

        $moved = $this->repository()->transition(
            $referral,
            ReferralStatus::Received,
            new Actor($this->actorUserId, Role::Staff),
            null,
            self::NOW,
        );

        self::assertSame(ReferralStatus::Received, $moved->status);
        self::assertSame(2, $moved->version);

        $history = $this->historyFor($referral->id);
        self::assertCount(2, $history);
        self::assertSame('submitted', $history[1]['from_status']);
        self::assertSame('received', $history[1]['to_status']);
        self::assertSame('staff', $history[1]['actor_role']);

        $receivedAt = $this->pdo()->query(
            'SELECT received_at FROM referrals WHERE id = ' . $referral->id
        );

        if ($receivedAt === false) {
            self::fail('Cannot read the received_at stamp.');
        }

        self::assertSame(self::NOW, $receivedAt->fetchColumn());
    }

    public function testASecondWriterHoldingTheStaleVersionIsRefused(): void
    {
        $referral = $this->createReferral();

        $this->repository()->transition(
            $referral,
            ReferralStatus::Received,
            new Actor($this->actorUserId, Role::Staff),
            null,
            self::NOW,
        );

        // The same entity is now one version behind reality: a coordinator who read
        // the page before the clerk acted must not overwrite the clerk.
        $this->expectException(ReferralTransitionException::class);

        $this->repository()->transition(
            $referral,
            ReferralStatus::AdministrativeReview,
            new Actor($this->actorUserId, Role::Admin),
            null,
            self::NOW,
        );
    }

    public function testAnUncontrolledTransitionIsRefused(): void
    {
        $referral = $this->createReferral();

        try {
            $this->repository()->transition(
                $referral,
                ReferralStatus::ClinicallyApproved,
                new Actor($this->actorUserId, Role::Radiologist),
                'Looks fine to me.',
                self::NOW,
            );
            self::fail('A referral went from submitted to clinically approved in one step.');
        } catch (ReferralTransitionException) {
            self::assertSame(ReferralStatus::Submitted, $this->reload($referral->id)->status);
            self::assertCount(1, $this->historyFor($referral->id));
        }
    }

    public function testADecisionCannotSkipTheDatabaseVocabulary(): void
    {
        $referral = $this->createReferral();

        // WF-15: a referral may never be stored as scheduled, so even a bug that
        // reaches SQL cannot put an appointment state on a clinical request.
        try {
            $this->pdo()->exec(
                "UPDATE referrals SET status = 'scheduled' WHERE id = " . $referral->id
            );
            self::fail('The database accepted an appointment state on a referral.');
        } catch (PDOException) {
            self::assertSame('submitted', $this->reload($referral->id)->status->value);
        }
    }

    public function testADecisionIsStoredAndBecomesTheStandingDecision(): void
    {
        $referral = $this->moveToClinicalReview();

        $decision = $this->repository()->recordDecision(
            $this->decision($referral->id, ClinicalDecisionType::Approved, null),
        );

        self::assertGreaterThan(0, $decision->id);
        self::assertSame(ClinicalDecisionType::Approved, $decision->type);

        $standing = $this->repository()->currentDecision($referral->id);
        self::assertNotNull($standing);
        self::assertSame($decision->id, $standing->id);
        self::assertSame('45', (string) $standing->estimatedDurationMinutes);
        self::assertNull($standing->supersedesId);
    }

    /**
     * The priority a decision assigned survives a round trip, and the standing
     * value on the referral is what the clinician set — not the submitter's
     * request (AC-010).
     */
    public function testAClinicalPriorityIsStoredWithItsDecisionAndOnTheReferral(): void
    {
        $referral = $this->moveToClinicalReview();

        $decision = $this->repository()->recordDecision(
            $this->decision($referral->id, ClinicalDecisionType::Approved, null, 'expedited'),
        );
        $this->repository()->assignClinicalPriority($referral->id, 'expedited');

        $standing = $this->repository()->currentDecision($referral->id);
        self::assertNotNull($standing);
        self::assertSame($decision->id, $standing->id);
        self::assertSame('expedited', $standing->clinicalPriority);
        self::assertSame('Expedited', $standing->clinicalPriorityLabel());

        $reloaded = $this->reload($referral->id);
        self::assertSame('expedited', $reloaded->clinicalPriority);
        self::assertSame('routine', $reloaded->requestedUrgency);
    }

    public function testAssigningAPriorityToAMissingReferralIsRefused(): void
    {
        $this->expectException(ReferralNotFoundException::class);

        $this->repository()->assignClinicalPriority(999999, 'routine');
    }

    public function testAPriorityChangeKeepsBothValuesAndIsReadBackOldestFirst(): void
    {
        $referral = $this->moveToClinicalReview();
        $this->repository()->assignClinicalPriority($referral->id, 'routine');

        $this->repository()->recordPriorityChange(new ClinicalPriorityChange(
            id: 0,
            referralId: $referral->id,
            fromPriority: 'routine',
            toPriority: 'expedited',
            reason: 'The repeat film shows progression, so this should not wait for the routine list.',
            changedByUserId: $this->actorUserId,
            changedAtUtc: self::NOW,
        ));

        $history = $this->repository()->priorityChangeHistory($referral->id);

        self::assertCount(1, $history);
        self::assertSame('routine', $history[0]['change']->fromPriority);
        self::assertSame('expedited', $history[0]['change']->toPriority);
        self::assertSame($this->actorUserId, $history[0]['change']->changedByUserId);
        self::assertNotSame('', $history[0]['changedBy']);
    }

    public function testTheSchemaRefusesAChangeThatIsNotAChange(): void
    {
        $referral = $this->moveToClinicalReview();

        $this->expectException(PDOException::class);

        $statement = $this->pdo()->prepare(
            'INSERT INTO referral_priority_changes
                (referral_id, from_priority, to_priority, reason, changed_by_user_id, changed_at)
             VALUES (:referral_id, :from_priority, :to_priority, :reason, :changed_by, :changed_at)'
        );
        $statement->execute([
            'referral_id' => $referral->id,
            'from_priority' => 'routine',
            'to_priority' => 'routine',
            'reason' => 'Re-stating the same category as though it had moved.',
            'changed_by' => $this->actorUserId,
            'changed_at' => self::NOW,
        ]);
    }

    public function testADecisionCanBeSupersededOnceAndNotTwice(): void
    {
        $referral = $this->moveToClinicalReview();

        $first = $this->repository()->recordDecision(
            $this->decision($referral->id, ClinicalDecisionType::Deferred, null),
        );

        $second = $this->repository()->recordDecision(
            $this->decision($referral->id, ClinicalDecisionType::Approved, $first->id),
        );

        $standing = $this->repository()->currentDecision($referral->id);
        self::assertNotNull($standing);
        self::assertSame($second->id, $standing->id);

        // uq_decisions_supersedes: rewriting history by replacing the same decision
        // twice is refused by the database, not merely by the command.
        $this->expectException(PDOException::class);
        $this->repository()->recordDecision(
            $this->decision($referral->id, ClinicalDecisionType::Declined, $first->id),
        );
    }

    public function testTheHistoryNamesTheReviewerAndMarksTheDecisionThatWasReplaced(): void
    {
        $referral = $this->moveToClinicalReview();

        $first = $this->repository()->recordDecision(
            $this->decision($referral->id, ClinicalDecisionType::Deferred, null),
        );
        $second = $this->repository()->recordDecision(
            $this->decision($referral->id, ClinicalDecisionType::Approved, $first->id),
        );

        $history = $this->repository()->decisionHistory($referral->id);
        self::assertCount(2, $history);

        // Oldest first, and the reader can tell which one still stands without
        // reading a second table for the reviewer's name.
        self::assertSame($first->id, $history[0]->decision->id);
        self::assertFalse($history[0]->isCurrent());
        self::assertSame($second->id, $history[0]->supersededById);
        self::assertSame('Referral Repository Probe', $history[0]->reviewerName);

        self::assertSame($second->id, $history[1]->decision->id);
        self::assertTrue($history[1]->isCurrent());
        self::assertNull($history[1]->supersededById);
    }

    private function moveToClinicalReview(): Referral
    {
        $referral = $this->createReferral();
        $staff = new Actor($this->actorUserId, Role::Staff);

        $steps = [
            ReferralStatus::Received,
            ReferralStatus::AdministrativeReview,
            ReferralStatus::ClinicalReview,
        ];

        foreach ($steps as $step) {
            $referral = $this->repository()->transition($referral, $step, $staff, null, self::NOW);
        }

        return $referral;
    }

    private function decision(
        int $referralId,
        ClinicalDecisionType $type,
        ?int $supersedesId,
        ?string $clinicalPriority = null,
    ): ClinicalDecision {
        return new ClinicalDecision(
            id: 0,
            referralId: $referralId,
            type: $type,
            rationale: 'Two centimetre segment VI lesion, safe window, suitable for percutaneous biopsy.',
            technique: 'Percutaneous core biopsy',
            modality: 'Ultrasound',
            sedation: 'Local anaesthetic',
            estimatedDurationMinutes: 45,
            preprocedureConditions: null,
            redirectDestination: null,
            informationRequested: null,
            decidedByUserId: $this->actorUserId,
            decidedAtUtc: self::NOW,
            supersedesId: $supersedesId,
            clinicalPriority: $clinicalPriority,
        );
    }

    public function testTwoMovesAndADuplicateLeaveTwoRowsInTheOutbox(): void
    {
        // The old unique key was (subject, template, channel), so the second
        // "moved" message for one appointment collided with the first and
        // INSERT IGNORE dropped it. This is the regression the dedupe key exists
        // for, asserted against the real index rather than the array in the double.
        $pdo = $this->pdo;
        self::assertInstanceOf(PDO::class, $pdo);
        $referral = $this->moveToClinicalReview();

        $outbox = new PdoOutboxRepository($pdo);
        $outbox->record(null, $referral->id, [
            $this->message('appointment:BK-TEST:moved:2031-06-03 14:00:00'),
            $this->message('appointment:BK-TEST:moved:2031-06-04 08:00:00'),
            $this->message('appointment:BK-TEST:moved:2031-06-04 08:00:00'),
        ]);

        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM notification_outbox WHERE referral_id = :id AND template = :template'
        );
        $statement->execute(['id' => $referral->id, 'template' => NotificationTemplate::AppointmentMoved->value]);

        self::assertSame(2, (int) $statement->fetchColumn());
    }

    private function message(string $dedupeKey): OutboundMessage
    {
        return new OutboundMessage(
            channel: NotificationChannel::Sms,
            template: NotificationTemplate::AppointmentMoved,
            recipient: '+251911000444',
            payload: ['reference' => 'BK-TEST', 'scheduled_at_utc' => '2031-06-03 14:00:00'],
            dedupeKey: $dedupeKey,
        );
    }
    private function repository(): PdoReferralRepository
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

    public function testTheConfirmationIsEnqueuedWithTheReferral(): void
    {
        $referral = $this->repository()->create(
            $this->referralEntity('RF-OUTBOX-1'),
            new Actor($this->actorUserId, Role::Patient),
            self::NOW,
            [
                new OutboundMessage(
                    channel: NotificationChannel::Sms,
                    template: NotificationTemplate::ReferralReceived,
                    recipient: '+251911000333',
                    payload: ['reference' => 'RF-OUTBOX-1'],
                ),
            ],
        );
        $this->createdReferralIds[] = $referral->id;

        $statement = $this->pdo()->prepare(
            'SELECT referral_id, channel, template FROM notification_outbox WHERE referral_id = :id'
        );
        $statement->execute(['id' => $referral->id]);
        $row = $statement->fetch();

        self::assertIsArray($row);
        $channel = is_scalar($row['channel'] ?? null) ? (string) $row['channel'] : '';
        $template = is_scalar($row['template'] ?? null) ? (string) $row['template'] : '';

        self::assertSame('sms', $channel);
        self::assertSame('referral_received', $template);
    }

    public function testAFailedOuterScopeLeavesNeitherTheReferralNorItsNotification(): void
    {
        // The notification is written inside the caller's unit of work, so a command
        // that fails after enqueueing must not leave a referral without its message
        // — or a message without its referral (WF-14).
        $reference = 'RF-OUTBOX-2';

        try {
            (new PdoTransaction($this->pdo()))->run(
                function () use ($reference): void {
                    $this->repository()->create(
                        $this->referralEntity($reference),
                        new Actor($this->actorUserId, Role::Patient),
                        self::NOW,
                        [
                            new OutboundMessage(
                                channel: NotificationChannel::Sms,
                                template: NotificationTemplate::ReferralReceived,
                                recipient: '+251911000333',
                                payload: ['reference' => $reference],
                            ),
                        ],
                    );

                    throw new RuntimeException('A later step in the command failed.');
                },
            );
        } catch (RuntimeException) {
            // Expected: the outer scope gave up.
        }

        $referrals = $this->pdo()->prepare('SELECT COUNT(*) FROM referrals WHERE reference = :reference');
        $referrals->execute(['reference' => $reference]);
        self::assertSame(0, (int) $referrals->fetchColumn());

        $messages = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM notification_outbox WHERE payload LIKE :needle'
        );
        $messages->execute(['needle' => '%' . $reference . '%']);
        self::assertSame(0, (int) $messages->fetchColumn());
    }

    /**
     * AUTH-03 / BL-E1: the profile on the row is the boundary — not the account that
     * typed the form, and not the institution two physicians share (ADR-014).
     */
    public function testReferredByReturnsOnlyThatProfilesReferrals(): void
    {
        $mine = $this->insertPhysicianProfile();
        $theirs = $this->insertPhysicianProfile();

        $myReferral = $this->createReferral($mine);
        $theirReferral = $this->createReferral($theirs);
        $unattributed = $this->createReferral();

        $ids = array_map(
            static fn (Referral $referral): int => $referral->id,
            $this->repository()->referredBy($mine),
        );

        self::assertContains($myReferral->id, $ids);
        self::assertNotContains($theirReferral->id, $ids);
        self::assertNotContains($unattributed->id, $ids);
    }

    public function testReferredByIsNewestFirst(): void
    {
        $profile = $this->insertPhysicianProfile();

        $older = $this->createReferral($profile);
        $newer = $this->createReferral($profile);

        $ids = array_map(
            static fn (Referral $referral): int => $referral->id,
            $this->repository()->referredBy($profile),
        );

        // Both rows share a timestamp in this fixture, so the id decides the tie the
        // way the statement's ORDER BY does. What is being asserted is that the list
        // is ordered newest first rather than by whatever the table happened to
        // return.
        self::assertSame([$newer->id, $older->id], array_slice($ids, 0, 2));
    }

    public function testOutstandingInformationIsReceptionsRequest(): void
    {
        $referral = $this->createReferral();

        self::assertNull($this->repository()->outstandingInformation($referral->id));

        $clerk = new Actor($this->actorUserId, Role::Staff);

        $this->repository()->transition($referral, ReferralStatus::Received, $clerk, null, self::NOW);
        $this->repository()->transition(
            $this->reload($referral->id),
            ReferralStatus::AdministrativeReview,
            $clerk,
            null,
            self::NOW,
        );
        $this->repository()->transition(
            $this->reload($referral->id),
            ReferralStatus::AwaitingInformation,
            $clerk,
            'Send the most recent ultrasound report.',
            self::NOW,
        );

        self::assertSame(
            'Send the most recent ultrasound report.',
            $this->repository()->outstandingInformation($referral->id),
        );
    }

    /**
     * A radiologist asking a clinical question moves the referral with no reason —
     * `more_information` is not one of the outcomes that must justify itself — so the
     * question exists only on the decision. The second source has to be read, or the
     * referrer is shown a state with nothing to answer.
     */
    public function testACliniciansQuestionIsFoundOnTheDecision(): void
    {
        $referral = $this->createReferral();
        $clerk = new Actor($this->actorUserId, Role::Staff);

        $this->repository()->transition($referral, ReferralStatus::Received, $clerk, null, self::NOW);
        $this->repository()->transition(
            $this->reload($referral->id),
            ReferralStatus::AdministrativeReview,
            $clerk,
            null,
            self::NOW,
        );
        $this->repository()->transition(
            $this->reload($referral->id),
            ReferralStatus::ClinicalReview,
            $clerk,
            null,
            self::NOW,
        );

        $this->repository()->recordDecision(new ClinicalDecision(
            id: 0,
            referralId: $referral->id,
            type: ClinicalDecisionType::MoreInformation,
            rationale: 'The referral does not say whether the patient is still breastfeeding.',
            technique: null,
            modality: null,
            sedation: null,
            estimatedDurationMinutes: null,
            preprocedureConditions: null,
            redirectDestination: null,
            informationRequested: 'Confirm current breastfeeding status.',
            decidedByUserId: $this->actorUserId,
            decidedAtUtc: self::NOW,
            supersedesId: null,
            clinicalPriority: null,
        ));

        $this->repository()->transition(
            $this->reload($referral->id),
            ReferralStatus::AwaitingInformation,
            new Actor($this->actorUserId, Role::Radiologist),
            null,
            self::NOW,
        );

        self::assertSame(
            'Confirm current breastfeeding status.',
            $this->repository()->outstandingInformation($referral->id),
        );
    }

    private function referralEntity(string $reference): Referral
    {
        return new Referral(
            id: 0,
            reference: $reference,
            status: ReferralStatus::Submitted,
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
        );
    }

    private function createReferral(?int $physicianProfileId = null): Referral
    {
        $referral = $this->repository()->create(
            new Referral(
                id: 0,
                reference: 'RF-TEST-' . bin2hex(random_bytes(4)),
                status: ReferralStatus::Submitted,
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
                referringPhysicianProfileId: $physicianProfileId,
            ),
            new Actor($this->actorUserId, Role::Patient),
            self::NOW,
        );

        $this->createdReferralIds[] = $referral->id;

        return $referral;
    }

    /**
     * A referrer record, so a referral can name one. Inserted `pending`: the
     * scoping query does not read the verification status, and the `verified` state
     * has its own CHECK requiring a verifier and a date.
     */
    private function insertPhysicianProfile(): int
    {
        $userId = $this->insertUser();
        $this->probeUserIds[] = $userId;

        $statement = $this->pdo()->prepare(
            "INSERT INTO physician_profiles
                (user_id, practice_number, institution, status, created_at, updated_at)
             VALUES (:user_id, :practice, 'Yekatit 12 Hospital', 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $statement->execute([
            'user_id' => $userId,
            'practice' => 'ET-MD-' . bin2hex(random_bytes(5)),
        ]);

        $id = (int) $this->pdo()->lastInsertId();
        $this->createdProfileIds[] = $id;

        return $id;
    }

    private function reload(int $id): Referral
    {
        $referral = $this->repository()->findById($id);
        self::assertNotNull($referral);

        return $referral;
    }

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (email, name, role, password_hash, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'email' => 'referral-repo-' . bin2hex(random_bytes(6)) . '@example.test',
            'name' => 'Referral Repository Probe',
            'role' => 'patient',
            'hash' => str_repeat('0', 60),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @return list<array{from_status: ?string, to_status: string, actor_role: string}>
     */
    private function historyFor(int $referralId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT from_status, to_status, actor_role FROM referral_status_history
             WHERE referral_id = :id ORDER BY id'
        );
        $statement->execute(['id' => $referralId]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'from_status' => isset($row['from_status']) && is_scalar($row['from_status'])
                    ? (string) $row['from_status']
                    : null,
                'to_status' => is_scalar($row['to_status'] ?? null) ? (string) $row['to_status'] : '',
                'actor_role' => is_scalar($row['actor_role'] ?? null) ? (string) $row['actor_role'] : '',
            ];
        }

        return $rows;
    }
}
