<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ReferralStatus;
use App\Domain\Role;
use PHPUnit\Framework\TestCase;

/**
 * These tests exist to keep the workflow invariants enforceable after the fact.
 * Each one corresponds to a rule from the clinical workflow specification, so a
 * future change that quietly re-opens an administrative route to clinical
 * authority fails here rather than in production.
 */
final class ReferralStatusTest extends TestCase
{
    public function testADraftCanOnlyBeSubmittedOrCancelled(): void
    {
        self::assertSame(
            [ReferralStatus::Submitted, ReferralStatus::Cancelled],
            ReferralStatus::Draft->allowedTransitions()
        );
    }

    public function testADraftCannotSkipSubmissionAndReachClinicalReview(): void
    {
        // Otherwise saving a draft would be a way to place a case in the clinical
        // queue without ever submitting it.
        self::assertFalse(ReferralStatus::Draft->canTransitionTo(ReferralStatus::ClinicalReview));
        self::assertFalse(ReferralStatus::Draft->canTransitionTo(ReferralStatus::ApprovedForScheduling));
    }

    public function testTheSubmitterRolesMaySubmitADraft(): void
    {
        self::assertSame(
            [Role::Physician, Role::Staff],
            ReferralStatus::Draft->allowedActors(ReferralStatus::Submitted)
        );
    }

    public function testTerminalStatesHaveNoOutgoingTransitions(): void
    {
        foreach ([ReferralStatus::Declined, ReferralStatus::Cancelled, ReferralStatus::Closed] as $terminal) {
            self::assertTrue($terminal->isTerminal());
            self::assertSame([], $terminal->allowedTransitions(), $terminal->value);
        }
    }

    /** WF-02: clinical approval precedes elective scheduling. */
    public function testReferralCannotReachSchedulingWithoutClinicalApproval(): void
    {
        self::assertFalse(ReferralStatus::Submitted->canTransitionTo(ReferralStatus::ApprovedForScheduling));
        self::assertFalse(ReferralStatus::Received->canTransitionTo(ReferralStatus::ApprovedForScheduling));
        self::assertFalse(ReferralStatus::AdministrativeReview->canTransitionTo(
            ReferralStatus::ApprovedForScheduling,
        ));
        self::assertFalse(ReferralStatus::ClinicalReview->canTransitionTo(ReferralStatus::ApprovedForScheduling));

        self::assertTrue(ReferralStatus::ClinicalReview->canTransitionTo(ReferralStatus::ClinicallyApproved));
        self::assertTrue(ReferralStatus::ClinicallyApproved->canTransitionTo(
            ReferralStatus::ApprovedForScheduling,
        ));
    }

    /** WF-15: the referral lifecycle contains no appointment or encounter state. */
    public function testReferralVocabularyExcludesAppointmentStates(): void
    {
        $appointmentStates = [
            'scheduled', 'confirmed', 'rescheduled', 'checked_in', 'no_show', 'in_progress',
            'completed', 'preprocedure_verification', 'ready', 'patient_arrived', 'patient_notified',
            'followup_required',
        ];

        foreach (ReferralStatus::cases() as $case) {
            self::assertNotContains($case->value, $appointmentStates);
        }
    }

    /** WF-03 / SEC 10.1: only a radiologist holds clinical authority. */
    public function testOnlyRadiologistsMayRecordClinicalDecisions(): void
    {
        $clinicalTargets = [
            ReferralStatus::ClinicallyApproved,
            ReferralStatus::Deferred,
            ReferralStatus::Declined,
            ReferralStatus::AlternativePathway,
            ReferralStatus::AwaitingImaging,
        ];

        foreach ($clinicalTargets as $target) {
            self::assertSame(
                [Role::Radiologist],
                ReferralStatus::ClinicalReview->allowedActors($target),
                $target->value,
            );
        }
    }

