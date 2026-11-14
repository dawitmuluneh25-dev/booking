<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Record one procedure-day event, and move the appointment with it (§15, AC-4).
 *
 * Four refusals are deliberate, and each names what is missing:
 *
 * 1. **The actor may not record this kind.** Arrival follows the desk permission;
 *    the rest follow procedure coordination. The administrator holds neither.
 * 2. **The appointment is not at the right point in its workflow.** Recording an
 *    arrival on a case already in progress is not a late arrival — it is a
 *    different event, and the refusal says which states would have accepted it.
 * 3. **The consent check has nothing to check.** `consent_verified` names the
 *    consent record it verified, and refuses to assert "verified" when the referral
 *    carries no standing agreement. This is the same position AC-009 takes about
 *    approval: a claim the record cannot support is not a record.
 * 4. **The event is out of sequence and no reason was given.** AC-4 permits an
 *    out-of-order record *with* a reason and forbids one without. A real day can
 *    begin with the patient already on the table; a record of that day has to say
 *    so rather than pretend the sequence held.
 *
 * What this command does *not* do is enforce the readiness checklist. The §14
 * items, their owners, stop classifications and versions are BL-D2; this slice
 * records what happened, and BL-D2 will sit in front of `procedure_started` and
 * refuse it while a HARD STOP item is outstanding. Writing a checklist here would
 * have meant inventing sixty-one items without their approved owners.
 */
