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
                Permission::DocumentUpload,
                Permission::DocumentReadMetadata,
                Permission::DocumentDownload,
                Permission::DocumentQuarantineManage,
            ],
            self::Staff => [
                Permission::BookingsManage,
                Permission::ReferralSubmit,
                Permission::ReferralTriage,
                Permission::ScheduleManage,
                Permission::DocumentUpload,
                Permission::DocumentReadMetadata,
                Permission::DocumentDownload,
            ],
            self::Radiologist => [
                Permission::ClinicalReview,
                Permission::ClinicalApprove,
                Permission::ProcedureCoordinate,
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
                Permission::UrgentCoordinate,
                Permission::ScheduleManage,
                Permission::DocumentUpload,
                Permission::DocumentReadMetadata,
                Permission::DocumentDownload,
            ],
            self::Patient => [
                Permission::ReferralSubmit,
                Permission::DocumentUpload,
                Permission::DocumentReadMetadata,
                Permission::DocumentDownload,
            ],
        };
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
