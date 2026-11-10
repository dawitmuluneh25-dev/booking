<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Elective referral lifecycle (WFS 5.3).
 *
 * Two things are deliberate here.
 *
 * First, the vocabulary: this enum holds referral and clinical states only. There
 * is no `scheduled`, `in_progress` or `completed`, because a referral is not an
 * appointment (WF-01) and the two lifecycles must not share one status column
 * (WF-15). Once a referral becomes an appointment it stops here, at
 * `converted_to_appointment`; what happens in the room is `AppointmentStatus`.
 *
 * Second, the authority: every transition names the roles that may perform it, so
 * "can reception approve this?" is answered by the domain rather than by which
 * controller happened to be wired up. Clinical decisions list the radiologist and
 * no administrative role, which is WF-03 and SEC 10.1 in executable form.
 */
enum ReferralStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Received = 'received';
    case AdministrativeReview = 'administrative_review';
    case AwaitingInformation = 'awaiting_information';
    case AwaitingImaging = 'awaiting_imaging';
    case ClinicalReview = 'clinical_review';
    case ClinicallyApproved = 'clinically_approved';
    case ApprovedForScheduling = 'approved_for_scheduling';
    case Deferred = 'deferred';
    case Declined = 'declined';
    case AlternativePathway = 'alternative_pathway';
    case ConvertedToAppointment = 'converted_to_appointment';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft (not submitted)',
            self::Submitted => 'Submitted',
            self::Received => 'Received',
            self::AdministrativeReview => 'Administrative review',
            self::AwaitingInformation => 'Awaiting information',
            self::AwaitingImaging => 'Awaiting imaging',
            self::ClinicalReview => 'Clinical review',
            self::ClinicallyApproved => 'Clinically approved',
            self::ApprovedForScheduling => 'Approved for scheduling',
            self::Deferred => 'Deferred',
            self::Declined => 'Declined',
            self::AlternativePathway => 'Alternative pathway',
            self::ConvertedToAppointment => 'Booked (appointment created)',
            self::Cancelled => 'Cancelled',
            self::Closed => 'Closed',
        };
    }

    /** A referral in a terminal state cannot be reopened by an ordinary command. */
    public function isTerminal(): bool
    {
        return $this === self::Declined
            || $this === self::Cancelled
            || $this === self::Closed;
    }

    /** States in which clinical judgement may be recorded. */
    public function isClinicalReviewState(): bool
    {
        return $this === self::ClinicalReview;
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        $cancellable = [self::Cancelled];

        return match ($this) {
            self::Draft => [self::Submitted, ...$cancellable],
            self::Submitted => [self::Received, ...$cancellable],
            self::Received => [self::AdministrativeReview, ...$cancellable],
            self::AdministrativeReview => [
                self::AwaitingInformation,
                self::ClinicalReview,
                ...$cancellable,
            ],
            self::AwaitingInformation => [self::AdministrativeReview, self::ClinicalReview, ...$cancellable],
            self::AwaitingImaging => [self::ClinicalReview, ...$cancellable],
            self::ClinicalReview => [
                self::AwaitingInformation,
                self::AwaitingImaging,
                self::ClinicallyApproved,
                self::Deferred,
                self::Declined,
                self::AlternativePathway,
                ...$cancellable,
            ],
            self::ClinicallyApproved => [self::ApprovedForScheduling, ...$cancellable],
            self::ApprovedForScheduling => [
                self::ConvertedToAppointment,
                self::Deferred,
                ...$cancellable,
            ],
            self::Deferred => [self::ClinicalReview, ...$cancellable],
            self::ConvertedToAppointment => [self::Closed],
            self::AlternativePathway => [self::Closed],
            self::Declined, self::Cancelled, self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Roles permitted to perform the transition, per WFS 5.3 and SEC 6.4-6.8.
     *
     * Administrative roles triage, schedule and close. They never appear on a
     * clinical decision, and the administrator role is absent from every clinical
     * transition because administrative privilege is not clinical privilege
     * (SEC 2.5, 6.8).
     *
     * @return list<Role>
     */
    public function allowedActors(self $target): array
    {
        if (!$this->canTransitionTo($target)) {
            return [];
        }

        return match ($target) {
            self::Submitted => [Role::Physician, Role::Patient, Role::Staff],
            self::Received => [Role::Staff, Role::Admin],
            self::AdministrativeReview => [Role::Staff, Role::Admin],
            self::AwaitingInformation => $this === self::ClinicalReview
                ? [Role::Radiologist]
                : [Role::Staff, Role::Admin],
            self::AwaitingImaging => [Role::Radiologist],
            self::ClinicalReview => [Role::Staff, Role::Admin, Role::Radiologist],
            self::ClinicallyApproved,
            self::Deferred,
            self::Declined,
            self::AlternativePathway => [Role::Radiologist],
            self::ApprovedForScheduling => [Role::Staff, Role::Admin],
            // The procedure coordinator arranges the session itself, so booking is
            // theirs as well as the desk's. Releasing for scheduling stays with
            // staff and admin: deciding that a referral is ready to be booked is
            // reception's call, not the person who then finds it a room.
            self::ConvertedToAppointment => [Role::Staff, Role::Admin, Role::Coordinator],
            self::Closed => [Role::Staff, Role::Admin],
            self::Cancelled => [Role::Patient, Role::Physician, Role::Staff, Role::Admin],
            self::Draft => [],
        };
    }

    /**
     * Whether the transition requires a written reason.
     *
     * Declining, redirecting and deferring a patient without a recorded reason is
     * the failure mode the specification calls out (API 10.3), so the requirement
     * lives with the transition rather than with a particular form.
     */
    public function requiresReason(self $target): bool
    {
        return $target === self::Declined
            || $target === self::AlternativePathway
            || $target === self::Deferred
            || $target === self::Cancelled;
    }
}
