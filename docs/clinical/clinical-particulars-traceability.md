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
| Four distinct services with stable codes | Implemented | `procedures.clinical_code` carries the document's `FNAC`, `CORE_BIOPSY`, `ABSCESS_DRAINAGE`, `HYDROSTATIC_INTUSSUSCEPTION` next to the stored codes `image_guided_fnac`, `image_guided_core_biopsy`, `image_guided_abscess_drainage`, `hydrostatic_intussusception_reduction`; unique key; migration 0026; `MigrationIntegrityTest::testApprovedProcedureCodesAreRecordedOnceAgainstTheCatalogue` — see §4.1 |
| A service is not bookable until capability is confirmed | Implemented | `procedures.active`; only active elective rows reach the form (`Procedure::isElective`), `ReferralPageTest` |
| Modality is configurable per procedure, not a promise | Partial | `procedures` has no modality column; modality is recorded on the clinical decision |
| Procedure-specific approved field sets | Not started | `procedures.required_fields` is NULL for every row and `ck_procedures_approved` refuses a field set that names no approver. See §4.2 |

### 3.3 Common data dictionary (§6, A-02)

| Field group | Status | Evidence / notes |
|---|---|---|
| `PAT-001`–`PAT-010` patient identity and contact | Partial | `patient_name`, `patient_phone`, `patient_dob`, `patient_sex`; the document's remaining identity fields have no column |
| `PAT-011`–`PAT-013` guardian | Implemented | Intake records the three parts (`guardian_name`, `guardian_phone`, `guardian_relationship`) and refuses a partial one; `PAT-013`'s verification is a separate staff act with its own actor and basis (`guardian_verified_at`, `guardian_verified_by_user_id`, `guardian_verification_basis`, migration 0034). `VerifyGuardianAuthority` requires `ReferralTriage`, refuses a second verification, and refuses a basis that says nothing; the CHECK constraints make a verification time without an actor unrepresentable. Nothing infers authority from a relationship label or a missing date of birth (§6.2, §6.3). `VerifyGuardianAuthorityTest`, `SubmitElectiveReferralTest` |
| `REF-001`–`REF-010` referral and referrer | Partial | referrer name, facility, phone, `referring_physician_profile_id`; indication is free text in `clinical_summary` |
| `REF-011`–`REF-012` requested vs clinical priority | Implemented | Separate columns with separate write paths; `Priority`, migration 0024, `MigrationIntegrityTest::testPriorityColumnsAcceptExactlyTheApprovedVocabulary` |
| `MED-001`–`MED-007` history, medication, allergy, assessment | Implemented except structured medications | `referral_clinical_assessments` (migration 0032) with append-only supersede chain: MED-003 anticoagulant, MED-004 allergy and MED-005 pregnancy as four-value vocabularies that include `unknown` and `not_yet_assessed`, plus MED-001 history, MED-006 prior procedure and MED-007 examination context. Every column is nullable and null means "not recorded", never "no" (§6.2). MED-002 current medications needs its own child table and is deliberately not half-built. `RecordClinicalAssessmentTest` |
| `IMG-001`–`IMG-004` imaging context | Partial | IMG-001 modality, IMG-002 date and IMG-004 secure reference are stored on the assessment (0032), with `none_available` and `unknown` as distinct values; IMG-003 imaging report is a document upload. There is still no imaging-review status field |
| `DOC-001`, `COM-001`, `COM-002` | Partial | Document upload, quarantine and download are implemented; contact and acknowledgement records exist for urgent cases only |
| `CONS-001` consent | Partial | `procedure_consents` (0030) records the dialogue, outcome, withdrawal and representative authority; CONS-001's eight-value process status is not stored as one field — the presence and outcome of a record imply it. "Discussion planned" has no representation |

