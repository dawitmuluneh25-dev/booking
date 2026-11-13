<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AgeSource;
use App\Domain\AgeUnit;
use App\Domain\PatientAge;
use PHPUnit\Framework\TestCase;

/**
 * An age and a date of birth, asked whether they describe the same child.
 *
 * §6.3 asks for the two to be flagged for reconciliation when they disagree. The
 * interesting decision is what "disagree" means, and the answer here is the unit's
 * own span rather than a chosen number of days: "7 years" means at least seven and
 * not yet eight, so a date of birth implying seven years and eight months agrees
 * while one implying six years and two months does not. Nothing about that is a
 * clinical judgement, which is the point.
 */
final class PatientAgeTest extends TestCase
{
    public function testAWholeNumberOfYearsCoversTheYearItNames(): void
    {
        $age = new PatientAge(7, AgeUnit::Years, AgeSource::ParentOrGuardian);

        // Recorded on 2026-11-13, so seven years old spans 2019-11-13 to 2018-11-13.
        self::assertFalse($age->disagreesWithDateOfBirth('2019-01-01', '2026-11-13'));
        self::assertFalse($age->disagreesWithDateOfBirth('2018-12-31', '2026-11-13'));
    }

    public function testAnAgeAYearOutOnEitherSideDisagrees(): void
    {
        $age = new PatientAge(7, AgeUnit::Years, AgeSource::ParentOrGuardian);

        // Nearly nine: outside [7, 8).
        self::assertTrue($age->disagreesWithDateOfBirth('2017-12-01', '2026-11-13'));
        // Just turned six: below seven.
        self::assertTrue($age->disagreesWithDateOfBirth('2020-06-01', '2026-11-13'));
    }

    public function testAnAgeInDaysIsJudgedInDays(): void
    {
        $age = new PatientAge(10, AgeUnit::Days, AgeSource::ParentOrGuardian);

        self::assertFalse($age->disagreesWithDateOfBirth('2026-11-03', '2026-11-13'));
        self::assertTrue($age->disagreesWithDateOfBirth('2026-10-20', '2026-11-13'));
    }

    public function testTheComparisonUsesTheDayTheAgeWasStatedNotToday(): void
    {
        $age = new PatientAge(3, AgeUnit::Years, AgeSource::Caregiver);

        // Stated in 2020, when the child was three. Compared against today the same
        // record would read as six years out of date and be flagged as a conflict
        // that never happened.
        self::assertFalse($age->disagreesWithDateOfBirth('2017-05-01', '2020-08-01'));
        self::assertTrue($age->disagreesWithDateOfBirth('2017-05-01', '2026-11-13'));
    }

    public function testNoDateOfBirthMeansNoVerdictRatherThanAgreement(): void
    {
        // §6.2: an absent field is not a reassuring one. There is nothing to
        // disagree with, and "null" says so rather than "false".
        $age = new PatientAge(7, AgeUnit::Years, AgeSource::Staff);

        self::assertNull($age->disagreesWithDateOfBirth(null, '2026-11-13'));
        self::assertNull($age->disagreesWithDateOfBirth('', '2026-11-13'));
        self::assertNull($age->disagreesWithDateOfBirth('not-a-date', '2026-11-13'));
    }

    public function testABirthAfterTheAgeWasStatedIsLeftToTheDateValidation(): void
    {
        $age = new PatientAge(2, AgeUnit::Years, AgeSource::Staff);

        self::assertNull($age->disagreesWithDateOfBirth('2027-01-01', '2026-11-13'));
    }

    public function testAnAgeReadsAsASentence(): void
    {
        self::assertSame(
            '1 year, stated by a parent or guardian.',
            (new PatientAge(1, AgeUnit::Years, AgeSource::ParentOrGuardian))->describeWithSource(),
        );
        self::assertSame(
            '10 days, stated by hospital staff.',
            (new PatientAge(10, AgeUnit::Days, AgeSource::Staff))->describeWithSource(),
        );
    }

    public function testAnImpossibleAgeIsRefusedByItsUnit(): void
    {
        self::assertTrue(AgeUnit::Years->isPlausible(7));
        self::assertTrue(AgeUnit::Years->isPlausible(0));
        self::assertFalse(AgeUnit::Years->isPlausible(200));
        self::assertFalse(AgeUnit::Years->isPlausible(-1));
        // 40 000 days is about 110 years, and plausible; 60 000 is not.
        self::assertTrue(AgeUnit::Days->isPlausible(40000));
        self::assertFalse(AgeUnit::Days->isPlausible(60000));
    }
}
