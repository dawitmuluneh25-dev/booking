<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\DaySchedule;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DayScheduleTest extends TestCase
{
    public function testItBuildsHalfHourSlotsForTheOpeningWindow(): void
    {
        $addis = new DateTimeZone('Africa/Addis_Ababa');
        $date = new DateTimeImmutable('2030-05-01', $addis);

        $slots = DaySchedule::slots($date, $addis, 30, 8, 17);

        self::assertCount(18, $slots);
        self::assertSame('08:00', $slots[0]->format('H:i'));
        self::assertSame('16:30', $slots[count($slots) - 1]->format('H:i'));
        self::assertSame('Africa/Addis_Ababa', $slots[0]->getTimezone()->getName());
    }

    public function testSlotsConvertToUtcOnTheSameGrid(): void
    {
        $addis = new DateTimeZone('Africa/Addis_Ababa');
        $slots = DaySchedule::slots(new DateTimeImmutable('2030-05-01', $addis), $addis, 30, 8, 17);

        $utc = $slots[0]->setTimezone(new DateTimeZone('UTC'));

        self::assertSame('05:00', $utc->format('H:i'));
        self::assertSame('2030-05-01', $utc->format('Y-m-d'));
    }

    public function testItReturnsNoSlotsForAnInvalidWindow(): void
    {
        $tz = new DateTimeZone('UTC');
        $date = new DateTimeImmutable('2030-05-01', $tz);

        self::assertSame([], DaySchedule::slots($date, $tz, 0, 8, 17));
        self::assertSame([], DaySchedule::slots($date, $tz, 30, 17, 8));
    }

    public function testItParsesStrictIsoDates(): void
    {
        $tz = new DateTimeZone('UTC');

        self::assertNotNull(DaySchedule::parseDate('2030-05-01', $tz));
        self::assertNull(DaySchedule::parseDate('01/05/2030', $tz));
        self::assertNull(DaySchedule::parseDate('2030-13-01', $tz));
        self::assertNull(DaySchedule::parseDate('', $tz));
    }
}
