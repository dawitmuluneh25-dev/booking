<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Actor;
use App\Domain\ApproveForScheduling;
use App\Domain\AuthorisationException;
use App\Domain\ClinicalDecision;
use App\Domain\ClinicalDecisionType;
use App\Domain\Referral;
use App\Domain\ReferralStatus;
use App\Domain\ReferralTransitionException;
use App\Domain\Role;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryReferralRepository;

final class ApproveForSchedulingTest extends TestCase
{
    private const NOW = '2026-11-08 12:00:00';

    private InMemoryReferralRepository $repository;

    private ApproveForScheduling $command;

    protected function setUp(): void
    {
        $this->repository = new InMemoryReferralRepository();
        $this->command = new ApproveForScheduling($this->repository);
    }

    public function testAReferralWithAStandingApprovalIsReleasedForScheduling(): void
    {
        $referral = $this->approvedReferral();

        $updated = $this->command->execute(new Actor(4, Role::Staff), $referral->id, self::NOW);

        self::assertSame(ReferralStatus::ApprovedForScheduling, $updated->status);
    }

    public function testAReferralWithNoDecisionAtAllIsRefused(): void
    {
        // The status column says approved; nothing in the record says a clinician
        // approved it. The command trusts the record, not the column (WF-02).
        $referral = $this->referralAt(ReferralStatus::ClinicallyApproved);

        $this->expectException(ReferralTransitionException::class);
        $this->expectExceptionMessageMatches('/standing clinical approval/i');

        $this->command->execute(new Actor(4, Role::Staff), $referral->id, self::NOW);
    }

    public function testAReferralWhoseStandingDecisionIsADeferralIsRefused(): void
    {
        $referral = $this->referralAt(ReferralStatus::ClinicallyApproved);

        $this->repository->recordDecision($this->decision($referral->id, ClinicalDecisionType::Deferred));

        $this->expectException(ReferralTransitionException::class);
        $this->command->execute(new Actor(4, Role::Staff), $referral->id, self::NOW);
    }

    public function testASupersededApprovalCannotBeReleased(): void
    {
        // The state machine does not produce this shape — an approval leaves
        // clinical review — so this is the guard against a record that arrived
        // another way: an import, a manual fix, a migration. The standing decision
        // is the deferral, and the approval that it replaced releases nothing.
        $referral = $this->referralAt(ReferralStatus::ClinicallyApproved);

        $approval = $this->repository->recordDecision(
            $this->decision($referral->id, ClinicalDecisionType::Approved),
        );
        $this->repository->recordDecision(
            $this->decision($referral->id, ClinicalDecisionType::Deferred, $approval->id),
        );

        $this->expectException(ReferralTransitionException::class);
        $this->expectExceptionMessageMatches('/standing clinical approval/i');

        $this->command->execute(new Actor(4, Role::Staff), $referral->id, self::NOW);
    }

    public function testItDoesNotActOnAReferralThatIsNotClinicallyApproved(): void
    {
        $referral = $this->referralAt(ReferralStatus::ClinicalReview);
        $this->repository->recordDecision($this->decision($referral->id, ClinicalDecisionType::Approved));

        $this->expectException(ReferralTransitionException::class);
        $this->command->execute(new Actor(4, Role::Staff), $referral->id, self::NOW);
    }

    public function testAPatientCannotReleaseTheirOwnReferral(): void
    {
        $referral = $this->approvedReferral();

        $this->expectException(AuthorisationException::class);
        $this->command->execute(new Actor(7, Role::Patient), $referral->id, self::NOW);
    }

    public function testTheCoordinatorCannotReleaseForScheduling(): void
    {
        // The coordinator books the session once it is released; deciding that a
        // referral is ready to be booked is reception's call.
        $referral = $this->approvedReferral();

        $this->expectException(ReferralTransitionException::class);
        $this->command->execute(new Actor(5, Role::Coordinator), $referral->id, self::NOW);
    }

    public function testTheReferrerCannotReleaseIt(): void
    {
        $referral = $this->approvedReferral();

        $this->expectException(AuthorisationException::class);
        $this->command->execute(new Actor(3, Role::Physician), $referral->id, self::NOW);
    }

    private function approvedReferral(): Referral
    {
        $referral = $this->referralAt(ReferralStatus::ClinicallyApproved);
        $this->repository->recordDecision($this->decision($referral->id, ClinicalDecisionType::Approved));

        return $referral;
    }

    private function referralAt(ReferralStatus $status): Referral
    {
        $referral = new Referral(
            id: 0,
            reference: 'RF-SCHED-' . $status->value,
            status: $status,
            version: 1,
            patientName: 'Selamawit Girma',
            patientPhone: '+251911000333',
            patientDob: '1991-04-12',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'image_guided_biopsy',
            requestedUrgency: 'routine',
            clinicalSummary: 'Six weeks of right upper quadrant pain.',
            targetSite: 'Liver, segment VI',
            referringPhysicianName: 'Dr Bekele',
            referringFacility: 'St Paul Referral',
            referringPhysicianPhone: null,
            submittedByUserId: 3,
            submittedOnBehalfOf: 'referrer',
            createdAtUtc: '2026-11-07 08:00:00',
        );

        return $this->repository->create($referral, new Actor(3, Role::Physician), '2026-11-07 08:00:00');
    }

    private function decision(
        int $referralId,
        ClinicalDecisionType $type,
        ?int $supersedesId = null,
    ): ClinicalDecision {
        return new ClinicalDecision(
            id: 0,
            referralId: $referralId,
            type: $type,
            rationale: 'Reviewed the imaging and the referral letter in full before deciding.',
            technique: null,
            modality: null,
            sedation: null,
            estimatedDurationMinutes: null,
            preprocedureConditions: null,
            redirectDestination: null,
            informationRequested: null,
            decidedByUserId: 2,
            decidedAtUtc: '2026-11-08 09:00:00',
            supersedesId: $supersedesId,
        );
    }
}
