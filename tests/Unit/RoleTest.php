<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Permission;
use App\Domain\Role;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function testAdminCanManageBookingsAndUsers(): void
    {
        self::assertTrue(Role::Admin->can(Permission::BookingsManage));
        self::assertTrue(Role::Admin->can(Permission::UsersManage));
    }

    public function testStaffCanManageBookingsButNotUsers(): void
    {
        self::assertTrue(Role::Staff->can(Permission::BookingsManage));
        self::assertFalse(Role::Staff->can(Permission::UsersManage));
    }

    public function testOnlyAdminCanConfigureTheClinicOrReadDiagnostics(): void
    {
        self::assertTrue(Role::Admin->can(Permission::ClinicConfigure));
        self::assertTrue(Role::Admin->can(Permission::SystemMaintain));
        self::assertFalse(Role::Staff->can(Permission::ClinicConfigure));
        self::assertFalse(Role::Staff->can(Permission::SystemMaintain));
    }

    /** SEC 2.5 / 6.8: an administrator does not hold clinical authority. */
    public function testAdministratorHasNoClinicalDecisionPermission(): void
    {
        self::assertFalse(Role::Admin->can(Permission::ClinicalReview));
        self::assertFalse(Role::Admin->can(Permission::ClinicalApprove));
        self::assertFalse(Role::Admin->isClinical());
    }

    public function testRadiologistHoldsClinicalAuthorityButNotUserAdministration(): void
    {
        self::assertTrue(Role::Radiologist->can(Permission::ClinicalReview));
        self::assertTrue(Role::Radiologist->can(Permission::ClinicalApprove));
        self::assertTrue(Role::Radiologist->isClinical());

        // Decision 6 / AC-009: the register that governs clinical approval is a
        // clinical act, so the clinical role holds it and the administrator does not.
        self::assertTrue(Role::Radiologist->can(Permission::ClinicalCredentialManage));
        self::assertFalse(Role::Admin->can(Permission::ClinicalCredentialManage));

        self::assertFalse(Role::Radiologist->can(Permission::UsersManage));
        self::assertFalse(Role::Radiologist->can(Permission::SystemMaintain));
    }

    public function testReceptionTriagesAndSchedulesWithoutClinicalAuthority(): void
    {
        self::assertTrue(Role::Staff->can(Permission::ReferralTriage));
        self::assertTrue(Role::Staff->can(Permission::ScheduleManage));
        self::assertFalse(Role::Staff->can(Permission::ClinicalReview));
        self::assertFalse(Role::Staff->can(Permission::ClinicalApprove));
    }

    /**
     * BL-E1 gives a referrer a page showing what became of the referrals their own
     * verified profile is named on. That is a second permission, not a wider one:
     * reading one's own correspondence still does not open the clinic's queue.
     */
    public function testReferrersSubmitAndReadTheirOwnButDoNotTriage(): void
    {
        $role = Role::Physician;

        self::assertTrue($role->can(Permission::ReferralSubmit));
        self::assertTrue($role->can(Permission::ReferralReadOwn));
        self::assertFalse($role->can(Permission::ReferralTriage));
        self::assertFalse($role->can(Permission::ClinicalApprove));
        self::assertFalse($role->can(Permission::ScheduleManage));
        self::assertFalse($role->isClinical());
    }

    /**
     * WF-05 wants whoever is at the desk to be able to claim an urgent case, and no
     * more than that. The two permissions split along the line the aggregate already
     * draws between acknowledgement and coordination.
     */
    public function testAcknowledgementAndCoordinationAreSeparateUrgentPermissions(): void
    {
        self::assertTrue(Role::Staff->can(Permission::UrgentAcknowledge));
        self::assertFalse(Role::Staff->can(Permission::UrgentCoordinate));

        self::assertTrue(Role::Coordinator->can(Permission::UrgentAcknowledge));
        self::assertTrue(Role::Coordinator->can(Permission::UrgentCoordinate));

        self::assertTrue(Role::Radiologist->can(Permission::UrgentCoordinate));

        // A referrer registers a case; they do not work the board.
        self::assertFalse(Role::Physician->can(Permission::UrgentAcknowledge));
        self::assertFalse(Role::Physician->can(Permission::UrgentCoordinate));
    }

    /**
     * `UrgentStatus::allowedActors(Closed)` names the administrator, so closure is
     * reachable for them. It grants no clinical decision: accepting or declining a
     * child's case stays with the radiologist.
     */
    public function testAnAdministratorMayCoordinateUrgentCasesWithoutClinicalAuthority(): void
    {
        self::assertTrue(Role::Admin->can(Permission::UrgentAcknowledge));
        self::assertTrue(Role::Admin->can(Permission::UrgentCoordinate));

        self::assertFalse(Role::Admin->can(Permission::ClinicalApprove));
        self::assertFalse(Role::Admin->isClinical());
    }

    public function testAPatientIsARecordAndHoldsNothing(): void
    {
        // ADR-016: the role exists so a referral and a guardian relationship have
        // somebody to point at. It is not an account anybody signs in to, and a grant
        // nothing can reach is a permission waiting to be trusted by a future screen.
        self::assertFalse(Role::Patient->canSignIn());
        self::assertSame([], Role::Patient->permissions());
    }

    public function testEverySigningInRoleHasALabelAndAStatedReason(): void
    {
        foreach (Role::cases() as $role) {
            self::assertNotSame('', $role->label(), $role->value);

            if ($role === Role::Patient) {
                continue;
            }

            self::assertNotSame([], $role->permissions(), $role->value);
        }
    }
}
