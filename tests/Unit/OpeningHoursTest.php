<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\OpeningHours;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OpeningHoursTest extends TestCase
{
    public function testItNormalisesTimeFormats(): void
    {
        self::assertSame('08:00', OpeningHours::normalizeTime('8:00'));
        self::assertSame('08:00', OpeningHours::normalizeTime('08:00:00'));
        self::assertSame('23:59', OpeningHours::normalizeTime(' 23:59 '));
        self::assertNull(OpeningHours::normalizeTime('24:00'));
        self::assertNull(OpeningHours::normalizeTime('08:60'));
        self::assertNull(OpeningHours::normalizeTime(''));
        self::assertNull(OpeningHours::normalizeTime('8'));
    }

    public function testItRejectsAnImpossibleWeekday(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OpeningHours(8, false, '08:00', '17:00');
    }

    public function testItRejectsAWindowThatClosesBeforeItOpens(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OpeningHours(1, false, '17:00', '08:00');
    }

    public function testAClosedDayMayKeepPlaceholderTimes(): void
    {
        $hours = new OpeningHours(6, true, '17:00', '08:00');

        self::assertSame('Closed', $hours->spans());
    }

    public function testItAcceptsASlotThatFinishesExactlyAtClosing(): void
    {
        $hours = new OpeningHours(3, false, '08:00', '17:00');
        $local = new DateTimeImmutable('2030-05-01 16:30:00', new DateTimeZone('Africa/Addis_Ababa'));

        self::assertTrue($hours->contains($local, 30));
        self::assertFalse($hours->contains($local, 60));
    }

    public function testItRejectsTimesOutsideTheWindow(): void
    {
        $hours = new OpeningHours(3, false, '08:00', '17:00');
        $tz = new DateTimeZone('Africa/Addis_Ababa');

        self::assertFalse($hours->contains(new DateTimeImmutable('2030-05-01 07:30:00', $tz), 30));
        self::assertFalse($hours->contains(new DateTimeImmutable('2030-05-01 17:00:00', $tz), 30));
        self::assertTrue($hours->contains(new DateTimeImmutable('2030-05-01 08:00:00', $tz), 30));
    }

    public function testItRejectsADifferentWeekday(): void
    {
        $hours = new OpeningHours(3, false, '08:00', '17:00');
        $saturday = new DateTimeImmutable('2030-05-04 10:00:00', new DateTimeZone('Africa/Addis_Ababa'));

        self::assertFalse($hours->contains($saturday, 30));
    }

    public function testAnAppointmentRunningPastMidnightNeverFits(): void
    {
        $hours = new OpeningHours(3, false, '08:00', '23:59');
        $late = new DateTimeImmutable('2030-05-01 23:45:00', new DateTimeZone('Africa/Addis_Ababa'));

        self::assertFalse($hours->contains($late, 30));
    }
}
