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

- **No names appear in this document.** Operations assigns individuals; the software does not hold
  an employee list, and inventing placeholder names would create the fictitious identities the
  decision forbids.
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
