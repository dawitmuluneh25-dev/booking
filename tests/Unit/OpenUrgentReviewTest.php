<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\OpenUrgentReview;
use App\Domain\Permission;
use App\Domain\RecordUrgentDecision;
use App\Domain\RegisterUrgentCase;
use App\Domain\Role;
use App\Domain\UrgentCase;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTransitionException;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InlineTransaction;
use Tests\Support\InMemoryUrgentCaseRepository;

/**
 * Taking a new case into clinical review.
 *
 * The step that makes the decision screen reachable at all: `RecordUrgentDecision`
 * refuses every choice from `received`, because a case cannot be accepted before it
 * has been reviewed. Without this the decision panel existed for no case.
 */
final class OpenUrgentReviewTest extends TestCase
{
    private const NOW = '2026-11-08 13:00:00';

    private InMemoryUrgentCaseRepository $cases;

    private OpenUrgentReview $command;

    private Actor $radiologist;

    protected function setUp(): void
    {
        $this->cases = new InMemoryUrgentCaseRepository();
        $this->command = new OpenUrgentReview($this->cases);
        $this->radiologist = new Actor(1, Role::Radiologist);
    }

    public function testTakingACaseIntoReviewIsRecordedWithItsActor(): void
    {
        $case = $this->registered();

        $moved = $this->command->execute($this->radiologist, $case->id, self::NOW);

        self::assertSame(UrgentStatus::UnderClinicalReview, $moved->urgency);
        self::assertSame(UrgentStatus::UnderClinicalReview, $this->cases->caseOrFail($case->id)->urgency);
    }

    public function testReviewingTheCaseMakesADecisionRecordable(): void
    {
        $case = $this->registered();

        // The whole reason this command exists: before it, this call is refused.
        $decider = new RecordUrgentDecision($this->cases, new Validator(), new InlineTransaction());

        try {
            $decider->execute($this->radiologist, $case->id, [
                'decision_type' => 'proceed_to_resource_coordination',
                'rationale' => 'Attempting to accept a case that nobody has reviewed yet.',
            ], self::NOW);
            self::fail('A case in received should not accept a decision.');
        } catch (ValidationException $refused) {
            self::assertArrayHasKey('decision_type', $refused->errors);
        }

        $this->command->execute($this->radiologist, $case->id, self::NOW);

        $accepted = $decider->execute($this->radiologist, $case->id, [
            'decision_type' => 'proceed_to_resource_coordination',
            'rationale' => 'Reducible on ultrasound with no ischaemia; the surgical team is on site.',
        ], self::NOW);

        self::assertSame(UrgentStatus::AcceptedForCoordination, $accepted->urgency);
    }

    public function testOnlyAClinicianMayOpenTheReview(): void
    {
        $case = $this->registered();

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessage(Permission::ClinicalReview->value);

        $this->command->execute(new Actor(3, Role::Staff), $case->id, self::NOW);
    }

    public function testACaseAlreadyInReviewCannotBeReopened(): void
    {
        $case = $this->registered();
        $this->command->execute($this->radiologist, $case->id, self::NOW);

        $this->expectException(UrgentTransitionException::class);

        $this->command->execute($this->radiologist, $case->id, self::NOW);
    }

    public function testACaseAlreadyAcceptedCannotBeSentBackToReview(): void
    {
        $case = $this->registered();
        $this->command->execute($this->radiologist, $case->id, self::NOW);

        (new RecordUrgentDecision($this->cases, new Validator(), new InlineTransaction()))->execute(
            $this->radiologist,
            $case->id,
            [
                'decision_type' => 'proceed_to_resource_coordination',
                'rationale' => 'Acute presentation with a surgical team available on site today.',
            ],
            self::NOW,
        );

        $this->expectException(UrgentTransitionException::class);

        $this->command->execute($this->radiologist, $case->id, self::NOW);
    }

    private function registered(): UrgentCase
    {
        return (new RegisterUrgentCase($this->cases, new Validator()))->execute(
            new Actor(3, Role::Staff),
            [
                'patient_name' => 'Bereket Tesfaye',
                'patient_dob' => '2021-03-04',
                'patient_age_months' => '',
                'guardian_name' => 'Almaz Tesfaye',
                'guardian_phone' => '+251911222444',
                'guardian_relationship' => 'Mother',
                'referring_physician_name' => 'Dr Yonas Girma',
                'referring_facility' => 'Adama Hospital',
                'referring_physician_phone' => '+251911222000',
                'receiving_hospital_name' => 'HakimeRAD',
                'clinical_summary' => 'Six hours of colicky pain with a target sign on ultrasound.',
                'imaging_summary' => 'Ultrasound shows an ileocolic intussusception without ischaemia.',
            ],
            self::NOW,
        );
    }
}
