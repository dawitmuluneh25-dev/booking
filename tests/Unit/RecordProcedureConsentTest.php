<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\ConsentNotPermittedException;
use App\Domain\ConsentOutcome;
use App\Domain\ConsentParticipant;
use App\Domain\RecordProcedureConsent;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Domain\WithdrawConsent;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryConsentRepository;
use Tests\Support\InMemoryReferralRepository;
use Tests\Support\InlineTransaction;

/**
 * Recording a consent discussion (§18.1).
 *
 * The properties worth testing are the ones a reader would assume: that consent
 * cannot be recorded before there is anything to consent to, that an agreement on
 * behalf of somebody else cannot exist without verified authority, that a refusal
 * does not need that authority, and that a later discussion supersedes an earlier
 * one without erasing it.
 */
final class RecordProcedureConsentTest extends TestCase
{
    private const NOW = '2026-11-13 09:00:00';

    private InMemoryConsentRepository $consents;

    private InMemoryReferralRepository $referrals;

    private RecordProcedureConsent $command;

    private WithdrawConsent $withdraw;

    private int $referralId = 0;

    protected function setUp(): void
    {
        $this->consents = new InMemoryConsentRepository([12 => 'Dr Selam Tesfaye']);
        $this->referrals = new InMemoryReferralRepository();
        $this->command = new RecordProcedureConsent(
            $this->consents,
            $this->referrals,
            new Validator(),
            new InlineTransaction(),
        );
        $this->withdraw = new WithdrawConsent($this->consents, new Validator());

        $this->referralId = $this->referral(ReferralStatus::ClinicallyApproved)->id;
    }

    public function testAClinicianRecordsAnAgreementWithThePatient(): void
    {
        $consent = $this->command->execute($this->clinician(), $this->referralId, $this->input(), self::NOW);

        self::assertSame(ConsentOutcome::Agreed, $consent->outcome);
        self::assertSame(ConsentParticipant::Self, $consent->participant);
        self::assertTrue($consent->isStandingAgreement());
        self::assertNull($consent->supersedesId);
        self::assertSame(12, $consent->recordedByUserId);
    }