    /** SEC 6.8: administrative privilege is not clinical privilege. */
    public function testAdministratorAndReceptionAreExcludedFromClinicalOutcomes(): void
    {
        $clinicalOutcomes = [
            ReferralStatus::ClinicallyApproved,
            ReferralStatus::Deferred,
            ReferralStatus::Declined,
            ReferralStatus::AlternativePathway,
            ReferralStatus::AwaitingImaging,
        ];

        foreach ($clinicalOutcomes as $outcome) {
            $actors = ReferralStatus::ClinicalReview->allowedActors($outcome);

            self::assertNotContains(Role::Admin, $actors, $outcome->value);
            self::assertNotContains(Role::Staff, $actors, $outcome->value);
            self::assertNotContains(Role::Coordinator, $actors, $outcome->value);
        }

        // Routing a referral *into* review is administrative work, not clinical.
        self::assertContains(
            Role::Admin,
            ReferralStatus::AdministrativeReview->allowedActors(ReferralStatus::ClinicalReview),
        );
    }

    /** Every advertised transition must be performable by somebody. */
    public function testEveryDeclaredTransitionNamesAtLeastOneActor(): void
    {
        foreach (ReferralStatus::cases() as $from) {
            foreach ($from->allowedTransitions() as $to) {
                self::assertNotSame(
                    [],
                    $from->allowedActors($to),
                    $from->value . ' → ' . $to->value,
                );
            }
        }
    }

    public function testUndeclaredTransitionHasNoActors(): void
    {
        self::assertSame([], ReferralStatus::Submitted->allowedActors(ReferralStatus::Closed));
        self::assertSame([], ReferralStatus::Closed->allowedActors(ReferralStatus::ClinicalReview));
    }

    /**
     * Cancellation is available while the referral is still being triaged, reviewed
     * or scheduled. It is deliberately unavailable once an appointment exists: at
     * that point cancellation is an appointment action, and cancelling the
     * appointment must not silently cancel the clinical request (WF-15).
     */
    public function testReferralCanBeCancelledUntilAnAppointmentExists(): void
    {
        $notYetBooked = [
            ReferralStatus::Submitted,
            ReferralStatus::Received,
            ReferralStatus::AdministrativeReview,
            ReferralStatus::AwaitingInformation,
            ReferralStatus::AwaitingImaging,
            ReferralStatus::ClinicalReview,
            ReferralStatus::ClinicallyApproved,
            ReferralStatus::ApprovedForScheduling,
            ReferralStatus::Deferred,
        ];

        foreach ($notYetBooked as $state) {
            self::assertTrue(
                $state->canTransitionTo(ReferralStatus::Cancelled),
                $state->value . ' should be cancellable',
            );
        }

        self::assertFalse(ReferralStatus::ConvertedToAppointment->canTransitionTo(ReferralStatus::Cancelled));
        self::assertFalse(ReferralStatus::AlternativePathway->canTransitionTo(ReferralStatus::Cancelled));
    }

    public function testPatientsCannotApproveTheirOwnReferral(): void
    {
        $actors = ReferralStatus::ClinicalReview->allowedActors(ReferralStatus::ClinicallyApproved);

        self::assertNotContains(Role::Patient, $actors);
        self::assertNotContains(Role::Physician, $actors);
        self::assertNotContains(Role::Coordinator, $actors);
    }

    public function testAdverseOutcomesRequireAWrittenReason(): void
    {
        self::assertTrue(ReferralStatus::ClinicalReview->requiresReason(ReferralStatus::Declined));
        self::assertTrue(ReferralStatus::ClinicalReview->requiresReason(ReferralStatus::Deferred));
        self::assertTrue(ReferralStatus::ClinicalReview->requiresReason(ReferralStatus::AlternativePathway));
        self::assertTrue(ReferralStatus::Submitted->requiresReason(ReferralStatus::Cancelled));

        self::assertFalse(ReferralStatus::ClinicalReview->requiresReason(ReferralStatus::ClinicallyApproved));
        self::assertFalse(ReferralStatus::Submitted->requiresReason(ReferralStatus::Received));
    }

    public function testExceptionCarriesTheAttemptedTransition(): void
    {
        $exception = new \App\Domain\ReferralTransitionException(
            ReferralStatus::Submitted,
            ReferralStatus::ClinicallyApproved,
        );

        self::assertSame(ReferralStatus::Submitted, $exception->from);
        self::assertSame(ReferralStatus::ClinicallyApproved, $exception->to);
        self::assertStringContainsString('submitted', $exception->getMessage());
        self::assertStringContainsString('clinically_approved', $exception->getMessage());
    }
}
