-- 0011_create_urgent_core.sql
-- The urgent intussusception pathway as its own aggregate.
--
-- WF-04 asks for a separate urgent workflow, not an urgency flag on the elective
-- referral. The separation here is structural: an urgent case is its own row in
-- its own table, so a case cannot be both elective and urgent. The database
-- specification models a common `care_cases` root with elective and urgent
-- subtypes and leaves "exactly one subtype" to a transactional service check
-- (DDS 143-163). Two independent aggregates reach the same guarantee without a
-- rule that a future code path has to remember to enforce.
--
-- WF-05: a notification is not an acknowledgement. Delivery lives in
-- `notification_outbox`; `urgent_acknowledgements` is only written when an
-- identified staff member says they have seen the case. Nothing in the outbox
-- dispatcher may insert into it.
--
-- WF-06: direct contact must be recorded. `urgent_contact_attempts` requires an
-- actor, a channel and an outcome, and a telephone attempt must carry the number
-- that was dialled. A call that nobody wrote down did not happen.
--
-- WF-07: urgent work is not payment-gated. There is no payment, fee or invoice
-- column anywhere in this file, and none of these tables references a billing
-- table. Gating cannot be added by a configuration change alone.
--
-- Relation to 0010: an urgent case never becomes an appointment (DDS 167). Its
-- coordination is recorded as events on this aggregate. This retires the note in
-- 0010's header that anticipated an urgent appointment; `appointments.referral_id`
-- stays nullable for an appointment recorded for a patient whose elective
-- referral was never captured in this system, and it is not an urgent-case link.
--
-- Append-only: `urgent_acknowledgements`, `urgent_contact_attempts`,
-- `urgent_escalations` and `urgent_events` are history tables. No repository
-- issues UPDATE or DELETE against them; a correction is a new row.

