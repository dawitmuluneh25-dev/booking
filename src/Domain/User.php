<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * An authenticated staff account. The password hash never leaves the
 * repository, so it cannot leak into a template, log or session dump.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public readonly Role $role,
        public readonly bool $active,
        public readonly ?string $lastLoginAt = null,
        public readonly int $authVersion = 1,
    ) {
    }

    public function can(Permission $permission): bool
    {
        return $this->role->can($permission);
    }
}
