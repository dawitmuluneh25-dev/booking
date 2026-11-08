<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\BookingStatus;
use PHPUnit\Framework\TestCase;

final class BookingStatusTest extends TestCase
{
    public function testRequestedCanBeConfirmedOrCancelled(): void
    {
        self::assertTrue(BookingStatus::Requested->canTransitionTo(BookingStatus::Confirmed));
        self::assertTrue(BookingStatus::Requested->canTransitionTo(BookingStatus::Cancelled));
        self::assertFalse(BookingStatus::Requested->canTransitionTo(BookingStatus::Completed));
        self::assertFalse(BookingStatus::Requested->canTransitionTo(BookingStatus::Requested));
    }

    public function testConfirmedCanCompleteOrCancel(): void
    {
        self::assertTrue(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Completed));
        self::assertTrue(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Cancelled));
        self::assertFalse(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Requested));
    }

    public function testTerminalStatesHaveNoTransitions(): void
    {
        self::assertTrue(BookingStatus::Cancelled->isTerminal());
        self::assertTrue(BookingStatus::Completed->isTerminal());
        self::assertSame([], BookingStatus::Cancelled->allowedTransitions());
        self::assertSame([], BookingStatus::Completed->allowedTransitions());
        self::assertFalse(BookingStatus::Cancelled->canTransitionTo(BookingStatus::Confirmed));
    }

    public function testLabelsAreHumanReadable(): void
    {
        self::assertSame('Requested', BookingStatus::Requested->label());
        self::assertSame('Confirmed', BookingStatus::Confirmed->label());
    }
}
