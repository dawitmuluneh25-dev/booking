<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The events of a procedure day (§15), as opposed to the room's states.
 *
 * `AppointmentStatus` answers "where is this case in the room workflow". A
 * milestone answers "what happened, when, and who recorded it". The two are
 * related but not the same record, and this enum keeps them apart on purpose:
 *
 * - the status is a fact about now, and is superseded by the next status;
 * - a milestone is a fact about a moment, and is never superseded at all.
 *
 * Three of the five walk the appointment through states the machine already
 * permits (`statusPath()`), because recording "the procedure started" and leaving
 * the appointment reading `ready` would be two records disagreeing about the same
 * minute. The other two — a consent check and the end of recovery — have no state
 * of their own and must not invent one.
 *
 * The order in `predecessors()` is what AC-4 means by "out of order": a milestone
 * whose predecessors are missing can still be recorded, because real days do run
 * out of order, but only with a reason, and the reason is stored with the record.
 */
enum ProcedureMilestone: string
{
    case Arrival = 'arrival';
    case ConsentVerified = 'consent_verified';
    case ProcedureStarted = 'procedure_started';
    case ProcedureCompleted = 'procedure_completed';
    case RecoveryCompleted = 'recovery_completed';

    public function label(): string
    {
        return match ($this) {
            self::Arrival => 'Patient arrival',
            self::ConsentVerified => 'Consent verified',
            self::ProcedureStarted => 'Procedure started',
            self::ProcedureCompleted => 'Procedure completed',
            self::RecoveryCompleted => 'Recovery completed',
        };
    }

    /**
     * Who may record it.
     *
     * Arrival is a desk fact and follows the desk permission that already governs
     * checking a patient in (`RecordPatientArrival`). Everything else is a
     * procedure-day act and follows the coordination permission — reception may
     * see the day, but "the procedure started" is not a sentence reception writes.
     *
     * The administrator holds neither: Decision 5 leaves the administrator
     * technical authority only.
     */
    public function requiredPermission(): Permission
    {
        return $this === self::Arrival
            ? Permission::BookingsManage
            : Permission::ProcedureCoordinate;
    }

    /**
     * What ought to have happened before this.
     *
     * Used for the out-of-order rule only. It is deliberately not a hard gate:
     * §15 describes a day that can begin with a patient already on the table.
     *
     * @return list<self>
     */
    public function predecessors(): array
    {
        return match ($this) {
            self::Arrival => [],
            self::ConsentVerified => [self::Arrival],
            self::ProcedureStarted => [self::Arrival, self::ConsentVerified],
            self::ProcedureCompleted => [self::ProcedureStarted],
            self::RecoveryCompleted => [self::ProcedureCompleted],
        };
    }

    /**
     * The states this milestone may be recorded from.
     *
     * Not the same question as `statusPath()`: this says how far along the
     * appointment must already be, while the path says what to do next. Recording
     * an arrival on a case that is already in progress is not a late arrival, it is
     * a different event, and the refusal says so.
     *
     * @return list<AppointmentStatus>
     */
    public function allowedFrom(): array
    {
        return match ($this) {
            self::Arrival => [AppointmentStatus::Scheduled, AppointmentStatus::Confirmed],
            self::ConsentVerified => [
                AppointmentStatus::CheckedIn,
                AppointmentStatus::PreprocedureVerification,
                AppointmentStatus::Ready,
            ],
            self::ProcedureStarted => [
                AppointmentStatus::CheckedIn,
                AppointmentStatus::PreprocedureVerification,
                AppointmentStatus::Ready,
            ],
            self::ProcedureCompleted => [AppointmentStatus::InProgress],
            // Recovery is observed after the procedure. A case that was deferred or
            // abandoned has no recovery to complete, and inventing a state for one
            // would put a room back in use that nobody is in.
            self::RecoveryCompleted => [AppointmentStatus::Completed],
        };
    }

    /**
     * The states this milestone walks the appointment through, in order.
     *
     * Every step is an edge `AppointmentStatus::allowedTransitions()` already
     * permits; the command applies them one at a time and never jumps. Steps
     * already passed are skipped, so a case that is already `ready` moves once.
     *
     * `procedure_started` carries the whole chain because nothing in this release
     * sets `preprocedure_verification` or `ready` — those two steps are the
     * readiness checklist (BL-D2, §14), which is a separate slice. Until it exists,
     * the operator's act of starting is the only evidence those steps happened, and
     * leaving the appointment reading `checked_in` because a checklist that does not
     * exist was not ticked would make the record wrong about the room.
     *
     * @return list<AppointmentStatus>
     */
    public function statusPath(): array
    {
        return match ($this) {
            self::Arrival => [AppointmentStatus::CheckedIn],
            self::ConsentVerified => [],
            self::ProcedureStarted => [
                AppointmentStatus::PreprocedureVerification,
                AppointmentStatus::Ready,
                AppointmentStatus::InProgress,
            ],
            self::ProcedureCompleted => [AppointmentStatus::Completed],
            self::RecoveryCompleted => [],
        };
    }

    /** Only a consent check has a consent record to name. */
    public function namesConsentRecord(): bool
    {
        return $this === self::ConsentVerified;
    }
}
