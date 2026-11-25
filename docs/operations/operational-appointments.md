# Operational appointments — Release 1

Source: **HR-ENG-DEC-R1-003 D-07** (22 November 2026), "Adopt five role-based operational
appointments". Implemented in `src/Domain/OperationalAppointment.php` and held to the decision's
wording by `tests/Unit/OperationalAppointmentTest.php`.

## The two constraints that shape this document

D-07 adopts five responsibilities and then restricts them in two sentences:

> Do not create fictitious employee identities.
>
> Do not infer clinical privileges from an operational job title. Keep permissions aligned with
> verified role assignments and delegated authority.

Both are about the same mistake: treating a job title as an authorisation. An appointment is a
responsibility somebody is on the rota for. It is **not** a login role, and holding one grants no
permission. Access is decided by the role the person signed in with (`Role`, consulted by
`Actor::can()`), and by the delegated authority recorded against them.

Two consequences worth stating because they look like omissions:

- **No names appear in this document, and none are seeded.** Operations assigns individuals. Since
  25 November 2026 the software *does* hold the holders — D-05 requires a named holder and a deputy
  per appointment — but it holds only what Operations enters on `/admin/operations` (see §6 below).
  Nothing is pre-filled by a migration, because a placeholder name is the fictitious identity the
  decision forbids. This document still names nobody: the record of who holds what is the table, and
  the record of who changed it is the audit trail.
- **No appointment maps to a permission in code.** The enum has three methods — title,
  responsibility, and `confersClinicalAuthority()` — and none of them returns or names a permission.
  A test asserts that by reflection, so adding a `permissions()` method fails the build rather than
  quietly turning a rota into a grant.

## The five appointments

| Appointment | What it owns (D-07 wording) | What it must not be read as |
|---|---|---|
| **Operations Lead** | overall operational ownership, staffing coordination, service readiness, and operational oversight | authority to approve clinical content or a procedure |
| **Referral and Intake Coordinator** | intake administration, referral completeness, communication with referring parties, and intake tracking | authority to accept a referral, or to decide its clinical priority |
| **Clinical Coordination Officer** | coordination between referring teams, authorized clinicians, and relevant receiving services **without independently exercising clinical decision authority** | clinical decision authority — the decision says so in the sentence that creates the role |
| **Procedure Scheduling and Resource Coordinator** | approved procedure scheduling, resource coordination, and appointment administration | authority to approve a procedure, or to schedule one that is not approved |
| **Backup and Escalation Duty Coordinator** | backup operational coverage, escalation monitoring, and documented handover | authority to escalate to a contact route that has not been verified |

## How access is actually assigned

1. A person is given a **Role** — `admin`, `staff`, `coordinator`, `radiologist`, `physician`,
   `nurse`, `tech`, `lab` or `patient` — through the existing account flow. That is the only thing
   that grants a permission.
2. The **appointment** is recorded operationally: it says who is on the rota for a responsibility,
   not what their login may do.
3. Where a responsibility needs a capability, the capability is granted to the *role*, or through a
   specific permission the role already holds. It is never granted because somebody's job title
   contains the word "clinical".

The practical reading: a Clinical Coordination Officer who signs in as `staff` can do exactly what
`staff` can do. If the work needs more than that, the answer is a verified role assignment with the
authority behind it — not a wider role invented for the rota.

## Assignment rules

- Operations assigns actual individuals and confirms coverage before production operations begin
  (D-07's operational condition).
- The same person may hold an appointment and a clinical role; the two remain separate records and
  the appointment does not widen the role.
- One appointment may be shared by several people. Nothing in the model assumes an appointment has
  exactly one holder.
- The source-document reconciliation D-07 records is **not** closed by this file: the supplied MySQL
  Database Design Specification labels §4.2 "Patients and Referral Intake", and these five
  appointments are an operating-model decision, not a correction to that section. The document owner
  reconciles the cross-reference; this file does not claim to have done it.

## 6. Who holds them — added by D-05 and D-08 (25 November 2026)

D-07 adopted the five responsibilities as structure and named nobody. **D-05** is the decision that
names them, and **D-08** is the decision about where a name lives: secrets stay in the environment,
operational values move into the database.

### 6.1 The table

`operational_appointments` (migration `0048`) holds `role_key`, `holder_name`, `deputy_name`,
`updated_by` and `updated_at`. The five rows are seeded by the migration with both names `NULL`,
so the screen lists every responsibility before anybody fills one in. The responsibility text is not
duplicated here: it stays in `OperationalAppointment`, which is why a new responsibility is a code
change and a new holder is an operational one.

### 6.2 The screen

`/admin/operations`, permission `ClinicConfigure` — the same permission as the escalation roster, and
for the same reason: this is operational configuration, and holding an appointment grants no access.
Each row shows the title, what the responsibility owns, the current holder and deputy, and who last
changed it.

Three rules are enforced, each because of a failure the project has already met:

| Rule | Why |
|---|---|
| The role key must be one the enum declares | An appointment invented by a typo would sit in the table for ever, count toward nothing, and never appear on the screen |
| The deputy cannot be the holder | The deputy exists to be called when the holder is unreachable; the same name twice is a rota with no cover that reads as covered |
| An empty field is a vacancy, stored as `NULL` | An empty string is a holder the coverage count cannot see |

A submission that changes nothing writes nothing. The form posts all five rows and one button, so
saving unconditionally would produce four non-events per correction and make the trail unreadable.

### 6.3 What is recorded

Every change writes one `audit_events` row: action `operations.appointment_changed`, object type
`operational_appointment`, object ref the responsibility key, the signed-in account, and
`before_json` / `after_json` carrying `{"holder": …, "deputy": …}`. The audit trail issues no `UPDATE`
or `DELETE`, so those maps are the history.

### 6.4 What the release gate reads

`release:gates` counts an appointment as **covered** only when it has both a holder and a deputy — the
same rule the screen's vacancy alert states. Before migration `0048` the fact was `null`, meaning
unobservable; now it is a count, and the gate reports `open` until five of five are covered.

### 6.5 Filling it in

1. Sign in with an account holding `ClinicConfigure` (admin, or the radiologist role).
2. `/admin/operations` → name a holder and a deputy for each responsibility.
3. Save. The vacancy alert disappears when all five are covered; every change lands in the audit trail.

D-05 names **Trede Agua** and **Dawit Muluneh** as holders within the approved decision; the deputies
and the remaining appointments are entered by the people accountable for them, per deployment.

### 6.6 What this still does not do

- **No rota.** No shifts, hours or handover. D-05 asks who holds a responsibility and who covers it;
  anything more would need a decision nobody has taken.
- **No contact numbers here.** A roster of names is not a directory; numbers live on
  `/admin/escalation-routes`, where verification is recorded.
- **No access.** Holding an appointment still permits exactly what the account's role permits — the
  rule in §"How access is actually assigned" above is unchanged.
- **Per deployment, per database.** Each hospital installation has its own database, so the same five
  responsibilities are named separately for each: one codebase, one vocabulary, one set of holders per
  installation.
