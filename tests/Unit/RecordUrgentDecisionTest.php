<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\Permission;
use App\Domain\RecordUrgentDecision;
use App\Domain\RegisterUrgentCase;
use App\Domain\Role;
use App\Domain\UrgentCase;
use App\Domain\UrgentCaseNotFoundException;
use App\Domain\UrgentDecisionType;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTransitionException;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InlineTransaction;
use Tests\Support\InMemoryUrgentCaseRepository;

/**
 * The coordination decision (§10.2, INT-016).
 *
 * The tests are about two boundaries: which choice may be recorded against which
 * state, and the fact that a decision and the move it releases cannot exist
 * separately.
 */
final class RecordUrgentDecisionTest extends TestCase
{
    private const NOW = '2026-11-08 13:00:00';

    private InMemoryUrgentCaseRepository $cases;

    private RecordUrgentDecision $command;

    private Actor $radiologist;

    private Actor $coordinator;

    protected function setUp(): void
    {
        $this->cases = new InMemoryUrgentCaseRepository([1 => 'Dr Selam Bekele', 2 => 'Tigist Alemu']);
        $this->command = new RecordUrgentDecision($this->cases, new Validator(), new InlineTransaction());
        $this->radiologist = new Actor(1, Role::Radiologist);
        $this->coordinator = new Actor(2, Role::Coordinator);
    }

    public function testProceedingRecordsTheDecisionAndAcceptsTheCaseInOneAct(): void
    {
        $case = $this->reviewing();

        $moved = $this->command->execute($this->radiologist, $case->id, [
            'decision_type' => 'proceed_to_resource_coordination',
            'rationale' => 'Reducible on ultrasound and the child is stable; the team can take this today.',
        ], self::NOW);

        self::assertSame(UrgentStatus::AcceptedForCoordination, $moved->urgency);

        $current = $this->cases->currentUrgentDecision($case->id);
        self::assertNotNull($current);
        self::assertSame(UrgentDecisionType::ProceedToResourceCoordination, $current->type);
        self::assertSame('accepted_for_coordination', $current->resultingStatus);

        // The deciding clinician is on the record, not just the fact of the move.
        self::assertSame(1, $current->decidedByUserId);
    }

    public function testADecisionThatReleasesNoStateLeavesTheCaseWhereItIs(): void
    {
        $case = $this->reviewing();

        $moved = $this->command->execute($this->radiologist, $case->id, [
            'decision_type' => 'defer_pending_review',
            'rationale' => 'Awaiting the repeat ultrasound before committing to a technique.',
        ], self::NOW);

        self::assertSame(UrgentStatus::UnderClinicalReview, $moved->urgency);

        // The decision exists, so "nobody has looked at this" stays false.
        $current = $this->cases->currentUrgentDecision($case->id);

        self::assertNotNull($current);
        self::assertSame(UrgentDecisionType::DeferPendingReview, $current->type);
        self::assertNull($current->resultingStatus);
    }

    public function testATransferCannotBeRecordedBeforeTheCaseIsAccepted(): void
    {
        $case = $this->reviewing();

        $this->expectException(ValidationException::class);

        try {
            $this->command->execute($this->radiologist, $case->id, [
                'decision_type' => 'transfer',
                'rationale' => 'The receiving hospital has the paediatric surgery cover for this.',
            ], self::NOW);
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('decision_type', $exception->errors);
            // Nothing was written before the refusal.
            self::assertNull($this->cases->currentUrgentDecision($case->id));
            self::assertSame(UrgentStatus::UnderClinicalReview, $this->cases->caseOrFail($case->id)->urgency);

            throw $exception;
        }
    }

    public function testAClosedCaseHasNoDecisionLeftToRecord(): void
    {
        $case = $this->reviewing();
        $this->cases->transitionUrgency(
            $this->cases->caseOrFail($case->id),
            UrgentStatus::Closed,
            $this->coordinator,
            'Family took the child to the referral hospital directly; nothing outstanding.',
            self::NOW,
        );

        $this->expectException(ValidationException::class);

        try {
            $this->command->execute($this->radiologist, $case->id, [
                'decision_type' => 'other',
                'rationale' => 'Recording a note after the case was already closed.',
            ], self::NOW);
        } catch (ValidationException $exception) {
            self::assertStringContainsString('closed', (string) ($exception->errors['decision_type'] ?? ''));
            throw $exception;
        }
    }

    public function testAShortRationaleIsRefused(): void
    {
        $case = $this->reviewing();

        $this->expectException(ValidationException::class);

        $this->command->execute($this->radiologist, $case->id, [
            'decision_type' => 'proceed_to_resource_coordination',
            'rationale' => 'yes',
        ], self::NOW);
    }

