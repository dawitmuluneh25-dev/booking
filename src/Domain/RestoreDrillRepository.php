<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What the restore drills actually proved, kept where the release gate can read it.
 *
 * The repository has no `update` and no `delete`. A drill record is evidence: rewriting one to say it
 * passed would be the single most damaging edit this codebase could make, so the interface does not
 * offer it.
 */
interface RestoreDrillRepository
{
    /**
     * @param array{
     *     archive: string,
     *     encrypted: bool,
     *     target: string,
     *     tables: int,
     *     rows: int,
     *     verdict: string,
     *     actor: string
     * } $drill
     */
    public function record(array $drill, string $verifiedAtUtc): void;

    /**
     * The most recent drill that passed, or null if none has.
     *
     * @return array{archive: string, encrypted: bool, target: string, tables: int, rows: int, verifiedAt: string}|null
     */
    public function latestSuccessful(?string $archive = null): ?array;

    /** How many drills exist at all, so a reader can tell "never run" from "never passed". */
    public function count(): int;
}
