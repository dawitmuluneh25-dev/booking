<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Clinical status of an urgent intussusception case.
 *
 * The vocabulary is the one the database specification declares for
 * `urgent_cases.urgency_status`, and it is deliberately not the elective
 * referral vocabulary: an urgent case is never `approved_for_scheduling` and
 * never becomes an appointment (WF-04, WF-15).
 *
 * Transitions encoded here are only those the workflow documents support:
 * a case is received, clinically reviewed, then coordinated, refused or sent
 * elsewhere, and every branch ends closed. The elective path has intermediate
 * administrative states; the urgent path does not, because the case must not
 * wait on paperwork while a child is symptomatic.
 *
 * What is deliberately absent: escalation intervals, escalation levels and
 * after-hours routes. Those are clinical-governance decisions (WFS 7.6, CG-08)
 * and must come from approved configuration, not from a developer's guess.
 */
enum UrgentStatus: string
{
    case Received = 'received';
    case UnderClinicalReview = 'under_clinical_review';
    case AcceptedForCoordination = 'accepted_for_coordination';
    case Redirected = 'redirected';
    case Declined = 'declined';
    case Transferred = 'transferred';
    case Completed = 'completed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::UnderClinicalReview => 'Under clinical review',
            self::AcceptedForCoordination => 'Accepted for coordination',
            self::Redirected => 'Redirected elsewhere',
            self::Declined => 'Declined',
            self::Transferred => 'Transferred',
            self::Completed => 'Procedure completed',
            self::Closed => 'Closed',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Received => [self::UnderClinicalReview, self::Closed],
            self::UnderClinicalReview => [
                self::AcceptedForCoordination,
                self::Redirected,
                self::Declined,
                self::Closed,
            ],
            self::AcceptedForCoordination => [self::Completed, self::Transferred],
            self::Completed, self::Redirected, self::Declined, self::Transferred => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Who may move the case into the given state.
     *
     * The clinical outcomes return the radiologist alone. An administrator
     * coordinates care; they do not accept, decline or redirect a child's case,
     * which is the separation the prototype collapsed (WF-03, SEC 2.5).
     *
     * @return list<Role>
     */
    public function allowedActors(self $target): array
    {
        if (!$this->canTransitionTo($target)) {
            return [];
        }

        return match ($target) {
            self::UnderClinicalReview,
            self::AcceptedForCoordination,
            self::Redirected,
            self::Declined => [Role::Radiologist],
            self::Completed => [Role::Radiologist, Role::Coordinator],
            self::Transferred => [Role::Coordinator, Role::Radiologist],
            self::Closed => [Role::Coordinator, Role::Admin],
            self::Received => [],
        };
    }

    /** A refusal or redirection without a stated reason is not auditable. */
    public function requiresReason(self $target): bool
    {
        return in_array($target, [self::Redirected, self::Declined, self::Closed], true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /** States that only a radiologist can move the case out of. */
    public function isClinicalState(): bool
    {
        return match ($this) {
            self::UnderClinicalReview,
            self::AcceptedForCoordination,
            self::Redirected,
            self::Declined => true,
            default => false,
        };
    }

    public function occurredEventType(): ?string
    {
        return match ($this) {
            self::UnderClinicalReview => 'clinical_review',
            self::Transferred => 'transfer_arranged',
            self::Completed => 'outcome_recorded',
            default => null,
        };
    }
}
