<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Actor;
use App\Domain\AppointmentNotFoundException;
use App\Domain\AppointmentTransitionException;
use App\Domain\AuthorisationException;
use App\Domain\BookAppointment;
use App\Domain\ReferralNotFoundException;
use App\Domain\Permission;
use App\Domain\ReferralTransitionException;
use App\Domain\Resource;
use App\Domain\ScheduleWorkspace;
use App\Domain\SlotUnavailableException;
use App\Domain\ValidationException;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\Gate;
use App\Security\Session;
use App\Support\FormErrors;
use App\Support\Logger;
use App\Support\View;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The reception calendar and the booking wizard.
 *
 * The rule this class exists to keep is PRD 7.4: the client never decides that a
 * slot is free. Both forms therefore offer a list of times the server has already
 * checked, each carrying its UTC instant; a hand-edited POST that names another
 * time reaches `resource_slots` and is refused there. The page and the
 * reservation share one computation, so they cannot disagree about what the
 * calendar means.
 */
final class ScheduleController
{
    private const DEFAULT_DURATION = 60;

    private const MAX_DURATION = 480;

    /** @param Closure(): ScheduleWorkspace $workspace */
    public function __construct(
        private readonly View $view,
        private readonly Gate $gate,
        private readonly Logger $logger,
        private readonly Closure $workspace,
        private readonly string $timezone,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->gate->authenticate();
        $zone = new DateTimeZone($this->timezone);
        $date = $this->localDate($request, $zone);
        $duration = $this->duration($request);
        $workspace = $this->workspace();

        $rows = $workspace->board->day($date, $zone, $duration, $this->now());

        $previous = $date->modify('-1 day');
        $next = $date->modify('+1 day');

        return Response::html($this->view->render('schedule/index', [
            'title' => 'Schedule',
            'date' => $date->format('Y-m-d'),
            'dateLabel' => $date->format('l j F Y'),
            'previous' => $previous === false ? $date->format('Y-m-d') : $previous->format('Y-m-d'),
            'next' => $next === false ? $date->format('Y-m-d') : $next->format('Y-m-d'),
            'duration' => $duration,
            'rows' => $rows,
            'timezone' => $this->timezone,
            'notice' => Session::pullFlash('notice'),
            'error' => Session::pullFlash('error'),
            'fieldErrors' => FormErrors::pull(),
            'canBook' => $this->gate->allows(Permission::ScheduleManage),
            'canCancel' => $this->gate->allows(Permission::BookingsManage),
            'currentUser' => $user,
        ]));
    }

    public function create(Request $request): Response
    {
        $this->gate->authenticate();
        $zone = new DateTimeZone($this->timezone);
        $date = $this->localDate($request, $zone);
        $duration = $this->duration($request);
        $workspace = $this->workspace();

        $referrals = $workspace->referrals->approvedForScheduling();
        $selected = (int) $request->input('referral_id', '0');

        return Response::html($this->view->render('schedule/slots', [
            'title' => 'Book an appointment',
            'mode' => 'book',
            'action' => '/schedule/book',
            'dateAction' => '/schedule/book',
            'referral' => null,
            'appointment' => null,
            'referrals' => $referrals,
            'selectedReferral' => $selected,
            'date' => $date->format('Y-m-d'),
            'duration' => $duration,
            'dateLabel' => $date->format('l j F Y'),
            'resourceSlots' => $this->resourceSlots($date, $duration, $zone),
            'notice' => Session::pullFlash('notice'),
            'error' => Session::pullFlash('error'),
            'fieldErrors' => FormErrors::pull(),
        ]));
    }

