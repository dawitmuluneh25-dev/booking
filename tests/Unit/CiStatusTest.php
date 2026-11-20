<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CiState;
use App\Support\CiStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The question this answers is "did CI pass for *this* commit", and every test below
 * is about one way of getting that wrong.
 *
 * The payloads are trimmed copies of real responses — same field names, same
 * `workflow_runs` envelope — so a change in the API's shape fails a test rather than
 * producing a confident wrong answer in a release note.
 */
final class CiStatusTest extends TestCase
{
    private const SHA = 'a5c0c1d8048e2cdb0d783b7f25289cc24078cd2d';

    public function testASuccessfulRunForTheCommitIsGreen(): void
    {
        $runs = [self::fixtureRun(self::SHA, 17, 'completed', 'success')];

        $verdict = CiStatus::fromPayload(self::payload($runs), self::SHA);

        self::assertSame(CiState::Green, $verdict->state);
        self::assertSame(17, $verdict->runNumber);
        self::assertSame(0, $verdict->exitCode());
        self::assertStringContainsString('green', $verdict->describe());
        self::assertStringContainsString('#17', $verdict->describe());
    }

    /**
     * `git log --oneline` prints seven characters, and that is what gets pasted into
     * `--sha=`. The first version compared strings exactly, so the abbreviation matched
     * nothing and the command answered "no run exists" for a commit whose build was
     * green — the same false negative as reading the newest run, arriving from the other
     * side. Abbreviated input is therefore resolved against the full `head_sha`.
     */
    public function testAnAbbreviatedShaResolvesToTheFullCommit(): void
    {
        $runs = [self::fixtureRun(self::SHA, 17, 'completed', 'success')];

        $verdict = CiStatus::fromPayload(self::payload($runs), 'a5c0c1d');

        self::assertSame(CiState::Green, $verdict->state);
        self::assertSame(17, $verdict->runNumber);
    }

    /**
     * An abbreviation is a prefix, not a similarity: a run for `a5c0c1d…` must not be
     * claimed by `a5c0c1e`, or the tool would confirm the wrong build.
     */
    public function testAnAbbreviationDoesNotMatchADifferentCommit(): void
    {
        $runs = [self::fixtureRun(self::SHA, 17, 'completed', 'success')];

        $verdict = CiStatus::fromPayload(self::payload($runs), 'a5c0c1e');

        self::assertSame(CiState::Absent, $verdict->state);
        self::assertSame(2, $verdict->exitCode());
    }

    /**
     * Six characters is not an abbreviation anyone uses; it is a typo or a truncated
     * paste. Treating it as a prefix would let it match a commit it does not name.
     */
    public function testAnImplausiblyShortShaMatchesNothing(): void
    {
        $runs = [self::fixtureRun(self::SHA, 17, 'completed', 'success')];

        $verdict = CiStatus::fromPayload(self::payload($runs), 'a5c0c1');

        self::assertSame(CiState::Absent, $verdict->state);
    }

    public function testAFailedRunIsRedAndExitsNonZero(): void
    {
        $runs = [self::fixtureRun(self::SHA, 7, 'completed', 'failure')];

        $verdict = CiStatus::fromPayload(self::payload($runs), self::SHA);

        self::assertSame(CiState::Red, $verdict->state);
        self::assertSame(1, $verdict->exitCode());
        self::assertStringContainsString('RED', $verdict->describe());
    }

    /**
     * A run that has not finished carries `status: in_progress` and a null conclusion.
     * Reading the null as "not failed" is how "green" gets invented before the build
     * has run a single test.
     */
    public function testARunThatHasNotFinishedIsPendingRatherThanGreen(): void
    {
        $runs = [self::fixtureRun(self::SHA, 18, 'in_progress', null)];

        $verdict = CiStatus::fromPayload(self::payload($runs), self::SHA);

        self::assertSame(CiState::Pending, $verdict->state);
        self::assertSame(3, $verdict->exitCode());
        self::assertStringNotContainsString('green', $verdict->describe());
    }

    /**
     * The one that matters most. The API returns newest first, so the top row is
     * almost always a run for a different commit — the previous push. A tool that
     * takes the top row reports yesterday's green for today's code, and does it most
     * often when the current commit has not been built at all.
     */
    public function testAGreenRunForAnotherCommitIsNotGreenForThisOne(): void
    {
        $payload = self::payload([
            self::fixtureRun('1111111111111111111111111111111111111111', 20, 'completed', 'success'),
            self::fixtureRun(self::SHA, 17, 'completed', 'success'),
        ]);

        $verdict = CiStatus::fromPayload(
            $payload,
            '2222222222222222222222222222222222222222',
        );

        self::assertSame(CiState::Absent, $verdict->state);
        self::assertSame(2, $verdict->exitCode());
        self::assertNull($verdict->runNumber);
        self::assertStringNotContainsString('green', $verdict->describe());
    }

    public function testNoRunAtAllIsAbsentRatherThanRed(): void
    {
        $verdict = CiStatus::fromPayload(self::payload([]), self::SHA);

        self::assertSame(CiState::Absent, $verdict->state);
        self::assertSame(2, $verdict->exitCode());
    }

    /**
     * A re-run leaves two rows for the same commit: the failed first attempt and the
     * green second one. The newest attempt is the answer, decided by run number rather
     * than by row order.
     */
    public function testARerunIsResolvedToTheNewestAttempt(): void
    {
        $payload = self::payload([
            self::fixtureRun(self::SHA, 19, 'completed', 'success'),
            self::fixtureRun(self::SHA, 18, 'completed', 'failure'),
        ]);

        $verdict = CiStatus::fromPayload($payload, self::SHA);

        self::assertSame(CiState::Green, $verdict->state);
        self::assertSame(19, $verdict->runNumber);
    }

    /**
     * `ci.yml` runs with `--fail-on-skipped`, so a skipped run is a failure inside the
     * thing being read. Reporting it as green would contradict the workflow.
     */
    public function testASkippedRunIsNotGreen(): void
    {
        $runs = [self::fixtureRun(self::SHA, 21, 'completed', 'skipped')];

        $verdict = CiStatus::fromPayload(self::payload($runs), self::SHA);

        self::assertSame(CiState::Red, $verdict->state);
    }

    public function testAResponseWithoutARunsArrayIsRefusedRatherThanReadAsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CiStatus::fromPayload(['message' => 'Not Found'], self::SHA);
    }

    public function testRunsOfADifferentWorkflowAreIgnored(): void
    {
        $payload = self::payload([
            self::fixtureRun(self::SHA, 30, 'completed', 'success', 'Deploy'),
        ]);

        $verdict = CiStatus::fromPayload($payload, self::SHA, 'CI');

        self::assertSame(CiState::Absent, $verdict->state);
    }

    /**
     * @param list<array<string, mixed>> $runs
     * @return array<string, mixed>
     */
    private static function payload(array $runs): array
    {
        return ['total_count' => count($runs), 'workflow_runs' => $runs];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixtureRun(
        string $sha,
        int $number,
        string $status,
        ?string $conclusion,
        string $workflow = 'CI',
    ): array {
        return [
            'id' => 44775676033,
            'name' => $workflow,
            'run_number' => $number,
            'head_sha' => $sha,
            'status' => $status,
            'conclusion' => $conclusion,
            'html_url' => 'https://github.com/dawitmuluneh25-dev/booking/actions/runs/' . $number,
            'updated_at' => '2026-11-20T16:41:35Z',
        ];
    }
}