final class RecordProcedureMilestone
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly ProcedureMilestoneRepository $milestones,
        private readonly ConsentRepository $consents,
        private readonly Validator $validator,
        private readonly Transaction $transaction,
        private readonly AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input note, out_of_order_reason
     *
     * @throws AuthorisationException
     * @throws AppointmentNotFoundException
     * @throws ProcedureMilestoneException
     * @throws ValidationException
     */
    public function execute(
        Actor $actor,
        string $reference,
        ProcedureMilestone $kind,
        array $input,
        ?string $nowUtc = null,
    ): Appointment {
        if (!$actor->can($kind->requiredPermission())) {
            throw new AuthorisationException($actor->role, $kind->requiredPermission());
        }

        $appointment = $this->appointments->findByReference(trim($reference));

        if ($appointment === null) {
            throw new AppointmentNotFoundException($reference);
        }

        $recorded = $this->milestones->forAppointment($appointment->id);
        $consentId = $kind->namesConsentRecord() ? $this->standingConsentId($appointment) : null;

        $this->assertNotAlreadyRecorded($recorded, $kind, $consentId);

        if (!in_array($appointment->status, $kind->allowedFrom(), true)) {
            throw ProcedureMilestoneException::notNow($kind, $appointment->status);
        }

        $note = $this->text($input, 'note');
        $reason = $this->text($input, 'out_of_order_reason');

        $errors = $this->validator->validate(['note' => $note], ['note' => 'max:500']);

        $missing = $this->missingPredecessors($recorded, $kind);
        $outOfOrder = $missing !== [];

        if ($outOfOrder) {
            $errors += $this->validator->validate(
                ['out_of_order_reason' => $reason],
                ['out_of_order_reason' => 'required|min:10|max:255'],
            );

            if (isset($errors['out_of_order_reason'])) {
                $errors['out_of_order_reason'] = $this->outOfOrderMessage(
                    $kind,
                    $missing,
                    (string) $errors['out_of_order_reason'],
                );
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $occurredAt = $nowUtc ?? gmdate('Y-m-d H:i:s');

        return $this->transaction->run(function () use (
            $actor,
            $appointment,
            $kind,
            $note,
            $consentId,
            $outOfOrder,
            $reason,
            $occurredAt,
        ): Appointment {
            $current = $appointment;
            $steps = $this->remainingPath($current->status, $kind);

            foreach ($steps as $step) {
                $current = $this->appointments->transition($current, $step, $actor, null, $occurredAt);
            }

            $stored = $this->milestones->record(new ProcedureMilestoneRecord(
                id: 0,
                appointmentId: $appointment->id,
                kind: $kind,
                occurredAtUtc: $occurredAt,
                recordedByUserId: $actor->userId,
                recordedByRole: $actor->role,
                note: $note === '' ? null : $note,
                consentId: $consentId,
                resultingStatus: $steps === [] ? null : $current->status,
                outOfOrderReason: $outOfOrder ? $reason : null,
                createdAtUtc: $occurredAt,
            ));

            $this->audit->record(AuditEntry::byActor(
                actor: $actor,
                action: AuditAction::ProcedureMilestoneRecorded,
                objectType: 'appointment',
                objectRef: $appointment->reference,
                after: [
                    'milestone' => $kind->value,
                    'milestone_id' => $stored->id,
                    'occurred_at' => $occurredAt,
                    'resulting_status' => $steps === [] ? null : $current->status->value,
                    'out_of_order' => $outOfOrder,
                ],
                occurredAtUtc: $occurredAt,
            ));

            return $current;
        });
    }

    /**
     * The consent the point-of-care check is a check *of*.
     *
     * @throws ProcedureMilestoneException when there is nothing to verify
     */
    private function standingConsentId(Appointment $appointment): int
    {
        if ($appointment->referralId <= 0) {
            throw ProcedureMilestoneException::noReferral();
        }

        $consent = $this->consents->current($appointment->referralId);

        if ($consent === null) {
            throw ProcedureMilestoneException::noConsentRecord();
        }

        if (!$consent->isStandingAgreement()) {
            throw ProcedureMilestoneException::consentNotStanding($consent->standingLabel());
        }

        return $consent->id;
    }

    /**
     * The last record is the one that matters: an event legitimately repeats later
     * in the day (a deferred case restarted, a consent discussion repeated), but a
     * second identical entry immediately after the first is a double submission with
     * nothing in between to explain it.
     *
     * @param list<ProcedureMilestoneRecord> $recorded
     */
    private function assertNotAlreadyRecorded(
        array $recorded,
        ProcedureMilestone $kind,
        ?int $consentId,
    ): void {
        $last = $recorded === [] ? null : $recorded[count($recorded) - 1];

        if ($last === null || $last->kind !== $kind) {
            return;
        }

        // A consent check against a *different* consent record is a new statement
        // about a different agreement, not a repeat.
        if ($kind->namesConsentRecord() && $last->consentId !== $consentId) {
            return;
        }

        throw ProcedureMilestoneException::alreadyRecorded($last->kind, $last->occurredAtUtc, $last->recordedByRole);
    }

    /**
     * @param list<ProcedureMilestoneRecord> $recorded
     *
     * @return list<ProcedureMilestone>
     */
    private function missingPredecessors(array $recorded, ProcedureMilestone $kind): array
    {
        $kinds = array_map(
            static fn (ProcedureMilestoneRecord $record): ProcedureMilestone => $record->kind,
            $recorded,
        );

        return array_values(array_filter(
            $kind->predecessors(),
            static fn (ProcedureMilestone $predecessor): bool => !in_array($predecessor, $kinds, true),
        ));
    }

    /**
     * The states still to walk, given where the appointment already is.
     *
     * Every step is an edge the transition table permits, applied in order. A step
     * the appointment has already passed is skipped, so a case already at `ready`
     * moves once rather than being refused for not being at `checked_in`.
     *
     * @return list<AppointmentStatus>
     */
    private function remainingPath(AppointmentStatus $current, ProcedureMilestone $kind): array
    {
        $path = $kind->statusPath();
        $index = array_search($current, $path, true);

        if ($index === false) {
            return $path;
        }

        return array_slice($path, $index + 1);
    }

    /**
     * @param list<ProcedureMilestone> $missing
     */
    private function outOfOrderMessage(ProcedureMilestone $kind, array $missing, string $base): string
    {
        $names = implode(' and ', array_map(
            static fn (ProcedureMilestone $case): string => $case->label(),
            $missing,
        ));

        // A missing reason is the common case and deserves the explanation; a reason
        // that is merely too short keeps the validator's own sentence.
        if (str_contains($base, 'required')) {
            return $kind->label() . ' is being recorded without ' . $names
                . '. Give a reason of at least ten characters for recording it out of '
                . 'sequence — or record the missing event first.';
        }

        return $base;
    }

    /** @param array<string, mixed> $input */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
