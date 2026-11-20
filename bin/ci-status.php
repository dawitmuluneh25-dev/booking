#!/usr/bin/env php
<?php

/**
 * Asks GitHub whether CI passed for a commit, and answers with an exit code.
 *
 *     bin/ci-status                 # the commit at HEAD
 *     bin/ci-status --sha=<sha>     # a specific commit
 *     bin/ci-status --wait=300      # poll until it finishes, up to five minutes
 *     bin/ci-status --json          # machine-readable
 *
 * Exit codes: 0 green · 1 red · 2 no run, or the API could not be read · 3 still running.
 *
 * The distinction between 1 and 2 is the whole point. A single `curl` cannot make it:
 * when the network fails the natural output is an empty string, and an empty string
 * recorded in a release note reads as "fine". Seven commits were logged that way. This
 * command refuses to guess — anything it cannot read exits 2, never 0.
 *
 * The token, if present in GITHUB_TOKEN, raises the rate limit from 60 requests an hour
 * to 5,000. It is read from the environment, never written down, and never printed.
 */

declare(strict_types=1);

// This file is a script, not a library: declaring a symbol and doing something are
// the same act here, and separating them would break the one-command contract the
// release ritual depends on.
// phpcs:disable PSR1.Files.SideEffects

use App\Support\CiState;
use App\Support\CiStatus;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(2);
}

require $autoload;

const CI_STATUS_PER_PAGE = 5;
const CI_STATUS_ATTEMPTS = 3;
const CI_STATUS_POLL_SECONDS = 15;
const CI_STATUS_TIMEOUT_SECONDS = 30;

/** @var array<string, mixed> $options */
$options = [
    'sha' => null,
    'workflow' => 'CI',
    'repo' => null,
    'wait' => 0,
    'json' => false,
    'help' => false,
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--json') {
        $options['json'] = true;
        continue;
    }

    if ($argument === '--help' || $argument === '-h') {
        $options['help'] = true;
        continue;
    }

    if (preg_match('/^--(sha|workflow|repo|wait)=(.+)$/', (string) $argument, $matches) === 1) {
        $options[$matches[1]] = $matches[1] === 'wait' ? (int) $matches[2] : $matches[2];
        continue;
    }

    fwrite(STDERR, 'Unknown argument: ' . (string) $argument . "\n");
    exit(2);
}

if ($options['help'] === true) {
    fwrite(STDOUT, <<<'TEXT'
        Usage: bin/ci-status [--sha=<sha>] [--workflow=CI] [--repo=owner/name] [--wait=<seconds>] [--json]

          --sha       commit to ask about (default: git rev-parse HEAD)
          --workflow  workflow name to match (default: CI)
          --repo      owner/name (default: parsed from the origin remote)
          --wait      poll every 15 s until finished, up to this many seconds
          --json      print the verdict as JSON

        Exit codes: 0 green · 1 red · 2 no run or unreadable · 3 still running.

        TEXT);
    exit(0);
}

$sha = is_string($options['sha']) && $options['sha'] !== '' ? $options['sha'] : gitValue($root, ['rev-parse', 'HEAD']);

if ($sha === null || preg_match('/^[0-9a-f]{7,40}$/', $sha) !== 1) {
    fwrite(STDERR, "Cannot determine the commit to check. Pass --sha=<sha>.\n");
    exit(2);
}

// `?head_sha=` is applied by GitHub, not by us: an abbreviated sha returns an empty
// list, so the parser never sees the run that exists and the command answers "no run"
// for a green commit. Expand it locally before asking. Every commit a release gate
// cares about is in this clone, and `^{commit}` also resolves a tag to its commit.
if (strlen($sha) < 40) {
    $expanded = gitValue($root, ['rev-parse', '--verify', $sha . '^{commit}']);

    if ($expanded === null || strlen($expanded) !== 40) {
        fwrite(STDERR, "Cannot resolve $sha to a commit in this repository.\n");
        fwrite(STDERR, "Pass the full 40-character sha if the commit exists only on the remote.\n");
        exit(2);
    }

    $sha = $expanded;
}

$repo = is_string($options['repo']) && $options['repo'] !== ''
    ? $options['repo']
    : repoFromRemote($root);

if ($repo === null) {
    fwrite(STDERR, "Cannot determine the repository. Pass --repo=owner/name.\n");
    exit(2);
}

$workflow = is_string($options['workflow']) ? $options['workflow'] : 'CI';
$wait = is_int($options['wait']) ? $options['wait'] : 0;
$token = getenv('GITHUB_TOKEN');
$token = is_string($token) && $token !== '' ? $token : null;

$deadline = microtime(true) + max(0, $wait);

