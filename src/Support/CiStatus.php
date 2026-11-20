<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Reads a GitHub Actions runs payload and answers one question about one commit.
 *
 * The rule that matters — and the reason this is a class rather than three lines in a
 * shell script — is that a run only counts if its `head_sha` is **exactly** the commit
 * asked about. Matching "the most recent run" is the natural shortcut and it produces
 * the worst possible answer: a green badge for a commit that was never built, quoted
 * in a release note. The API returns the newest first, so that shortcut is always
 * available and always wrong by one push.
 *
 * Re-runs of the same commit are resolved by taking the highest run number, which is
 * the newest attempt rather than whichever row happened to arrive first.
 */
final class CiStatus
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload, string $sha, string $workflow = 'CI'): CiVerdict
    {
        if (!isset($payload['workflow_runs']) || !is_array($payload['workflow_runs'])) {
            throw new InvalidArgumentException(
                'The response has no workflow_runs array. That is a change in the API or an error body, '
                . 'not an empty history.'
            );
        }

        $match = null;

        foreach ($payload['workflow_runs'] as $run) {
            if (!is_array($run)) {
                continue;
            }

            $runSha = isset($run['head_sha']) && is_string($run['head_sha']) ? $run['head_sha'] : '';
            $runName = isset($run['name']) && is_string($run['name']) ? $run['name'] : '';

            if ($runSha !== $sha || $runName !== $workflow) {
                continue;
            }

            if ($match === null || self::runNumber($run) > self::runNumber($match)) {
                $match = $run;
            }
        }

        if ($match === null) {
            return new CiVerdict(CiState::Absent, $sha, $workflow);
        }

        $status = isset($match['status']) && is_string($match['status']) ? $match['status'] : '';
        $conclusion = isset($match['conclusion']) && is_string($match['conclusion']) ? $match['conclusion'] : null;

        $url = isset($match['html_url']) && is_string($match['html_url']) ? $match['html_url'] : null;
        $completedAt = isset($match['updated_at']) && is_string($match['updated_at'])
            ? $match['updated_at']
            : null;

        // Anything not finished is pending, whatever the conclusion field happens to
        // hold: GitHub leaves it null until the run completes, and a null conclusion
        // read as "not failed" is how a green gets invented.
        $state = $status === 'completed'
            ? self::stateFor($conclusion)
            : CiState::Pending;

        return new CiVerdict(
            state: $state,
            sha: $sha,
            workflow: $workflow,
            runNumber: self::runNumber($match),
            url: $url,
            completedAt: $completedAt,
        );
    }

    /**
     * Only `success` is green. Everything else — failure, cancelled, timed out,
     * action required, startup failure, skipped — is a build the release cannot claim.
     * `skipped` counts against the build rather than for it because the workflow runs
     * with `--fail-on-skipped`: a skipped test is a failure there, so calling it green
     * here would contradict the thing being reported on.
     */
    private static function stateFor(?string $conclusion): CiState
    {
        return $conclusion === 'success' ? CiState::Green : CiState::Red;
    }

    /**
     * @param array<string, mixed> $run
     */
    private static function runNumber(array $run): int
    {
        $number = $run['run_number'] ?? 0;

        return is_int($number) ? $number : (is_numeric($number) ? (int) $number : 0);
    }
}
