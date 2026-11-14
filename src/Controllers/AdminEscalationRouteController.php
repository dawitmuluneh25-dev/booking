<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\EscalationReadiness;
use App\Domain\EscalationRoute;
use App\Domain\EscalationRouteKind;
use App\Domain\EscalationRouteNotFoundException;
use App\Domain\EscalationRouteRepository;
use App\Domain\Permission;
use App\Domain\RecordEscalationRoute;
use App\Domain\RetireEscalationRoute;
use App\Domain\User;
use App\Domain\UserRepository;
use App\Domain\ValidationException;
use App\Domain\VerifyEscalationRoute;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

/**
 * The escalation roster (Decision 4).
 *
 * Operational configuration, not clinical authority: the routes say who to
 * telephone, and the permission is the one that already governs hours and
 * destinations. Nothing here decides anything about a patient.
 *
 * The screen shows the activation state first, because the person maintaining the
 * roster needs to know whether the thing they are configuring is currently usable.
 * A roster that looks complete while escalation stays disabled is the failure this
 * page exists to prevent.
 */
final class AdminEscalationRouteController
{
    private const BASE = '/admin/escalation-routes';

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly EscalationRouteRepository $routes,
        private readonly EscalationReadiness $readiness,
        private readonly UserRepository $users,
        private readonly RecordEscalationRoute $record,
        private readonly VerifyEscalationRoute $verify,
        private readonly RetireEscalationRoute $retire,
        private readonly string $timezone,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::BASE));
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        return $this->render([], 200);
    }

    public function store(Request $request): Response
    {
        $user = $this->authorise($request);

        try {
            $route = $this->record->execute(new Actor($user->id, $user->role), [
                'kind' => (string) $request->input('kind', ''),
                'label' => (string) $request->input('label', ''),
                'contact_name' => (string) $request->input('contact_name', ''),
                'contact_phone' => (string) $request->input('contact_phone', ''),
                'is_staffed' => (string) $request->input('is_staffed', '1'),
            ]);
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422);
        } catch (PDOException) {
            return $this->render([
                'label' => 'A route of this kind already uses that label. Give this one its own name '
                    . 'so the roster stays readable.',
            ], 422);
        }

        Session::flash(
            'notice',
            'Recorded ' . $route->label . '. It counts toward escalation only once somebody confirms '
            . 'that it is answered.',
        );

        return Response::redirect(self::BASE, 303);
    }

    /** @param array<string, string> $params */
    public function verify(Request $request, array $params): Response
    {
        $user = $this->authorise($request);

        try {
            $this->verify->execute(
                new Actor($user->id, $user->role),
                (int) ($params['id'] ?? 0),
                (string) $request->input('verification_note', ''),
            );
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422);
        } catch (EscalationRouteNotFoundException $exception) {
            throw HttpException::notFound($exception->getMessage());
        }

        Session::flash('notice', 'The route is verified and now counts toward escalation.');

        return Response::redirect(self::BASE, 303);
    }

    /** @param array<string, string> $params */
    public function retire(Request $request, array $params): Response
    {
        $user = $this->authorise($request);

        try {
            $route = $this->retire->execute(
                new Actor($user->id, $user->role),
                (int) ($params['id'] ?? 0),
            );
        } catch (EscalationRouteNotFoundException $exception) {
            throw HttpException::notFound($exception->getMessage());
        }

        Session::flash('notice', 'Retired ' . $route->label . '. The row is kept for the record.');

        return Response::redirect(self::BASE, 303);
    }

    private function authorise(Request $request): User
    {
        if (!$this->auth->check()) {
            throw HttpException::forbidden('Sign in to continue.');
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        return $this->gate->authenticate();
    }

    /** @param array<string, string> $errors */
    private function render(array $errors, int $status): Response
    {
        $names = [];

        foreach ($this->users->all() as $account) {
            $names[$account->id] = $account->name;
        }

        $routes = [];

        foreach ($this->routes->all() as $route) {
            $routes[] = [
                'route' => $route,
                'verifiedBy' => $route->verifiedByUserId === null
                    ? null
                    : ($names[$route->verifiedByUserId] ?? 'Unknown account'),
                'verifiedAt' => $route->verifiedAtUtc === null
                    ? null
                    : $this->localTime($route->verifiedAtUtc),
            ];
        }

        return Response::html($this->view->render('admin/escalation-routes', [
            'title' => 'Escalation routes',
            'routes' => $routes,
            'readiness' => $this->readiness->assess(),
            'kinds' => EscalationRouteKind::cases(),
            'errors' => $errors,
            'notice' => Session::pullFlash('notice'),
            'currentUser' => $this->gate->authenticate(),
        ]), $status);
    }

    private function localTime(string $utc): string
    {
        $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

        if ($at === false) {
            return $utc;
        }

        return $at->setTimezone(new DateTimeZone($this->timezone))->format('j M Y, H:i');
    }
}
