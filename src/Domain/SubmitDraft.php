<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Turn a saved draft into a submitted referral.
 *
 * This is the moment completeness is enforced, because it is the moment the
 * referral becomes the clinic's work. Ownership is checked here as well: a
 * physician may only submit their own draft, whatever the role table says about
 * who may perform the transition.
 */
final class SubmitDraft
{
    private const MIN_SUMMARY = 10;

    public function __construct(private readonly ReferralRepository $referrals)
    {
    }

    /**
     * @throws AuthorisationException|ReferralNotFoundException|ReferralTransitionException|ValidationException
     */
    public function execute(Actor $actor, string $reference, ?string $nowUtc = null): Referral
    {
        $referral = $this->referrals->findByReference($reference);

        if ($referral === null) {
            throw new ReferralNotFoundException($reference);
        }

        if ($referral->status !== ReferralStatus::Draft) {
            throw new ReferralTransitionException($referral->status, ReferralStatus::Submitted);
        }

        if (
            !$actor->can(Permission::ReferralTriage)
            && $referral->submittedByUserId !== $actor->userId
        ) {
            throw AuthorisationException::forObject(
                $actor->role,
                Permission::ReferralSubmit,
                'This draft belongs to somebody else.',
            );
        }

        $errors = $this->missing($referral);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        $message = new OutboundMessage(
            channel: NotificationChannel::Sms,
            template: NotificationTemplate::ReferralReceived,
            recipient: $referral->guardianPhone !== null && $referral->guardianPhone !== ''
                ? $referral->guardianPhone
                : $referral->patientPhone,
            payload: [
                'reference' => $referral->reference,
                'procedure' => $referral->procedureCode,
            ],
        );

        return $this->referrals->transition(
            $referral,
            ReferralStatus::Submitted,
            $actor,
            null,
            $now,
            [$message],
        );
    }

    /** @return array<string, string> */
    private function missing(Referral $referral): array
    {
        $errors = [];

        if (mb_strlen($referral->patientName) < 2) {
            $errors['patient_name'] = 'The patient’s name is required before this can be submitted.';
        }

        if (preg_match('/^\+?[0-9][0-9 ()\-]{6,19}$/', $referral->patientPhone) !== 1) {
            $errors['patient_phone'] = 'A contactable phone number is required before this can be submitted.';
        }

        if ($referral->procedureCode === '') {
            $errors['procedure_code'] = 'Choose a procedure before submitting.';
        }

        if (mb_strlen($referral->clinicalSummary ?? '') < self::MIN_SUMMARY) {
            $errors['clinical_summary'] = 'A clinical summary is required before this can be submitted.';
        }

        return $errors;
    }
}
