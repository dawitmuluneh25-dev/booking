<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\AdvanceUrgentCoordination;
use App\Domain\AuthorisationException;
use App\Domain\Permission;
use App\Domain\RegisterUrgentCase;
use App\Domain\Role;
use App\Domain\UrgentCase;
use App\Domain\UrgentContactAttempt;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentStatus;
use App\Domain\UrgentTransitionException;
use App\Domain\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryUrgentCaseRepository;

/**
 * The two coordination states that had no writer (WF-05).
 *
 * The tests are about what is *not* inferred: a contact attempt is not an
 * engagement, resolution needs an owner, and neither state arrives as a side effect
 * of something else.
 */
final class AdvanceUrgentCoordinationTest extends TestCase
{
    private const NOW = '2026-11-08 13:00:00';

    private InMemoryUrgentCaseRepository $cases;

    private AdvanceUrgentCoordination $command;

    private Actor $coordinator;

    private Actor $radiologist;

    protected function setUp(): void
    {
        $this->cases = new InMemoryUrgentCaseRepository([2 => 'Tigist Alemu', 1 => 'Dr Selam Bekele']);
        $this->command = new AdvanceUrgentCoordination($this->cases);
        $this->coordinator = new Actor(2, Role::Coordinator);
        $this->radiologist = new Actor(1, Role::Radiologist);
    }

    public function testAnAcknowledgedCaseCanBecomeCoordinating(): void
    {
        $case = $this->contacted();

        $moved = $this->command->execute($this->coordinator, $case->id, [
            'coordination' => 'coordinating',
            'note' => 'The paediatric surgical team has agreed to take the case this afternoon.',
        ], self::NOW);

        self::assertSame(UrgentCoordination::Coordinating, $moved->coordination);

        // The move is on the timeline with the actor who made it.
        $entry = $this->caseEntry($case->id, 'contacting', 'coordinating');

        self::assertStringContainsString('contacting → coordinating', $entry);
    }

    public function testCoordinatingCanBecomeResolvedAndTheCaseLeavesNothingOutstanding(): void
    {
        $case = $this->contacted();

        $this->command->execute($this->coordinator, $case->id, [
            'coordination' => 'coordinating',
        ], self::NOW);

        $resolved = $this->command->execute($this->coordinator, $case->id, [
            'coordination' => 'resolved',
            'note' => 'Reduction done, the child is recovering on the ward.',
        ], '2026-11-08 17:00:00');

        self::assertSame(UrgentCoordination::Resolved, $resolved->coordination);
        self::assertTrue($resolved->coordination->isTerminal());
    }

    public function testACaseNobodyAcknowledgedCannotBeResolved(): void
    {
        // Registered and never claimed. canProgressTo() is the method that says a
        // resolution needs an owner, and the transition table already refuses this.
        $case = $this->registered();

        $this->expectException(UrgentTransitionException::class);

        $this->command->execute($this->coordinator, $case->id, [
            'coordination' => 'resolved',
            'note' => 'Attempting to resolve a case nobody has picked up.',
        ], self::NOW);
    }

    public function testTheCommandRefusesTheStatesThatHaveTheirOwnEvidence(): void
    {
        $case = $this->contacted();

        $this->expectException(ValidationException::class);

        try {
            // `escalated` has its own command with a required reason; accepting it
            // here would let a case be escalated with no reason recorded.
            $this->command->execute($this->coordinator, $case->id, [
                'coordination' => 'escalated',
            ], self::NOW);
        } catch (ValidationException $exception) {
            self::assertStringContainsString(
                'Coordinating',
                (string) ($exception->errors['coordination'] ?? ''),
            );
            throw $exception;
        }
    }

    public function testAnAdministratorMayCloseACaseWithoutBeingAbleToResolveIt(): void
    {
        // The reason resolution is not inferred from closure: Admin holds
        // urgent.coordinate and may close (UrgentStatus names it for Closed), but is
        // not named for Resolved. Resolving on close would give them the act anyway.
        $case = $this->contacted();

        $this->expectException(UrgentTransitionException::class);

        $this->command->execute(new Actor(5, Role::Admin), $case->id, [
            'coordination' => 'coordinating',
        ], self::NOW);
    }

    public function testNobodyCanReportTheTeamEngagedBeforeAnyContactWasAttempted(): void
    {
        // The table refuses it, and that refusal is the point: `contacting` records an
        // attempt, `coordinating` records that the other end engaged. Skipping the
        // first would let a case claim an engagement with no call behind it.
        $case = $this->registered();
        $acknowledged = $this->cases->acknowledge($case, $this->coordinator, 'telephone', null, self::NOW);

        $this->expectException(UrgentTransitionException::class);

        $this->command->execute($this->coordinator, $acknowledged->id, [
            'coordination' => 'coordinating',
        ], self::NOW);
    }

    public function testARoleWithoutTheCoordinationPermissionIsRefusedFirst(): void
    {
        $case = $this->contacted();

        $this->expectException(AuthorisationException::class);
        $this->expectExceptionMessage(Permission::UrgentCoordinate->value);

        $this->command->execute(new Actor(4, Role::Physician), $case->id, [
            'coordination' => 'coordinating',
        ], self::NOW);
    }

    public function testAResolvedCaseHasNowhereLeftToGo(): void
    {
        $case = $this->contacted();

        $this->command->execute($this->coordinator, $case->id, [
            'coordination' => 'resolved',
        ], self::NOW);

        $this->expectException(UrgentTransitionException::class);

        $this->command->execute($this->coordinator, $case->id, [
            'coordination' => 'coordinating',
        ], self::NOW);
    }

    public function testAStaleCaseObjectCannotMoveTheCoordinationTwice(): void
    {
        $case = $this->contacted();

        // Two coordinators read the same acknowledged case. The first moves it.
        $this->command->execute($this->coordinator, $case->id, ['coordination' => 'coordinating'], self::NOW);

        // The second holds its own stale copy and must not be told it succeeded.
        $this->expectException(UrgentTransitionException::class);

        $this->cases->transitionCoordination(
            $case,
            UrgentCoordination::Coordinating,
            $this->radiologist,
            null,
            self::NOW,
        );
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

    /**
     * A case somebody has acknowledged and then tried to call.
     *
     * `contacting` is not a detour: `Acknowledged` transitions to `Contacting`,
     * `Escalated` or `Resolved`, so a case cannot be reported as coordinating before
     * anybody has attempted contact.
     */
    private function contacted(): UrgentCase
    {
        $case = $this->registered();
        $acknowledged = $this->cases->acknowledge($case, $this->coordinator, 'telephone', null, self::NOW);

        return $this->cases->recordContactAttempt($acknowledged, new UrgentContactAttempt(
            caseId: $acknowledged->id,
            attemptedByUserId: $this->coordinator->userId,
            contactRole: 'receiving_team',
            contactName: 'Sister Hana',
            contactPhone: '+251911222111',
            channel: 'telephone',
            outcome: 'reached',
            notes: 'The surgical registrar is expecting the referral.',
            attemptedAtUtc: self::NOW,
        ));
    }

    /** The timeline row for a coordination move, as the page would render it. */
    private function caseEntry(int $caseId, string $from, string $to): string
    {
        foreach ($this->cases->timeline($caseId) as $entry) {
            if ($entry->label === $from . ' → ' . $to) {
                return $entry->description();
            }
        }

        self::fail('No timeline entry moving the case from ' . $from . ' to ' . $to . ' was recorded.');
    }
}
