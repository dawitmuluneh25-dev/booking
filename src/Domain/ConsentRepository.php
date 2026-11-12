<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Persistence boundary for recorded consent discussions (§18.1).
 *
 * Separate from `ReferralRepository` because consent is its own record with its
 * own lifecycle: a referral's status says where the request is, while a consent
 * says what a patient and a clinician agreed, and the second survives the first
 * reaching a terminal state.
 *
 * Append-only by contract. `withdraw()` is the single permitted update, and it only
 * ever fills three columns on a row that was otherwise complete — nothing rewrites
 * what was discussed, by whom, or on whose authority.
 */
interface ConsentRepository
{
    public function record(ProcedureConsent $consent): ProcedureConsent;

    public function findById(int $id): ?ProcedureConsent;

    /**
     * The consent that currently stands: the row nothing supersedes.
     *
     * Newest-by-timestamp would be wrong after a correction for the same reason it
     * would be wrong for a clinical decision — the corrected row is not necessarily
     * the later one.
     */
    public function current(int $referralId): ?ProcedureConsent;

    /**
     * Every consent recorded against a referral, oldest first, with the recorder's
     * name. Withdrawal is visible on the row rather than removing it.
     *
     * @return list<array{consent: ProcedureConsent, recordedBy: string}>
     */
    public function history(int $referralId): array;

    /**
     * Record that an agreement no longer stands.
     *
     * The reason is required by the schema as well as by the command, because a
     * withdrawal without a stated reason is an unexplained gap in the record at the
     * point where somebody has to decide what happens to the procedure.
     */
    public function withdraw(int $consentId, Actor $actor, string $reason, string $occurredAtUtc): void;
}
