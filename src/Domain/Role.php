<?php

declare(strict_types=1);

namespace App\Domain;

enum Role: string
{
    case Admin = 'admin';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Staff => 'Staff',
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => [
                Permission::BookingsManage,
                Permission::UsersManage,
                Permission::ClinicConfigure,
                Permission::SystemMaintain,
            ],
            self::Staff => [Permission::BookingsManage],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
