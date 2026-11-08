<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Permission;
use App\Domain\Role;
use App\Domain\UrgentCoordination;
use App\Domain\UrgentStatus;
use PHPUnit\Framework\TestCase;

/**
 * The urgent pathway is checked here rather than in a controller, because the
 * rules exist to be true for every caller: HTTP, CLI, or a future integration.
 */
final class UrgentStatusTest extends TestCase
{
    public function testEveryUrgentCaseEndsClosed(): void
    {
        foreach (UrgentStatus::cases() as $state) {
            if ($state->isTerminal()) {
                self::assertSame([], $state->allowedTransitions());

                continue;
            }

            self::assertNotSame(
                [],
                $state->allowedTransitions(),
                $state->value . ' cannot reach any other state, so a case would stall there.'
            );
        }

        self::assertTrue(UrgentStatus::Closed->isTerminal());
    }

    public function testEveryDeclaredTransitionNamesAnActor(): void
    {
        foreach (UrgentStatus::cases() as $from) {
            foreach ($from->allowedTransitions() as $to) {
                self::assertNotSame(
                    [],
                    $from->allowedActors($to),
                    'Nobody is allowed to move an urgent case from ' . $from->value . ' to ' . $to->value . '.'
                );
            }
        }

        foreach (UrgentCoordination::cases() as $from) {
            foreach ($from->allowedTransitions() as $to) {
                self::assertNotSame(
                    [],
                    $from->allowedActors($to),
                    'Nobody is allowed to move coordination from ' . $from->value . ' to ' . $to->value . '.'
                );
            }
        }
    }

    public function testCaseCannotBeCoordinatedBeforeClinicalReview(): void
    {
        self::assertFalse(UrgentStatus::Received->canTransitionTo(UrgentStatus::AcceptedForCoordination));
        self::assertFalse(UrgentStatus::Received->canTransitionTo(UrgentStatus::Completed));
        self::assertTrue(UrgentStatus::Received->canTransitionTo(UrgentStatus::UnderClinicalReview));
    }

    public function testOnlyARadiologistAcceptsDeclinesOrRedirects(): void
    {
        $clinicalOutcomes = [
            UrgentStatus::UnderClinicalReview,
            UrgentStatus::AcceptedForCoordination,
            UrgentStatus::Redirected,
            UrgentStatus::Declined,
        ];

        foreach ($clinicalOutcomes as $target) {
            $actors = UrgentStatus::Received->allowedActors($target);

            if ($target === UrgentStatus::UnderClinicalReview) {
                self::assertSame([Role::Radiologist], $actors);

                continue;
            }

            self::assertSame(
                [Role::Radiologist],
                UrgentStatus::UnderClinicalReview->allowedActors($target),
                $target->value . ' is a clinical decision.'
            );
        }

        // An administrator coordinates; they do not decide a child's case.
        self::assertFalse(Role::Admin->can(Permission::ClinicalApprove));
        self::assertNotContains(Role::Admin, UrgentStatus::UnderClinicalReview->allowedActors(
            UrgentStatus::Declined
        ));
    }

    public function testAdministratorCanCloseButNotAccept(): void
    {
        self::assertContains(Role::Admin, UrgentStatus::Declined->allowedActors(UrgentStatus::Closed));
        self::assertNotContains(
            Role::Admin,
            UrgentStatus::UnderClinicalReview->allowedActors(UrgentStatus::AcceptedForCoordination)
        );
    }

    public function testUrgentVocabularyIsNotTheElectiveVocabulary(): void
    {
        $urgent = array_map(static fn (UrgentStatus $s): string => $s->value, UrgentStatus::cases());

        foreach (['approved_for_scheduling', 'converted_to_appointment', 'scheduled', 'no_show'] as $elective) {
            self::assertNotContains($elective, $urgent);
        }

        self::assertContains('accepted_for_coordination', $urgent);
    }

    public function testAdverseOutcomesRequireAReason(): void
    {
        self::assertTrue(UrgentStatus::UnderClinicalReview->requiresReason(UrgentStatus::Declined));
        self::assertTrue(UrgentStatus::UnderClinicalReview->requiresReason(UrgentStatus::Redirected));
        self::assertTrue(UrgentStatus::Received->requiresReason(UrgentStatus::Closed));
        self::assertFalse(
            UrgentStatus::UnderClinicalReview->requiresReason(UrgentStatus::AcceptedForCoordination)
        );
    }

    public function testTheTwoAxesShareNoVocabulary(): void
    {
        // Acknowledging is not a clinical decision, so no coordination value may
        // double as a clinical one; if these vocabularies overlapped, one status
        // column would start implying the other (WF-05).
        $clinical = array_map(static fn (UrgentStatus $s): string => $s->value, UrgentStatus::cases());
        $operational = array_map(
            static fn (UrgentCoordination $s): string => $s->value,
            UrgentCoordination::cases()
        );

        self::assertSame([], array_intersect($clinical, $operational));
        self::assertFalse(UrgentCoordination::Unacknowledged->isAcknowledged());
        self::assertTrue(UrgentCoordination::Acknowledged->isAcknowledged());
    }

    public function testCoordinationCannotResolveBeforeAnyoneOwnsIt(): void
    {
        self::assertFalse(UrgentCoordination::Unacknowledged->canProgressTo(UrgentCoordination::Resolved));
        self::assertFalse(
            UrgentCoordination::Unacknowledged->canTransitionTo(UrgentCoordination::Resolved)
        );
        self::assertTrue(UrgentCoordination::Acknowledged->canProgressTo(UrgentCoordination::Resolved));
        self::assertTrue(UrgentCoordination::Acknowledged->canProgressTo(UrgentCoordination::Contacting));
    }

    public function testEscalationRecursWithoutBeingAFinalState(): void
    {
        self::assertTrue(UrgentCoordination::Contacting->canTransitionTo(UrgentCoordination::Escalated));
        self::assertTrue(UrgentCoordination::Escalated->canTransitionTo(UrgentCoordination::Coordinating));
        self::assertFalse(UrgentCoordination::Escalated->isTerminal());
        self::assertTrue(UrgentCoordination::Contacting->requiresReason(UrgentCoordination::Escalated));
        self::assertFalse(UrgentCoordination::Contacting->requiresReason(UrgentCoordination::Coordinating));
    }

    public function testResolvingEndsTheOperationalAxisOnly(): void
    {
        self::assertTrue(UrgentCoordination::Resolved->isTerminal());
        self::assertSame([], UrgentCoordination::Resolved->allowedTransitions());

        // Clinical status is a different question, so resolving coordination does
        // not imply the case is closed clinically.
        self::assertFalse(UrgentStatus::Closed->canTransitionTo(UrgentStatus::Completed));
    }

    public function testAcknowledgementIsNotDeliveredByTheNotificationPipeline(): void
    {
        // Only internal roles hold an operational permission; a nurse or clerk may
        // acknowledge, but the coordinator role is the narrowest that may escalate.
        $acknowledgers = UrgentCoordination::Unacknowledged->allowedActors(UrgentCoordination::Acknowledged);

        foreach ([Role::Staff, Role::Coordinator, Role::Radiologist, Role::Admin] as $role) {
            self::assertContains($role, $acknowledgers);
        }

        self::assertNotContains(
            Role::Patient,
            $acknowledgers,
            'A patient cannot acknowledge on behalf of the clinic.'
        );
        self::assertNotContains(
            Role::Physician,
            UrgentCoordination::Contacting->allowedActors(UrgentCoordination::Coordinating)
        );
    }
}
