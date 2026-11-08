<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\BookingNotFoundException;
use App\Domain\BookingService;
use App\Domain\InvalidTransitionException;
use App\Domain\Permission;
use App\Domain\SlotUnavailableException;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\View;
use Closure;
use Throwable;

final class BookingController
{
    /** @param Closure(): BookingService $serviceFactory */
    public function __construct(
        private readonly View $view,
        private readonly Closure $serviceFactory,
        private readonly string $timezone,
        private readonly Auth $auth,
        private readonly Gate $gate,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/bookings'));
        }

        try {
            $bookings = $this->service()->upcoming(20);
        } catch (Throwable) {
            throw new HttpException(503, 'Bookings are temporarily unavailable.');
        }

        return Response::html($this->view->render('bookings/index', [
            'title' => 'Upcoming bookings',
            'bookings' => $bookings,
            'timezone' => $this->timezone,
            'notice' => Session::pullFlash('notice'),
            'error' => Session::pullFlash('error'),
            'currentUser' => $this->auth->user(),
            'canManage' => $this->gate->allows(Permission::BookingsManage),
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->form([], [], 200);
    }

    public function store(Request $request): Response
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        try {
            $booking = $this->service()->request($request->all());
        } catch (ValidationException $exception) {
            return $this->form($exception->errors, $request->all(), 422);
        } catch (SlotUnavailableException) {
            return $this->form(
                ['scheduled_at' => 'That time was just taken. Please choose another.'],
                $request->all(),
                409,
            );
        }

        Session::flash('notice', 'Booking request received.');

        return Response::redirect('/bookings/' . rawurlencode($booking->reference));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $reference = $params['reference'] ?? '';

        try {
            $booking = $this->service()->find($reference);
        } catch (Throwable) {
            throw new HttpException(503, 'Bookings are temporarily unavailable.');
        }

        if ($booking === null) {
            throw HttpException::notFound('That booking reference does not exist.');
        }

        return Response::html($this->view->render('bookings/show', [
            'title' => 'Booking ' . $booking->reference,
            'booking' => $booking,
            'timezone' => $this->timezone,
            'currentUser' => $this->auth->user(),
        ]));
    }

    /** @param array<string, string> $params */
    public function confirm(Request $request, array $params): Response
    {
        return $this->changeStatus($request, $params, 'confirm');
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        return $this->changeStatus($request, $params, 'cancel');
    }

    /** @param array<string, string> $params */
    private function changeStatus(Request $request, array $params, string $action): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/bookings'));
        }

        $this->gate->authorize(Permission::BookingsManage);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $reference = $params['reference'] ?? '';
        $user = $this->auth->user();
        $actor = 'user:' . ($user === null ? 'unknown' : $user->email);

        try {
            $service = $this->service();

            if ($action === 'confirm') {
                $service->confirm($reference, $actor);
            } else {
                $service->cancel($reference, $actor, 'Cancelled from the staff console');
            }
        } catch (BookingNotFoundException) {
            throw HttpException::notFound('That booking reference does not exist.');
        } catch (InvalidTransitionException $exception) {
            Session::flash('error', $exception->getMessage());

            return Response::redirect('/bookings');
        }

        Session::flash(
            'notice',
            $action === 'confirm' ? 'Booking confirmed.' : 'Booking cancelled; the slot is free again.',
        );

        return Response::redirect('/bookings');
    }

    /**
     * Resolved lazily so rendering the form does not require a database
     * connection to exist yet.
     */
    private function service(): BookingService
    {
        return ($this->serviceFactory)();
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $old
     */
    private function form(array $errors, array $old, int $status): Response
    {
        return Response::html($this->view->render('bookings/create', [
            'title' => 'New booking',
            'services' => BookingService::SERVICES,
            'errors' => $errors,
            'old' => $old,
            'currentUser' => $this->auth->user(),
        ]), $status);
    }
}
