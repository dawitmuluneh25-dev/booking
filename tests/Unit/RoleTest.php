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
}
