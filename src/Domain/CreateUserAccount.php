<?php

declare(strict_types=1);

namespace App\Domain;

use App\Security\PasswordHasher;

/**
 * Provision a staff account.
 *
 * There is no self-service registration anywhere in this system (SEC 2.2): an
 * administrator creates the account, the temporary password is shown once and
 * never stored in the clear, and the person is expected to reset it.
 *
 * The role comes from the command's argument, not from a posted field, so a form
 * cannot choose its own privilege. Creating an account is also the only way a
 * `Role` enters the system.
 */
final class CreateUserAccount
{
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /**
     * @param array<string, mixed> $input email, name, role
     *
     * @return array{user: User, temporary_password: string}
     *
     * @throws AuthorisationException the actor may not manage users
     * @throws ValidationException    the details are incomplete or the email is taken
     */
    public function execute(Actor $actor, array $input): array
    {
        if (!$actor->can(Permission::UsersManage)) {
            throw new AuthorisationException($actor->role, Permission::UsersManage);
        }

        $email = mb_strtolower(trim($this->text($input, 'email')));
        $name = trim($this->text($input, 'name'));
        $roleValue = trim($this->text($input, 'role'));
        $role = Role::tryFrom($roleValue);

        $errors = [];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif ($this->users->findCredentialsByEmail($email) !== null) {
            $errors['email'] = 'An account already uses that email address.';
        }

        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Enter the person\'s full name.';
        }

        if ($role === null) {
            $errors['role'] = 'Choose a role: '
                . implode(', ', array_map(static fn (Role $case): string => $case->value, Role::cases()))
                . '.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        \assert($role instanceof Role);

        $password = $this->temporaryPassword();
        $id = $this->users->create($email, $name, $role, $this->hasher->hash($password));

        $user = $this->users->findById($id);

        if ($user === null) {
            throw new UserNotFoundException($id);
        }

        return ['user' => $user, 'temporary_password' => $password];
    }

    /** Long enough for the policy, and readable when dictated over the phone. */
    private function temporaryPassword(): string
    {
        $password = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < PasswordHasher::MIN_LENGTH + 4; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
