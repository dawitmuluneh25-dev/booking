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

    public function testReferrersMayOnlySubmitReferrals(): void
    {
        $role = Role::Physician;

        self::assertTrue($role->can(Permission::ReferralSubmit));
        self::assertFalse($role->can(Permission::ReferralTriage));
        self::assertFalse($role->can(Permission::ClinicalApprove));
        self::assertFalse($role->can(Permission::ScheduleManage));
        self::assertFalse($role->isClinical());
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