### 3.4 Clinical review and decision (§12, A-07)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Decision options: approve, defer, decline, alternative pathway, redirect, more information | Implemented | `ClinicalDecisionType`; `RecordClinicalDecisionTest` |
| A decision requires a rationale | Implemented | `clinical_decisions.rationale NOT NULL` plus a required field; `RecordClinicalDecisionTest` |
| Only a privileged clinical role may decide | Implemented | `Permission::ClinicalApprove`, checked in the command and again in the repository |
| Approval available only to a verified, privileged practitioner | Not started | AC-009 requires clinical approval to be unavailable until credential and privilege verification is recorded (LP-003, §16.3). Today `Role::Radiologist` grants it on the role alone. There is no credential register to check against, so there is nothing to verify yet; this stays a release gate rather than a claim |
| A superseded decision is visible and cannot be acted on | Implemented | `supersedes_decision_id` with a unique key; `ApproveForSchedulingTest` |
| Clinical priority assigned by a clinician with a reason | Implemented | `RecordClinicalDecision` requires a category on approval and stores it on the decision (migration 0025) and on the referral in one unit of work; `RecordClinicalDecisionTest::testAnApprovalRecordsTheCategoryOnBothTheDecisionAndTheReferral` |
| The assigned category is the clinician's, never the submitter's | Implemented | `referrals.requested_urgency` and `referrals.clinical_priority` are written by different commands and neither copies the other (AC-010); `RecordClinicalDecisionTest::testARequestedUrgencyDoesNotBecomeTheClinicalPriority` |
| No default category for a review nobody has made | Implemented | The form's first option is "Not stated", and an approval without a category is refused rather than defaulted to routine (§12.4) |

### 3.5 Priority and escalation (§13, A-08)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Routine / expedited / urgent direct coordination | Implemented | `Priority`; `PriorityTest` |
| Submitters may request routine or expedited only | Implemented | `SubmitElectiveReferral` validates against `Priority::requestableValues()`; `SaveReferralDraft` refuses an unrequestable value rather than letting the CHECK become a 500 |
| Urgent direct coordination leaves the ordinary queue | Implemented as a guard | An approval cannot carry it: approval is what releases a referral into the elective queue, and the category exists to say that queue is the wrong route. It is recorded with a redirect instead (`Priority::leavesTheOrdinaryQueue`, `assignableOnApproval`) |
| Changing an assigned category | Implemented | `ReassignClinicalPriority` with `referral_priority_changes` (migration 0028) keeping both values, the reason, the actor and the time; available only while the referral is `clinically_approved` or `approved_for_scheduling`, refused once an appointment exists, refused same-to-same, and refused for "urgent direct coordination" because leaving the queue is a redirect rather than a change of position; `ReassignClinicalPriorityTest` |
| Escalation levels and acknowledgement intervals | Implemented as a person's judgement, no policy | Escalating records a level, a reason and an actor, and the board states "Escalation policy: not configured" and names LP-020. No interval, ladder or after-hours route exists, and elapsed time carries no "overdue" label (`UrgentBoardPageTest`) |

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
| Specimen dispatch, receipt and follow-up ownership | Partly enabled; the record itself is not started | The laboratory destination is no longer a dependencies item: `laboratory_destinations` (migration 0031) is a serialised catalogue — `PATHOLOGY-LAB-<n>` / `Pathology Lab <n>`, both derived from one serial — administered at `/admin/laboratories`, with closure instead of deletion so an earlier specimen keeps naming where it went. The specimen record itself is BL-C7 and still needs the C-1 field sets and BL-C3's milestones |
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
| Approved service pages | Built as drafts, none published | `/services` and `/services/{slug}` exist and need no session; the four pages (§17.1–§17.4) are rows in `published_content` (migration 0035), imported by `bin/console content:sync`. Nothing is served yet, because publication requires §17.5's reviewer and both dates and the rows deliberately do not have them. `ServicePageTest`, `AdminContentPageTest`, `ContentRepositoryTest` |
| Urgent pathway notice with real numbers | Not started | BL-A6; the page must fail closed if numbers are absent |
| Privacy, booking policy, contact and referral guidance | Not started | BL-A7 |
| Content carries a named approver and review date | Implemented as a gate, not as a claim | `published_content` stores §17.5's field set — content key, audience, language, version, author, clinical reviewer, approver, approval date, effective date, review date, superseded version, translation-review status. **Dawit Muluneh, approved 2026-11-13** is recorded as the approver for the four service pages. The `ck_published_content_complete` CHECK refuses a published row missing the reviewer, the approver, either date, the review date or the translation-review status, and `PublishContent` names whichever are missing (§17.5's first four bullets are therefore structural, not procedural). Expiry is derived from the review date rather than stored, so a page stops being served without anyone having to remember. See §4.5 |

