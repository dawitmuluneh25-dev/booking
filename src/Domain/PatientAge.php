<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * An age as somebody stated it, with the unit and the source attached (`PAT-003`).
 *
 * §6.3 offers the age as a *conditional alternative* to a date of birth, and then
 * asks for two things an integer alone cannot do: record who supplied it, and flag
 * it for reconciliation when it disagrees with a recorded date of birth. Both are
 * here, and the disagreement is computed rather than stored — it is a fact about
 * two fields the record already holds, and storing it would create a third value
 * that can drift from the other two.
 */
final class PatientAge
{
    private const DAYS_PER_YEAR = 365.25;

    private const DAYS_PER_MONTH = 30.4375;

    public function __construct(
        public readonly int $value,
        public readonly AgeUnit $unit,
        public readonly AgeSource $source,
    ) {
    }

    /** "7 years" — the number and its unit, without the source. */
    public function describe(): string
    {
        return $this->value . ' ' . ($this->value === 1 ? $this->unit->singular() : $this->unit->value);
    }

    /** "7 years, stated by a parent or guardian." */
    public function describeWithSource(): string
    {
        return $this->describe() . ', stated by ' . $this->source->label() . '.';
    }

    /**
     * Whether a recorded date of birth contradicts this age on the day it was taken.
     *
     * `$recordedOn` is when the age was stated, not today: an age typed two years
     * ago describes the patient as they were then, and comparing it to today would
     * manufacture a disagreement out of the passage of time.
     *
     * Returns null when there is no date of birth to compare against or it cannot
     * be read — absence is not agreement, and §6.2 forbids reading an empty field
     * as a reassuring one.
     */
    public function disagreesWithDateOfBirth(?string $dateOfBirth, string $recordedOn): ?bool
    {
        if ($dateOfBirth === null || $dateOfBirth === '') {
            return null;
        }

        $born = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth);
        $stated = DateTimeImmutable::createFromFormat('!Y-m-d', substr($recordedOn, 0, 10));

        if ($born === false || $stated === false) {
            return null;
        }

        $impliedDays = ($stated->getTimestamp() - $born->getTimestamp()) / 86400.0;

        if ($impliedDays < 0) {
            // A date of birth after the day the age was stated. That is a data
            // problem rather than an age disagreement, and it is left to the date
            // validation that already refuses a future date of birth.
            return null;
        }

        $lower = $this->value * $this->daysPerUnit();
        $upper = ($this->value + 1) * $this->daysPerUnit();

        return $impliedDays < $lower || $impliedDays >= $upper;
    }

    private function daysPerUnit(): float
    {
        return match ($this->unit) {
            AgeUnit::Days => 1.0,
            AgeUnit::Weeks => 7.0,
            AgeUnit::Months => self::DAYS_PER_MONTH,
            AgeUnit::Years => self::DAYS_PER_YEAR,
        };
    }
}
