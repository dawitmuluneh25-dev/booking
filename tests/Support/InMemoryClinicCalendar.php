<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\ClinicCalendar;
use App\Domain\OpeningHours;
use DateTimeImmutable;

/**
 * Mon–Fri 08:00–17:00 by default, with optional closures, so a service test
 * does not need a database to exercise opening-hours rules.
 */
final class InMemoryClinicCalendar implements ClinicCalendar
{
    /** @var array<int, OpeningHours> */
    private array $week = [];

    /** @var array<string, string|null> */
    private array $closures;

    /**
     * @param array<int, OpeningHours>    $week
     * @param array<string, string|null>  $closures
     */
    public function __construct(array $week = [], array $closures = [])
    {
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $this->week[$weekday] = $week[$weekday]
                ?? new OpeningHours($weekday, $weekday >= 6, '08:00', '17:00');
        }

        $this->closures = $closures;
    }

    public static function alwaysOpen(): self
    {
        $week = [];
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $week[$weekday] = new OpeningHours($weekday, false, '08:00', '17:00');
        }

        return new self($week);
    }

    /** @param list<string> $dates */
    public static function withClosures(array $dates): self
    {
        $closures = [];
        foreach ($dates as $date) {
            $closures[$date] = 'closed';
        }

        return new self([], $closures);
    }

    /** @return array<int, OpeningHours> */
    public function week(): array
    {
        return $this->week;
    }

    public function hoursFor(DateTimeImmutable $localDate): ?OpeningHours
    {
        $hours = $this->week[(int) $localDate->format('N')] ?? null;

        if ($hours === null || $hours->closed) {
            return null;
        }

        return array_key_exists($localDate->format('Y-m-d'), $this->closures) ? null : $hours;
    }

    /** @return list<array{date: string, reason: string|null}> */
    public function closures(): array
    {
        $closures = [];
        foreach ($this->closures as $date => $reason) {
            $closures[] = ['date' => (string) $date, 'reason' => $reason];
        }

        return $closures;
    }

    /** @param array<int, OpeningHours> $week */
    public function saveWeek(array $week): void
    {
        $this->week = $week;
    }

    public function addClosure(string $date, ?string $reason): void
    {
        $this->closures[$date] = $reason;
    }

    public function removeClosure(string $date): void
    {
        unset($this->closures[$date]);
    }
}
