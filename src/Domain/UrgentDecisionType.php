<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The controlled choices for a coordination decision (§10.2, INT-016).
 *
 * Two of the six release no state. That is not an oversight: "defer pending
 * review" and "other" are decisions a clinician can legitimately record without
 * the case moving, and forcing them to name a target status would have turned a
 * deferral into either an acceptance or a refusal — a clinical answer the software
 * invented.
 *
 * The other four name the state the case enters, which is what makes the
 * controlled choice checkable against `UrgentStatus::allowedTransitions()` instead
 * of being a label nothing enforces.
 */
enum UrgentDecisionType: string
{
    case ProceedToResourceCoordination = 'proceed_to_resource_coordination';
    case AlternativePathway = 'alternative_pathway';
    case Transfer = 'transfer';
    case DeferPendingReview = 'defer_pending_review';
    case DeclineIrReduction = 'decline_ir_reduction';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ProceedToResourceCoordination => 'Proceed to resource coordination',
            self::AlternativePathway => 'Alternative pathway',
            self::Transfer => 'Transfer to another team',
            self::DeferPendingReview => 'Defer pending review',
            self::DeclineIrReduction => 'Decline IR reduction',
            self::Other => 'Other (recorded, releases no state)',
        };
    }

    /** The state this choice moves the case into, or null where it releases none. */
    public function resultingStatus(): ?UrgentStatus
    {
        return match ($this) {
            self::ProceedToResourceCoordination => UrgentStatus::AcceptedForCoordination,
            self::AlternativePathway => UrgentStatus::Redirected,
            self::Transfer => UrgentStatus::Transferred,
            self::DeclineIrReduction => UrgentStatus::Declined,
            self::DeferPendingReview,
            self::Other => null,
        };
    }

    /**
     * Whether this choice can be recorded against a case in the given state.
     *
     * A choice that names a state must be a legal move from where the case is, and
     * a deferral only means anything while the case is actually in review. `other`
     * is recordable from any state that has not closed, because its whole purpose
     * is to let a clinician say something the vocabulary did not anticipate without
     * the application pretending it understood.
     */
    public function canBeRecordedFrom(UrgentStatus $status): bool
    {
        if ($status->isTerminal()) {
            return false;
        }

        $target = $this->resultingStatus();

        if ($target !== null) {
            return $status->canTransitionTo($target);
        }

        return match ($this) {
            self::DeferPendingReview => $status === UrgentStatus::UnderClinicalReview,
            self::Other => true,
            default => true,
        };
    }

    /**
     * Who may record this choice when it releases no state.
     *
     * The choices that do release a state need no entry here: the transition table
     * already names who may make the move, and the repository enforces it. A
     * deferral has no transition to check, so its authority has to be stated — and
     * it is the radiologist's, because deciding to wait on a child's imaging is a
     * clinical judgement like any other. Leaving it unchecked would have let a
     * coordinator defer a clinical question, which is the one thing the role matrix
     * is most careful about.
     *
     * @return list<Role>
     */
    public function releasingRoles(): array
    {
        return match ($this) {
            self::DeferPendingReview => [Role::Radiologist],
            self::Other => [Role::Radiologist, Role::Coordinator, Role::Admin],
            default => [],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }
}
