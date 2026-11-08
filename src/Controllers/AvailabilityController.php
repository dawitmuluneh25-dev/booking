<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Booking;
use App\Domain\BookingService;
use App\Domain\ClinicCalendar;
use App\Domain\DaySchedule;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Security\Auth;
use App\Support\View;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class AvailabilityController
{
    /**
     * @param Closure(): BookingService $serviceFactory
     */
    public function __construct(
        private readonly View $view,
        private readonly Closure $serviceFactory,
        private readonly string $timezone,
        private readonly Auth $auth,
        private readonly ClinicCalendar $calendar,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?next=' . rawurlencode('/availability'));
        }

        $timezone = new DateTimeZone($this->timezone);
        $requested = (string) $request->input('date', '');
        $date = $requested === ''
            ? new DateTimeImmutable('today', $timezone)
            : DaySchedule::parseDate($requested, $timezone);

        if ($date === null) {
            $date = new DateTimeImmutable('today', $timezone);
        }

        $hours = $this->calendar->hoursFor($date);

        $slots = $hours === null
            ? []
            : DaySchedule::slots(
                $date,
                $timezone,
                BookingService::SLOT_MINUTES,
                $hours->opens,
                $hours->closes,
            );

        $booked = [];
        if ($slots !== []) {
            $utc = new DateTimeZone('UTC');
            $from = $slots[0]->setTimezone($utc)->format('Y-m-d H:i:s');
            $to = $slots[count($slots) - 1]
                ->modify('+' . BookingService::SLOT_MINUTES . ' minutes')
                ->setTimezone($utc)
                ->format('Y-m-d H:i:s');

            try {
                $booked = $this->service()->bookedSlots($from, $to);
            } catch (Throwable) {
                throw new HttpException(503, 'Availability is temporarily unavailable.');
            }
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $utc = new DateTimeZone('UTC');

        /** @var list<array{label: string, booking: Booking|null, past: bool}> $rows */
        $rows = [];
        $free = 0;
        $taken = 0;

        foreach ($slots as $slot) {
            $key = $slot->setTimezone($utc)->format('Y-m-d H:i:s');
            $booking = $booked[$key] ?? null;
            $past = $slot->setTimezone($utc) < $now;

            if ($booking !== null) {
                $taken++;
            } elseif (!$past) {
                $free++;
            }

            $rows[] = [
                'label' => $slot->format('H:i'),
                'booking' => $booking,
                'past' => $past,
            ];
        }

        return Response::html($this->view->render('availability/index', [
            'title' => 'Availability',
            'date' => $date->format('Y-m-d'),
            'dateLabel' => $date->format('l j F Y'),
            'previous' => $date->modify('-1 day')->format('Y-m-d'),
            'next' => $date->modify('+1 day')->format('Y-m-d'),
            'rows' => $rows,
            'freeCount' => $free,
            'bookedCount' => $taken,
            'hours' => $hours,
            'timezone' => $this->timezone,
            'currentUser' => $this->auth->user(),
        ]));
    }

    private function service(): BookingService
    {
        return ($this->serviceFactory)();
    }
}