### 3.10 Consent, governance and urgent coordination (§18, §20, §10)

| Requirement | Status | Evidence / notes |
|---|---|---|
| Consent as a documented dialogue | Implemented for the record, not for the policy | `procedure_consents` (migration 0030) stores what was discussed, the practitioner's account of benefits, risks and alternatives, questions and answers, the information version and language, interpreter involvement, capacity, the attestation reference and the responsible practitioner — §18.1's list in full. `RecordProcedureConsent` refuses anything before the clinical decision, refuses an agreement on somebody else's behalf without verified authority (§18.2), and offers no emergency override (§18.3): an inability to take part is recorded as such. `WithdrawConsent` records the withdrawal rather than deleting the agreement. The record is append-only and supersedes rather than edits. What is **not** decided, and is deliberately absent, is who holds parental responsibility or when an emergency exception applies — that is local legal and institutional policy (§18.2, §18.3, LP-007 onwards), so the system stores a verdict a person reached and never computes one. `RecordProcedureConsentTest`, `ConsentRepositoryTest` |
| Urgent registration, acknowledgement, contact attempts, escalation, closure | Implemented for the coordination axis | `/urgent-cases` board, `/urgent-cases/new` intake, acknowledge, contact log, escalate and close, with a six-table timeline; `CloseUrgentCase` added; `closed_at` and the coordination events named by `UrgentStatus::occurredEventType()` are now written; `urgent.acknowledge` split from `urgent.coordinate` so reception can claim a case without being able to close it; `CloseUrgentCaseTest`, `UrgentBoardPageTest`, `UrgentCaseRepositoryTest` |
| A clinical decision on an urgent case (`INT-016`) | Implemented | `urgent_decisions` (migration 0027) carries the six controlled choices with a rationale, a decision-maker and a time; `RecordUrgentDecision` writes the decision and the move it releases in one transaction, and refuses a choice that cannot move the case from where it is; the panel offers only the choices the actor's role can record, asked of `UrgentStatus::allowedActors()`; a second decision supersedes the first without erasing it; `RecordUrgentDecisionTest` |
| The coordination axis completing (`coordinating`, `resolved`) | Implemented | `AdvanceUrgentCoordination` with `urgent_coordination_history` (migration 0029); the two states are explicit acts by a coordinator or radiologist, never inferred — a contact attempt is `contacting`, not `coordinating`, and closing a case does not resolve the coordination, because an Administrator may close but is not named for `Resolved`. `AdvanceUrgentCoordinationTest` |
| Acknowledgement cannot be produced by a notification path | Implemented | Acknowledgement writes an actor and a channel; the outbox has no path to it |
| Audit of clinical and operational actions | Partial | `audit_events` covers identity, documents and roles; checklist, milestone and urgent-board actions have nothing to audit yet |
| Translation governance | Not started | A-19 approved the requirement; no translated content exists |

---

## 4. Deviations and open questions

### 4.1 Procedure codes: reconciled as two identifiers, not a rename

**Status: closed as a mapping.** The document proposes `FNAC`, `CORE_BIOPSY`, `ABSCESS_DRAINAGE` and
`HYDROSTATIC_INTUSSUSCEPTION`. The catalogue stores longer, lower-case codes. The requirement the
document states is that the four services are **distinct and stable**; renaming would rewrite the
`procedure_code` of every referral to satisfy a naming convention, including the retired combined
code that exists precisely so old rows keep resolving.

The resolution is that both identifiers are real and both are stored. `procedures.clinical_code`
(migration 0026) carries the document's code, with a unique key, so the mapping is a row in a table
the tests can ask rather than a table in a document:

| Document code | Stored code |
|---|---|
| `FNAC` | `image_guided_fnac` |
| `CORE_BIOPSY` | `image_guided_core_biopsy` |
| `ABSCESS_DRAINAGE` | `image_guided_abscess_drainage` |
| `HYDROSTATIC_INTUSSUSCEPTION` | `hydrostatic_intussusception_reduction` |

