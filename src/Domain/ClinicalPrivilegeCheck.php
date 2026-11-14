<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The answer to "may this practitioner approve this procedure today", with the
 * evidence it was decided on and, when the answer is no, the reason.
 *
 * The reason is part of the value rather than a log line, because Decision 6
 * requires the refusal to name what is missing: a screen that says "not permitted"
 * tells a clinician to telephone somebody, and a screen that says "your credential
 * expired on 4 March" tells them what to telephone about.
 */
final class ClinicalPrivilegeCheck
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly ?PractitionerCredential $credential,
        public readonly ?PractitionerPrivilege $privilege,
    ) {
    }

    public static function allowed(
        PractitionerCredential $credential,
        PractitionerPrivilege $privilege,
    ): self {
        return new self(true, null, $credential, $privilege);
    }

    public static function refused(
        string $reason,
        ?PractitionerCredential $credential = null,
        ?PractitionerPrivilege $privilege = null,
    ): self {
        return new self(false, $reason, $credential, $privilege);
    }

    /** The day this authority lapses, when it is bounded. */
    public function expiresOn(): ?string
    {
        $credentialExpiry = $this->credential?->expiresOnDay();
        $privilegeExpiry = $this->privilege?->endsOn();

        if ($credentialExpiry === null) {
            return $privilegeExpiry;
        }

        if ($privilegeExpiry === null) {
            return $credentialExpiry;
        }

        return $credentialExpiry < $privilegeExpiry ? $credentialExpiry : $privilegeExpiry;
    }
}
