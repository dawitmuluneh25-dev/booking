<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bootstrap\Config;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The retention window is a promise the privacy notice makes on the system's
 * behalf. It is expressed in days because that is what the sweeper takes, but
 * the governing period is directed in years, and the two must not drift apart.
 *
 * This test exists because the value sat at two years while the direction said
 * five, and nothing in the suite noticed. It happened a second time in the other
 * direction: the code held five years while DEC-2026-11-20-02 approved seven,
 * which would have disposed of clinical records two years before the directive
 * allows. Both failures are the same failure — a number in code outliving the
 * decision that set it.
 */
final class RetentionWindowTest extends TestCase
{
    public function testTheDefaultWindowIsSevenYears(): void
    {
        self::assertSame(2557, Config::DEFAULT_RETENTION_DAYS);
    }

    public function testTheSampleEnvironmentDeclaresTheSameWindowAsTheCode(): void
    {
        $sample = dirname(__DIR__, 2) . '/.env.example';
        $contents = file_get_contents($sample);

        self::assertIsString($contents, 'The sample environment file must be readable.');
        self::assertSame(
            1,
            preg_match('/^RETENTION_DAYS=(\d+)$/m', $contents, $matches),
            'The sample environment must declare RETENTION_DAYS on its own line.',
        );

        // Spelled as a null-coalesce so the captured group is narrowed by the
        // assertion below rather than assumed to exist after a successful match.
        $declared = $matches[1] ?? null;
        self::assertNotNull($declared, 'The sample environment must state a number of days.');

        self::assertSame(
            Config::DEFAULT_RETENTION_DAYS,
            (int) $declared,
            'The sample environment and the code must state the same retention window.',
        );
    }

    public function testTheWindowCoversTheLongestSevenYearSpan(): void
    {
        // 2028 and 2032 both hold a 29 February, so this span is the worst case:
        // a shorter window would end before seven years had passed.
        $start = new DateTimeImmutable('2027-02-25 00:00:00', new DateTimeZone('UTC'));
        $end = new DateTimeImmutable('2034-02-25 00:00:00', new DateTimeZone('UTC'));

        $days = (int) $start->diff($end)->days;

        self::assertSame(2557, $days, 'This span is the worst case for a seven-year window.');
        self::assertGreaterThanOrEqual(
            $days,
            Config::DEFAULT_RETENTION_DAYS,
            'The default must not end before the longest seven-year span has passed.',
        );

        // Seven years of 365 days, and the six-year figure with one leap day,
        // both fall short of the span the notice promises.
        self::assertLessThan($days, 7 * 365);
        self::assertLessThan($days, 2556);
    }
}