while (true) {
    $response = requestRuns($repo, $sha, $token);

    if ($response['ok'] !== true) {
        fwrite(STDERR, 'CI: cannot read the API — ' . $response['error'] . "\n");
        fwrite(STDERR, "CI: this is not a failure of the build. Try again, or check the run URL by hand.\n");
        exit(2);
    }

    try {
        $verdict = CiStatus::fromPayload($response['payload'], $sha, $workflow);
    } catch (InvalidArgumentException $exception) {
        fwrite(STDERR, 'CI: cannot read the response — ' . $exception->getMessage() . "\n");
        exit(2);
    }

    // A run that has not finished is not an answer, and neither is a run that GitHub
    // has not created yet: `--wait` issued in the same second as the push would
    // otherwise report "no run" and be believed. Both are retried until the deadline;
    // everything else — green, red, or a workflow that genuinely does not exist — is
    // final on the first read.
    $unfinished = $verdict->state === CiState::Pending
        || ($verdict->state === CiState::Absent && $wait > 0);

    if (!$unfinished || microtime(true) >= $deadline) {
        break;
    }

    sleep(CI_STATUS_POLL_SECONDS);
}

if ($options['json'] === true) {
    fwrite(STDOUT, (string) json_encode($verdict->toArray(), JSON_PRETTY_PRINT) . "\n");
    exit($verdict->exitCode());
}

fwrite(STDOUT, $verdict->describe() . "\n");

if ($verdict->url !== null) {
    fwrite(STDOUT, $verdict->url . "\n");
}

if ($verdict->state === CiState::Absent) {
    fwrite(
        STDOUT,
        sprintf("        queried %s by head_sha — a commit that was never pushed has no run\n", $repo)
    );
}

exit($verdict->exitCode());

/**
 * @param list<string> $arguments
 */
function gitValue(string $root, array $arguments): ?string
{
    $command = 'git -C ' . escapeshellarg($root) . ' ' . implode(' ', array_map('escapeshellarg', $arguments));
    $output = [];
    $status = 0;

    exec($command . ' 2>&1', $output, $status);

    if ($status !== 0 || $output === []) {
        return null;
    }

    $value = trim((string) $output[0]);

    return $value === '' ? null : $value;
}

/**
 * `https://github.com/owner/name.git` and `git@github.com:owner/name.git` both mean
 * the same repository, and both are in use depending on how the clone was made.
 */
function repoFromRemote(string $root): ?string
{
    $url = gitValue($root, ['remote', 'get-url', 'origin']);

    if ($url === null) {
        return null;
    }

    if (preg_match('#github\.com[:/]+([^/]+)/(.+?)(?:\.git)?$#', $url, $matches) !== 1) {
        return null;
    }

    return $matches[1] . '/' . $matches[2];
}

/**
 * @return array{ok: bool, payload: array<string, mixed>, error: string}
 */
function requestRuns(string $repo, string $sha, ?string $token): array
{
    $url = sprintf(
        'https://api.github.com/repos/%s/actions/runs?head_sha=%s&per_page=%d',
        $repo,
        $sha,
        CI_STATUS_PER_PAGE
    );

    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: booking-ci-status',
    ];

    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $lastError = 'no attempt was made';

    for ($attempt = 1; $attempt <= CI_STATUS_ATTEMPTS; $attempt++) {
        $response = httpGet($url, $headers);

        if ($response['ok'] === true) {
            $decoded = json_decode($response['body'], true);

            if (!is_array($decoded)) {
                return ['ok' => false, 'payload' => [], 'error' => 'the response was not JSON'];
            }

            /** @var array<string, mixed> $decoded */
            return ['ok' => true, 'payload' => $decoded, 'error' => ''];
        }

        $lastError = $response['error'];

        if ($attempt < CI_STATUS_ATTEMPTS) {
            sleep($attempt === 1 ? 2 : 5);
        }
    }

    return ['ok' => false, 'payload' => [], 'error' => $lastError];
}

/**
 * @param list<string> $headers
 * @return array{ok: bool, body: string, error: string}
 */
function httpGet(string $url, array $headers): array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);

        if ($handle === false) {
            return ['ok' => false, 'body' => '', 'error' => 'could not initialise curl'];
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => CI_STATUS_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            // Abort a transfer that has stalled rather than spending the whole budget
            // on a connection that is delivering bytes at dial-up speed. Seen on
            // 20 November: three attempts, each cut off mid-body at ~80 KB.
            CURLOPT_LOW_SPEED_LIMIT => 1000,
            CURLOPT_LOW_SPEED_TIME => 10,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body)) {
            return ['ok' => false, 'body' => '', 'error' => $error !== '' ? $error : 'no response'];
        }

        if ($status !== 200) {
            return ['ok' => false, 'body' => '', 'error' => 'HTTP ' . $status . ' from ' . $url];
        }

        return ['ok' => true, 'body' => $body, 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => CI_STATUS_TIMEOUT_SECONDS,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        return ['ok' => false, 'body' => '', 'error' => 'the request failed (no curl extension available)'];
    }

    $statusLine = $http_response_header[0] ?? '';
    $status = preg_match('#\s(\d{3})\s#', (string) $statusLine, $matches) === 1 ? (int) $matches[1] : 0;

    if ($status !== 200) {
        return ['ok' => false, 'body' => '', 'error' => 'HTTP ' . $status . ' from ' . $url];
    }

    return ['ok' => true, 'body' => $body, 'error' => ''];
}
