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

    /** Signed in, whatever the role. */
    public function authenticate(): User
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw HttpException::forbidden('Sign in to continue.');
        }

        return $user;
    }

    /**
     * Object-level check.
     *
     * A refused object answers 404, never 403 (SEC 8.4): telling a referrer that a
     * referral exists but is not theirs is itself a disclosure, and on this system
     * that disclosure is about a patient.
     */
    public function authorizeObject(bool $allowed): void
    {
        $this->authenticate();

        if (!$allowed) {
            // The generic message is the point: naming the object, or saying it is
            // forbidden, would confirm that it exists.
            throw HttpException::notFound();
        }
    }
}
