<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The five operational responsibilities HR-ENG-DEC-R1-003 D-07 adopts for Release 1.
 *
 * This enum is deliberately poor in behaviour. It names responsibilities and says what each one
 * owns; it does not map any of them to a permission, a role, or a person, and it has no method that
 * could be made to. That is the decision, not an omission:
 *
 *   "Do not infer clinical privileges from an operational job title. Keep permissions aligned with
 *   verified role assignments and delegated authority."
 *
 * So the two vocabularies stay apart. `Role` is what the access-control layer knows — admin, staff,
 * radiologist, and so on — and it is what `Actor::can()` consults. An appointment is a job somebody
 * does on a rota; two people may hold one appointment, and one person may hold an appointment and a
 * clinical role at once, but holding "Clinical Coordination Officer" grants nothing by itself. The
 * coordinator who holds it can act only as far as the role they signed in with allows, which is why
 * the coordination responsibility is worded as coordination *between* teams rather than as authority
 * over a case.
 *
 * Nothing in the application reads this enum to make an authorisation decision. It exists so the
 * operating model is a thing the code states rather than a paragraph in a document that drifts from
 * the code; `OperationalAppointmentTest` holds it to D-07's wording and fails if a permission is ever
 * attached to it.
 */
enum OperationalAppointment: string
{
    case OperationsLead = 'operations_lead';
    case ReferralIntakeCoordinator = 'referral_intake_coordinator';
    case ClinicalCoordinationOfficer = 'clinical_coordination_officer';
    case ProcedureSchedulingCoordinator = 'procedure_scheduling_coordinator';
    case BackupEscalationDutyCoordinator = 'backup_escalation_duty_coordinator';

    /** The title D-07 gives this responsibility, word for word. */
    public function title(): string
    {
        return match ($this) {
            self::OperationsLead => 'Operations Lead',
            self::ReferralIntakeCoordinator => 'Referral and Intake Coordinator',
            self::ClinicalCoordinationOfficer => 'Clinical Coordination Officer',
            self::ProcedureSchedulingCoordinator => 'Procedure Scheduling and Resource Coordinator',
            self::BackupEscalationDutyCoordinator => 'Backup and Escalation Duty Coordinator',
        };
    }

    /** What D-07 says this responsibility owns. */
    public function responsibility(): string
    {
        return match ($this) {
            self::OperationsLead => 'overall operational ownership, staffing coordination, service '
                . 'readiness, and operational oversight',
            self::ReferralIntakeCoordinator => 'intake administration, referral completeness, '
                . 'communication with referring parties, and intake tracking',
            self::ClinicalCoordinationOfficer => 'coordination between referring teams, authorized '
                . 'clinicians, and relevant receiving services without independently exercising '
                . 'clinical decision authority',
            self::ProcedureSchedulingCoordinator => 'approved procedure scheduling, resource '
                . 'coordination, and appointment administration',
            self::BackupEscalationDutyCoordinator => 'backup operational coverage, escalation '
                . 'monitoring, and documented handover',
        };
    }

    /**
     * Whether holding this appointment confers clinical decision authority.
     *
     * Always false, and stated as a method rather than left to the reader because it is the one
     * question a job title invites. It returns false for every case including the Clinical
     * Coordination Officer, whose own D-07 wording says the role coordinates "without independently
     * exercising clinical decision authority".
     */
    public function confersClinicalAuthority(): bool
    {
        return false;
    }
}
