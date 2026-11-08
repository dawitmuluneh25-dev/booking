<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * The clinic's bookable week, as configuration rather than constants.
 */
interface ClinicCalendar
{
    /** @return array<int, OpeningHours> keyed by ISO weekday (1–7) */
    public function week(): array;

    /**
     * Hours for the day of $localDate, or null when the clinic is closed that
     * day: either the weekday is closed or a one-off closure matches.
     */
    public function hoursFor(DateTimeImmutable $localDate): ?OpeningHours;

    /** @return list<array{date: string, reason: string|null}> */
    public function closures(): array;

    /** @param array<int, OpeningHours> $week */
    public function saveWeek(array $week): void;

    public function addClosure(string $date, ?string $reason): void;

    public function removeClosure(string $date): void;
}