The retired `image_guided_biopsy` deliberately carries **no** clinical code: it stood for FNAC and
core biopsy at once, and mapping it to either would claim those referrals recorded a technique nobody
wrote down. See ADR-019.

What is still the Clinical Lead's to decide is narrower than before: whether the *stored* identifiers
should also be the document's. That would be a rename with a data migration, and it is no longer
required for the two vocabularies to be reconcilable.

### 4.2 The field sets are the largest unimplemented part of A-02

`required_fields` was designed to hold an approved field set per procedure and the schema refuses one
without an approver. Filling it is not mechanical: the document names 176 fields across groups, and
several of them (`MED-*`, `IMG-*`, `CONS-*`) need columns, validation and a visibility rule before
they can be stored at all. The reason this has not been started is that doing it half-way would put
clinical questions on a form without the review framework that consumes them.

### 4.3 Clinical priority: assigned on the decision, and changeable afterwards

**Status: closed.** Both halves of §13.1's "assign/change" exist.

`RecordClinicalDecision` assigns the category, with the rule that it must fit the outcome: an approval
states routine or expedited, a redirect may state urgent direct coordination, and the outcomes that
release nothing store nothing. The value is written to the decision row and to the referral in one
unit of work, so neither can exist without the other.

`ReassignClinicalPriority` changes it afterwards, which could not go through the decision command
because a referral in `clinically_approved` is no longer in clinical review. It requires a rationale,
writes `referral_priority_changes` with both categories, and is refused in four situations that each
matter more than the change would:

| Refused | Why |
|---|---|
| The referral has no category | Assignment belongs to the decision; a category with no decision behind it is what AC-010 forbids |
| Same category | The table is a record of moves, not of restatements |
| `urgent_direct_coordination` | It says the queue is the wrong route, so acquiring it means leaving the queue — a redirect, not a change of position |
| An appointment already exists | Escalating a booked case is a scheduling decision, and this command cannot rebook. Saying "expedited" would promise an effect nothing delivers |

The last one is the boundary of the slice rather than an oversight: reordering a booked list is BL-C3's
territory, and it needs the resource engine, not a column.

### 4.4 The checklist role gap and the checklist gap are the same gap

**Status: re-framed, no owner decision needed yet.** Section 14 assigns every checklist item to a
responsible role using `REC`, `CLIN`, `PROC`, `TECH`, `NUR` and `LAB`. The application has `staff`,
`radiologist`, `coordinator`, `admin`, `physician` and `patient`; three of the document's roles have
no counterpart, and sixteen of the fifty-four items are owned by them.

Those three roles (`TECH`, `NUR`, `LAB`) appear nowhere in the approved matrix as holding clinical
authority. Their only duties are the checklist item, the technical or nursing resource confirmation,
and procedure-day status — none of which this release implements. So the missing vocabulary blocks
exactly one thing: the checklist. It does not block a clinical decision, an approval, a priority or a
booking.

That collapses two open items into one and removes the need for a decision now. The consequence is a
constraint on BL-D2 rather than a fork in the road:

- The checklist slice adds `TECH`, `NUR` and `LAB` to `Role` **and** the permissions those roles
  actually exercise, in one change. Adding empty roles first would create accounts that can sign in
  and do nothing, which `Role::permissions()` explicitly argues against.
- Until then, no checklist item may be assigned to a role that does not exist, and the checklist is
  not partially built on a mapping that would let a receptionist complete a nursing check.

### 4.5 Two governance inputs answered on 13 November 2026

**Status: recorded; one of them now has a place to live, and one is still short of three inputs.**

**Approval of published clinical copy.** Dawit Muluneh is the named approver, with an approval date of
2026-11-13, for the clinical content the package supplies (§17.1–§17.4 service pages, §11.2–§11.3
confirmation and upload wording, §10.5 urgent wording, and the policy pages). Read as one approver for
the whole set rather than one per page; if that is wrong, the correction is a per-page name and date,
which is exactly what the content model has to store.

