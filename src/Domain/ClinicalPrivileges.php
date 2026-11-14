<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The approval-authority rule (AC-009, LP-003, §16.3, Product Owner Decision 6).
 *
 * Approval requires three facts to hold on the day of the decision:
 *
 *   1. a credential has been recorded and is neither suspended nor revoked;
 *   2. that credential's window covers the day;
 *   3. a privilege naming this procedure has a window that covers the day.
 *
 * All three are facts with dates. None of them is inferred from a job title, an
 * account role, or the fact that the person is on the rota — §16.3 says a title
 * activates nothing, and this class is where that sentence is enforced.
 *
 * What it deliberately does not read: supervision text. A supervision condition
 * says something about how a privilege may be exercised, and what it permits is a
 * clinical rule the approved package does not state. The condition is recorded and
 * shown; it is not turned into a refusal by inference.
 *
 * Dates are ISO `Y-m-d` strings throughout. A privilege covers a clinic day, and
 * comparing two dates as strings is exact for that format and free of timezone
 * arithmetic — which is the point, because a privilege that lapses at midnight UTC
 * would lapse at 03:00 in Addis.
 */
final class ClinicalPrivileges
{
    public function __construct(private readonly PractitionerCredentialRepository $credentials)
    {
    }

    /**
     * @param string      $onDay          clinic day of the decision, `Y-m-d`
     * @param string|null $procedureLabel the procedure as a person reads it, for the refusal
     */
    public function check(
        int $userId,
        string $procedureCode,
        string $onDay,
        ?string $procedureLabel = null,
    ): ClinicalPrivilegeCheck {
        $credentials = $this->credentials->forUser($userId);

        // The catalogue code is an identifier the product never shows a person
        // (Procedure's own docblock says so). A refusal that quoted it would be the
        // one place an internal code reached a screen, so without a label the
        // message points at the procedure the reader is already looking at.
        $name = $procedureLabel === null || $procedureLabel === '' ? 'this procedure' : $procedureLabel;

        if ($credentials === []) {
            return ClinicalPrivilegeCheck::refused(
                'No credential verification is recorded for your account, so clinical approval '
                . 'is unavailable (AC-009). Clinical Governance has to record a verification before '
                . 'you can approve a procedure.',
            );
        }

        $usable = null;

        foreach ($credentials as $credential) {
            if (!$credential->status->authorisesApproval() || !$credential->coversDay($onDay)) {
                continue;
            }

            $privilege = $credential->privilegeFor($procedureCode);

            if ($privilege !== null && $this->privilegeCovers($privilege, $onDay)) {
                return ClinicalPrivilegeCheck::allowed($credential, $privilege);
            }

            // Newest first, so the first usable credential is the most recent one
            // that could have authorised the approval. Its missing privilege is the
            // most useful thing to report.
            $usable ??= $credential;
        }

        if ($usable !== null) {
            return $this->refusedForMissingPrivilege($usable, $procedureCode, $name, $onDay);
        }

        return $this->refusedForCredential($credentials[0], $onDay);
    }

    private function refusedForMissingPrivilege(
        PractitionerCredential $credential,
        string $procedureCode,
        string $name,
        string $onDay,
    ): ClinicalPrivilegeCheck {
        $privilege = $credential->privilegeFor($procedureCode);

        if ($privilege === null) {
            return ClinicalPrivilegeCheck::refused(
                'Your verified credential records no privilege for ' . $name . ', so you cannot '
                . 'approve this procedure. Privileges are granted per procedure by Clinical '
                . 'Governance.',
                $credential,
            );
        }

        $started = $privilege->startsOn();

        if ($onDay < $started) {
            return ClinicalPrivilegeCheck::refused(
                'Your privilege for ' . $name . ' starts on ' . $started . ', so it does not cover '
                . 'this decision.',
                $credential,
                $privilege,
            );
        }

        return ClinicalPrivilegeCheck::refused(
            'Your privilege for ' . $name . ' ended on ' . (string) $privilege->endsOn() . ', so it '
            . 'no longer covers a decision. Clinical Governance has to record a new one.',
            $credential,
            $privilege,
        );
    }

    private function refusedForCredential(
        PractitionerCredential $credential,
        string $onDay,
    ): ClinicalPrivilegeCheck {
        if ($credential->status === PractitionerCredentialStatus::Revoked) {
            return ClinicalPrivilegeCheck::refused(
                'Your credential was revoked, so it cannot authorise approval. A new verification '
                . 'is required.',
                $credential,
            );
        }

        if ($credential->status === PractitionerCredentialStatus::Suspended) {
            return ClinicalPrivilegeCheck::refused(
                'Your credential is suspended, so it cannot authorise approval until it is '
                . 'reinstated.',
                $credential,
            );
        }

        if ($onDay < $credential->startsOn()) {
            return ClinicalPrivilegeCheck::refused(
                'Your credential takes effect on ' . $credential->startsOn() . ', so it does not '
                . 'authorise a decision taken today.',
                $credential,
            );
        }

        return ClinicalPrivilegeCheck::refused(
            'Your credential expired on ' . (string) $credential->expiresOnDay() . ', so it does '
            . 'not authorise approval. Clinical Governance has to record a current verification.',
            $credential,
        );
    }

    private function privilegeCovers(PractitionerPrivilege $privilege, string $onDay): bool
    {
        if ($onDay < $privilege->startsOn()) {
            return false;
        }

        $ended = $privilege->endsOn();

        return $ended === null || $onDay <= $ended;
    }
}
