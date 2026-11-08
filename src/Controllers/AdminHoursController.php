<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\ClinicCalendar;
use App\Domain\DaySchedule;
use App\Domain\OpeningHours;
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
use DateTimeZone;
use Throwable;

/**
 * Edits the clinic week. Times are wall-clock `HH:MM` in the application
 * timezone; nothing here converts to UTC.
 */
final class AdminHoursController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Gate $gate,
        private readonly ClinicCalendar $calendar,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/hours'));
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        try {
            return $this->render([], 200, []);
        } catch (Throwable) {
            return Response::html($this->view->render('admin/hours', [
                'title' => 'Opening hours',
                'rows' => [],
                'closures' => [],
                'errors' => [],
                'notice' => null,
                'timezone' => date_default_timezone_get(),
                'unavailable' => true,
                'currentUser' => $this->auth->user(),
            ]), 503);
        }
    }

    public function update(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/admin/hours'));
        }

        $this->gate->authorize(Permission::ClinicConfigure);

        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }

        $action = $request->input('action', 'save_week');
        $raw = $this->rawHours($request);

        try {
            $notice = match ($action) {
                'add_closure' => $this->addClosure($request),
                'remove_closure' => $this->removeClosure($request),
                default => $this->saveWeek($raw),
            };
        } catch (ValidationException $exception) {
            try {
                return $this->render($exception->errors, 422, $raw);
            } catch (Throwable) {
                throw new HttpException(503, 'Opening hours could not be saved.');
            }
        } catch (Throwable) {
            throw new HttpException(503, 'Opening hours could not be saved.');
        }

        Session::flash('notice', $notice);

        return Response::redirect('/admin/hours');
    }

    /**
     * @param array<int, array{closed: bool, opens: string, closes: string}> $week
     */
    private function saveWeek(array $week): string
    {
        $errors = [];
        $openDays = 0;
        $hours = [];

        foreach ($week as $weekday => $entry) {
            $opens = OpeningHours::normalizeTime($entry['opens']);
            $closes = OpeningHours::normalizeTime($entry['closes']);

            if ($opens === null || $closes === null) {
                $errors['hours.' . $weekday] = 'Use a 24-hour time such as 08:00.';
                continue;
            }

            if (!$entry['closed'] && $closes <= $opens) {
                $errors['hours.' . $weekday] = 'Closing time must be after opening time.';
                continue;
            }

            if (!$entry['closed']) {
                $openDays++;
            }

            $hours[$weekday] = new OpeningHours($weekday, $entry['closed'], $opens, $closes);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($openDays === 0) {
            throw new ValidationException(['hours' => 'At least one day must be open.']);
        }

        $this->calendar->saveWeek($hours);

        return 'Opening hours saved.';
    }

    private function addClosure(Request $request): string
    {
        $date = (string) $request->input('closed_on', '');
        $parsed = DaySchedule::parseDate($date, new DateTimeZone('UTC'));

        if ($parsed === null) {
            throw new ValidationException(['closed_on' => 'Enter a valid date, for example 2026-12-25.']);
        }

        $reason = $request->input('reason', '');
        if ($reason !== null && mb_strlen($reason) > 120) {
            throw new ValidationException(['reason' => 'Keep the reason to 120 characters or fewer.']);
        }

        $this->calendar->addClosure($parsed->format('Y-m-d'), $reason);

        return 'Closed day added.';
    }

    private function removeClosure(Request $request): string
    {
        $date = (string) $request->input('closed_on', '');
        $parsed = DaySchedule::parseDate($date, new DateTimeZone('UTC'));

        if ($parsed === null) {
            throw new ValidationException(['closed_on' => 'Enter a valid date.']);
        }

        $this->calendar->removeClosure($parsed->format('Y-m-d'));

        return 'Closed day removed.';
    }

    /**
     * @return array<int, array{closed: bool, opens: string, closes: string}>
     */
    private function rawHours(Request $request): array
    {
        $all = $request->all();
        $raw = $all['hours'] ?? null;
        $raw = is_array($raw) ? $raw : [];

        $hours = [];
        foreach (range(1, 7) as $weekday) {
            $entry = $raw[$weekday] ?? null;
            $entry = is_array($entry) ? $entry : [];

            $opens = $entry['opens'] ?? '';
            $closes = $entry['closes'] ?? '';

            $hours[$weekday] = [
                'closed' => ($entry['closed'] ?? '') === '1',
                'opens' => is_string($opens) ? trim($opens) : '',
                'closes' => is_string($closes) ? trim($closes) : '',
            ];
        }

        return $hours;
    }

    /**
     * @param array<string, string>                                              $errors
     * @param array<int, array{closed: bool, opens: string, closes: string}>      $submitted
     */
    private function render(array $errors, int $status, array $submitted): Response
    {
        $stored = $this->calendar->week();
        $rows = [];

        foreach (range(1, 7) as $weekday) {
            $fallback = $stored[$weekday] ?? new OpeningHours($weekday, false, '08:00', '17:00');
            $entry = $submitted[$weekday] ?? null;

            $closed = $entry === null ? $fallback->closed : $entry['closed'];
            $opens = $entry === null || $entry['opens'] === '' ? $fallback->opens : $entry['opens'];
            $closes = $entry === null || $entry['closes'] === '' ? $fallback->closes : $entry['closes'];

            $rows[] = [
                'weekday' => $weekday,
                'label' => $fallback->label(),
                'closed' => $closed,
                'opens' => substr(OpeningHours::normalizeTime($opens) ?? $opens, 0, 5),
                'closes' => substr(OpeningHours::normalizeTime($closes) ?? $closes, 0, 5),
            ];
        }

        return Response::html($this->view->render('admin/hours', [
            'title' => 'Opening hours',
            'rows' => $rows,
            'closures' => $this->calendar->closures(),
            'errors' => $errors,
            'notice' => Session::pullFlash('notice'),
            'timezone' => date_default_timezone_get(),
            'unavailable' => false,
            'currentUser' => $this->auth->user(),
        ]), $status);
    }
}
