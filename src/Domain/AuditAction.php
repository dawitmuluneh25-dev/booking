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

    /**
     * Who holds an operational appointment, or who deputises, changed (D-05).
     *
     * Its own action rather than reusing the escalation ones: an appointment is a rota, a route is a
     * telephone number, and an audit filter that could not tell them apart would be unable to answer
     * "who was Operations Lead in November" without reading the before/after maps.
     */
    case OperationalAppointmentChanged = 'operations.appointment_changed';
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
    // The public account-request queue (ADR-013). Submission is recorded without the
    // stranger's free-text note: the trail is readable by administrators, and that
    // note is the one field an unauthenticated visitor wrote.
    case AccountRequested = 'account.requested';
    case AccountRequestApproved = 'account.request_approved';
    case AccountRequestRejected = 'account.request_rejected';
    case AccountRequestExpired = 'account.request_expired';
    case UserDeactivated = 'user.deactivated';
    case UserReactivated = 'user.reactivated';

    public function label(): string
    {
        return match ($this) {
            self::UserCreated => 'Account created',
            self::UserRoleChanged => 'Role changed',
            self::UserDeactivated => 'Access suspended',
            self::UserReactivated => 'Access restored',
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
        self::OperationalAppointmentChanged => 'Operational appointment changed',
            self::ProcedureMilestoneRecorded => 'Procedure milestone recorded',
            self::LoginSucceeded => 'Sign-in succeeded',
            self::LoginFailed => 'Sign-in failed',
            self::LoginThrottled => 'Sign-in throttled',
            self::DocumentReleased => 'Document released',
            self::DocumentWithheld => 'Document withheld',
            self::AccountRequested => 'Referring account requested',
            self::AccountRequestApproved => 'Referring account approved',
            self::AccountRequestRejected => 'Referring account refused',
            self::AccountRequestExpired => 'Referring account request expired',
        };
    }
}
