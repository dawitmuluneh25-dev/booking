<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One recorded consent discussion (§18.1).
 *
 * Immutable: a correction is a new row naming the one it replaces, and a
 * withdrawal is a timestamp on the row that recorded the agreement. What was said,
 * by whom and on whose authority therefore survives whatever happened afterwards —
 * which is the property §20.4 asks for when it requires the record to be
 * reconstructible.
 *
 * This class deliberately offers no way to compute consent. It holds what a
 * clinician documented; software that could derive it would be replacing the
 * dialogue §18.1 describes.
 */
final class ProcedureConsent
{
    public function __construct(
        public readonly int $id,
        public readonly int $referralId,
        public readonly ConsentParticipant $participant,
        public readonly string $participantName,
        public readonly bool $authorityVerified,
        public readonly ?int $authorityVerifiedByUserId,
        public readonly ?string $authorityVerifiedAtUtc,
        public readonly bool $capacityAssessed,
        public readonly ?string $capacityNote,
        public readonly ?string $targetSite,
        public readonly string $informationVersion,
        public readonly string $informationLanguage,
        public readonly string $discussionRecord,
        public readonly ?string $questionsRecorded,
        public readonly bool $interpreterInvolved,
        public readonly ?string $interpreterLanguage,
        public readonly ConsentOutcome $outcome,
        public readonly string $attestationReference,
        public readonly int $responsiblePractitionerUserId,
        public readonly int $recordedByUserId,
        public readonly string $consentedAtUtc,
        public readonly ?string $withdrawnAtUtc = null,
        public readonly ?int $withdrawnByUserId = null,
        public readonly ?string $withdrawalReason = null,
        public readonly ?int $supersedesId = null,
    ) {
    }

    public function withIdentity(int $id): self
    {
        return new self(
            id: $id,
            referralId: $this->referralId,
            participant: $this->participant,
            participantName: $this->participantName,
            authorityVerified: $this->authorityVerified,
            authorityVerifiedByUserId: $this->authorityVerifiedByUserId,
            authorityVerifiedAtUtc: $this->authorityVerifiedAtUtc,
            capacityAssessed: $this->capacityAssessed,
            capacityNote: $this->capacityNote,
            targetSite: $this->targetSite,
            informationVersion: $this->informationVersion,
            informationLanguage: $this->informationLanguage,
            discussionRecord: $this->discussionRecord,
            questionsRecorded: $this->questionsRecorded,
            interpreterInvolved: $this->interpreterInvolved,
            interpreterLanguage: $this->interpreterLanguage,
            outcome: $this->outcome,
            attestationReference: $this->attestationReference,
            responsiblePractitionerUserId: $this->responsiblePractitionerUserId,
            recordedByUserId: $this->recordedByUserId,
            consentedAtUtc: $this->consentedAtUtc,
            withdrawnAtUtc: $this->withdrawnAtUtc,
            withdrawnByUserId: $this->withdrawnByUserId,
            withdrawalReason: $this->withdrawalReason,
            supersedesId: $this->supersedesId,
        );
    }

    /**
     * The same record, withdrawn. Only the three withdrawal columns move: what was
     * discussed, by whom and on whose authority is not rewritable, which is why this
     * returns a copy rather than offering setters.
     */
    public function withWithdrawal(string $atUtc, int $byUserId, string $reason): self
    {
        return new self(
            id: $this->id,
            referralId: $this->referralId,
            participant: $this->participant,
            participantName: $this->participantName,
            authorityVerified: $this->authorityVerified,
            authorityVerifiedByUserId: $this->authorityVerifiedByUserId,
            authorityVerifiedAtUtc: $this->authorityVerifiedAtUtc,
            capacityAssessed: $this->capacityAssessed,
            capacityNote: $this->capacityNote,
            targetSite: $this->targetSite,
            informationVersion: $this->informationVersion,
            informationLanguage: $this->informationLanguage,
            discussionRecord: $this->discussionRecord,
            questionsRecorded: $this->questionsRecorded,
            interpreterInvolved: $this->interpreterInvolved,
            interpreterLanguage: $this->interpreterLanguage,
            outcome: $this->outcome,
            attestationReference: $this->attestationReference,
            responsiblePractitionerUserId: $this->responsiblePractitionerUserId,
            recordedByUserId: $this->recordedByUserId,
            consentedAtUtc: $this->consentedAtUtc,
            withdrawnAtUtc: $atUtc,
            withdrawnByUserId: $byUserId,
            withdrawalReason: $reason,
            supersedesId: $this->supersedesId,
        );
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawnAtUtc !== null;
    }

    /** Whether the agreement still stands: given, not withdrawn, on this row. */
    public function isStandingAgreement(): bool
    {
        return $this->outcome->isAgreement() && !$this->isWithdrawn();
    }

    /**
     * What the current record says about this referral, in one phrase.
     *
     * Distinguishes an agreement that still stands from one withdrawn, and an
     * unverified representative from a verified one, because those are the four
     * states a reader has to be able to tell apart before a procedure.
     */
    public function standingLabel(): string
    {
        if ($this->outcome === ConsentOutcome::Agreed) {
            return $this->isWithdrawn() ? 'Agreed, then withdrawn' : 'Agreed';
        }

        return $this->outcome->label();
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::intOf($row['id'] ?? null),
            referralId: self::intOf($row['referral_id'] ?? null),
            participant: ConsentParticipant::tryFrom(self::stringOf($row['participant_relationship'] ?? null))
                ?? ConsentParticipant::Other,
            participantName: self::stringOf($row['participant_name'] ?? null),
            authorityVerified: self::intOf($row['authority_verified'] ?? null) === 1,
            authorityVerifiedByUserId: is_numeric($row['authority_verified_by_user_id'] ?? null)
                ? (int) $row['authority_verified_by_user_id']
                : null,
            authorityVerifiedAtUtc: self::nullable($row['authority_verified_at'] ?? null),
            capacityAssessed: self::intOf($row['capacity_assessed'] ?? null) === 1,
            capacityNote: self::nullable($row['capacity_note'] ?? null),
            targetSite: self::nullable($row['target_site'] ?? null),
            informationVersion: self::stringOf($row['information_version'] ?? null),
            informationLanguage: self::stringOf($row['information_language'] ?? null),
            discussionRecord: self::stringOf($row['discussion_record'] ?? null),
            questionsRecorded: self::nullable($row['questions_recorded'] ?? null),
            interpreterInvolved: self::intOf($row['interpreter_involved'] ?? null) === 1,
            interpreterLanguage: self::nullable($row['interpreter_language'] ?? null),
            outcome: ConsentOutcome::tryFrom(self::stringOf($row['outcome'] ?? null))
                ?? ConsentOutcome::UnableToConsent,
            attestationReference: self::stringOf($row['attestation_reference'] ?? null),
            responsiblePractitionerUserId: self::intOf($row['responsible_practitioner_user_id'] ?? null),
            recordedByUserId: self::intOf($row['recorded_by_user_id'] ?? null),
            consentedAtUtc: self::stringOf($row['consented_at'] ?? null),
            withdrawnAtUtc: self::nullable($row['withdrawn_at'] ?? null),
            withdrawnByUserId: is_numeric($row['withdrawn_by_user_id'] ?? null)
                ? (int) $row['withdrawn_by_user_id']
                : null,
            withdrawalReason: self::nullable($row['withdrawal_reason'] ?? null),
            supersedesId: is_numeric($row['supersedes_consent_id'] ?? null)
                ? (int) $row['supersedes_consent_id']
                : null,
        );
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function stringOf(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullable(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
