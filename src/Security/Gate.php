<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\Permission;
use App\Domain\User;
use App\Http\HttpException;

/**
 * Authorisation checks. Controllers ask for a permission; roles are an
 * implementation detail of Role.
 */
final class Gate
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function user(): ?User
    {
        return $this->auth->user();
    }

    public function allows(Permission $permission): bool
    {
        return $this->auth->user()?->can($permission) ?? false;
    }

    public function authorize(Permission $permission): void
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw HttpException::forbidden('Sign in to continue.');
        }

        if (!$user->can($permission)) {
            throw HttpException::forbidden('Your role cannot perform this action.');
        }
    }
}
