<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Record that an agreement no longer stands (§18.1).
 *
 * Withdrawal is an act, not a deletion: the row keeps what was discussed and by
 * whom, and gains a time, an actor and a reason. That is what makes "consent was
 * given and then withdrawn" answerable later, which is the whole point of keeping
 * the dialogue rather than a flag.
 *
 * Three refusals:
 *
 *   - only a clinician may record it, because the same person has to decide what
 *     happens to the procedure afterwards;
 *   - only an agreement can be withdrawn, since a decline or an inability to take
 *     part was never an agreement to take away;
 *   - a reason is required, because the next reader is deciding whether to proceed.
 */
final class WithdrawConsent
{
    public function __construct(
        private readonly ConsentRepository $consents,
        private readonly Validator $validator,
    ) {
    }

    /**
     * @throws AuthorisationException   the actor is not a clinician
     * @throws ConsentNotFoundException no such record
     * @throws ValidationException      the reason is missing or too short
     * @throws ConsentNotPermittedException the record is not an agreement, or is
     *                                      already withdrawn
     */
    public function execute(
        Actor $actor,
        int $consentId,
        string $reason,
        ?string $nowUtc = null,
    ): void {
        if (!$actor->can(Permission::ClinicalApprove)) {
            throw new AuthorisationException($actor->role, Permission::ClinicalApprove);
        }

        $consent = $this->consents->findById($consentId);

        if ($consent === null) {
            throw new ConsentNotFoundException($consentId);
        }

        if (!$consent->outcome->isAgreement()) {
            throw new ConsentNotPermittedException(
                null,
                'Only a recorded agreement can be withdrawn; this record is '
                . $consent->outcome->label() . '.',
            );
        }

        if ($consent->isWithdrawn()) {
            throw new ConsentNotPermittedException(null, 'This agreement was already withdrawn.');
        }

        $errors = $this->validator->validate(
            ['withdrawal_reason' => trim($reason)],
            ['withdrawal_reason' => 'required|min:10|max:500'],
        );

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->consents->withdraw(
            $consentId,
            $actor,
            trim($reason),
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