    public function store(Request $request): Response
    {
        $actor = $this->actor();
        $this->assertCsrf($request);

        $referralId = (int) $request->input('referral_id', '0');

        try {
            [$resourceId, $startsAt] = $this->slot($request);

            $appointment = $this->workspace()->book->execute($actor, $referralId, [
                'resource_id' => $resourceId,
                'starts_at' => $startsAt,
                'duration_minutes' => $request->input('duration_minutes', ''),
            ]);
        } catch (ValidationException $exception) {
            return $this->backToForm('/schedule/book?referral_id=' . $referralId, $exception->errors);
        } catch (SlotUnavailableException $exception) {
            // The authoritative refusal. The desk gets a sentence it can act on,
            // and the times it sees next are recomputed from occupancy.
            return $this->backToForm('/schedule/book?referral_id=' . $referralId, ['starts_at' => $exception->getMessage()]);
        } catch (ReferralTransitionException | ReferralNotFoundException $exception) {
            return $this->backToForm('/schedule/book', ['referral_id' => $exception->getMessage()]);
        } catch (AuthorisationException) {
            throw HttpException::forbidden('Your role may not schedule.');
        }

        $this->logger->info('appointment.booked', [
            'appointment' => $appointment->reference,
            'starts_at' => $appointment->startsAtUtc,
        ]);

        Session::flash('notice', 'Booked ' . $appointment->reference . '.');

        return Response::redirect('/schedule?date=' . rawurlencode(substr($appointment->startsAtUtc, 0, 10)));
    }

    /** @param array<string, string> $params */
    public function move(Request $request, array $params): Response
    {
        $this->gate->authenticate();
        $zone = new DateTimeZone($this->timezone);
        $workspace = $this->workspace();

        $appointment = $workspace->appointments->findByReference((string) ($params['reference'] ?? ''));

        if ($appointment === null) {
            throw HttpException::notFound();
        }

        $date = $this->localDate($request, $zone);
        $duration = $appointment->durationMinutes > 0
            ? $appointment->durationMinutes
            : $this->duration($request);

        return Response::html($this->view->render('schedule/slots', [
            'title' => 'Move ' . $appointment->reference,
            'mode' => 'move',
            'action' => '/schedule/' . rawurlencode($appointment->reference) . '/reschedule',
            'dateAction' => '/schedule/' . rawurlencode($appointment->reference) . '/move',
            'referral' => null,
            'appointment' => $appointment,
            'referrals' => [],
            'selectedReferral' => 0,
            'date' => $date->format('Y-m-d'),
            'duration' => $duration,
            'dateLabel' => $date->format('l j F Y'),
            'resourceSlots' => $this->resourceSlots($date, $duration, $zone),
            'notice' => Session::pullFlash('notice'),
            'error' => Session::pullFlash('error'),
            'fieldErrors' => FormErrors::pull(),
        ]));
    }

    /** @param array<string, string> $params */
    public function reschedule(Request $request, array $params): Response
    {
        $actor = $this->actor();
        $this->assertCsrf($request);

        $reference = (string) ($params['reference'] ?? '');
        $back = '/schedule/' . rawurlencode($reference) . '/move';

        try {
            [$resourceId, $startsAt] = $this->slot($request);

            $appointment = $this->workspace()->reschedule->execute($actor, $reference, [
                'resource_id' => $resourceId,
                'starts_at' => $startsAt,
                'duration_minutes' => $request->input('duration_minutes', ''),
            ], (string) $request->input('reason', ''));
        } catch (ValidationException $exception) {
            return $this->backToForm($back, $exception->errors);
        } catch (SlotUnavailableException $exception) {
            return $this->backToForm($back, ['starts_at' => $exception->getMessage()]);
        } catch (AppointmentTransitionException $exception) {
            return $this->backToForm($back, ['starts_at' => $exception->getMessage()]);
        } catch (AppointmentNotFoundException) {
            throw HttpException::notFound();
        } catch (AuthorisationException) {
            throw HttpException::forbidden('Your role may not reschedule.');
        }

        $this->logger->info('appointment.rescheduled', [
            'appointment' => $appointment->reference,
            'starts_at' => $appointment->startsAtUtc,
        ]);

        Session::flash('notice', 'Moved ' . $appointment->reference . ' to ' . $appointment->startsAtUtc . ' UTC.');

        return Response::redirect('/schedule?date=' . rawurlencode(substr($appointment->startsAtUtc, 0, 10)));
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $actor = $this->actor();
        $this->assertCsrf($request);

        $reference = (string) ($params['reference'] ?? '');
        $date = (string) $request->input('date', gmdate('Y-m-d'));

        try {
            $appointment = $this->workspace()->cancel->execute(
                $actor,
                $reference,
                (string) $request->input('reason', ''),
            );
        } catch (ValidationException $exception) {
            return $this->backToBoard($date, $exception->errors);
        } catch (AppointmentTransitionException $exception) {
            return $this->backToBoard($date, ['reason' => $exception->getMessage()]);
        } catch (AppointmentNotFoundException) {
            throw HttpException::notFound();
        } catch (AuthorisationException) {
            throw HttpException::forbidden('Your role may not cancel an appointment.');
        }

        $this->logger->info('appointment.cancelled', ['appointment' => $appointment->reference]);
        Session::flash('notice', 'Cancelled ' . $appointment->reference . '; the room is free again.');

        return Response::redirect('/schedule?date=' . rawurlencode($date));
    }

