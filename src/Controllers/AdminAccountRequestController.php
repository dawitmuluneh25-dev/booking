<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AccountRequest;
use App\Domain\AccountRequestNotFoundException;
use App\Domain\AccountRequestRepository;
use App\Domain\ApproveAccountRequest;
use App\Domain\AuthorisationException;
use App\Domain\Actor;
use App\Domain\Permission;
use App\Domain\RejectAccountRequest;
use App\Domain\User;
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
 * The queue behind the public form.
 *
 * Reading the queue and deciding on it are different permissions, on the owner's
 * instruction (answer Q2, 18 Nov 2026): reception may triage — see who is waiting and
 * flag a duplicate — without holding the right to create accounts, which is what
 * approving does.
 *
 * A temporary password is shown exactly once, on the response to the approval that
 * created it. It is not flashed into the session and not written to a log: a value
 * that appears in a second response is a value that outlives the page.
 */
final class AdminAccountRequestController
{
    public function __construct(
        private readonly View $view,
        private readonly AccountRequestRepository $requests,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly ApproveAccountRequest $approve,
        private readonly RejectAccountRequest $reject,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/account-requests'));
        }

        $this->gate->authorize(Permission::AccountRequestTriage);

        return $this->render([], 200, null);
    }

    /** @param array<string, string> $params */
    public function approve(Request $request, array $params): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/account-requests'));
        }

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $id = (int) ($params['id'] ?? '0');
        $reason = trim((string) $request->input('reason', ''));

        try {
            $result = $this->approve->execute($this->actor(), $id, $reason);
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422, null);
        } catch (AuthorisationException) {
            throw new HttpException(403);
        } catch (AccountRequestNotFoundException) {
            throw new HttpException(404);
        }

        return $this->render([], 200, [
            'user' => $result['user'],
            'temporaryPassword' => $result['temporary_password'],
            'request' => $result['request'],
        ]);
    }

    /** @param array<string, string> $params */
    public function reject(Request $request, array $params): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/account-requests'));
        }

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $id = (int) ($params['id'] ?? '0');
        $reason = (string) $request->input('reason', '');

        try {
            $request_ = $this->reject->execute($this->actor(), $id, $reason);
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422, null);
        } catch (AuthorisationException) {
            throw new HttpException(403);
        } catch (AccountRequestNotFoundException) {
            throw new HttpException(404);
        }

        Session::flash('notice', 'Request ' . $request_->reference . ' was refused.');

        return Response::redirect('/admin/account-requests', 303);
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
     * @param array{user: User, temporaryPassword: string, request: AccountRequest}|null $created
     */
    private function render(array $errors, int $status, ?array $created): Response
    {
        try {
            $pending = $this->requests->pending();
            $decided = $this->requests->recentlyDecided();
            $unavailable = false;
        } catch (Throwable) {
            $pending = [];
            $decided = [];
            $unavailable = true;
        }

        $user = $this->auth->user();

        return Response::html($this->view->render('admin/account-requests', [
            'title' => 'Referring account requests',
            'pending' => $pending,
            'decided' => $decided,
            'unavailable' => $unavailable,
            'errors' => $errors,
            'created' => $created,
            // Deciding creates an account. Showing the forms to somebody who cannot
            // use them invites a 403 that looks like a bug.
            'mayDecide' => $user !== null && $user->can(Permission::UsersManage),
            'notice' => Session::pullFlash('notice'),
            'currentUser' => $user,
        ]), $status);
    }
}
