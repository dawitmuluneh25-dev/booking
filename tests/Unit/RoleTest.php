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

    public function testReferrersAndPatientsMayOnlySubmitReferrals(): void
    {
        foreach ([Role::Physician, Role::Patient] as $role) {
            self::assertTrue($role->can(Permission::ReferralSubmit));
            self::assertFalse($role->can(Permission::ReferralTriage));
            self::assertFalse($role->can(Permission::ClinicalApprove));
            self::assertFalse($role->can(Permission::ScheduleManage));
            self::assertFalse($role->isClinical());
        }
    }

    public function testEveryRoleHasALabelAndAtLeastOnePermission(): void
    {
        foreach (Role::cases() as $role) {
            self::assertNotSame('', $role->label(), $role->value);
            self::assertNotSame([], $role->permissions(), $role->value);
        }
    }
}
