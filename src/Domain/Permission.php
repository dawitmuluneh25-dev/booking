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

    /**
     * Reading the referrals I referred (BL-E1).
     *
     * A referring physician has to be able to see what became of what they sent.
     * This permission admits the page; it does not decide the rows — the verified
     * profile named on the referral does (`ReferralAccessPolicy`), so granting the
     * page cannot be mistaken for granting the queue. It is deliberately not
     * `referral.triage`: reading one's own correspondence is not reading the
     * clinic's work, and handing a referrer triage to make a list render would have
     * been the cheap way to do it.
     */
    case ReferralReadOwn = 'referral.read.own';

    case ReferralTriage = 'referral.triage';
    case ClinicalReview = 'clinical.review';
    case ClinicalApprove = 'clinical.approve';
    case ProcedureCoordinate = 'procedure.coordinate';

    /**
     * Urgent coordination splits along the same line the aggregate already draws
     * (WF-05): acknowledging a case says "I have seen this", and every role on the
     * board may say it; coordinating a case — escalating it, closing it — is
     * narrower. One permission would have forced a receptionist to hold the power
     * to close a child's case in order to open the screen that shows it.
     */
    case UrgentAcknowledge = 'urgent.acknowledge';
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

    /**
     * Putting patient-facing content live (§17.5).
     *
     * This is the act of publishing what somebody else approved, not the approval
     * itself. The approval is a recorded name and date on the content row, and no
     * permission can stand in for it: the schema refuses a published row that does
     * not carry one. Separating the two is what stops "can use the button" from
     * being mistaken for "clinically accountable for the words".
     */
    case ContentPublish = 'content.publish';

    /**
     * Reading the in-app notification list.
     *
     * The list carries a reference and a time and nothing else, so this is not a
     * grant of access to anything: it lets somebody see that something happened,
     * not what it was about. It is held by the roles that work the clinic floor and
     * not by a referring physician, whose relationship is to their own patients
     * rather than to the clinic's traffic.
     */
    case NotificationRead = 'notification.read';
}
