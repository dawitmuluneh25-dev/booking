<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\AvailabilityWindow;
use App\Domain\ResourceSchedule;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 criterion 4, at the level where the answer is computed.
 *
 * If this is wrong, the wizard offers a time that cannot be booked, or hides one
 * that can. Both are visible to a patient, so the cases below are the ones a
 * clinic actually meets: a lunch gap, a booking that straddles a window edge, and
 * a slot that has already started.
 */
final class ResourceScheduleTest extends TestCase
{
    private const DAY = '2031-06-03';

    private ResourceSchedule $schedule;

    private DateTimeZone $zone;

    protected function setUp(): void
    {
        $this->schedule = new ResourceSchedule();
        $this->zone = new DateTimeZone('Africa/Addis_Ababa');
    }

    public function testAFreeDayOffersEveryStartThatFitsTheWindow(): void
    {
        $slots = $this->free(['08:00', '10:00'], '60');

        self::assertSame(['08:00', '08:15', '08:30', '08:45', '09:00'], array_column($slots, 'label'));
    }

    public function testAnAppointmentMayNotStraddleTheEndOfTheWindow(): void
    {
        // A 60-minute appointment in a two-hour window cannot start at 15:30.
        $slots = $this->free(['14:00', '16:00'], '60');

        self::assertSame(['14:00', '14:15', '14:30', '14:45', '15:00'], array_column($slots, 'label'));
        self::assertNotContains('15:15', array_column($slots, 'label'));
    }

    public function testAnOccupiedQuantumRemovesOnlyTheStartsThatNeedIt(): void
    {
        // The clinic runs on UTC+3, so the 09:00 local quantum is 06:00 UTC. A
        // 60-minute appointment starting at 08:00 ends before it; 08:15 does not.
        $slots = $this->free(['08:00', '10:00'], '60', ['2031-06-03 06:00:00']);

        $labels = array_column($slots, 'label');

        self::assertContains('08:00', $labels);
        self::assertNotContains('08:15', $labels);
        self::assertNotContains('08:45', $labels);
        self::assertNotContains('09:00', $labels);
    }

    public function testATwoHourGapProducesNoFivePmSlotWhenTheWindowCloses(): void
    {
        $slots = $this->free(['08:00', '09:00'], '60');

        self::assertCount(1, $slots);
        self::assertSame('08:00', $slots[0]['label']);
    }

    public function testPastStartsAreNotOffered(): void
    {
        // 05:10 UTC is 08:10 in the clinic, so 08:00 has begun and 08:15 has not.
        $now = new DateTimeImmutable('2031-06-03 05:10:00', new DateTimeZone('UTC'));

        $slots = $this->schedule->freeSlots(
            [new AvailabilityWindow(2, '08:00', '11:00')],
            [],
            new DateTimeImmutable(self::DAY, $this->zone),
            $this->zone,
            30,
            $now,
        );

        $labels = array_column($slots, 'label');

        self::assertNotContains('08:00', $labels);
        self::assertContains('08:15', $labels);
    }

    public function testTwoWindowsOnOneDayAreBothOfferedInOrder(): void
    {
        $slots = $this->schedule->freeSlots(
            [new AvailabilityWindow(2, '14:00', '16:00'), new AvailabilityWindow(2, '08:00', '09:00')],
            [],
            new DateTimeImmutable(self::DAY, $this->zone),
            $this->zone,
            60,
            new DateTimeImmutable('2031-06-01 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame(['08:00', '14:00', '14:15', '14:30', '14:45', '15:00'], array_column($slots, 'label'));
    }

    public function testAQuarterHourDurationIsRejectedAsNotAWholeNumberOfQuanta(): void
    {
        $slots = $this->schedule->freeSlots(
            [new AvailabilityWindow(2, '08:00', '10:00')],
            [],
            new DateTimeImmutable(self::DAY, $this->zone),
            $this->zone,
            20,
            new DateTimeImmutable('2031-06-01 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame([], $slots);
    }

    public function testNoWindowMeansNoSlots(): void
    {
        self::assertSame([], $this->free([]));
    }

    public function testQuantumStartsIncludeEveryQuarterOfTheAppointment(): void
    {
        $starts = ResourceSchedule::quantumStarts(
            new DateTimeImmutable('2031-06-03 09:00:00', new DateTimeZone('UTC')),
            45,
        );

        self::assertSame(
            ['2031-06-03 09:00:00', '2031-06-03 09:15:00', '2031-06-03 09:30:00'],
            $starts,
        );
    }

    /**
     * @param array{0: string, 1: string}|list<string> $window
     * @param list<string>                             $occupied
     *
     * @return list<array{utc: string, label: string}>
     */
    private function free(array $window, string $duration = '30', array $occupied = []): array
    {
        $windows = $window === []
            ? []
            : [new AvailabilityWindow(2, $window[0], $window[1])];

        return $this->schedule->freeSlots(
            $windows,
            $occupied,
            new DateTimeImmutable(self::DAY, $this->zone),
            $this->zone,
            (int) $duration,
            new DateTimeImmutable('2031-06-01 00:00:00', new DateTimeZone('UTC')),
        );
    }
}
