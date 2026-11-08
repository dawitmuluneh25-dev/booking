<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Named capabilities. Route handlers ask for a permission, never for a role,
 * so adding a role does not require touching controllers.
 */
enum Permission: string
{
    case BookingsManage = 'bookings.manage';
    case UsersManage = 'users.manage';
}
