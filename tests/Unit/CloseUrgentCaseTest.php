<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AcknowledgeUrgentCase;
use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\CloseUrgentCase;
use App\Domain\RegisterUrgentCase;
use App\Domain\Role;
use App\Domain\UrgentCase;
use App\Domain\UrgentCaseNotFoundException;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTimelineEntry;
use App\Domain\UrgentTransitionException;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUrgentCaseRepository;

/**
 * Closure is the last thing that happens to a case, and the only place the record
 * says what became of the child.
 *
 * §10.3 step 10 keeps the case open until accountable handover is documented, and
 * `INT-023` makes the outcome mandatory. So the tests here are about the note being
 * real, and about closure not becoming a way for the wrong person to make a case
 * disappear.
 */
final class CloseUrgentCaseTest extends TestCase
{
    private const NOW = '2026-11-08 13:00:00';

    private InMemoryUrgentCaseRepository $repository;

    private RegisterUrgentCase $register;

    private AcknowledgeUrgentCase $acknowledge;

    private CloseUrgentCase $close;

    protected function setUp(): void
    {
        $this->repository = new InMemoryUrgentCaseRepository();
        $this->register = new RegisterUrgentCase($this->repository, new Validator());
        $this->acknowledge = new AcknowledgeUrgentCase($this->repository);
        $this->close = new CloseUrgentCase($this->repository);
    }

    public function testClosingRecordsTheOutcomeTheStatusAndTheClosedTime(): void
    {
        $case = $this->receivedCase();

        $closed = $this->close->execute(
            new Actor(5, Role::Coordinator),
            $case->id,
            'Reduced under fluoroscopy at Black Lion; child admitted for observation.',
            self::NOW,
        );

        self::assertSame(UrgentStatus::Closed, $closed->urgency);
        self::assertSame(
            'Reduced under fluoroscopy at Black Lion; child admitted for observation.',
            $this->lastReasonOf($case->id),
        );
        self::assertSame(self::NOW, $this->repository->closedAtFor($case->id));
    }

    public function testAClosureWithoutAnOutcomeIsRefused(): void
    {
        $case = $this->receivedCase();

        $this->expectException(ValidationException::class);

        $this->close->execute(new Actor(5, Role::Coordinator), $case->id, '  done  ', self::NOW);
    }

    public function testNothingIsWrittenWhenTheOutcomeIsRefused(): void
    {
        $case = $this->receivedCase();

        try {
            $this->close->execute(new Actor(5, Role::Coordinator), $case->id, '', self::NOW);
        } catch (ValidationException) {
            // expected
        }

        self::assertSame(UrgentStatus::Received, $this->repository->caseOrFail($case->id)->urgency);
        self::assertNull($this->repository->closedAtFor($case->id));
    }

    /**
     * A radiologist holds `urgent.coordinate` but is not among the roles the status
     * machine allows to close. The permission opens the door; the transition table
     * still decides who may walk through it.
     */
    public function testARadiologistCannotCloseACase(): void
    {
        $case = $this->receivedCase();

        $this->expectException(UrgentTransitionException::class);

        $this->close->execute(
            new Actor(2, Role::Radiologist),
            $case->id,
            'Reduced successfully; child observed overnight.',
            self::NOW,
        );
    }

    public function testAnActorWithoutTheCoordinationPermissionIsRefusedBeforeAnythingIsRead(): void
    {
        $case = $this->receivedCase();

        $this->expectException(AuthorisationException::class);

        $this->close->execute(new Actor(9, Role::Staff), $case->id, 'Nothing further was required.', self::NOW);
    }

    public function testACaseThatIsMidCoordinationCannotJumpStraightToClosed(): void
    {
        $case = $this->receivedCase();
        $radiologist = new Actor(2, Role::Radiologist);

        // Received → under review → accepted. `accepted_for_coordination` only leads
        // to completed or transferred, so closing it here would skip the outcome the
        // pathway exists to record.
        $case = $this->repository->transitionUrgency(
            $case,
            UrgentStatus::UnderClinicalReview,
            $radiologist,
            null,
            self::NOW,
        );
        $this->repository->transitionUrgency(
            $case,
            UrgentStatus::AcceptedForCoordination,
            $radiologist,
            null,
            self::NOW,
        );

        $this->expectException(UrgentTransitionException::class);

        $this->close->execute(
            new Actor(5, Role::Coordinator),
            $case->id,
            'Closed without recording whether the reduction happened.',
            self::NOW,
        );
    }

    public function testClosingAMissingCaseIsNotFound(): void
    {
        $this->expectException(UrgentCaseNotFoundException::class);

        $this->close->execute(new Actor(5, Role::Coordinator), 999, 'No such case to close.', self::NOW);
    }

    /**
     * The status move and the coordination event are both written, because §10.3
     * step 10 asks for the outcome as a record and not only as a state.
     */
    public function testTheTimelineCarriesTheClosureInOrder(): void
    {
        $case = $this->receivedCase();
        $this->acknowledge->execute(new Actor(4, Role::Staff), $case->id, 'telephone', null, self::NOW);
        $this->close->execute(
            new Actor(5, Role::Coordinator),
            $case->id,
            'Not reduced: transferred to paediatric surgery at Black Lion.',
            self::NOW,
        );

        $timeline = $this->repository->timeline($case->id);
        $descriptions = array_map(
            static fn (UrgentTimelineEntry $entry): string => $entry->description(),
            $timeline,
        );

        self::assertContains('Acknowledged — telephone', $descriptions);
        self::assertContains('Status received → closed', $descriptions);
        self::assertSame(UrgentCoordination::Acknowledged, $this->repository->caseOrFail($case->id)->coordination);
    }

    private function receivedCase(): UrgentCase
    {
        return $this->register->execute(
            new Actor(3, Role::Physician),
            [
                'patient_name' => 'Bereket Tesfaye',
                'patient_dob' => '2025-09-02',
                'patient_age_months' => '14',
                'guardian_name' => 'Tesfaye Alemu',
                'guardian_phone' => '+251911222444',
                'guardian_relationship' => 'Father',
                'referring_physician_name' => 'Dr Hana',
                'referring_facility' => 'Adama Hospital',
                'clinical_summary' => 'Twelve hours of colicky pain with redcurrant stool.',
                'imaging_summary' => 'Target sign on transverse view.',
            ],
            self::NOW,
        );
    }

    private function lastReasonOf(int $caseId): ?string
    {
        $rows = $this->repository->historyFor($caseId);
        $last = $rows[count($rows) - 1] ?? null;

        return $last === null ? null : $last['reason'];
    }
}
