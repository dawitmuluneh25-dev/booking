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

    /**
     * The three clinical support roles the approved matrix names, added under
     * Product Owner Decision 5 (14 November 2026).
     *
     * The values are the matrix's own abbreviations (`TECH`, `NUR`, `LAB`) rather
     * than paraphrases, so a governance document and a `users.role` column cannot
     * drift into saying different things about the same person.
     */
    case Technician = 'tech';
    case Nurse = 'nur';
    case Laboratory = 'lab';

    case Patient = 'patient';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Staff => 'Reception & booking officer',
            self::Radiologist => 'Interventional radiologist',
            self::Physician => 'Referring physician',
            self::Coordinator => 'Procedure coordinator',
            self::Technician => 'Radiology technician (TECH)',
            self::Nurse => 'Nurse (NUR)',
            self::Laboratory => 'Laboratory officer (LAB)',
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
                // Approving a request creates an account; reading the queue does not,
                // and reception holds only the reading half (owner answer Q2).
                Permission::AccountRequestTriage,
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
                Permission::NotificationRead,
            ],
            self::Staff => [
                Permission::BookingsManage,
                Permission::AccountRequestTriage,
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
                Permission::NotificationRead,
            ],
            self::Radiologist => [
                Permission::ClinicalReview,
                Permission::ClinicalApprove,
                // Clinical governance act, not administration: the clinical role
                // records the verification its own approvals depend on, and the
                // administrator cannot (Decision 5, Decision 6, AC-009).
                Permission::ClinicalCredentialManage,
                Permission::ProcedureCoordinate,
                Permission::UrgentAcknowledge,
                Permission::UrgentCoordinate,
                Permission::ScheduleManage,
                Permission::DocumentUpload,
                Permission::DocumentReadMetadata,
                Permission::DocumentDownload,
                Permission::NotificationRead,
            ],
            self::Physician => [
                Permission::ReferralSubmit,
                // Their own referrals, not the clinic's queue (BL-E1): the page is
                // admitted here, and the object-level policy decides which rows.
                Permission::ReferralReadOwn,
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
                Permission::NotificationRead,
            ],
            // The three clinical support roles hold one capability and no clinical
            // authority (Decision 5, ADR-023).
            //
            // Decision 5 gives each of them an area of work — assigned modality,
            // equipment and technical readiness tasks; nursing readiness and nursing
            // documentation; specimen receipt, handling, processing and receipt
            // status — and none of those surfaces exists yet. Granting a permission
            // for a screen that has not been built would put a claim in the role
            // matrix that the product cannot honour, and BL-E1 was closed for exactly
            // that: a policy nobody can exercise is not a control.
            //
            // So each starts with the only surface that exists, is reachable, and
            // carries no clinical content: the in-app notification list, which shows a
            // reference and a time and nothing else. It is what makes the account
            // usable the day it is provisioned. Their real grants arrive with BL-C3
            // (readiness) and BL-C7 (specimens), as new permissions — never by
            // widening one of these.
            self::Technician, self::Nurse, self::Laboratory => [
                Permission::NotificationRead,
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

    /**
     * Roles that may hold clinical authority. Keeps administration out of it.
     *
     * `NUR` prepares a patient and documents what they did; `TECH` prepares the
     * machine and the room; `LAB` handles the specimen. None of that is a clinical
     * decision, and Decision 5 says so explicitly — nursing documentation is not
     * approval, and a role that prepares is not a role that decides. The test for
     * this flag in this codebase is holding `clinical.review` or
     * `clinical.approve`, which none of the three does.
     */
    public function isClinical(): bool
    {
        return $this === self::Radiologist;
    }
}
