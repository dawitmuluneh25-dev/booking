<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AllergyStatus;
use App\Domain\AnticoagulantStatus;
use App\Domain\AssessmentNotPermittedException;
use App\Domain\AuthorisationException;
use App\Domain\PregnancyStatus;
use App\Domain\RecordClinicalAssessment;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryClinicalAssessmentRepository;
use Tests\Support\InMemoryReferralRepository;
use Tests\Support\InlineTransaction;

/**
 * Recording the pre-procedure assessment (§6.5, §6.6).
 *
 * The behaviour that matters is what happens to the things nobody asked about: they
 * stay null and read as "not recorded", and a status that asserts a finding cannot
 * be stored without the finding behind it.
 */
final class RecordClinicalAssessmentTest extends TestCase
{
    private const NOW = '2026-11-13 11:00:00';

    private InMemoryClinicalAssessmentRepository $assessments;

    private InMemoryReferralRepository $referrals;

    private RecordClinicalAssessment $command;

    private int $referralId = 0;

    protected function setUp(): void
    {
        $this->assessments = new InMemoryClinicalAssessmentRepository();
        $this->referrals = new InMemoryReferralRepository();
        $this->command = new RecordClinicalAssessment(
            $this->assessments,
            $this->referrals,
            new Validator(),
            new InlineTransaction(),
        );

        $this->referralId = $this->referral(ReferralStatus::ClinicalReview)->id;
    }

    public function testAClinicianRecordsTheThreeAnswersThatDecideBleedingRisk(): void
    {
        $assessment = $this->command->execute($this->clinician(), $this->referralId, [
            'allergy_status' => 'no_known_allergy_confirmed',
            'anticoagulant_status' => 'no_use_confirmed',
            'pregnancy_status' => 'not_relevant',
        ], self::NOW);

        self::assertSame(AllergyStatus::NoKnownAllergyConfirmed, $assessment->allergy);
        self::assertSame(AnticoagulantStatus::NoUseConfirmed, $assessment->anticoagulant);
        self::assertSame(PregnancyStatus::NotRelevant, $assessment->pregnancy);
        self::assertTrue($assessment->isCompleteForProcedure());
    }

    /**
     * The central distinction: a question nobody asked is not a negative answer.
     */
    public function testAnUnaskedQuestionStaysNullRatherThanBecomingNegative(): void
    {
        $assessment = $this->command->execute($this->clinician(), $this->referralId, [
            'relevant_history' => 'No significant past medical history reported by the patient.',
        ], self::NOW);

        self::assertNull($assessment->allergy);
        self::assertNull($assessment->anticoagulant);
        self::assertNull($assessment->pregnancy);
        self::assertFalse($assessment->isCompleteForProcedure());
        self::assertSame('Not recorded', \App\Domain\ClinicalAssessment::describe($assessment->allergy));
    }

    public function testUnknownAndNotYetAssessedAreRecordableAndNeitherCountsAsEstablished(): void
    {
        $assessment = $this->command->execute($this->clinician(), $this->referralId, [
            'allergy_status' => 'unknown',
            'anticoagulant_status' => 'not_yet_assessed',
        ], self::NOW);

        self::assertSame(AllergyStatus::Unknown, $assessment->allergy);
        self::assertFalse($assessment->allergy->isEstablished());
        self::assertFalse($assessment->anticoagulant?->isEstablished());
        self::assertFalse($assessment->isCompleteForProcedure());
    }

    public function testAKnownAllergyNeedsTheReactionRecorded(): void
    {
        try {
            $this->command->execute($this->clinician(), $this->referralId, [
                'allergy_status' => 'known_allergy',
            ], self::NOW);
            self::fail('A known allergy was stored with nothing behind it.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('allergy_details', $exception->errors);
        }
    }

    public function testReportedAnticoagulantUseNeedsTheDrugNamed(): void
    {
        try {
            $this->command->execute($this->clinician(), $this->referralId, [
                'anticoagulant_status' => 'reported_use',
            ], self::NOW);
            self::fail('Anticoagulant use was stored without naming the drug.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('anticoagulant_details', $exception->errors);
        }
    }

    public function testAnEmptyAssessmentIsRefusedBecauseItReadsAsUnremarkable(): void
    {
        try {
            $this->command->execute($this->clinician(), $this->referralId, [], self::NOW);
            self::fail('An empty assessment was stored.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('assessment', $exception->errors);
            self::assertStringContainsString('unremarkable', (string) $exception->errors['assessment']);
        }
    }

    public function testAnUncontrolledStatusIsRefused(): void
    {
        try {
            $this->command->execute($this->clinician(), $this->referralId, [
                'allergy_status' => 'none',
            ], self::NOW);
            self::fail('An invented allergy value was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('allergy_status', $exception->errors);
        }
    }

    public function testANonClinicianCannotRecordAnAssessment(): void
    {
        $this->expectException(AuthorisationException::class);

        $this->command->execute(new Actor(13, Role::Staff), $this->referralId, [
            'allergy_status' => 'unknown',
        ], self::NOW);
    }

    public function testANewAssessmentSupersedesTheEarlierOneWithoutErasingIt(): void
    {
        $first = $this->command->execute($this->clinician(), $this->referralId, [
            'allergy_status' => 'unknown',
        ], self::NOW);

        $second = $this->command->execute($this->clinician(), $this->referralId, [
            'allergy_status' => 'known_allergy',
            'allergy_details' => 'Penicillin — urticaria and wheeze after a course in 2019.',
        ], '2026-11-14 09:00:00');

        self::assertSame($first->id, $second->supersedesId);
        self::assertSame($second->id, $this->assessments->current($this->referralId)?->id);
        self::assertCount(2, $this->assessments->history($this->referralId));
        self::assertSame(
            AllergyStatus::Unknown,
            $this->assessments->history($this->referralId)[0]['assessment']->allergy,
            'What was known before the procedure was lost.',
        );
    }

    public function testAFinishedReferralTakesNoAssessment(): void
    {
        $cancelled = $this->referral(ReferralStatus::Cancelled);

        $this->expectException(AssessmentNotPermittedException::class);

        $this->command->execute($this->clinician(), $cancelled->id, [
            'allergy_status' => 'unknown',
        ], self::NOW);
    }

    private function clinician(): Actor
    {
        return new Actor(12, Role::Radiologist);
    }

    private function referral(ReferralStatus $status): Referral
    {
        return $this->referrals->create(
            new Referral(
                id: 0,
                reference: 'RF-2026-0300',
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
