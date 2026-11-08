<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The appointment lifecycle, which is a different lifecycle from the referral's
 * (WF-15).
 *
 * Only the states needed to create and hold a booking are implemented here;
 * the room workflow — checked in, in progress, completed, no show — belongs to
 * Phase 5 where the staff screens and their transitions are written. The
 * vocabulary already matches `ck_appointments_status` so that phase adds
 * transitions rather than renaming states.
 */
enum AppointmentStatus: string
{
    case Held = 'held';
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case PreprocedureVerification = 'preprocedure_verification';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Deferred = 'deferred';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Held',
            self::Scheduled => 'Scheduled',
            self::Confirmed => 'Confirmed',
            self::CheckedIn => 'Checked in',
            self::PreprocedureVerification => 'Pre-procedure verification',
            self::Ready => 'Ready',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'Did not attend',
            self::Deferred => 'Deferred',
        };
    }

    /** A state that occupies resources. */
    public function holdsResources(): bool
    {
        return $this !== self::Cancelled && $this !== self::NoShow;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * The room workflow, kept here rather than in the phase-5 screens so the staff
     * commands that already exist cannot invent their own edges.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Held => [self::Scheduled, self::Cancelled],
            self::Scheduled => [self::Confirmed, self::CheckedIn, self::Cancelled, self::NoShow],
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::PreprocedureVerification, self::Cancelled, self::NoShow],
            self::PreprocedureVerification => [self::Ready, self::Cancelled, self::Deferred],
            self::Ready => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::Deferred],
            self::Deferred => [self::Scheduled, self::Cancelled],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