CREATE TABLE urgent_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(24) NOT NULL,
    procedure_code VARCHAR(40) NOT NULL,
    -- 32, not 24: 'accepted_for_coordination' is 26 characters and a narrower
    -- column turns a valid transition into a truncation error at runtime.
    urgency_status VARCHAR(32) NOT NULL DEFAULT 'received',
    coordination_status VARCHAR(32) NOT NULL DEFAULT 'unacknowledged',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    patient_name VARCHAR(120) NOT NULL,
    patient_dob DATE NULL,
    patient_age_months SMALLINT UNSIGNED NULL,
    guardian_name VARCHAR(120) NULL,
    guardian_phone VARCHAR(32) NOT NULL,
    guardian_relationship VARCHAR(40) NULL,
    referring_user_id BIGINT UNSIGNED NULL,
    referring_physician_name VARCHAR(120) NULL,
    referring_facility VARCHAR(160) NULL,
    referring_physician_phone VARCHAR(32) NULL,
    receiving_hospital_name VARCHAR(160) NULL,
    clinical_summary TEXT NOT NULL,
    imaging_summary TEXT NULL,
    received_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_urgent_reference (reference),
    KEY ix_urgent_status_received (urgency_status, received_at),
    KEY ix_urgent_coordination (coordination_status, received_at),
    KEY ix_urgent_referrer (referring_user_id),
    CONSTRAINT fk_urgent_referrer
        FOREIGN KEY (referring_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_urgent_status CHECK (urgency_status IN (
        'received', 'under_clinical_review', 'accepted_for_coordination',
        'redirected', 'declined', 'transferred', 'completed', 'closed'
    )),
    CONSTRAINT ck_urgent_coordination CHECK (coordination_status IN (
        'unacknowledged', 'acknowledged', 'contacting', 'coordinating',
        'escalated', 'resolved'
    )),
    CONSTRAINT ck_urgent_age CHECK (patient_age_months IS NULL OR patient_age_months < 216)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An acknowledgement is a claim by a named person. It is not a delivery receipt,
-- so it cannot be produced by the notification pipeline and cannot be anonymous.
CREATE TABLE urgent_acknowledgements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    urgent_case_id BIGINT UNSIGNED NOT NULL,
    acknowledged_by_user_id BIGINT UNSIGNED NOT NULL,
    acknowledgement_channel VARCHAR(20) NOT NULL,
    note VARCHAR(1000) NULL,
    acknowledged_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_urgent_ack_time (urgent_case_id, acknowledged_at),
    CONSTRAINT fk_urgent_ack_case
        FOREIGN KEY (urgent_case_id) REFERENCES urgent_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_urgent_ack_user
        FOREIGN KEY (acknowledged_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_urgent_ack_channel CHECK (acknowledgement_channel IN (
        'application', 'telephone', 'in_person', 'other'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE urgent_contact_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    urgent_case_id BIGINT UNSIGNED NOT NULL,
    attempted_by_user_id BIGINT UNSIGNED NOT NULL,
    contact_role VARCHAR(24) NOT NULL,
    contact_name VARCHAR(120) NULL,
    contact_phone VARCHAR(32) NULL,
    channel VARCHAR(20) NOT NULL,
    outcome VARCHAR(24) NOT NULL,
    notes TEXT NULL,
    attempted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_urgent_contact_time (urgent_case_id, attempted_at),
    KEY ix_urgent_contact_actor (attempted_by_user_id),
    CONSTRAINT fk_urgent_contact_case
        FOREIGN KEY (urgent_case_id) REFERENCES urgent_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_urgent_contact_actor
        FOREIGN KEY (attempted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_urgent_contact_role CHECK (contact_role IN (
        'referring_clinician', 'hospital', 'patient_family', 'receiving_team', 'other'
    )),
    CONSTRAINT ck_urgent_contact_channel CHECK (channel IN (
        'telephone', 'application', 'in_person', 'other'
    )),
    CONSTRAINT ck_urgent_contact_outcome CHECK (outcome IN (
        'answered', 'no_answer', 'busy', 'wrong_number', 'message_left',
        'connected', 'failed', 'other'
    )),
    CONSTRAINT ck_urgent_contact_number CHECK (
        channel <> 'telephone' OR contact_phone IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Escalation recurs; it is an event, not a state the case moves into. A resolved
-- escalation stays resolved while later ones are added beside it.
CREATE TABLE urgent_escalations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    urgent_case_id BIGINT UNSIGNED NOT NULL,
    escalated_by_user_id BIGINT UNSIGNED NOT NULL,
    assigned_to_user_id BIGINT UNSIGNED NULL,
    escalation_level SMALLINT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    escalated_at DATETIME NOT NULL,
    acknowledged_at DATETIME NULL,
    resolved_at DATETIME NULL,
    resolution_note TEXT NULL,
    PRIMARY KEY (id),
    KEY ix_urgent_escalation_time (urgent_case_id, escalated_at),
    KEY ix_escalation_assignee (assigned_to_user_id, resolved_at),
    CONSTRAINT fk_urgent_escalation_case
        FOREIGN KEY (urgent_case_id) REFERENCES urgent_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_urgent_escalation_actor
        FOREIGN KEY (escalated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_urgent_escalation_assignee
        FOREIGN KEY (assigned_to_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_escalation_level CHECK (escalation_level >= 1),
    CONSTRAINT ck_escalation_resolution CHECK (
        resolved_at IS NULL OR acknowledged_at IS NOT NULL
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The operational narrative: hospital contacted, receiving team confirmed,
-- transfer arranged, outcome recorded. A coordinated time or destination belongs
-- here rather than in `appointments` (DDS 167).
CREATE TABLE urgent_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    urgent_case_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    event_by_user_id BIGINT UNSIGNED NOT NULL,
    hospital_or_team VARCHAR(160) NULL,
    event_note TEXT NOT NULL,
    event_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_urgent_events_time (urgent_case_id, event_at),
    CONSTRAINT fk_urgent_event_case
        FOREIGN KEY (urgent_case_id) REFERENCES urgent_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_urgent_event_actor
        FOREIGN KEY (event_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT ck_urgent_event_type CHECK (event_type IN (
        'clinical_review', 'hospital_contacted', 'receiving_team_confirmed',
        'transfer_arranged', 'arrival_confirmed', 'procedure_update',
        'outcome_recorded', 'other'
    ))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
