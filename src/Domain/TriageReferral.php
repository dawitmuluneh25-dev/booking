<?php

declare(strict_types=1);

namespace App\Domain;

use App\Validation\Validator;

/**
 * Administrative movement of an elective referral.
 *
 * Reception receives, opens for review and passes to the radiologist. It may also
 * ask for missing information. It may not approve, decline, defer or redirect:
 * those are clinical outcomes and the command refuses them by name rather than
 * letting the state table answer with a generic "not allowed" (WF-03).
 *
 * The target is an argument, but not an open one: `ADMINISTRATIVE_TARGETS` is the
 * whole of what this command can reach. Handing it `ClinicallyApproved` is a
 * programming error, and it says so.
 */
final class TriageReferral
{
    /** Every state this command may move a referral into. */
    private const ADMINISTRATIVE_TARGETS = [
        ReferralStatus::Received,
        ReferralStatus::AdministrativeReview,
        ReferralStatus::AwaitingInformation,
        ReferralStatus::ClinicalReview,
    ];

    public function __construct(
        private readonly ReferralRepository $repository,
        private readonly Validator $validator,
    ) {
    }

    /**
     * @throws AuthorisationException      the actor may not triage, or asked for a
     *                                     clinical outcome
     * @throws ReferralNotFoundException   the referral does not exist
     * @throws ValidationException         a missing-information request has no text
     * @throws ReferralTransitionException the movement is not in the state table
     */
    public function execute(
        Actor $actor,
        int $referralId,
        ReferralStatus $target,
        ?string $reason = null,
        ?string $nowUtc = null,
    ): Referral {
        if (!$actor->can(Permission::ReferralTriage)) {
            throw new AuthorisationException($actor->role, Permission::ReferralTriage);
        }

        if (!in_array($target, self::ADMINISTRATIVE_TARGETS, true)) {
            throw AuthorisationException::forObject(
                $actor->role,
                Permission::ReferralTriage,
                'That is a clinical outcome; it must be recorded by a radiologist.',
            );
        }

        $referral = $this->repository->findById($referralId);

        if ($referral === null) {
            throw new ReferralNotFoundException('#' . $referralId);
        }

        $written = $reason === null ? '' : trim($reason);

        if ($target === ReferralStatus::AwaitingInformation) {
            $errors = $this->validator->validate(['reason' => $written], [
                'reason' => 'required|min:10|max:1000',
            ]);

            if ($errors !== []) {
                throw new ValidationException([
                    'reason' => 'Say what information is missing, so the referrer can supply it.',
                ]);
            }
        }

        return $this->repository->transition(
            $referral,
            $target,
            $actor,
            $written === '' ? null : $written,
            $nowUtc ?? gmdate('Y-m-d H:i:s'),
        );
    }
}
