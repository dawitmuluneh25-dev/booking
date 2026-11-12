# Architecture decision register

**Register ID:** HAKIMERAD-ADR-REGISTER
**Supersedes:** the ADR table formerly embedded in `README.md` §6
**Rule:** a decision that changes the shape of the system is recorded here before it is
implemented, not after. Code comments explain *what*; this register explains *why*, and who
owns the answer.

## Status vocabulary

| Status | Meaning |
|---|---|
| **Proposed** | Written down, not decided. No code may depend on it beyond a reversible stub. |
| **Accepted** | Approved by the named owner on the named date. |
| **Implemented, unapproved** | Built and running, but no named approver has signed it. It is a real decision that still needs a signature — the honest state of every entry migrated from the README. |
| **Superseded** | Replaced by a later ADR. The entry stays; the successor is linked. |
| **Rejected** | Considered and declined. Kept so it is not re-litigated from memory. |

An entry carries an owner *role* where no person has signed. A role is not an approval: it
names who must sign, which is why the status column says "unapproved" rather than "accepted".

## Index

| ID | Decision | Status | Owner | Date | Supersedes |
|---|---|---|---|---|---|
| [ADR-001](#adr-001) | Server-rendered PHP monolith, no SPA framework | Implemented, unapproved | Architecture | not recorded | — |
| [ADR-002](#adr-002) | Zero runtime Composer dependencies | Implemented, unapproved | Architecture | not recorded | — |
| [ADR-003](#adr-003) | MySQL only, no silent fallback | Implemented, unapproved | Architecture | not recorded | — |
| [ADR-004](#adr-004) | UTC in storage, IANA zone at the edges | Implemented, unapproved | Architecture | not recorded | — |
| [ADR-005](#adr-005) | A request is not an appointment | Implemented, unapproved | Clinical governance + Architecture | not recorded | — |
| [ADR-006](#adr-006) | Service worker caches static assets only | Implemented, unapproved | Architecture | not recorded | — |
| [ADR-007](#adr-007) | Explicit route table, thin controllers | Implemented, unapproved | Architecture | not recorded | — |
| [ADR-008](#adr-008) | Forward-only migrations with checksums | Implemented, unapproved | Architecture | not recorded | — |
| [ADR-009](#adr-009) | Backups are written in PHP, not by `mysqldump` | Implemented, unapproved | Operations + Architecture | not recorded | — |
| [ADR-010](#adr-010) | Retention redacts instead of deleting | Implemented, unapproved | Clinical governance + Architecture | not recorded | — |
| [ADR-011](#adr-011) | Opening hours are data, not constants | Implemented, unapproved | Operations | not recorded | — |
| [ADR-012](#adr-012) | Notifications leave through a bridge we own | Implemented, unapproved | Architecture + Operations | not recorded | — |
| [ADR-013](#adr-013) | Both intake models remain, with a stated boundary | **Accepted** | Product owner | 2026-11-10 | — |
| [ADR-014](#adr-014) | Institution is descriptive metadata, not a boundary | **Accepted** | Product owner | 2026-11-10 | — |
| [ADR-015](#adr-015) | Patients hold accounts and sign in during release 1 | **Superseded** by ADR-016 | Product owner | 2026-11-10 | — |
| [ADR-016](#adr-016) | No patient sign-in during release 1 | **Accepted** | Product owner | 2026-11-11 | ADR-015 |
| [ADR-017](#adr-017) | Yegara for human mail, Brevo for transactional email | **Accepted**, activation gated | Product owner | 2026-11-11 | — |
| [ADR-018](#adr-018) | The clinical particulars are approved; production is not | **Accepted**, implementation in progress | Clinical Lead (A-01–A-11), clinical teams and laboratory leads (A-12–A-15), Operations and Legal/Data Protection (A-16–A-19) | 2026-11-12 | — |
| [ADR-019](#adr-019) | Two identifiers per service: the catalogue code stays, the approved code is recorded beside it | Implemented | Architecture, on the approved catalogue in HR-IR-CLIN-R1-001 §3.1 | 2026-11-12 | — |
| [ADR-020](#adr-020) | Claiming an urgent case and closing one are different permissions | Implemented | Architecture, against the two-axis urgent aggregate (WF-05) | 2026-11-12 | — |

The 12 migrated entries were recorded in `README.md` §6 during the build. Their original
decision dates were never captured, and inventing one would make the register look more
complete than it is; the gap is recorded instead, and BL-G5 closes it by collecting signatures.

---

## ADR-001

**Decision.** A server-rendered PHP monolith with no SPA framework.

**Context.** The deployment target is shared hosting where a build step, a Node toolchain and a
long-running process are all unavailable or unreliable.

**Consequences.** Works on plain PHP hosting; no client-side state to secure; no bundle to
version. Server round-trips replace client routing, which is acceptable for a low-volume
clinical workflow and unacceptable if the UI later needs offline editing.

**Status.** Implemented, unapproved. **Owner.** Architecture.

## ADR-002

**Decision.** Zero runtime Composer dependencies.

**Context.** Every runtime package is a supply-chain surface that must be patched on a host with
no deployment automation.

**Consequences.** `composer install --no-dev` deploys only the autoloader. Anything that would
have been a library is hand-written and therefore owns its own bugs — the router, validator,
container, and migration runner are all in `src/`.

**Status.** Implemented, unapproved. **Owner.** Architecture.

## ADR-003

**Decision.** MySQL only. No silent fallback to another engine.

**Context.** Two dialects means two behaviours for transactions, `CHECK` constraints and
locking, and the reservation guarantee depends on all three.

**Consequences.** A missing `pdo_mysql` is a startup error, not a degraded mode. The track
decision required by `Implementation Plan.md` §2.3 is recorded separately as BL-G2.

**Status.** Implemented, unapproved. **Owner.** Architecture.

## ADR-004

**Decision.** Store UTC; convert at the edges using a configured IANA zone.

**Context.** The clinic operates on `Africa/Addis_Ababa`, which has no DST today, but a stored
local timestamp is a permanent ambiguity the moment anything changes.

**Consequences.** Every persisted instant is UTC. Rendering, slot labels and message bodies
convert through `Config::timezone()`. A deployment that leaves `APP_TIMEZONE=UTC` renders every
time three hours early — a configuration hazard, tracked as BL-G6.

**Status.** Implemented, unapproved. **Owner.** Architecture.

## ADR-005

**Decision.** A request is not an appointment.

**Context.** WF-01. The product's central safety claim is that submitting a referral never
reserves clinical time and never implies clinical approval.

**Consequences.** `bookings` are created in `requested`; `referrals` carry no time at all;
confirmation and scheduling are explicit later transitions performed by authorised staff. Any
screen that blurs the two is a defect, not a design preference.

**Status.** Implemented, unapproved. **Owner.** Clinical governance + Architecture.

## ADR-006

**Decision.** The service worker caches static assets only.

**Context.** A cached HTML page containing submitted data can present a stale booking as
current, which is a clinical-safety problem rather than a performance one.

**Consequences.** `PRECACHE` holds `offline.html`, CSS, JS, icons and the manifest. Navigations
are network-first with an offline fallback; non-GET requests are untouched. Cache name is
versioned so a deploy can evict the previous shell.

**Status.** Implemented, unapproved. **Owner.** Architecture.

## ADR-007

**Decision.** An explicit route table with thin controllers; every route declares its own access.

**Context.** The discarded prototype had routes nobody could enumerate and an impersonation
endpoint that no reviewer noticed.

**Consequences.** `Router::add()` refuses a route without `RouteAccess`. `RouteAccessManifestTest`
pins the public set, so widening the attack surface requires editing an expectation in a test.

**Status.** Implemented, unapproved. **Owner.** Architecture.

## ADR-008

**Decision.** Forward-only migrations, each checksummed.

**Context.** MySQL DDL is not transactional. A half-applied migration must be detectable rather
than guessed at.

**Consequences.** Every migration is applied once and recorded with a checksum;
`MigrationIntegrityTest` rebuilds the schema from empty and asserts the structural invariants.
Rollback is a restore, rehearsed as BL-G4.

**Status.** Implemented, unapproved. **Owner.** Architecture.

## ADR-009

**Decision.** Backups are written by PHP, not by `mysqldump`.

**Context.** Shared hosting disables `exec()`, and a dump produced by a binary the host may not
have is not a backup you can rely on.

**Consequences.** `db:backup` streams a gzip dump through PDO; `db:restore-drill` restores into a
scratch schema and compares row counts. The drill has been executed, not merely documented.

**Status.** Implemented, unapproved. **Owner.** Operations + Architecture.

## ADR-010

**Decision.** Retention redacts instead of deleting.

**Context.** The schedule, status history and audit trail must stay defensible after the person
is no longer in the record.

**Consequences.** Rows survive; identifiers are overwritten. Windows shorter than 30 days are
refused, so a misconfiguration cannot become a data-destruction event.

**Status.** Implemented, unapproved. **Owner.** Clinical governance + Architecture.

## ADR-011

**Decision.** Opening hours are data, not constants.

**Context.** The clinic changes its week without a deployment, and the same table must drive both
the availability calendar and booking validation or the two will disagree.

**Consequences.** `resource_availability` is the single source; `ClinicCalendar` reads it and the
slot picker is generated from the same quantum logic the reservation engine enforces.

**Status.** Implemented, unapproved. **Owner.** Operations.

## ADR-012

**Decision.** Notifications leave through a bridge we own.

**Context.** Provider choice, credentials and sender IDs change for commercial reasons and must
not be reachable from a request.

**Consequences.** The app writes one outbox row in the command's transaction and posts one
minimal JSON payload from a dispatcher. A delivery failure never rolls back a clinical command
(WF-14). WhatsApp and SMS remain behind operational approval.

**Status.** Implemented, unapproved. **Owner.** Architecture + Operations.

## ADR-013

**Decision.** Both intake models remain in release 1, with the boundary below. `bookings` is not
retired and `referrals` does not absorb it.

**The boundary.**

| | `referrals` | `bookings` |
|---|---|---|
| What it is | Clinical intake: a request for a procedure with **no time chosen** | Appointment record: a specific slot a patient asked for and staff confirmed |
| Who reaches it | Patients, guardians and verified referring physicians (public intake, built in Phase 1) | Staff only, behind `bookings.manage`. No public route, and none may be added |
| Who decides | A radiologist approves before anything is scheduled | Staff confirm or decline the requested slot |
| Vocabulary | `submitted` … `approved_for_scheduling` | `requested` → `confirmed` / `cancelled` |

**Why this is written down rather than left implicit.** The two models coexisted by accident: the
public homepage sent patients to `/bookings/new`, which redirects an anonymous visitor to `/login`,
while the model the specifications describe had no screen at all (F-01, F-02). That was not a
missing feature; it was an undefined boundary producing a broken front door.

**Consequences.**

- The public call to action moves to the referral pathway. "New booking" leaves the public header
  and stays in the staff navigation, labelled so it cannot be mistaken for intake.
- No screen offers both. A page links either to intake or to appointment management, never to both.
- Two status vocabularies persist, and every screen must say which one it is showing.
- `bookings` gains no clinical capability: no approval step, no referral link requirement, no
  procedure catalogue. Widening it into a second clinical intake would vacate this decision.
- The `bookings` notification templates stay, because staff confirmation of a requested slot is a
  real event that a patient is told about.

**Not decided here.** Whether `bookings` is eventually retired once the referral pathway handles the
same volume is left open, and would be a new ADR superseding this one.

**Status.** Accepted. **Owner.** Product owner. **Date.** 2026-11-10. **Supersedes.** —

---

## ADR-014

**Decision.** An institution is descriptive metadata. It is not a tenancy boundary, not an
authorisation input, and nothing in the product may present it as one.

**Context.** `physician_profiles.institution` is free text typed by the physician. Two options were
put to the Product Owner: add institutions and membership and enforce hospital-level isolation, or
record that institution is a label. The Technical Advisory Committee recommended the second for
release 1 and the Product Owner accepted it.

**Why this needed deciding at all.** The access policy's own comment claimed a physician "must not
read a colleague's referrals from another institution", which implies institution is what stops
them. It is not: a physician sees a referral only when their own profile is the one it was
submitted under. That rule is narrower than institution scoping and depends on no text field — but
the comment told a reader the opposite, and a comment that overstates a control is how a control
gets removed later by somebody who believed it was there.

**Consequences.**

- Authorisation continues to key on the physician profile (AUTH-02/AUTH-03), never on institution.
- `testTheSameInstitutionIsNotTheSameAuthorization` is the executable statement of this decision and
  must keep passing: two physicians at one hospital still cannot see each other's referrals.
- Renaming a physician's institution changes a label and nothing else. No access is gained or lost.
- The product must not offer institution-level filtering as though it were isolation.
- If multi-hospital tenancy is later required, it is a new ADR superseding this one, and it must
  bring institution records, membership, and a test proving cross-institution denial.

**Status.** Accepted. **Owner.** Product owner. **Date.** 2026-11-10. **Supersedes.** —

---

## ADR-015

**Decision.** Patients hold accounts and sign in during release 1. The patient portal is in scope,
not conditional.

**Context.** `Role::Patient` existed with grants and no sign-in path, which the audit called out as
either a missing capability or a dormant permission. The Technical Advisory Committee recommended
removing the role for release 1. The Product Owner decided the opposite: patients sign in, and the
role stays because it is used.

**What this commits the product to.** The decision is larger than a login form, and the cost is
recorded here rather than discovered later:

- **Identity.** A patient account must be tied to a referral without letting anybody claim somebody
  else's case. The existing single-use status link (BL-A2) proves possession of a phone number; it
  is not an account. Linking an account to an existing referral is the step that needs designing.
- **Authorisation.** Every patient-facing endpoint enforces object-level access on the server: a
  patient sees referrals they submitted, and a recorded guardian with acting authority sees the same
  set. Denial is 404, never 403 (SEC 8.4).
- **Notifications.** Sign-in needs a recovery path, so BL-E3 (a real email provider behind the
  outbox) becomes a prerequisite rather than a parallel task.
- **Support.** Somebody has to answer "I cannot get in" outside office hours, and that owner is not
  yet named (BL-G6).
- **Privacy.** The portal shows the patient their own data, which is the point — and it means the
  retention rule (ADR-010) is now visible to the person it protects, not only to staff.

**Consequences for the backlog.**

- BL-E1 splits in two: the **referrer** portal and the **patient** portal are separate deliverables
  with separate authorisation tests, so the patient half cannot delay the physician half.
- The physician dashboard is explicitly not weakened to make the patient path simpler.
- Patient and guardian identity data stay recorded whether or not the patient ever signs in: no
  patient login would not have meant no patient record, and having the login does not make the
  record optional.

**Status.** Superseded by ADR-016 on 2026-11-11. The decision below was accepted and then reversed before any patient-facing code was written; it is kept because the cost analysis is still the best statement of what patient access would require. **Owner.** Product owner. **Date.** 2026-11-10. **Supersedes.** —

---

## ADR-016

**Decision.** Patients do not sign in during release 1. No patient account can open a session.

**Context.** ADR-015 recorded the opposite a day earlier. The Product Owner reversed it before any
patient-facing screen existed, which is the cheap moment to reverse it: nothing was built, so
nothing has to be unwound. The advisory committee had recommended this position, and that
recommendation is now the accepted decision.

**How it is enforced, and why there.** A patient account can hold a password, so "there is no
patient login screen" would not be true — the ordinary sign-in form would accept it. The refusal
therefore lives in `Auth::attempt`, next to the deactivation check, and returns the same failure as
a wrong password. A distinct "this account may not sign in" answer would confirm that an address
exists, which is the enumeration oracle the login path is built to avoid.

**What this does not remove.** The committee was explicit, and it matters:

- A patient record still exists, with the details a referral is submitted for.
- Guardian relationships and consent records stay: they say who may act for a patient, not who may
  log in.
- Verified referring physicians still submit referrals, and staff may submit on a patient's behalf.
- The physician dashboard is untouched.

**What this does remove, stated plainly.** Patient self-submission through the product. With no
patient sign-in there is no account to submit from, so referrals arrive from referrers and from
staff. Anyone reading the Phase 1 exit criterion — "a referrer **or patient** can submit" — should
read it as the referrer half only.

**How far it goes: record-only, and no further.** The owner's instruction was to retain the role as a
record-only identity, so it keeps its place and loses its capabilities:

- `Role::Patient::permissions()` returns `[]`. The four grants it held — `referral.submit`, document
  upload, metadata read, document download — are removed rather than left dormant. A grant nothing
  can reach is a permission waiting to be trusted by the next screen that reads the role table.
- `ReferralStatus::allowedActors()` no longer lists a patient on `submitted` or `cancelled`. A role
  with no permission cannot perform either, and listing it would describe an authority that does not
  exist.
- `submittedOnBehalfOf()` no longer branches on `Role::Patient`. Staff record referrals, and the
  guardian fields — not the account's role — say whether the request was the patient's own or made
  on their behalf. That keeps `submitted_as` meaningful: it now states two true things, who entered
  the record and under whose authority.
- The `ReferralAccessPolicy` branch for `Role::Patient` stays. It cannot be reached without a
  session, and it is the correct rule if access ever returns; removing it would make the policy's
  `match` describe one less case than the system has.

**What would reopen this.** A patient portal is a new ADR superseding this one, and it must bring
the identity-linking design ADR-015 set out. Nothing here forecloses it; it forecloses doing it by
accident.

**Status.** Accepted. **Owner.** Product owner. **Date.** 2026-11-11. **Supersedes.** ADR-015.

---

## ADR-017

**Decision.** Two email providers, each for the job it is good at. Yegara keeps the human inbox
(`booking@hakimerad.com`). Application-generated notifications are sent through Brevo over its
HTTPS API from `notifications@hakimerad.com`, behind a provider adapter. The Operations Lead is the
credential custodian; Engineering receives a scoped service key, never the account password. The
full decision record is O-3-DEC-01.

**Context.** One shared-hosting mailbox cannot serve both roles. Human correspondence needs an
inbox somebody reads; transactional delivery needs bounded retries, a record of what the provider
accepted, and a way to reconcile a message against a later delivery report — none of which a shared
mailbox offers, and its sending limits are a hard ceiling rather than a policy. The split also keeps
a deliverability problem from becoming an email outage for staff.

**How it is enforced, and why there.**

- The provider is a `NotificationTransport`, so no workflow knows Brevo exists. A different
  provider is an adapter, not a rewrite — and O-3 forbids substituting one without a new decision.
- The credential is read from the environment, so it is not in the document root, not in Git and
  not in a template anybody can fetch. An empty key fails per message; it never silently succeeds.
- Production sending is off unless `NOTIFY_PRODUCTION_APPROVED` is set. An unset flag means
  unapproved, which is the safe reading of a missing decision. The unactivated transport refuses
  per message rather than falling back to the log driver, because a log driver marks the outbox
  *sent* and would leave the queue looking healthy while nothing left the building.
- The email subject carries the notification name and the reference only. A subject is the one line
  that survives forwarding and lock-screen previews, so it must not say why anybody is attending.
- On rejection only the provider's machine code is kept, never its message: Brevo's error text can
  quote the recipient address back, and the outbox persists failures into a column operators read.
- A 4xx is permanent and dead-letters at once; a 5xx or network error retries on the existing
  ladder. Retrying a rejected payload five times delays nothing and hides the cause.
- `notification_outbox.provider_message_id` records what the provider accepted. Acceptance is not
  delivery and delivery is not acknowledgement; the identifier is only what makes asking possible.

**What this does not do.** It does not activate production email. It does not receive delivery or
bounce webhooks — the column can hold that data, but the endpoint needs a provider account and a
staging environment to build against honestly. It does not fall back to personal Gmail, Microsoft
365 SMTP or Yegara SMTP under any condition; email is not a clinical channel, and an email is never
evidence that a clinician saw anything.

**Status.** Accepted by the Product owner on 2026-11-11 (O-3-DEC-01), with activation conditional on
the section 8 evidence: mailbox verification, organizational Brevo onboarding, host connectivity and
staging delivery, DNS and mail-flow checks, and release evidence. **Owner.** Product owner.
**Date.** 2026-11-11. **Supersedes.** —

---

## ADR-018

**Decision.** The Release 1 clinical particulars are approved. HR-IR-CLIN-R1-001 (v1.0) defines the
clinical data, review, priority, readiness, consent, specimen and patient-content model for four
procedures; HR-IR-R1-APP-001 records that A-01 to A-19 are **approved** and that A-20 —
engineering implementation — is **authorized**. Production release is **not** authorized, and
neither is production database cutover.

The four procedures keep separate workflows: image-guided FNAC, image-guided core needle biopsy,
image-guided abscess drainage, and hydrostatic intussusception reduction as urgent hospital
coordination rather than an elective booking. Limited unauthenticated intake remains the only public
route: no patient accounts, no public status lookup, no public self-service booking, no institution
tenancy. Clinical approval precedes scheduling, and a requested priority is never a clinical one.

**Context.** Until this decision every clinical detail in the product was either a preserved
baseline or a gap engineering had to leave visibly empty. `procedures.required_fields` is NULL by
design and the schema refuses a field set that names no approver; `referral_checklists` did not
exist; the urgent board has no configured escalation because no interval had been approved. That
honest emptiness was the correct state while the decision was open, but it also meant the product
could not become clinically complete by finishing code. The approval converts those empty slots into
specified content, and it is the first time the clinical model is fixed enough to implement against.

**How it is enforced, and why there.**

- The approved priority model is a domain enum, not a form option list. `Priority` holds three
  categories and splits them into requestable (routine, expedited) and clinical (adding urgent
  direct coordination). The split lives in the enum because a form is not the only caller and a
  hand-crafted POST is not a different kind of user.
- The two `referrals` columns state the same rule as the enum in two halves: `requested_urgency`
  accepts the requestable half only, `clinical_priority` accepts all three. A public intake cannot
  produce "urgent direct coordination" by posting the value the clinical screen writes.
- Retired vocabulary is migrated, not abandoned. `priority` and `urgent` had exactly one meaning
  each in the approved model, so migration 0024 converts them; the columns then refuse them, which
  is what stops the old words from reappearing in a report that is compared against the model.
- A category that claims urgency carries a clinical rationale. The requirement binds the clinical
  decision record, not the request: a referrer's words are not a justification, and the request
  object has no field that could pretend otherwise.
- The category is assigned by the clinical decision, not by intake. `RecordClinicalDecision` requires
  it on approval, stores it on the decision row (migration 0025) and on the referral in one unit of
  work, so there is no state where a referral has been released into a queue without a category
  behind it, or a category with no decision behind it. Each outcome may only carry a category that
  fits its workflow effect: an approval takes routine or expedited, a redirect may take urgent direct
  coordination, and declining, deferring and requesting information store none. An approval carrying
  urgent direct coordination is refused rather than recorded, because approval is what puts a case
  into the queue that category exists to avoid.
- A completed checklist is evidence that checks were recorded, never an authorization to proceed;
  nothing in the checklist path writes a referral status.
- The role matrix is enforced where it already was — `Role::permissions()` grants no clinical
  capability to `Admin`, and `ReferralStatus::allowedActors()` refuses an administrative clinical
  transition. The approval confirms that model rather than replacing it.
- Unresolved operational and legal items stay unresolved. The document names 25 local-policy and
  Operations dependencies (LP-001 to LP-025); none is satisfied by having a field for it.

**What this does not do.** It does not approve a clinical threshold, a fasting duration, a
medication interruption schedule, a sedation protocol, an observation period or a discharge
criterion — every one of those remains local policy, and the checklist deliberately contains none of
them. It does not set an acknowledgement interval in minutes: LP-020 is open, so escalation timing
still has no default and the escalation panel still reads "no policy configured". It does not confirm
staffing, equipment, room, modality or laboratory availability (LP-001, LP-002, LP-019); a procedure
is not bookable on the strength of a catalogue row. It does not authenticate the individual
signatories — the approval record states that it records decisions communicated by the project
decision-maker and does not replace institutional records. It does not authorize production release,
and the Section 24 acceptance scenarios (TS-001 to TS-022) remain mandatory and unexecuted.

**Status.** Accepted 2026-11-12 as recorded in HR-IR-R1-APP-001; implementation in progress, with
the remaining mapping in `docs/clinical/clinical-particulars-traceability.md`. **Owner.** Clinical
Lead for A-01 to A-11, clinical teams and laboratory leads for A-12 to A-15, Operations and
Legal/Data Protection for A-16 to A-19, Product Owner and Engineering Lead for A-20. **Date.**
2026-11-12. **Supersedes.** —

---

## ADR-019

**Two identifiers per service: the catalogue code stays, the approved code is recorded beside it**

**Context.** HR-IR-CLIN-R1-001 §3.1 names the Release 1 services `FNAC`, `CORE_BIOPSY`,
`ABSCESS_DRAINAGE` and `HYDROSTATIC_INTUSSUSCEPTION`. The catalogue already stores
`image_guided_fnac`, `image_guided_core_biopsy`, `image_guided_abscess_drainage` and
`hydrostatic_intussusception_reduction`, and `referrals.procedure_code` is a foreign key onto them.
The two vocabularies describe the same four services; they are not the same string. A reader moving
between the approved document, the SOPs, the acceptance scenarios and a database row had no way to
cross without a table kept in a markdown file, which nothing could test.

**Decision.** Both identifiers are kept, and both are stored. `procedures.code` stays the operational
identifier that referrals reference; `procedures.clinical_code` carries the document's code with a
unique key. Neither replaces the other, and neither is derived from the label.

**Consequences.**

- The mapping is a row, so a test can assert it (`testApprovedProcedureCodesAreRecordedOnceAgainstTheCatalogue`)
  and a report can join on it. The markdown table in the traceability record is now a convenience, not
  the source of truth.
- The unique key means one catalogue row cannot claim two approved identities, and the approved code
  cannot drift onto a second service.
- The retired `image_guided_biopsy` carries no clinical code. It represented FNAC and core biopsy at
  once, which is why it was retired; mapping it to either would claim the referrals stored under it
  recorded a technique nobody wrote down. NULL is the honest value, and the unique index permits many
  NULLs.
- Renaming the stored codes to the document's is still open, and still a data migration touching every
  referral. This decision makes that rename unnecessary rather than forbidding it.

**Status.** Implemented (migration 0026). The catalogue it describes was approved under A-01; the
choice of *how* to reconcile the two vocabularies is an engineering decision and is recorded here so
it is not re-litigated. **Owner.** Architecture. **Date.** 2026-11-12. **Supersedes.** —.

---

## ADR-020

**Claiming an urgent case and closing one are different permissions**

**Context.** The urgent aggregate already separates two axes: `urgency_status` is the clinical
question, `coordination_status` is whether anybody has reached the family or the receiving team
(WF-05). `UrgentCoordination::allowedActors()` encodes that separation — every internal role may
acknowledge, because acknowledgement says "I have seen this" rather than "I have decided this",
while escalation is limited to the coordinator and the radiologist.

The role model had one urgent permission, `urgent.coordinate`, held by the coordinator and the
radiologist only. Gating a board on it would have made the surface unreachable to reception,
which is the role most likely to be at the desk when a case arrives — and reception is exactly
the role WF-05 wants able to record the claim. The alternatives were to grant reception the power
to close a child's case in order to let them open the screen, or to gate the routes on nothing
and let the domain refuse afterwards, which would have made every refusal a 500.

**Decision.** Urgent coordination has two permissions. `urgent.acknowledge` covers seeing the
board, opening a case, acknowledging it and recording a contact attempt; `urgent.coordinate`
covers escalating and closing. The split follows the axis the domain already draws, not a new
distinction invented for the screen.

**Consequences.**

- Reception holds the first and not the second, which is what the transition tables already said
  about them. The board is reachable at 03:00 and the authority to end a case is not.
- The escalation and closure routes still carry the stronger permission, and the domain still
  refuses a role the transition table does not name. The permission opens the door; the table
  decides who may walk through it. A radiologist reaching closure gets a refusal, because
  `UrgentStatus::allowedActors(Closed)` names the coordinator and the administrator, not them.
- `Role::Admin` gains both, because the status model lets an administrator close a case. It
  gains no clinical capability, which `RoleTest` continues to assert.
- Intake stays on `referral.submit`, so the coordinate role cannot register a case it was told
  about by telephone. That is a deliberate consequence of keeping submission and coordination
  separate, and whether it should change belongs to the role-matrix approval (C-4), not to this
  decision.

**Status.** Implemented. **Owner.** Architecture, on the authorisation model approved under
A-10 and the two-axis aggregate in the database specification. **Date.** 2026-11-12.
**Supersedes.** —.

---

## Adding an entry

1. Take the next free number. Never renumber.
2. Fill Context, Decision, Consequences, Status, Owner, Date, Supersedes.
3. If it replaces an earlier decision, mark the old entry **Superseded** and link forward, and
   record the successor in this index.
4. If it needs a signature, the status is **Implemented, unapproved** until the signature exists.
   Do not upgrade the status because the code works.
