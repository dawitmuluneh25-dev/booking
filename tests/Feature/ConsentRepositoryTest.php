<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Config;
use App\Bootstrap\Env;
use App\Database\Connection;
use App\Database\PdoTransaction;
use App\Domain\Actor;
use App\Domain\ConsentNotPermittedException;
use App\Domain\ConsentOutcome;
use App\Domain\ConsentParticipant;
use App\Domain\ProcedureConsent;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Repositories\PdoConsentRepository;
use App\Repositories\PdoReferralRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The consent adapter against MySQL.
 *
 * The constraints under test are the ones that make the table a clinical record
 * rather than a form submission: an agreement on somebody else's behalf cannot
 * exist without verified authority, a withdrawal cannot exist without a reason, and
 * the current record is the row nothing supersedes.
 */
final class ConsentRepositoryTest extends TestCase
{
    private const NOW = '2026-11-13 09:00:00';

    private ?PDO $pdo = null;

    private ?PdoConsentRepository $repository = null;

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
        $this->repository = new PdoConsentRepository($this->pdo);
        $this->referralId = $this->createReferral();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            // Consents first: the foreign key refuses to orphan them, and a raw
            // insert in one test never passes through the helper that records ids.
            if ($this->referralId > 0) {
                // The supersede chain is a self-reference, so the links have to go
                // before the rows: the foreign key refuses to delete a record that
                // still has a successor, which is the behaviour under test elsewhere.
                $this->pdo->exec(
                    'UPDATE procedure_consents SET supersedes_consent_id = NULL WHERE referral_id = '
                    . $this->referralId
                );
                $this->pdo->exec('DELETE FROM procedure_consents WHERE referral_id = ' . $this->referralId);
                $this->pdo->exec('DELETE FROM notification_outbox WHERE referral_id = ' . $this->referralId);
                $this->pdo->exec('DELETE FROM referral_status_history WHERE referral_id = ' . $this->referralId);
                $this->pdo->exec('DELETE FROM referrals WHERE id = ' . $this->referralId);
            }