The name and the date now live in a row rather than a template constant, per §17.5: `published_content`
(migration 0035) stores them per page and version, and `/services` renders whatever the record says.
Changing the approver is a data change.

What it still does **not** do is make anything publishable, and the reason is now specific rather than
structural. §17.5 asks for an author, a clinical reviewer and an approver — three records, which may
name one person but are not one field — plus an approval date, an effective date, a review date, a
version and a language/translation-review status. The package supplies the author (itself) and the
version (1.0); the decision above supplies the approver and the approval date; the English master is
its own source language. **The clinical reviewer, the effective date and the review date have not been
supplied.** So the four pages are imported as drafts whose record says exactly that, and both
`PublishContent` and the `ck_published_content_complete` CHECK refuse to publish them until the three
arrive. They are not blanks waiting to be defaulted to the approval date — an effective date and a
review date are decisions about when the words start and stop applying, and this is not the place to
make them up.

**Laboratory destinations for specimens (§19.1).** Destinations are serialised pathology laboratories:
`Pathology Lab 1`, `Pathology Lab 2`, and so on. The count is deliberately unspecified — laboratories
exist as they exist, and inventing a number would be inventing an operational fact. The consequence for
BL-C7 is a shape rather than content: the destination is a **reference to a configured lab row** with a
serial and a label, never a free-text string, because §19.1 requires a specimen record to say where it
went in a form that can be counted and audited.

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
- Do not let an approval carry "urgent direct coordination". When that category is right, the answer
  is a redirect to the receiving team, because the approved workflow effect is direct contact and
  acknowledgement — not a booking that waits in the queue the clinician just left.
- Do not default a clinical priority. A review nobody has made has no category, which is why the form
  starts at "not stated" (§12.4).
- Do not claim the reassignment action of §13.1 exists until it has a command, a rationale
  requirement and an audit event.
- Do not describe role-based clinical approval as privilege-verified approval. AC-009 and LP-003
  require a credential record this release does not have.
- Do not let a recorded urgent case read as acknowledged, accepted or booked. The confirmation says
  it is none of those (§10.5), the board says so again, and the status vocabulary keeps the clinical
  and coordination axes separate so neither can imply the other.
- Do not record a coordination decision by moving the status and calling that the record. The decision
  exists so that "the case is accepted" is not the only evidence that somebody accepted it; a move
  with no decision row behind it is exactly the state `INT-016` forbids.
- Do not let a deferral be expressed as an acceptance or a refusal. Two of the six approved choices
  release no state, and that is the point of them.
- Do not add an emergency consent override, flag or "bypass" path. §18.3 requires the circumstances, the
  authorising person and the review route to be defined by the Clinical Lead and Legal/Governance first;
  until then the honest record is that consent could not be obtained, with a reason, routed to a person.
  A boolean here would be the generic bypass the section forbids.
- Do not let an agreement be recorded for a representative whose authority has not been verified, and do
  not infer the relationship from who submitted the form (§18.2). The schema refuses it, and the reason
  is that an accompanying adult is not automatically a legal guardian.
- Do not treat this traceability record, or a passing test suite, as clinical or operational
  acceptance.

---

## 6. Next slices, in dependency order

1. The urgent clinical surface is complete: a case can be registered, claimed, contacted, escalated,
   decided, coordinated and closed. What the urgent pathway still lacks is everything after
   acceptance — resource confirmation and authorised scheduling (§10.3 steps 8–9), which is BL-C3's
   territory and needs the resource engine rather than a status.
2. Readiness checklists, which begin by adding `TECH`, `NUR` and `LAB` with their permissions
   (BL-D2, see §4.4), then BL-C3 and BL-C7.
3. Credential and privilege verification, so that clinical approval stops resting on a role name
   (AC-009, LP-003, BL-C6).
4. Public service, urgent and policy pages against the approved copy (BL-A1, BL-A3, BL-A6, BL-A7).
5. Specimen, follow-up and outcome records (BL-C7), now that the laboratory destination scheme is
   answered: a specimen names a configured lab row, and receipt is separate from dispatch.
6. Section 24 scenario execution (BL-F5), which needs the screens above and a staging environment.


