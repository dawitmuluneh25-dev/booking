<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\ChangeUserRole;
use App\Domain\CreateUserAccount;
use App\Domain\Permission;
use App\Domain\Role;
use App\Domain\User;
use App\Domain\UserNotFoundException;
use App\Domain\UserRepository;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;
use Throwable;

/**
 * Accounts: the list, the create form, and the role change.
 *
 * Both writes go through the existing commands rather than touching the table
 * here. `CreateUserAccount` is the only path by which a `Role` enters the system,
 * and `ChangeUserRole` bumps `auth_version` in the same statement that changes the
 * role — so a revoked administrator stops being an administrator on their next
 * request rather than at their next sign-in.
 */
final class AdminUserController
{
    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly CreateUserAccount $create,
        private readonly ChangeUserRole $changeRole,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/users'));
        }

        $this->gate->authorize(Permission::UsersManage);

        return $this->render([], 200, null);
    }

    public function store(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/users'));
        }

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $actor = $this->actor();

        $input = [
            'email' => (string) $request->input('email', ''),
            'name' => (string) $request->input('name', ''),
            'role' => (string) $request->input('role', ''),
        ];

        try {
            $created = $this->create->execute($actor, $input);
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422, null, $input);
        } catch (AuthorisationException) {
            throw new HttpException(403);
        }

        // Shown once, on this response, and never stored: a flash or a log line
        // would keep the temporary password alive after the page is closed.
        return $this->render([], 200, $created['temporary_password'], $input, $created['user']);
    }

    public function changeRole(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/users'));
        }

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $targetId = (int) $request->input('user_id', '0');
        $role = Role::tryFrom((string) $request->input('role', ''));

        if ($targetId <= 0 || $role === null) {
            return $this->render(['role' => 'Choose a person and a role.'], 422, null);
        }

        try {
            $updated = $this->changeRole->execute($this->actor(), $targetId, $role);
        } catch (UserNotFoundException) {
            throw new HttpException(404);
        } catch (AuthorisationException $exception) {
            // Changing your own role is refused by the command; the page says why
            // rather than showing a bare 403, because an administrator clicking
            // their own row deserves a sentence.
            return $this->render(['role' => $exception->getMessage()], 403, null);
        }

        Session::flash('notice', $updated->name . ' is now ' . $updated->role->label() . '.');
        Session::flash('notice_detail', 'Their existing sessions end on the next request.');

        return Response::redirect('/admin/users', 303);
    }

    private function actor(): Actor
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new HttpException(401);
        }

        return new Actor($user->id, $user->role);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $previous
     */
    private function render(
        array $errors,
        int $status,
        ?string $temporaryPassword,
        array $previous = [],
        ?User $created = null,
    ): Response {
        try {
            $users = $this->users->all();
            $unavailable = false;
        } catch (Throwable) {
            $users = [];
            $unavailable = true;
        }

        return Response::html($this->view->render('admin/users', [
            'title' => 'Users',
            'users' => $users,
            'unavailable' => $unavailable,
            'errors' => $errors,
            'previous' => $previous,
            'created' => $created,
            'temporaryPassword' => $temporaryPassword,
            'roles' => Role::cases(),
            'notice' => Session::pullFlash('notice'),
            'noticeDetail' => Session::pullFlash('notice_detail'),
            'currentUser' => $this->auth->user(),
        ]), $status);
    }
}
