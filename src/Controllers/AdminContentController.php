<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\ContentNotFoundException;
use App\Domain\ContentRepository;
use App\Domain\ContentStatus;
use App\Domain\Permission;
use App\Domain\PublishedContent;
use App\Domain\PublishContent;
use App\Domain\ValidationException;
use App\Domain\WithdrawContent;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;

/**
 * Where patient-facing content is published and withdrawn (§17.5).
 *
 * The screen exists to answer one question per row: why is this not showing? A
 * draft with no reviewer, a version whose review date has passed and a withdrawn
 * page all look identical from the outside — they are all absent — so the admin
 * screen is where the three are told apart.
 *
 * It cannot fill in the missing fields. That is deliberate: the reviewer, the
 * effective date and the review date are inputs from outside the software, and a
 * form that could type them would let an administrator approve clinical copy by
 * pressing a button. The screen reports what is missing; somebody has to supply it.
 */
final class AdminContentController
{
    private const NEXT = '/admin/content';

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly ContentRepository $content,
        private readonly PublishContent $publish,
        private readonly WithdrawContent $withdraw,
        private readonly ?string $today = null,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::NEXT));
        }

        $this->gate->authorize(Permission::ContentPublish);

        return $this->render([], 200, null);
    }

    /** @param array<string, string> $params */
    public function publish(Request $request, array $params): Response
    {
        return $this->act($request, $params, true);
    }

    /** @param array<string, string> $params */
    public function withdraw(Request $request, array $params): Response
    {
        return $this->act($request, $params, false);
    }

    /** @param array<string, string> $params */
    private function act(Request $request, array $params, bool $publishing): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::NEXT));
        }

        $this->gate->authorize(Permission::ContentPublish);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $user = $this->gate->authenticate();
        $actor = new Actor($user->id, $user->role);
        $id = (int) ($params['id'] ?? 0);

        try {
            $record = $publishing
                ? $this->publish->execute($actor, $id)
                : $this->withdraw->execute($actor, $id);

            Session::flash('notice', $record->contentKey . ' ' . $record->version . ' is now '
                . strtolower($record->status->label()) . '.');
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors));
        } catch (ContentNotFoundException) {
            throw new HttpException(404);
        }

        return Response::redirect(self::NEXT, 303);
    }

    /** @param array<string, string> $errors */
    private function render(array $errors, int $status, ?string $notice): Response
    {
        $today = $this->today ?? gmdate('Y-m-d');
        $records = $this->content->all();

        $nowShowing = 0;

        foreach ($records as $record) {
            if ($record->isServableOn($today)) {
                $nowShowing++;
            }
        }

        return Response::html($this->view->render('admin/content', [
            'title' => 'Patient content',
            'records' => $records,
            'today' => $today,
            'nowShowing' => $nowShowing,
            'notice' => $notice ?? Session::pullFlash('notice'),
            'error' => Session::pullFlash('error'),
            'errors' => $errors,
            'currentUser' => $this->gate->authenticate(),
            'canPublish' => $this->gate->allows(Permission::ContentPublish),
            'statusLabel' => static fn (PublishedContent $record): string => $record->status->label(),
            'isExpired' => static fn (PublishedContent $record): bool => $record->status === ContentStatus::Published
                && $record->isExpiredOn($today),
        ]), $status);
    }
}
