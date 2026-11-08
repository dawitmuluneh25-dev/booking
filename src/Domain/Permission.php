<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Named capabilities. Route handlers ask for a permission, never for a role,
 * so adding a role does not require touching controllers.
 */
enum Permission: string
{
    case BookingsManage = 'bookings.manage';
    case UsersManage = 'users.manage';
    case ClinicConfigure = 'clinic.configure';
    case SystemMaintain = 'system.maintain';
    case ReferralSubmit = 'referral.submit';
    case ReferralTriage = 'referral.triage';
    case ClinicalReview = 'clinical.review';
    case ClinicalApprove = 'clinical.approve';
    case ProcedureCoordinate = 'procedure.coordinate';
    case UrgentCoordinate = 'urgent.coordinate';
    case ScheduleManage = 'schedule.manage';

    /**
     * Document capabilities (SEC 6.7).
     *
     * `document.delete.request` and `document.export` are deliberately absent:
     * neither has a governed workflow in this release, and a permission nobody
     * can exercise is a claim the system does not need to make.
     */
    case DocumentUpload = 'document.upload';
    case DocumentReadMetadata = 'document.read.metadata';
    case DocumentDownload = 'document.download';
    case DocumentQuarantineManage = 'document.quarantine.manage';
}
