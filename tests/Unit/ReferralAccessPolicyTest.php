<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\PhysicianProfile;
use App\Domain\PhysicianStatus;
use App\Domain\Referral;
use App\Domain\ReferralAccessPolicy;
use App\Domain\ReferralStatus;
use App\Domain\Role;
use App\Domain\User;
use PHPUnit\Framework\TestCase;

/**
 * AUTH-02 and AUTH-03: a referrer sees their own referrals, if verified, and
 * nothing else. Every other role is a different answer, so each is asserted
 * separately rather than assumed from the happy path.
 */
final class ReferralAccessPolicyTest extends TestCase
{
    private ReferralAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ReferralAccessPolicy();
    }

    public function testAVerifiedPhysicianSeesTheirOwnReferral(): void
    {
        $profile = $this->profile(1, 11, PhysicianStatus::Verified);

        self::assertTrue($this->policy->canView($this->user(11, Role::Physician), $this->referral(1), $profile));
    }

    public function testTheSameInstitutionIsNotTheSameAuthorization(): void
    {
        // Two doctors at the same hospital. Seeing a colleague's referral would mean
        // seeing another clinician's patient without being asked to.
        $mine = $this->profile(1, 11, PhysicianStatus::Verified);
        $theirs = $this->profile(2, 12, PhysicianStatus::Verified);

        self::assertFalse($this->policy->canView($this->user(12, Role::Physician), $this->referral(1), $theirs));
        self::assertTrue($this->policy->canView($this->user(11, Role::Physician), $this->referral(1), $mine));
    }

    public function testAnUnverifiedPhysicianSeesNothing(): void
    {
        $pending = $this->profile(1, 11, PhysicianStatus::Pending);

        self::assertFalse($this->policy->canView($this->user(11, Role::Physician), $this->referral(1), $pending));
        self::assertFalse($this->policy->maySubmitAsPhysician($pending));
    }

    public function testASuspendedPhysicianSeesNothingEvenOnTheirOwnReferral(): void
    {
        $suspended = $this->profile(1, 11, PhysicianStatus::Suspended);

        self::assertFalse($this->policy->canView($this->user(11, Role::Physician), $this->referral(1), $suspended));
    }

    public function testAPhysicianWithNoProfileSeesNothing(): void
    {
        self::assertFalse($this->policy->canView($this->user(11, Role::Physician), $this->referral(1)));
    }

    public function testReceptionAndClinicalStaffSeeEveryReferral(): void
    {
        $referral = $this->referral(1);

        foreach ([Role::Staff, Role::Admin, Role::Radiologist, Role::Coordinator] as $role) {
            self::assertTrue(
                $this->policy->canView($this->user(4, $role), $referral),
                $role->value . ' should see referrals',
            );
        }
    }

    public function testAPatientSeesOnlyWhatTheySubmitted(): void
    {
        $referral = $this->referral(1);

        $owner = $this->user(21, Role::Patient, $referral->submittedByUserId);

        self::assertTrue($this->policy->canView($owner, $referral));
        self::assertFalse($this->policy->canView($this->user(22, Role::Patient), $referral));
    }

    public function testARecordedGuardianSeesWhatTheirPatientSubmitted(): void
    {
        $referral = $this->referral(1);

        self::assertTrue($this->policy->canView($this->user(21, Role::Patient), $referral, null, true));
    }

    private function user(int $id, Role $role, ?int $submittedBy = null): User
    {
        return new User(
            id: $submittedBy ?? $id,
            email: 'user' . $id . '@example.test',
            name: 'Test User',
            role: $role,
            active: true,
        );
    }

    private function profile(int $id, int $userId, PhysicianStatus $status): PhysicianProfile
    {
        return new PhysicianProfile(
            id: $id,
            userId: $userId,
            practiceNumber: 'ET-MD-' . $id,
            institution: 'Yekatit 12 Hospital',
            specialty: 'Internal medicine',
            status: $status,
            verifiedByUserId: null,
            verifiedAtUtc: null,
            reviewNote: null,
            createdAtUtc: '2026-11-08 09:00:00',
        );
    }

    private function referral(int $profileId): Referral
    {
        return new Referral(
            id: 1,
            reference: 'RF-261108-ABCDEF',
            status: ReferralStatus::Submitted,
            version: 1,
            patientName: 'Selamawit Girma',
            patientPhone: '+251911000333',
            patientDob: '1991-04-12',
            guardianName: null,
            guardianPhone: null,
            guardianRelationship: null,
            procedureCode: 'fluoroscopic_biopsy',
            requestedUrgency: 'routine',
            clinicalSummary: 'Six weeks of right upper quadrant pain.',
            targetSite: 'Liver, segment VI',
            referringPhysicianName: 'Dr Hana Bekele',
            referringFacility: 'Yekatit 12 Hospital',
            referringPhysicianPhone: null,
            submittedByUserId: 21,
            submittedOnBehalfOf: 'referrer',
            createdAtUtc: '2026-11-08 09:00:00',
            referringPhysicianProfileId: $profileId,
        );
    }
}