    public function testAnUnknownChoiceIsRefusedAndTheVocabularyIsNamed(): void
    {
        $case = $this->reviewing();

        $this->expectException(ValidationException::class);

        try {
            $this->command->execute($this->radiologist, $case->id, [
                'decision_type' => 'approve',
                'rationale' => 'Carried over from the elective vocabulary by mistake.',
            ], self::NOW);
        } catch (ValidationException $exception) {
            self::assertStringContainsString(
                'proceed_to_resource_coordination',
                (string) ($exception->errors['decision_type'] ?? ''),
            );
            throw $exception;
        }
    }

    public function testACoordinatorMayNotAcceptACaseTheyMayCoordinate(): void
    {
        $case = $this->reviewing();

        // They hold urgent.coordinate, so the permission check passes and the
        // refusal comes from the transition table — which is the authority that
        // actually decides who may accept a child's case.
        $this->expectException(UrgentTransitionException::class);

        $this->command->execute($this->coordinator, $case->id, [
            'decision_type' => 'proceed_to_resource_coordination',
            'rationale' => 'The list has room this afternoon so it can go ahead.',
        ], self::NOW);
    }

    public function testACoordinatorMayNotDeferAClinicalQuestionEither(): void
    {
        $case = $this->reviewing();

        // A deferral releases no state, so no transition stands behind it. Without
        // its own authority the coordinator could decide to wait on a child's
        // imaging, which is a clinical judgement.
        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessage(Permission::UrgentCoordinate->value);

        $this->command->execute($this->coordinator, $case->id, [
            'decision_type' => 'defer_pending_review',
            'rationale' => 'Awaiting the repeat film before committing to a technique.',
        ], self::NOW);
    }

    public function testARoleWithoutTheCoordinationPermissionIsRefusedFirst(): void
    {
        $case = $this->reviewing();

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessage(Permission::UrgentCoordinate->value);

        $this->command->execute(new Actor(4, Role::Staff), $case->id, [
            'decision_type' => 'proceed_to_resource_coordination',
            'rationale' => 'Reception attempts to accept a case, which is not its authority.',
        ], self::NOW);
    }

    public function testAMissingCaseIsRefusedByItsIdentifier(): void
    {
        $this->expectException(UrgentCaseNotFoundException::class);

        $this->command->execute($this->radiologist, 999, [
            'decision_type' => 'proceed_to_resource_coordination',
            'rationale' => 'This case does not exist, which is the point of the test.',
        ], self::NOW);
    }

    public function testASecondDecisionSupersedesTheFirstWithoutErasingIt(): void
    {
        $case = $this->reviewing();

        $this->command->execute($this->radiologist, $case->id, [
            'decision_type' => 'defer_pending_review',
            'rationale' => 'Waiting for the repeat film before deciding on reduction.',
        ], self::NOW);

        $this->command->execute($this->radiologist, $case->id, [
            'decision_type' => 'decline_ir_reduction',
            'rationale' => 'The repeat film shows spontaneous reduction, so no intervention is needed.',
        ], '2026-11-08 15:00:00');

        $history = $this->cases->urgentDecisionHistory($case->id);

        self::assertCount(2, $history);
        self::assertTrue($history[0]['superseded'], 'The deferral should be marked as no longer standing.');
        self::assertFalse($history[1]['superseded']);
        self::assertSame(
            UrgentDecisionType::DeclineIrReduction,
            $this->cases->currentUrgentDecision($case->id)?->type,
        );
        self::assertSame(UrgentStatus::Declined, $this->cases->caseOrFail($case->id)->urgency);
    }

    public function testEveryChoiceTheVocabularyOffersCanBeRecordedSomewhere(): void
    {
        // A choice that no state admits would be a dead option in the form.
        $recordable = [];

        foreach (UrgentDecisionType::cases() as $type) {
            foreach (UrgentStatus::cases() as $status) {
                if ($type->canBeRecordedFrom($status)) {
                    $recordable[$type->value] = true;
                    break;
                }
            }
        }

        self::assertCount(count(UrgentDecisionType::cases()), $recordable);
    }

    /** A case sitting in clinical review, which is where a decision is taken. */
    private function reviewing(): UrgentCase
    {
        $registered = (new RegisterUrgentCase($this->cases, new Validator()))->execute(
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
                'imaging_summary' => 'Ultrasound shows a ileocolic intussusception without ischaemia.',
            ],
            self::NOW,
        );

        return $this->cases->transitionUrgency(
            $this->cases->caseOrFail($registered->id),
            UrgentStatus::UnderClinicalReview,
            $this->radiologist,
            null,
            self::NOW,
        );
    }
}