    public function testARepresentativeCannotAgreeBeforeTheirAuthorityIsVerified(): void
    {
        try {
            $this->command->execute($this->clinician(), $this->referralId, $this->input([
                'participant_relationship' => 'parent',
                'participant_name' => 'Meseret Alemu',
            ]), self::NOW);
            self::fail('An agreement was recorded on unverified authority.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('authority_verified', $exception->errors);
        }

        self::assertNull($this->consents->current($this->referralId));
    }

    public function testAVerifiedRepresentativeCanAgreeAndTheRecordNamesTheVerifier(): void
    {
        $consent = $this->command->execute($this->clinician(), $this->referralId, $this->input([
            'participant_relationship' => 'parent',
            'participant_name' => 'Meseret Alemu',
            'authority_verified' => '1',
        ]), self::NOW);

        self::assertTrue($consent->authorityVerified);
        self::assertSame(12, $consent->authorityVerifiedByUserId);
        self::assertSame(self::NOW, $consent->authorityVerifiedAtUtc);
        self::assertTrue($consent->isStandingAgreement());
    }

    /**
     * Refusing care is not an act that needs somebody else's authorisation, so the
     * requirement that protects an agreement must not block a refusal.
     */
    public function testARepresentativeCanRecordARefusalWithoutVerifiedAuthority(): void
    {
        $consent = $this->command->execute($this->clinician(), $this->referralId, $this->input([
            'participant_relationship' => 'parent',
            'participant_name' => 'Meseret Alemu',
            'outcome' => 'declined',
        ]), self::NOW);

        self::assertSame(ConsentOutcome::Declined, $consent->outcome);
        self::assertFalse($consent->authorityVerified);
        self::assertFalse($consent->isStandingAgreement());
    }

    public function testThereIsNoEmergencyOverrideToRecord(): void
    {
        // §18.3 offers no generic bypass: an emergency is an inability to consent,
        // recorded with a reason, and it releases nothing.
        $consent = $this->command->execute($this->clinician(), $this->referralId, $this->input([
            'outcome' => 'unable_to_consent',
            'discussion_record' => 'Patient is unresponsive on arrival; the receiving surgical team was '
                . 'contacted and is attending. No representative is present.',
        ]), self::NOW);

        self::assertSame(ConsentOutcome::UnableToConsent, $consent->outcome);
        self::assertFalse($consent->outcome->isAgreement());
    }

    public function testANonClinicianCannotRecordConsent(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->command->execute(new Actor(13, Role::Staff), $this->referralId, $this->input(), self::NOW);
    }

    public function testConsentCannotBeRecordedBeforeTheClinicalDecision(): void
    {
        $submitted = $this->referral(ReferralStatus::ClinicalReview);

        $this->expectException(ConsentNotPermittedException::class);

        $this->command->execute($this->clinician(), $submitted->id, $this->input(), self::NOW);
    }

    public function testADiscussionRecordOfOneLineIsRefused(): void
    {
        try {
            $this->command->execute($this->clinician(), $this->referralId, $this->input([
                'discussion_record' => 'Explained.',
            ]), self::NOW);
            self::fail('A two-word discussion record was accepted as a consent dialogue.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('discussion_record', $exception->errors);
        }
    }

    public function testTheInformationVersionIsRequired(): void
    {
        try {
            $this->command->execute($this->clinician(), $this->referralId, $this->input([
                'information_version' => '',
            ]), self::NOW);
            self::fail('A record was stored without naming the information that was discussed.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('information_version', $exception->errors);
        }
    }

    public function testAnInterpreterMustBeGivenTheLanguageTheyWorkedIn(): void
    {
        try {
            $this->command->execute($this->clinician(), $this->referralId, $this->input([
                'interpreter_involved' => '1',
            ]), self::NOW);
            self::fail('An interpreter was recorded with no language.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('interpreter_language', $exception->errors);
        }
    }

    public function testThePatientCannotHaveRepresentativeAuthorityVerified(): void
    {
        try {
            $this->command->execute($this->clinician(), $this->referralId, $this->input([
                'authority_verified' => '1',
            ]), self::NOW);
            self::fail('A self-consent claimed verified representative authority.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('authority_verified', $exception->errors);
        }
    }

    public function testASecondDiscussionSupersedesTheFirstWithoutErasingIt(): void
    {
        $first = $this->command->execute($this->clinician(), $this->referralId, $this->input(), self::NOW);

        $second = $this->command->execute($this->clinician(), $this->referralId, $this->input([
            'outcome' => 'declined',
            'discussion_record' => 'The patient reconsidered after speaking with her family and declined '
                . 'the procedure; the alternatives were discussed again.',
        ]), '2026-11-14 10:00:00');

        self::assertSame($first->id, $second->supersedesId);
        self::assertSame($second->id, $this->consents->current($this->referralId)?->id);
        self::assertCount(2, $this->consents->history($this->referralId));
        self::assertSame($first->id, $this->consents->history($this->referralId)[0]['consent']->id);
    }

    public function testWithdrawalLeavesTheAgreementReadableAndNeedsAReason(): void
    {
        $consent = $this->command->execute($this->clinician(), $this->referralId, $this->input(), self::NOW);

        try {
            $this->withdraw->execute($this->clinician(), $consent->id, 'no', self::NOW);
            self::fail('A withdrawal with no usable reason was recorded.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('withdrawal_reason', $exception->errors);
        }

        $this->withdraw->execute(
            $this->clinician(),
            $consent->id,
            'The patient telephoned to withdraw consent before the procedure.',
            '2026-11-15 08:00:00',
        );

        $withdrawn = $this->consents->findById($consent->id);

        self::assertNotNull($withdrawn);
        self::assertTrue($withdrawn->isWithdrawn());
        self::assertFalse($withdrawn->isStandingAgreement());
        self::assertSame('Agreed, then withdrawn', $withdrawn->standingLabel());
        self::assertSame($consent->discussionRecord, $withdrawn->discussionRecord);
    }

    public function testWithdrawingTwiceIsRefusedAndANonClinicianCannotWithdrawAtAll(): void
    {
        $consent = $this->command->execute($this->clinician(), $this->referralId, $this->input(), self::NOW);

        try {
            $this->withdraw->execute(
                new Actor(13, Role::Staff),
                $consent->id,
                'Reception decided this was no longer needed.',
                self::NOW,
            );
            self::fail('A receptionist withdrew a clinical agreement.');
        } catch (AuthorisationException) {
            self::assertFalse($this->consents->findById($consent->id)?->isWithdrawn());
        }

        $this->withdraw->execute(
            $this->clinician(),
            $consent->id,
            'The patient withdrew before the procedure.',
            self::NOW,
        );

        $this->expectException(ConsentNotPermittedException::class);

        $this->withdraw->execute(
            $this->clinician(),
            $consent->id,
            'A second withdrawal of the same agreement.',
            self::NOW,
        );
    }

    private function clinician(): Actor
    {
        return new Actor(12, Role::Radiologist);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'participant_relationship' => 'self',
            'participant_name' => 'Abebe Bekele',
            'outcome' => 'agreed',
            'information_version' => 'FNAC-INFO-2026-03',
            'information_language' => 'en',
            'discussion_record' => 'Discussed the purpose of the sampling, the risk of bleeding and '
                . 'infection, and the alternative of ultrasound follow-up without sampling.',
            'attestation_reference' => 'Signed form held in the paper record, scan pending.',
            'target_site' => 'Left breast, upper outer quadrant',
        ], $overrides);
    }

    private function referral(ReferralStatus $status): Referral
    {
        return $this->referrals->create(
            new Referral(
                id: 0,
                reference: 'RF-2026-0200',
                status: $status,
                version: 1,
                patientName: 'Abebe Bekele',
                patientPhone: '+251911000001',
                patientDob: '1980-04-02',
                guardianName: null,
                guardianPhone: null,
                guardianRelationship: null,
                procedureCode: 'image_guided_fnac',
                requestedUrgency: 'routine',
                clinicalSummary: 'Palpable left breast mass.',
                targetSite: 'Left breast',
                referringPhysicianName: 'Dr Hana Tesfaye',
                referringFacility: 'Black Lion Hospital',
                referringPhysicianPhone: '+251911000002',
                submittedByUserId: 7,
                submittedOnBehalfOf: 'referrer',
                createdAtUtc: self::NOW,
                referringPhysicianProfileId: null,
            ),
            new Actor(7, Role::Staff),
            self::NOW,
        );
    }
}
