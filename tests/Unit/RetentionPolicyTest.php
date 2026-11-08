<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RetentionPolicy;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    public function testCutoffIsTheWindowBeforeNow(): void
    {
        $now = new DateTimeImmutable('2026-11-08 12:00:00', new DateTimeZone('UTC'));
        $policy = new RetentionPolicy(365);

        self::assertSame('2025-11-08 12:00:00', $policy->cutoff($now)->format('Y-m-d H:i:s'));
    }

    public function testOnlyFinishedBookingsPastTheCutoffAreEligible(): void
    {
        $now = new DateTimeImmutable('2026-11-08 12:00:00', new DateTimeZone('UTC'));
        $policy = new RetentionPolicy(365);

        self::assertTrue($policy->isEligible('completed', '2024-01-01 09:00:00', $now));
        self::assertTrue($policy->isEligible('cancelled', '2024-01-01 09:00:00', $now));

        // Still live: contact details are needed to run the clinic.
        self::assertFalse($policy->isEligible('requested', '2024-01-01 09:00:00', $now));
        self::assertFalse($policy->isEligible('confirmed', '2024-01-01 09:00:00', $now));

        // Finished, but inside the window.
        self::assertFalse($policy->isEligible('completed', '2026-06-01 09:00:00', $now));

        // Unknown status is never eligible.
        self::assertFalse($policy->isEligible('archived', '2024-01-01 09:00:00', $now));
    }

    public function testAWindowShorterThanThirtyDaysIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RetentionPolicy(7);
    }
}
