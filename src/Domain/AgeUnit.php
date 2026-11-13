<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The units §6.3 allows an age to be recorded in (`PAT-003`).
 *
 * The list is the document's own: days, weeks, months, years. It exists because
 * an age without a unit is not an age — "3" could be a three-day-old infant or a
 * three-year-old child, and a system that guesses is a system that will one day
 * give a neonate an adult's dose of attention.
 */
enum AgeUnit: string
{
    case Days = 'days';

    case Weeks = 'weeks';

    case Months = 'months';

    case Years = 'years';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $unit): string => $unit->value, self::cases());
    }

    /**
     * Whether a number in this unit could describe a living patient.
     *
     * A guard against typos, not a clinical threshold: it only rejects an age that
     * would make the patient older than any person has been. The bound is stated
     * once, here, so that "180 years" is refused in one place rather than
     * differently in each unit.
     */
    public function isPlausible(int $value): bool
    {
        if ($value < 0) {
            return false;
        }

        return $value * $this->daysInUnit() <= 150 * self::DAYS_IN_A_YEAR;
    }

    private const DAYS_IN_A_YEAR = 365.25;

    private function daysInUnit(): float
    {
        return match ($this) {
            self::Days => 1.0,
            self::Weeks => 7.0,
            self::Months => 30.4375,
            self::Years => self::DAYS_IN_A_YEAR,
        };
    }

    /** The singular form, for reading a record back in a sentence. */
    public function singular(): string
    {
        return match ($this) {
            self::Days => 'day',
            self::Weeks => 'week',
            self::Months => 'month',
            self::Years => 'year',
        };
    }

    /**
     * How long an age stated in this unit stays true.
     *
     * One unit, because an age written as a whole number of units means completed
     * units — the ordinary convention. "7 years" describes a child who is at least
     * seven and not yet eight, so a date of birth implying seven years and eight
     * months agrees with it while one implying six years and two months does not.
     *
     * That reading needs no invented tolerance: the interval is the unit's own
     * span. Picking a number of days either side would be choosing a clinical rule
     * this application has no business choosing.
     */
    public function spanInDays(): float
    {
        return match ($this) {
            self::Days => 1.0,
            self::Weeks => 7.0,
            // Months have no fixed length, so the widest month is the only span
            // that cannot be wrong.
            self::Months => 31.0,
            self::Years => 366.0,
        };
    }
}
