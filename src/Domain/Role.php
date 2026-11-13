<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Roles are a starting grant, not the authorisation model. Route handlers and
 * commands ask for a Permission; the role only decides which permissions a user
 * begins with, and object-level checks decide what that user may touch.
 *
 * The clinical roles are intentionally narrow. An administrator receives clinic
 * configuration, triage and scheduling, but no clinical decision: administrative
 * privilege is not clinical privilege (SEC 2.5, 6.8, 10.1). Someone who is both
 * an administrator and a radiologist holds two role assignments rather than one
 * widened role, because multiple roles are additive only where each role
 * independently permits the action (SEC 4.3).
 */
enum Role: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Radiologist = 'radiologist';
    case Physician = 'physician';
    case Coordinator = 'coordinator';
    case Patient = 'patient';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Staff => 'Reception & booking officer',
            self::Radiologist => 'Interventional radiologist',
            self::Physician => 'Referring physician',
            self::Coordinator => 'Procedure coordinator',
            self::Patient => 'Patient or guardian',
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => [
                Permission::BookingsManage,
                Permission::UsersManage,
                Permission::ClinicConfigure,
                Permission::SystemMaintain,
                Permission::ReferralTriage,
                Permission::ScheduleManage,
                // An administrator may acknowledge an urgent case and close one
                // (UrgentStatus::allowedActors(Closed) names Admin), but not accept,
                // decline or redirect it: coordination is not clinical authority.
                Permission::UrgentAcknowledge,
                Permission::UrgentCoordinate,
                Permission::DocumentUpload,
                Permission::DocumentReadMetadata,
                Permission::DocumentDownload,
                Permission::DocumentQuarantineManage,
                // Publishing patient-facing content is an administrative act on
                // something a clinician approved, not clinical authority: the
                // approver is a recorded name on the row and the schema refuses a
                // published row without one, so holding this permission cannot
                // stand in for an approval.
                Permission::ContentPublish,
            ],
            self::Staff => [
                Permission::BookingsManage,
                Permission::ReferralSubmit,
                Permission::ReferralTriage,
                Permission::ScheduleManage,
                // Acknowledgement only. Reception is the role most likely to be at
                // the desk when a case arrives, and WF-05 wants the claim recorded;
                // closing or escalating it stays with the coordinator.
                Permission::UrgentAcknowledge,
                Permission::DocumentUpload,
                Permission::DocumentReadMetadata,
                Permission::DocumentDownload,
            ],
            self::Radiologist => [
                Permission::ClinicalReview,
                Permission::ClinicalApprove,
                Permission::ProcedureCoordinate,
                Permission::UrgentAcknowledge,
                Permission::UrgentCoordinate,
                Permission::ScheduleManage,
                Permission::DocumentUpload,
                Permission::DocumentReadMetadata,
                Permission::DocumentDownload,
            ],
            self::Physician => [
                Permission::ReferralSubmit,
                Permission::DocumentUpload,
                Permission::DocumentReadMetadata,
                Permission::DocumentDownload,
            ],
            self::Coordinator => [
                Permission::BookingsManage,
                Permission::ProcedureCoordinate,
                Permission::UrgentAcknowledge,
                Permission::UrgentCoordinate,
                Permission::ScheduleManage,
                Permission::DocumentUpload,
                Permission::DocumentReadMetadata,
                Permission::DocumentDownload,
            ],
            // A record-only identity (ADR-016): the patient a referral was submitted
            // for, and the identity a guardian relationship points at. It holds no
            // capability, because a grant nothing can reach is a permission waiting
            // to be misused by the next screen that trusts it.
            self::Patient => [],
        };
    }

    /**
     * Whether this role may open a session at all.
     *
     * A patient account is a record, not a login (ADR-016): it carries the identity
     * a referral was submitted for, and the guardian relationship that points at it.
     * It has no sign-in path, and the refusal is the same one a wrong password
     * produces, because "this address exists but may not sign in" is still an
     * account-existence oracle.
     */
    public function canSignIn(): bool
    {
        return $this !== self::Patient;
    }
    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Roles that may hold clinical authority. Keeps administration out of it. */
    public function isClinical(): bool
    {
        return $this === self::Radiologist;
    }
}
