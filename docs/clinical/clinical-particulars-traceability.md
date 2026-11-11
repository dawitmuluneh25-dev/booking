# Clinical particulars — implementation traceability

**Source of truth:** `HR-IR-CLIN-R1-001` v1.0 (Clinical Particulars and Clinical Decision Package)
**Approval record:** `HR-IR-R1-APP-001` (Consolidated Release 1 Approval and Implementation Directive), 12 November 2026
**Register entry:** [ADR-018](../adr/README.md#adr-018)
**Status of this document:** living record; it is updated by the slice that changes a row, never in advance of it

---

## 1. Why this document exists

A-20 authorises engineering to implement the approved clinical particulars, and its twelfth
requirement is traceability: every approved field and workflow requirement must map to the
application and to a test. A checklist signed off in a document and a codebase that quietly
implements a different model is the failure mode this file is meant to make visible.

The rule for reading it: a row is **Implemented** only when the behaviour exists, is enforced
server-side, and has a test that fails if the enforcement is removed. A row is **Partial** when some
of that is true. A row is **Not started** when it is honest to say so — and it is always honest to
say so, because an empty slot with a named reason is worth more than a plausible default.

Nothing here claims clinical acceptance. The Section 24 scenarios (TS-001 to TS-022) are mandatory
before production and none has been executed.

---

## 2. Status of the release

| Gate | Status |
|---|---|
| A-01 – A-19 clinical, laboratory, operational and legal particulars | Approved as reported |
| A-20 engineering implementation | Authorised, in progress |
| Section 24 acceptance testing | Not executed |
| Production release | Not authorised |
| Production database cutover | Not authorised |

---

## 3. Mapping

### 3.1 Scope and product boundaries (§3, A-01)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Referral intake, clinical review, approval, booking, urgent coordination and completion are distinct | Implemented | `ReferralStatus`, `AllowedTransitions`, `clinical_decisions`; `ReferralStatusTest`, `ApproveForSchedulingTest` |
| No patient login, accounts, public lookup or self-booking | Implemented | ADR-016; `Role::Patient` holds no permission and `canSignIn()` is false; `RoleTest` |
| No institution tenancy; free text is not an authorisation boundary | Implemented | ADR-014; `ReferralAccessPolicyTest` |
| Limited unauthenticated patient/guardian intake | Partial | Intake exists but requires an authenticated submitter for referral creation; the unauthenticated path is the legacy `bookings` surface (ADR-013) |
| Intussusception is urgent coordination, never an elective booking | Partial | `urgent_cases` is a separate aggregate and cannot hold an elective state (`MigrationIntegrityTest::testUrgentAggregateIsSeparateFromElectiveAndScheduling`); no route exists yet |

### 3.2 Service catalogue (§3.1, §5, A-01)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Four distinct services with stable codes | Implemented with different identifiers | `procedures` rows exist for all four; the stored codes are `image_guided_fnac`, `image_guided_core_biopsy`, `image_guided_abscess_drainage`, `hydrostatic_intussusception_reduction` rather than the document's `FNAC`, `CORE_BIOPSY`, `ABSCESS_DRAINAGE`, `HYDROSTATIC_INTUSSUSCEPTION` — see §4.1 |
| A service is not bookable until capability is confirmed | Implemented | `procedures.active`; only active elective rows reach the form (`Procedure::isElective`), `ReferralPageTest` |
| Modality is configurable per procedure, not a promise | Partial | `procedures` has no modality column; modality is recorded on the clinical decision |
| Procedure-specific approved field sets | Not started | `procedures.required_fields` is NULL for every row and `ck_procedures_approved` refuses a field set that names no approver. See §4.2 |

### 3.3 Common data dictionary (§6, A-02)

| Field group | Status | Evidence / notes |
|---|---|---|
| `PAT-001`–`PAT-010` patient identity and contact | Partial | `patient_name`, `patient_phone`, `patient_dob`, `patient_sex`; the document's remaining identity fields have no column |
| `PAT-011`–`PAT-013` guardian | Partial | `guardian_name`, `guardian_phone`, `guardian_relationship` are stored; `guardian_verified_at` has no writer yet (BL-A4) |
| `REF-001`–`REF-010` referral and referrer | Partial | referrer name, facility, phone, `referring_physician_profile_id`; indication is free text in `clinical_summary` |
| `REF-011`–`REF-012` requested vs clinical priority | Implemented | Separate columns with separate write paths; `Priority`, migration 0024, `MigrationIntegrityTest::testPriorityColumnsAcceptExactlyTheApprovedVocabulary` |
| `MED-001`–`MED-007` history, medication, allergy, assessment | Not started | No structured columns; the clinical review record carries a free-text rationale |
| `IMG-001`–`IMG-004` imaging context | Partial | Documents can be attached and their metadata read; there is no imaging-review status field |
| `DOC-001`, `COM-001`, `COM-002` | Partial | Document upload, quarantine and download are implemented; contact and acknowledgement records exist for urgent cases only |
| `CONS-001` consent | Not started | No consent record, no version, no representative-authority status |

### 3.4 Clinical review and decision (§12, A-07)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Decision options: approve, defer, decline, alternative pathway, redirect, more information | Implemented | `ClinicalDecisionType`; `RecordClinicalDecisionTest` |
| A decision requires a rationale | Implemented | `clinical_decisions.rationale NOT NULL` plus a required field; `RecordClinicalDecisionTest` |
| Only a privileged clinical role may decide | Implemented | `Permission::ClinicalApprove`, checked in the command and again in the repository |
| A superseded decision is visible and cannot be acted on | Implemented | `supersedes_decision_id` with a unique key; `ApproveForSchedulingTest` |
| Clinical priority assigned by a clinician with a reason | Partial | The vocabulary and columns exist; no command assigns it yet, so it is read-only today. See §4.3 |

### 3.5 Priority and escalation (§13, A-08)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Routine / expedited / urgent direct coordination | Implemented | `Priority`; `PriorityTest` |
| Submitters may request routine or expedited only | Implemented | `SubmitElectiveReferral` validates against `Priority::requestableValues()`; `SaveReferralDraft` refuses an unrequestable value rather than letting the CHECK become a 500 |
| Escalation levels and acknowledgement intervals | Not started, deliberately | No interval has been approved (LP-020). Escalation has no default, by design |

### 3.6 Readiness checklists (§14, A-09)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Checklist items with owner, stage, evidence, stop classification and version | Not started | No table, no domain object. See §4.4 for the blocking question |
| Status vocabulary including `NOT_APPLICABLE_WITH_REASON` | Not started | Belongs with the item structure |
| An unauthorised person cannot waive a clinical requirement | Not started | Depends on the same role mapping |

### 3.7 Procedure-day and documentation (§15, §19)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Time-out, milestones, procedure record | Not started | BL-C3 |
| Specimen dispatch, receipt and follow-up ownership | Not started | BL-C3, and the laboratory destination is still an Operations dependency (LP-004 – LP-006) |
| Outcome and follow-up record | Not started | BL-C3 |

### 3.8 Role authority (§16, A-10)

| Requirement | Status | Evidence / notes |
|---|---|---|
| An administrator holds no clinical authority | Implemented | `Role::Admin::permissions()` grants no clinical permission; `ReferralStatus::allowedActors()` refuses an administrative clinical transition |
| Reception schedules only after clinical approval | Implemented | `ApprovedForScheduling` transition requires the clinical state first; `ApproveForSchedulingTest` |
| A job title does not activate clinical permission | Partial | Roles grant permissions; credential and privilege verification (LP-003) has no record |
| Twelve-role matrix | Partial | The application has six roles. Document roles `TECH`, `NUR`, `LAB` and the surgical/anaesthesia columns have no counterpart — see §4.4 |

### 3.9 Patient-facing content (§17, §11, A-11)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Approved service pages | Not started | BL-A1, BL-A3; no `/services` route |
| Urgent pathway notice with real numbers | Not started | BL-A6; the page must fail closed if numbers are absent |
| Privacy, booking policy, contact and referral guidance | Not started | BL-A7 |
| Content carries a named approver and review date | Not started | Belongs to the content model the pages will use |

### 3.10 Consent, governance and urgent coordination (§18, §20, §10)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Consent as a documented dialogue | Not started | No record; A-12 approved the particulars, not a form |
| Urgent registration, acknowledgement, contact attempts, escalation | Partial | `RegisterUrgentCase`, `AcknowledgeUrgentCase`, `RecordUrgentContactAttempt`, `EscalateUrgentCase` exist and are tested at the domain level; no routes, and `CloseUrgentCase` does not exist |
| Acknowledgement cannot be produced by a notification path | Implemented | Acknowledgement writes an actor and a channel; the outbox has no path to it |
| Audit of clinical and operational actions | Partial | `audit_events` covers identity, documents and roles; checklist, milestone and urgent-board actions have nothing to audit yet |
| Translation governance | Not started | A-19 approved the requirement; no translated content exists |

---

## 4. Deviations and open questions

### 4.1 Procedure codes differ from the document's proposed identifiers

The document proposes `FNAC`, `CORE_BIOPSY`, `ABSCESS_DRAINAGE` and
`HYDROSTATIC_INTUSSUSCEPTION`. The catalogue stores longer, lower-case codes. The requirement the
document states is that the four services are **distinct and stable**; that is satisfied, and
renaming would rewrite the `procedure_code` of every referral in order to match a naming convention,
including retired codes that exist precisely so old rows keep resolving. The mapping is recorded here
so a reader can go from the document to the row without guessing:

| Document code | Stored code |
|---|---|
| `FNAC` | `image_guided_fnac` |
| `CORE_BIOPSY` | `image_guided_core_biopsy` |
| `ABSCESS_DRAINAGE` | `image_guided_abscess_drainage` |
| `HYDROSTATIC_INTUSSUSCEPTION` | `hydrostatic_intussusception_reduction` |

This is a mapping, not an approval. If the Clinical Lead requires the literal identifiers, that is a
change decision with a data migration, and it should be taken deliberately rather than folded into
another slice.

### 4.2 The field sets are the largest unimplemented part of A-02

`required_fields` was designed to hold an approved field set per procedure and the schema refuses one
without an approver. Filling it is not mechanical: the document names 176 fields across groups, and
several of them (`MED-*`, `IMG-*`, `CONS-*`) need columns, validation and a visibility rule before
they can be stored at all. The reason this has not been started is that doing it half-way would put
clinical questions on a form without the review framework that consumes them.

### 4.3 Clinical priority has no writer

The approved model is stored and both columns enforce it, but no command assigns
`clinical_priority`, so the column is empty in practice. That is a gap in the review workflow, not in
the vocabulary: assigning a priority is a clinical decision with a required rationale, and it belongs
with the review record rather than the intake form.

### 4.4 The checklist cannot be built on six roles

Section 14 assigns every checklist item to a responsible role, using `REC`, `CLIN`, `PROC`, `TECH`,
`NUR` and `LAB`. The application has `staff`, `radiologist`, `coordinator`, `admin`, `physician` and
`patient`. Three of the document's roles have no counterpart, and sixteen of the fifty-four items are
owned by them.

Two honest options exist and neither is an engineering default:

1. Add the missing roles to the identity model, with the permissions and verification each implies.
2. Map several document roles onto one application role and state, in the checklist record, which
   competence actually completed the item.

Choosing (1) widens the permission surface and needs the Clinical Lead; choosing (2) loses a
distinction the approved matrix makes. The checklist slice waits on that answer rather than
inventing a mapping that would let a receptionist complete a nursing check.

---

## 5. What engineering must not do while this is open

- Do not add a laboratory threshold, fasting duration, medication hold, sedation rule, observation
  period or discharge criterion. Every one is `LOCAL POLICY REQUIRED` (§22, LP-007 – LP-012).
- Do not give escalation a default interval. LP-020 is open; the panel must keep reading "no policy
  configured".
- Do not mark a service bookable because a catalogue row exists. LP-001, LP-002 and LP-019 are
  unconfirmed.
- Do not let a completed checklist, a delivered email or an opened notification stand in for a
  clinical decision or a human acknowledgement (§2.2, CD-023).
- Do not treat this traceability record, or a passing test suite, as clinical or operational
  acceptance.

---

## 6. Next slices, in dependency order

1. Clinical priority assignment on the review record, with a required rationale (closes the read-only
   column in §3.4).
2. Urgent board routes and `CloseUrgentCase`, with the escalation panel reading "no policy
   configured" until LP-020 is answered (BL-D1).
3. Readiness checklists, once §4.4 is decided (BL-D2, then BL-C3).
4. Public service, urgent and policy pages against the approved copy (BL-A1, BL-A3, BL-A6, BL-A7).
5. Consent, specimen and follow-up records with the procedure-day timeline (BL-C3).
6. Section 24 scenario execution, which needs the screens above and a staging environment.