    /**
     * Free starts for every resource on one date.
     *
     * @return list<array{resource: Resource, slots: list<array{utc: string, label: string}>}>
     */
    private function resourceSlots(DateTimeImmutable $date, int $duration, DateTimeZone $zone): array
    {
        $board = $this->workspace()->board;
        $rows = [];

        foreach ($board->resources() as $resource) {
            $rows[] = [
                'resource' => $resource,
                'slots' => $board->freeSlots($resource->id, $date, $zone, $duration, $this->now()),
            ];
        }

        return $rows;
    }

    /** @param array<string, string> $errors */
    private function backToForm(string $path, array $errors): Response
    {
        FormErrors::remember($errors);

        return Response::redirect($path);
    }

    /** @param array<string, string> $errors */
    private function backToBoard(string $date, array $errors): Response
    {
        FormErrors::remember($errors);

        return Response::redirect('/schedule?date=' . rawurlencode($date));
    }

    private function localDate(Request $request, DateTimeZone $zone): DateTimeImmutable
    {
        $raw = trim((string) $request->input('date', ''));

        if ($raw !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $zone);

            if ($parsed !== false && $parsed->format('Y-m-d') === $raw) {
                return $parsed;
            }
        }

        return new DateTimeImmutable('today', $zone);
    }

    private function duration(Request $request): int
    {
        $raw = $request->input('duration_minutes', (string) self::DEFAULT_DURATION);
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_DURATION;

        if ($value < 15 || $value > self::MAX_DURATION || $value % BookAppointment::SLOT_MINUTES !== 0) {
            return self::DEFAULT_DURATION;
        }

        return $value;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * The chosen slot, as the form encodes it: one value that names both the room
     * and the instant.
     *
     * A single value cannot disagree with itself, which is the point - two hidden
     * fields could be edited independently, and the pair would then describe a
     * room and a time nobody chose.
     *
     * @return array{0: string, 1: string}
     */
    private function slot(Request $request): array
    {
        $raw = (string) $request->input('slot', '');
        $pattern = '/\A([0-9]{1,6})\|([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})\z/';
        if ($raw !== '' && preg_match($pattern, $raw, $match) === 1) {
            return [$match[1], $match[2]];
        }

        return [(string) $request->input('resource_id', ''), (string) $request->input('starts_at', '')];
    }

    private function actor(): Actor
    {
        $user = $this->gate->authenticate();
        return new Actor($user->id, $user->role);
    }

    private function assertCsrf(Request $request): void
    {
        if (!Csrf::validate($request->input(Csrf::FIELD))) {
            throw new HttpException(419);
        }
    }

    private function workspace(): ScheduleWorkspace
    {
        return ($this->workspace)();
    }
}