            if ($this->actorUserId > 0) {
                $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->actorUserId);
            }
        }

        $this->pdo = null;
        $this->repository = null;
        $this->referralId = 0;
    }

    public function testAConsentDiscussionRoundTripsWithEverythingItSaysItDiscussed(): void
    {
        $stored = $this->repository()->record($this->consent(ConsentParticipant::Self, [
            'interpreterInvolved' => true,
            'interpreterLanguage' => 'am',
            'questionsRecorded' => 'Asked whether the sample would change the operation. Answered that '
                . 'it would decide it.',
        ]));

        $reloaded = $this->repository()->findById($stored->id);

        self::assertNotNull($reloaded);
        self::assertSame(ConsentOutcome::Agreed, $reloaded->outcome);
        self::assertSame('FNAC-INFO-2026-03', $reloaded->informationVersion);
        self::assertSame('am', $reloaded->interpreterLanguage);
        self::assertTrue($reloaded->interpreterInvolved);
        self::assertTrue($reloaded->isStandingAgreement());
        self::assertStringContainsString('decide it', (string) $reloaded->questionsRecorded);
    }

    public function testTheSchemaRefusesAnAgreementOnSomebodyElsesBehalfWithoutVerifiedAuthority(): void
    {
        $this->expectException(PDOException::class);

        $this->insertRaw(
            participantRelationship: 'parent',
            outcome: 'agreed',
            authorityVerified: 0,
            discussion: 'Discussed the procedure with the child’s mother, who agreed to it.',
        );
    }

    public function testTheSchemaAcceptsTheSameAgreementOnceAuthorityIsVerified(): void
    {
        $inserted = $this->insertRaw(
            participantRelationship: 'parent',
            outcome: 'agreed',
            authorityVerified: 1,
            discussion: 'Discussed the procedure with the child’s mother, who agreed to it.',
            authorityVerifiedBy: $this->actorUserId,
            authorityVerifiedAt: self::NOW,
        );

        self::assertSame(1, $inserted);
    }

    public function testTheSchemaRefusesAConsentRecordWithNoDiscussionInIt(): void
    {
        $this->expectException(PDOException::class);

        $this->insertRaw(
            participantRelationship: 'self',
            outcome: 'agreed',
            authorityVerified: 0,
            discussion: 'Consent obtained.',
        );
    }

    public function testTheSchemaRefusesAWithdrawalWithNoReason(): void
    {
        $stored = $this->repository()->record($this->consent(ConsentParticipant::Self, []));

        $this->expectException(PDOException::class);

        $statement = $this->pdo()->prepare(
            'UPDATE procedure_consents
                SET withdrawn_at = :at, withdrawn_by_user_id = :by, withdrawal_reason = :reason
              WHERE id = :id'
        );
        $statement->execute([
            'at' => self::NOW,
            'by' => $this->actorUserId,
            'reason' => 'nope',
            'id' => $stored->id,
        ]);
    }

    public function testWithdrawalKeepsTheDiscussionAndASecondWithdrawalIsRefused(): void
    {
        $stored = $this->repository()->record($this->consent(ConsentParticipant::Self, []));

        $this->repository()->withdraw(
            $stored->id,
            new Actor($this->actorUserId, Role::Radiologist),
            'The patient telephoned to withdraw before the procedure.',
            '2026-11-14 08:00:00',
        );

        $withdrawn = $this->repository()->findById($stored->id);

        self::assertNotNull($withdrawn);
        self::assertTrue($withdrawn->isWithdrawn());
        self::assertSame($stored->discussionRecord, $withdrawn->discussionRecord);

        $this->expectException(ConsentNotPermittedException::class);

        $this->repository()->withdraw(
            $stored->id,
            new Actor($this->actorUserId, Role::Radiologist),
            'A second withdrawal of the same agreement.',
            '2026-11-15 08:00:00',
        );
    }

    public function testTheCurrentRecordIsTheOneNothingSupersedes(): void
    {
        $first = $this->repository()->record($this->consent(ConsentParticipant::Self, []));

        $second = $this->repository()->record($this->consent(ConsentParticipant::Self, [
            'outcome' => ConsentOutcome::Declined,
            'discussionRecord' => 'The patient reconsidered and declined the sampling after the '
                . 'alternatives were explained again.',
            'supersedesId' => $first->id,
        ]));

        self::assertSame($second->id, $this->repository()->current($this->referralId)?->id);

        $history = $this->repository()->history($this->referralId);

        self::assertCount(2, $history);
        self::assertSame($first->id, $history[0]['consent']->id);
        self::assertSame($second->id, $history[1]['consent']->id);
        self::assertNotSame('', $history[0]['recordedBy']);
    }

    public function testARecordCannotBeSupersededTwice(): void
    {
        $first = $this->repository()->record($this->consent(ConsentParticipant::Self, []));

        $this->repository()->record($this->consent(ConsentParticipant::Self, [
            'outcome' => ConsentOutcome::Declined,
            'discussionRecord' => 'The patient declined after the second discussion.',
            'supersedesId' => $first->id,
        ]));

        $this->expectException(PDOException::class);

        $this->repository()->record($this->consent(ConsentParticipant::Self, [
            'outcome' => ConsentOutcome::UnableToConsent,
            'discussionRecord' => 'A third record attempting to fork the supersede chain.',
            'supersedesId' => $first->id,
        ]));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function consent(ConsentParticipant $participant, array $overrides): ProcedureConsent
    {
        $consent = new ProcedureConsent(
            id: 0,
            referralId: $this->referralId,
            participant: $participant,
            participantName: 'Selamawit Girma',
            authorityVerified: (bool) ($overrides['authorityVerified'] ?? false),
            authorityVerifiedByUserId: null,
            authorityVerifiedAtUtc: null,
            capacityAssessed: true,
            capacityNote: 'Oriented, understood the discussion.',
            targetSite: 'Liver, segment VI',
            informationVersion: 'FNAC-INFO-2026-03',
            informationLanguage: 'en',
            discussionRecord: is_string($overrides['discussionRecord'] ?? null)
                ? $overrides['discussionRecord']
                : 'Discussed benefits, the risk of bleeding, and the alternative of imaging follow-up '
                    . 'without sampling.',
            questionsRecorded: isset($overrides['questionsRecorded']) && is_string($overrides['questionsRecorded'])
                ? $overrides['questionsRecorded']
                : null,
            interpreterInvolved: (bool) ($overrides['interpreterInvolved'] ?? false),
            interpreterLanguage: isset($overrides['interpreterLanguage'])
                && is_string($overrides['interpreterLanguage'])
                    ? $overrides['interpreterLanguage']
                    : null,
            outcome: ($overrides['outcome'] ?? null) instanceof ConsentOutcome
                ? $overrides['outcome']
                : ConsentOutcome::Agreed,
            attestationReference: 'Signed form in the paper record.',
            responsiblePractitionerUserId: $this->actorUserId,
            recordedByUserId: $this->actorUserId,
            consentedAtUtc: self::NOW,
            supersedesId: isset($overrides['supersedesId']) && is_int($overrides['supersedesId'])
                ? $overrides['supersedesId']
                : null,
        );

        return $consent;
    }

    private function insertRaw(
        string $participantRelationship,
        string $outcome,
        int $authorityVerified,
        string $discussion,
        ?int $authorityVerifiedBy = null,
        ?string $authorityVerifiedAt = null,
    ): int {
        $statement = $this->pdo()->prepare(
            'INSERT INTO procedure_consents
                (referral_id, participant_relationship, participant_name, authority_verified,
                 authority_verified_by_user_id, authority_verified_at, capacity_assessed,
                 information_version, information_language, discussion_record, interpreter_involved,
                 outcome, attestation_reference, responsible_practitioner_user_id,
                 recorded_by_user_id, consented_at, created_at)
             VALUES
                (:referral_id, :relationship, :name, :verified, :verified_by, :verified_at, 1,
                 :version, :language, :discussion, 0, :outcome, :attestation, :practitioner,
                 :recorded_by, :consented_at, :created_at)'
        );
        $statement->execute([
            'referral_id' => $this->referralId,
            'relationship' => $participantRelationship,
            'name' => 'Meseret Alemu',
            'verified' => $authorityVerified,
            'verified_by' => $authorityVerifiedBy,
            'verified_at' => $authorityVerifiedAt,
            'version' => 'FNAC-INFO-2026-03',
            'language' => 'en',
            'discussion' => $discussion,
            'outcome' => $outcome,
            'attestation' => 'Signed form held in the paper record.',
            'practitioner' => $this->actorUserId,
            'recorded_by' => $this->actorUserId,
            'consented_at' => self::NOW,
            'created_at' => self::NOW,
        ]);

        return $statement->rowCount();
    }

    private function insertUser(): int
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active, auth_version, created_at, updated_at)
             VALUES (:name, :email, :hash, :role, 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $statement->execute([
            'name' => 'Consent Test Clinician',
            'email' => 'consent-' . bin2hex(random_bytes(4)) . '@example.test',
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
                reference: 'RF-CONSENT-' . bin2hex(random_bytes(3)),
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
            ),
            new Actor($this->actorUserId, Role::Patient),
            self::NOW,
        );

        // The consent table does not care how a referral reached approval; walking
        // the whole clinical pathway here would test the pathway, not the consent.
        $this->pdo()->exec(
            "UPDATE referrals SET status = 'clinically_approved', clinical_priority = 'routine' WHERE id = "
            . $referral->id
        );

        return $referral->id;
    }

    private function repository(): PdoConsentRepository
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
