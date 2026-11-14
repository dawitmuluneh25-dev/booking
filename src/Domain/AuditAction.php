<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The closed list of things worth recording outside a clinical workflow.
 *
 * A string column would accept `user.rolechange`, `role_changed` and
 * `UserRoleChanged` as three different actions, and a filter that matches one of
 * them would quietly under-report. Naming them here also means a missing case is
 * a compile-time problem rather than a typo found months later in a report.
 */
enum AuditAction: string
{
    case UserCreated = 'user.created';
    case UserRoleChanged = 'user.role_changed';
    case PhysicianVerified = 'physician.verified';
    case PhysicianRejected = 'physician.rejected';
    case PhysicianSuspended = 'physician.suspended';
    case PractitionerCredentialRecorded = 'practitioner.credential_recorded';
    case PractitionerCredentialStatusChanged = 'practitioner.credential_status_changed';
    case PractitionerPrivilegeGranted = 'practitioner.privilege_granted';
    case PractitionerPrivilegeEnded = 'practitioner.privilege_ended';
    case EscalationRouteRecorded = 'escalation.route_recorded';
    case EscalationRouteVerified = 'escalation.route_verified';
    case EscalationRouteRetired = 'escalation.route_retired';
    // A procedure-day event (§15) is a statement about a patient at a moment, so
    // it leaves the workflow table and a copy in the audit trail. The audit row
    // carries the kind, the time and the state it produced — never the note, which
    // can hold clinical free text (BL-B2 criterion 3).
    case ProcedureMilestoneRecorded = 'procedure.milestone_recorded';
    case LoginSucceeded = 'auth.login_succeeded';
    case LoginFailed = 'auth.login_failed';
    case LoginThrottled = 'auth.login_throttled';
    case DocumentReleased = 'document.released';
    case DocumentWithheld = 'document.withheld';

    public function label(): string
    {
        return match ($this) {
            self::UserCreated => 'Account created',
            self::UserRoleChanged => 'Role changed',
            self::PhysicianVerified => 'Physician verified',
            self::PhysicianRejected => 'Physician rejected',
            self::PhysicianSuspended => 'Physician suspended',
            self::PractitionerCredentialRecorded => 'Practitioner credential recorded',
            self::PractitionerCredentialStatusChanged => 'Practitioner credential status changed',
            self::PractitionerPrivilegeGranted => 'Procedure privilege granted',
            self::PractitionerPrivilegeEnded => 'Procedure privilege ended',
            self::EscalationRouteRecorded => 'Escalation route recorded',
            self::EscalationRouteVerified => 'Escalation route verified',
            self::EscalationRouteRetired => 'Escalation route retired',
            self::ProcedureMilestoneRecorded => 'Procedure milestone recorded',
            self::LoginSucceeded => 'Sign-in succeeded',
            self::LoginFailed => 'Sign-in failed',
            self::LoginThrottled => 'Sign-in throttled',
            self::DocumentReleased => 'Document released',
            self::DocumentWithheld => 'Document withheld',
        };
    }
}
