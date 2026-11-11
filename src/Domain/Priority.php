<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The approved clinical priority model (HR-IR-CLIN-R1-001 §13.1, A-08).
 *
 * Three categories, and the vocabulary is the approval: before this enum the
 * catalogue stored `priority` and `urgent`, neither of which appears in the
 * approved model, and `expedited` — the only category an authorized clinician
 * may choose when earlier review is warranted — could not be stored at all.
 *
 * Two values are requestable and three are clinical. A submitter may ask for
 * routine or expedited review; "urgent direct coordination" is a clinician's
 * decision to bypass the ordinary queue and speak to a receiving team, so it is
 * never offered on a public form. The split is enforced here rather than in the
 * form, because the form is not the only caller and a hand-crafted POST is not
 * a different kind of user.
 *
 * What this enum does not do: it assigns nothing. Priority is a clinical
 * decision recorded with a rationale by an authorized practitioner, and a
 * checklist or a form may never produce it (CD-005, CD-006).
 */
enum Priority: string
{
    case Routine = 'routine';
    case Expedited = 'expedited';
    case UrgentDirectCoordination = 'urgent_direct_coordination';

    public function label(): string
    {
        return match ($this) {
            self::Routine => 'Routine',
            self::Expedited => 'Expedited',
            self::UrgentDirectCoordination => 'Urgent direct coordination',
        };
    }

    /**
     * A category that claims urgency must carry a reason.
     *
     * {@see \App\Domain\Referral::$requestedUrgency} cannot satisfy this — a
     * request has no rationale field, and that is the point: the submitter's
     * words are not a clinical justification. The requirement binds the clinical
     * decision record, which is where the priority becomes binding.
     */
    public function requiresClinicalRationale(): bool
    {
        return $this !== self::Routine;
    }

    /** Whether a referrer, patient or guardian may ask for this category. */
    public function isRequestable(): bool
    {
        return match ($this) {
            self::Routine, self::Expedited => true,
            self::UrgentDirectCoordination => false,
        };
    }

    /**
     * Whether assigning this category removes the case from the ordinary queue.
     *
     * §13.1 states the workflow effect of urgent direct coordination as "direct
     * contact, human acknowledgement and escalation events required", and its whole
     * purpose as "rather than relying on the ordinary queue". So a referral cannot
     * be released into elective scheduling while carrying it: the clinician has
     * said the queue is the wrong route. Where a case does need this category, the
     * decision that records it is a redirect to the receiving team, which is the
     * one elective outcome that does not promise a booking.
     */
    public function leavesTheOrdinaryQueue(): bool
    {
        return $this === self::UrgentDirectCoordination;
    }

    /**
     * The categories an approval may carry.
     *
     * Approval is what releases a referral into the elective queue, so only the
     * categories that describe work in that queue are admissible. This is the
     * approved model read one way round, not a new rule: the model has no
     * "urgent direct coordination" approval, because that category exists to say
     * an ordinary appointment is not the right next step.
     *
     * @return list<self>
     */
    public static function assignableOnApproval(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => !$case->leavesTheOrdinaryQueue(),
        ));
    }

    /** @return list<string> */
    public static function assignableOnApprovalValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::assignableOnApproval());
    }

    /** @return list<self> */
    public static function requestable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isRequestable()));
    }

    /** @return list<string> */
    public static function requestableValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::requestable());
    }

    /** @return list<string> */
    public static function clinicalValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * A human label for a value read out of the database.
     *
     * Falls back to the stored string so a row written before this model existed
     * still renders as something, rather than disappearing from the screen that
     * is meant to describe it.
     */
    public static function labelFor(string $value): string
    {
        return self::tryFrom($value)?->label() ?? $value;
    }
}
