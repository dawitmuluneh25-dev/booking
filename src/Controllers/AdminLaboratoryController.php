<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AddLaboratoryDestination;
use App\Domain\LaboratoryDestination;
use App\Domain\LaboratoryDestinationRepository;
use App\Domain\Permission;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;
use PDOException;

/**
 * The laboratory catalogue a specimen can be dispatched to (§19.1).
 *
 * There is no field for a name. The approved decision named a serialised scheme —
 * Pathology Lab 1, 2, 3 — and left the number of laboratories unstated, so the page
 * can add the next one in the series and nothing else. A free-text name box would
 * turn a configuration fact into free text and let an existing specimen's
 * destination drift out of step with what the laboratory is called.
 *
 * Deactivating is offered instead of deleting for the same reason: a specimen sent
 * to a laboratory that has since closed still has to name where it went.
 */
final class AdminLaboratoryController
{
    private const NEXT = '/admin/laboratories';

    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly LaboratoryDestinationRepository $destinations,
        private readonly AddLaboratoryDestination $add,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::NEXT));
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        return $this->render([], 200, null);
    }

    public function store(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::NEXT));
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $user = $this->gate->authenticate();

        try {
            $destination = $this->add->execute(new \App\Domain\Actor($user->id, $user->role));
        } catch (ValidationException $exception) {
            return $this->render($exception->errors, 422, null);
        } catch (PDOException) {
            // Two administrators adding at the same moment both read the same
            // highest serial; the unique key refuses the second, and the honest
            // answer is to reload rather than to renumber silently.
            return $this->render([
                'serial_no' => 'Another administrator added a laboratory at the same time. '
                    . 'Reload the page and check the series before adding again.',
            ], 422, null);
        }

        Session::flash('notice', $destination->label() . ' was added to the catalogue.');

        return Response::redirect(self::NEXT, 303);
    }

    /** @param array<string, string> $params */
    public function toggle(Request $request, array $params): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode(self::NEXT));
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $destination = $this->destinations->findById((int) ($params['id'] ?? 0));

        if ($destination === null) {
            throw new HttpException(404);
        }

        $this->destinations->setActive(
            $destination->id,
            !$destination->isActive,
            gmdate('Y-m-d H:i:s'),
        );

        Session::flash(
            'notice',
            $destination->label() . ($destination->isActive ? ' was closed to new specimens.' : ' was reopened.'),
        );

        return Response::redirect(self::NEXT, 303);
    }

    /** @param array<string, string> $errors */
    private function render(array $errors, int $status, ?string $notice): Response
    {
        $destinations = $this->destinations->all();
        $active = array_values(array_filter(
            $destinations,
            static fn (LaboratoryDestination $destination): bool => $destination->isActive,
        ));

        return Response::html($this->view->render('admin/laboratories', [
            'title' => 'Laboratories',
            'destinations' => $destinations,
            'activeCount' => count($active),
            'nextLabel' => 'Pathology Lab ' . $this->destinations->nextSerial(),
            'seriesFull' => $this->destinations->nextSerial() > AddLaboratoryDestination::MAX_SERIAL,
            'notice' => $notice ?? Session::pullFlash('notice'),
            'errors' => $errors,
            'currentUser' => $this->gate->authenticate(),
        ]), $status);
    }
}
