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

## Adding an entry

1. Take the next free number. Never renumber.
2. Fill Context, Decision, Consequences, Status, Owner, Date, Supersedes.
3. If it replaces an earlier decision, mark the old entry **Superseded** and link forward, and
   record the successor in this index.
4. If it needs a signature, the status is **Implemented, unapproved** until the signature exists.
   Do not upgrade the status because the code works.
