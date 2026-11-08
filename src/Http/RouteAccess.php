<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Permission;

/**
 * What a route demands before its handler runs.
 *
 * Every route must declare one of these. There is no default: a route added
 * without an access declaration fails at boot rather than becoming silently
 * public, which is the failure mode this class exists to prevent (SEC 8.2).
 *
 * `public()` is a decision, not an omission, and the route manifest test lists
 * every public route so the set can be reviewed as a whole.
 */
final class RouteAccess
{
    private function __construct(
        public readonly bool $isPublic,
        public readonly bool $needsAuthentication,
        public readonly ?Permission $permission,
        public readonly string $reason,
    ) {
    }

    /** Reachable without a session. The reason is shown in the route manifest. */
    public static function public(string $reason): self
    {
        return new self(true, false, null, $reason);
    }

    /** Any signed-in user, whatever their role. */
    public static function authenticated(): self
    {
        return new self(false, true, null, 'any signed-in user');
    }

    public static function permission(Permission $permission): self
    {
        return new self(false, true, $permission, 'requires ' . $permission->value);
    }

    public function describe(): string
    {
        return $this->reason;
    }
}
