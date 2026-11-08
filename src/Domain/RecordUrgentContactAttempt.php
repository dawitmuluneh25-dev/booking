<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Record one attempt to reach somebody about an urgent case, and move the case
 * into `contacting` if it was only acknowledged.
 *
 * Refusals worth naming:
 *
 * - a telephone attempt with no number is not a record of anything, so it is
 *   refused rather than stored as an unattributable row (WF-06);
 * - a case that has not been acknowledged cannot be "being contacted", because
 *   nobody has claimed it yet (WF-05);
 * - a connected outcome without saying who was reached is refused; "connected"
 *   with no counterpart leaves the next shift guessing.
 */
final class RecordUrgentContactAttempt
{
    public function __construct(private readonly UrgentCaseRepository $repository)
    {
    }

    /**
     * @throws UrgentCaseNotFoundException the case does not exist
     * @throws UrgentTransitionException   the case is not ready for contact
     * @throws ValidationException         the attempt is not complete
     */
    /**
     * @param array<string, mixed> $input
     */
    public function execute(
        Actor $actor,
        int $caseId,
        array $input,
        ?string $nowUtc = null,
    ): UrgentCase {
        $case = $this->repository->findById($caseId);

        if ($case === null) {
            throw new UrgentCaseNotFoundException($caseId);
        }

        $contactRole = $this->text($input, 'contact_role');
        $channel = $this->text($input, 'channel') === '' ? 'telephone' : $this->text($input, 'channel');
        $outcome = $this->text($input, 'outcome');
        $phone = $this->text($input, 'contact_phone');
        $name = $this->text($input, 'contact_name');
        $notes = $this->text($input, 'notes');

        $errors = [];

        if (!in_array($contactRole, UrgentContactAttempt::ROLES, true)) {
            $errors['contact_role'] = 'Who were you trying to reach? '
                . implode(', ', UrgentContactAttempt::ROLES) . '.';
        }

        if (!in_array($channel, UrgentContactAttempt::CHANNELS, true)) {
            $errors['channel'] = 'Channel must be ' . implode(', ', UrgentContactAttempt::CHANNELS) . '.';
        }

        if (!in_array($outcome, UrgentContactAttempt::OUTCOMES, true)) {
            $errors['outcome'] = 'Outcome must be ' . implode(', ', UrgentContactAttempt::OUTCOMES) . '.';
        }

        if ($channel === 'telephone' && $phone === '') {
            $errors['contact_phone'] = 'Record the number you dialled.';
        }

        if (in_array($outcome, UrgentContactAttempt::CONNECTED, true) && $name === '') {
            $errors['contact_name'] = 'Record who you spoke to.';
        }

        if (mb_strlen($notes) > 2000) {
            $errors['notes'] = 'Keep the note under 2000 characters.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($case->coordination === UrgentCoordination::Unacknowledged) {
            throw UrgentTransitionException::forCoordination(
                $case->coordination,
                UrgentCoordination::Contacting,
                'Acknowledge the case before recording contact attempts.',
            );
        }

        $attempt = new UrgentContactAttempt(
            caseId: $case->id,
            attemptedByUserId: $actor->userId,
            contactRole: $contactRole,
            contactName: $name === '' ? null : $name,
            contactPhone: $phone === '' ? null : $phone,
            channel: $channel,
            outcome: $outcome,
            notes: $notes === '' ? null : $notes,
            attemptedAtUtc: $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );

        return $this->repository->recordContactAttempt($case, $attempt);
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
