# Role matrix — implemented grants mapped to the approved matrix

**Status:** assembled for signature. **Not yet approved.** Nothing on this page may be
described as approved authority until a named person signs it (BL-G5, C-4).

**Why this document exists.** Product Owner Decision 5 (14 November 2026) preserved the
approved role matrix and approved `TECH`, `NUR` and `LAB` as distinct Release 1 permission
roles. It also required a written statement of what the implemented roles are approved to
do. This is that statement. It records what the code grants, in the product's own
vocabulary, so a reviewer can disagree with a line rather than with a system.

**How to read it.** Each row lists the permissions a role holds *today*. A permission is a
door; the rule that decides who may walk through it is a separate check, and the last
column names it where one applies. Where Decision 5 describes an area of work the product
cannot yet perform, the row says so instead of implying a capability.

---

## 1. The nine roles

| Role | Value | May | Must not | Decided by |
|---|---|---|---|---|
| **Administrator** | `admin` | Manage bookings, accounts, clinic configuration, schedules; triage referrals; publish approved content; acknowledge and close urgent cases; upload, read metadata of, download and quarantine documents; read the notification list | Review or approve a clinical decision; record a credential verification; accept, decline or redirect an urgent case | Decision 5 (technical authority only); SEC 2.5, 6.8 |
| **Reception & booking officer** | `staff` | Manage bookings and schedules; submit and triage referrals; acknowledge an urgent case; document upload, metadata, download; notification list | Approve clinically; close or escalate an urgent case; verify a credential; administer accounts | Decision 5; WF-05 |
| **Interventional radiologist** | `radiologist` | Review and approve clinically, within a verified procedure-specific privilege; coordinate procedures; cast and close urgent coordination; record and end credential verifications; schedules; documents; notification list | Record a credential verification for an account that cannot approve; approve outside a current privilege | Decision 5, Decision 6, AC-009, ADR-022 |
| **Referring physician** | `physician` | Submit referrals; read **their own** referrals (the page is admitted by `referral.read.own`, the rows are decided by the verified profile named on the referral); document upload, metadata, download | See the clinic's triage queue; see a colleague's referrals; approve anything | Decision 5; AUTH-02/AUTH-03; ADR-014; BL-E1 |
| **Procedure coordinator** | `coordinator` | Manage bookings; coordinate procedures; acknowledge, escalate and close urgent cases; schedules; documents; notification list | Approve clinically; triage referrals (they hold no `referral.triage`); verify a credential | Decision 5; WF-05 |
| **Radiology technician** | `tech` | Read the notification list | Clinical review, priority, approval or consent; scheduling; triage; account or clinic administration | Decision 5 |
| **Nurse** | `nur` | Read the notification list | Clinical approval; any consent decision; scheduling; triage; administration | Decision 5 |
| **Laboratory officer** | `lab` | Read the notification list | Any access to a clinical record beyond specimen work; clinical review or approval; scheduling; administration | Decision 5 |
| **Patient or guardian** | `patient` | Nothing. Record-only identity (ADR-016): it exists so a referral and a guardian relationship have somebody to point at | Sign in at all | ADR-016 |

## 2. Where the code and Decision 5 do not yet meet

Decision 5 gives `TECH`, `NUR` and `LAB` areas of work. None of those surfaces exists, so
none of those permissions exists — and a permission for a screen nobody can open is a claim
rather than a control (the reason BL-E1 was closed). The gap is listed here rather than
hidden behind a grant:

| Role | Authority Decision 5 names | Current state | Arrives with |
|---|---|---|---|
| `TECH` | Assigned modality, equipment, room and technical readiness tasks | No readiness-task record, no assignment, no screen | BL-D2 (readiness checklists), BL-C3 |
| `NUR` | Assigned nursing readiness and nursing documentation | No nursing readiness record; documentation beyond consent and clinical assessment does not exist | BL-D2, BL-C3 |
| `LAB` | Specimen receipt, handling, processing and receipt-status documentation | Specimen records do not exist | BL-C7 |

Until then each of the three holds exactly one permission — the notification list, which
carries a reference and a time and nothing else. It is what makes a provisioned account
usable; it is not clinical authority, and `RoleTest` asserts the set exactly so that a
later grant fails the build and has to be argued for.

## 3. Surrounding rules Decision 5 fixes

- Patients and guardians do not receive accounts in Release 1 (ADR-016).
- Public submitters need no account; the portal is for referrers with a verified profile.
- Surgery and anaesthesia personnel are external participants: no login, no role.
- The Clinical Lead holds clinical decision permissions only when individually credentialed,
  privileged and assigned — the credential is the authorisation, not the job title
  (ADR-022).
- A verified practitioner approves only within verified procedure-specific privileges.
- Every permission is enforced server-side at record level; hiding a control in the
  interface is not a control.
- Operations confirms staffing before accounts are provisioned.

## 4. Signature

| Field | Value |
|---|---|
| Approver | *not yet supplied* |
| Role and authority | *not yet supplied* |
| Date | *not yet supplied* |
| Scope approved | *not yet supplied* |

An unsigned row above is a description of the code, not an approval of it. C-4 has been
answered at the level of principle; this document is the artefact BL-G5 needs a signature
on.
