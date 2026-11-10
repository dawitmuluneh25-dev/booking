<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\AuthorisationException;
use App\Domain\Permission;
use App\Domain\User;
use App\Domain\PhysicianProfileRepository;
use App\Domain\PhysicianReviewException;
use App\Domain\PhysicianStatus;
use App\Domain\ValidationException;
use App\Domain\VerifyPhysician;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;

/**
 * The verification queue.
 *
 * Verification is the one act that decides whether a clinician's referrals are
 * accepted at all, so the screen has to show what the reviewer is judging on —
 * practice number, institution, specialty, when it was submitted — rather than a
 * name and two buttons.
 *
 * Every rule stays in `VerifyPhysician`: this class authenticates, filters, and
 * turns a refusal into a sentence. It never writes a status.
 */
final class AdminPhysicianController
{
    /** @var array<string, PhysicianStatus|null> */
    private const FILTERS = [
        'pending' => PhysicianStatus::Pending,
        'verified' => PhysicianStatus::Verified,
        'suspended' => PhysicianStatus::Suspended,
        'rejected' => PhysicianStatus::Rejected,
        'all' => null,
    ];

    public function __construct(
        private readonly View $view,
        private readonly Gate $gate,
        private readonly PhysicianProfileRepository $profiles,
        private readonly VerifyPhysician $verify,
        private readonly string $timezone,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->gate->authorize(Permission::UsersManage);
        $user = $this->gate->authenticate();
        $filter = (string) $request->input('status', 'pending');
        $filter = array_key_exists($filter, self::FILTERS) ? $filter : 'pending';

        return $this->render($user, $filter, [], 200);
    }

    /** @param array<string, string> $params */
    public function verify(Request $request, array $params): Response
    {
        return $this->decide($request, $params, PhysicianStatus::Verified);
    }

    /** @param array<string, string> $params */
    public function suspend(Request $request, array $params): Response
    {
        return $this->decide($request, $params, PhysicianStatus::Suspended);
    }

    /**
     * Decline a profile whose credentials do not check out.
     *
     * Not in the plan's two verbs, added because PhysicianStatus::Rejected and the
     * queue's Rejected filter would otherwise be states nothing can reach — and a
     * filter that can never fill is a promise the screen cannot keep.
     */
    /** @param array<string, string> $params */
    public function reject(Request $request, array $params): Response
    {
        return $this->decide($request, $params, PhysicianStatus::Rejected);
    }

    /** @param array<string, string> $params */
    private function decide(Request $request, array $params, PhysicianStatus $decision): Response
    {
        $this->gate->authorize(Permission::UsersManage);
        $user = $this->gate->authenticate();
        $this->assertCsrf($request);

        $id = (int) ($params['id'] ?? 0);
        $note = trim((string) $request->input('note', ''));

        try {
            $this->verify->execute(new Actor($user->id, $user->role), $id, $decision, $note === '' ? null : $note);
        } catch (ValidationException $exception) {
            return $this->render($user, 'pending', $exception->errors, 422);
        } catch (AuthorisationException $exception) {
            // Covers both "you may not verify" and "you may not verify yourself".
            // The second is the one worth a sentence rather than a bare 403.
            throw HttpException::forbidden($exception->getMessage());
        } catch (PhysicianReviewException $exception) {
            throw HttpException::notFound($exception->getMessage());
        }

        [$notice, $filter] = match ($decision) {
            PhysicianStatus::Verified => ['The physician can now submit referrals.', 'verified'],
            PhysicianStatus::Suspended => ['The physician can no longer submit referrals.', 'suspended'],
            default => ['The profile was rejected. The account can still sign in.', 'rejected'],
        };

        Session::flash('notice', $notice);

        return Response::redirect('/admin/physicians?status=' . $filter, 303);
    }

    /**
     * @param array<string, string> $errors
     */
    private function render(User $user, string $filter, array $errors, int $status): Response
    {
        $entries = $this->profiles->queued(self::FILTERS[$filter]);

        return Response::html($this->view->render('admin/physicians', [
            'title' => 'Referring physicians',
            'entries' => $entries,
            'filter' => $filter,
            'errors' => $errors,
            'notice' => Session::pullFlash('notice'),
            'timezone' => $this->timezone,
            'currentUser' => $user,
        ]), $status);
    }

    private function assertCsrf(Request $request): void
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }
    }
}
