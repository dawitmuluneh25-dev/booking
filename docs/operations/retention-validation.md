# Retention schedule — Legal validation and activation record

**Decision:** HR-ENG-DEC-R1-003 **D-02** (22 November 2026).
**Schedule:** `App\Domain\RetentionSchedule`, adopted 24 November 2026, switched off.
**Validation:** given **25 November 2026** by **Gemechisa Dalu** (Legal / Data Protection).
**Activation:** `RETENTION_SCHEDULE_ACTIVE=true` in the production environment.

---

## 1. What was validated

D-02 made activation conditional on Legal/Data Protection validating four things before production.
The validation covers the schedule as implemented, class by class:

| Record class | Period | Applies to |
|---|---|---|
| `clinical_and_procedure` | 5 years (1,825 days) | Procedure records, clinical notes, milestones |
| `referral_and_intake` | 7 years | Referrals, assessments, decisions, consent |
| `uploaded_clinical_documents` | 5 years (1,825 days) | Documents attached to a referral |
| `appointment_and_administrative` | 7 years | Appointments, resources, scheduling history |
| `notification_and_outbox` | 12 months (365 days) | Notification records and the outbox |
| `dispatch_runs` | 5 years (1,825 days) | Delivery schedule history — added 26 November 2026 |
| `security_and_audit` | 7 years (2,557 days) | Audit trail, sign-in records, security events |

Backups: **35 days maximum**, which is a ceiling rather than a target — see §6.

The validation covers the periods, the lawful basis for holding each class, the preservation
exceptions D-02 names, and the applicable Ethiopian requirements. It is recorded here as a statement
of what was validated and by whom; it is not a legal opinion authored by engineering.

## 2. What activation does

With the flag off (the state until today), `retention:purge` applies **one** seven-year window to every
record. That is safe and wrong: it keeps notification rows for seven years and offers no way to reduce
the period for a class that does not need it.

With the flag on, `retention:purge` applies the class-specific periods above. The change is not
retroactive in the sense of deleting anything a decision protects: every period is shorter only where
the class is lower-risk, and every purge writes an audit row naming the class, the count and the
cut-off.

## 2a. The defect that blocked activation, and its resolution (25 November 2026)

**Status: resolved.** The flag is now on, and this section is kept because the defect is the reason the
wiring exists — deleting it would hide how easily a schedule can look adopted and do nothing.

The validation above was given. The activation was **attempted and reverted on the same day**, because
setting the flag would have changed nothing and claimed something.

`retentionService()` builds one `RetentionPolicy` from `RETENTION_DAYS` and passes it to
`RetentionService`. The per-class schedule is **never consulted by the purge path**:
`RetentionSchedule::daysFor()` is called only by `retentionScheduleReport()`, and that function is not
called by any command. So:

- flipping `RETENTION_SCHEDULE_ACTIVE=true` changes no retention behaviour at all;
- the only class whose period differs from the seven-year default — `notification_and_outbox`, 365
  days — has no purge implementation anywhere in the codebase;
- `retention:plan` prints the single window, so an operator reading it cannot tell the schedule exists.

Activating in that state would have produced a green-looking flag, a gate reading `partial`, and no
change to what the system retains. That is the exact failure mode this project has spent a month
removing, so the flag was removed from `.env` and the work is recorded as a **blocking defect**:

> **DEFECT (D-02 activation), now fixed:** the per-class schedule was data and documentation, not
> behaviour. Activation required a purge path that honours `RetentionSchedule` per class, starting with
> `notification_and_outbox` (365 days), a dry-run that reports per class, and run records naming the
> class.

### What changed to fix it

| Piece | Before | Now |
|---|---|---|
| Booking sweep period | `RETENTION_DAYS` | `daysFor('appointment_and_administrative')` when active, `RETENTION_DAYS` otherwise |
| Referral sweep period | `RETENTION_DAYS` | `daysFor('referral_and_intake')` when active |
| Notification sweep | **did not exist** | `NotificationRetentionService` — retires outbox rows at `daysFor('notification_and_outbox')` = 365 days |
| Outbox redaction | recipient overwritten only when its booking was redacted | recipient, payload and `last_error` overwritten; `redacted_at` stamped; `dedupe_key`, template, channel, status and provider id kept |
| Run record | bookings, referrals, documents | plus `notifications_redacted` (migration 0047) |
| `retention:plan` | single window | per class, plus the schedule table — `retentionScheduleReport()` is now reachable |
| `retention:purge` | two sweeps | three, when the schedule is active |

**What survives in a retired outbox row, and why:** the row itself (it is the delivery record), the
unique `dedupe_key` (it is what stops a retried dispatch sending a second message), and the template,
channel, status, timestamps and provider message id (the accounting `outbox:receipt` reconciles against).
What does not survive is the phone number, the payload naming procedure and case, and the provider error
that sometimes echoes the recipient.

**Verified:** `tests/Feature/NotificationRetentionTest.php` asserts both halves against the real schema —
an old row loses its recipient and payload while keeping its accounting, a fresh row is untouched, a
second sweep counts zero, and the run lands in `retention_runs.notifications_redacted`.

Residual risk, stated rather than hidden: `security_and_audit` has a seven-year period and **no sweep**.
The audit trail is append-only by design, and a retention rule that deletes audit rows is a decision
nobody has taken. The class is therefore declared and unenforced, and this document says so.

## 3. How to activate in production

```bash
# In the production .env — never in the repository
RETENTION_SCHEDULE_ACTIVE=true
```

Then, before the first purge runs:

```bash
php bin/console retention:plan          # prints what would be redacted, per class, without writing
php bin/console retention:purge         # refuses without --yes; still dry-runs first
```

The flag is deliberately absent from `.env.example`'s defaults as a `true` value: an activation that
happens because somebody copied an example file is not an activation anybody decided.

## 4. Evidence this happened

| Evidence | Where |
|---|---|
| The validation itself | this file, §1 and the signature block below |
| The schedule in code | `src/Domain/RetentionSchedule.php`, classes and periods |
| The gate reading it | `php bin/console release:gates` — retention row moves from `open` to `partial` while the flag is on |
| The purge behaves per class | `tests/Unit/RetentionScheduleTest.php`, `tests/Feature/RetentionPurgeTest.php` |

Note the gate's honest word for this state: **`partial`**, not `met`. A flag being on is an assertion
that Legal validated the schedule; no local check can verify that assertion, and the gate does not
pretend otherwise.

## 5. What this does not cover

- **Statistical or aggregated retention** beyond the six classes above.
- **Legal hold**: a record under hold is not purged regardless of class. That mechanism is separate and
  must be exercised before the first production purge.
- **The 35-day backup ceiling** cannot be met while the hourly cadence and the 14-copy floor are both
  enforced — see the schedule's own note. Encryption and restore testing are D-08/D-09's subject; the
  arithmetic is recorded there.

## 5a. Coverage — which classes have a purge path (26 November 2026)

A period in the schedule and code that applies it are different things, and `retention:plan` could not show
the difference: a class with a declared period and no code read exactly like a class being emptied on
schedule. It now prints a coverage line per class and names every one whose period nothing applies.

Listed by the schedule's own class names. The first draft used table names (`bookings`, `referrals`)
and produced a report that was wrong in both directions; running `retention:plan` against the real
database is what caught it.

| Class | Period | Enforced | Position |
|---|---|---|---|
| `appointment_and_administrative` | 2557 days | yes | `PdoRetentionRepository` redacts `bookings` |
| `referral_and_intake` | 2557 days | yes | redacts `referrals` and deletes their tracking tokens |
| `notification_and_outbox` | 365 days | yes | redaction, active only under the schedule |
| `uploaded_clinical_documents` | 1825 days | **partly** | referral documents are redacted with their referral; uploads outside a referral are not reached |
| `clinical_and_procedure` | 1825 days | **no** | declared, nothing applies it |
| `security_and_audit` | 2557 days | **no** | declared, nothing applies it — and now the longest period in the schedule |
| `dispatch_runs` | 1825 days | **no** | scheduled on 26 November; still no sweep |

Each gap above is recorded rather than fixed, and the reason is the same for all of them: a period is a legal decision,
not an engineering one, and the mistake to avoid is an engineer choosing a number and calling it retention.

- **`security_and_audit`** is the more serious of those. An audit trail that is declared to expire and
  never does is a record kept beyond its stated basis. It also has an obvious tension to resolve first:
  deleting the evidence of who changed what is itself something a clinical system should think about
  deliberately, and legal hold has to exist before audit records can be removed.
- **`dispatch_runs`** is smaller in consequence — a delivery schedule and counts, no patient detail — but it
  is still operational history, and at one row per five minutes it grows about 105,000 rows a year.

**What is needed to close either:** a period approved by the same role that approved §6, and a purge path
that respects legal hold. Until then they appear in `retention:plan` under "Not covered", which is the point:
the report is the record, not the absence of a report.

## 6. Signature

| Role | Name | Date | Scope |
|---|---|---|---|
| Legal / Data Protection | **Gemechisa Dalu** | 2026-11-25 | Periods, lawful basis, preservation exceptions, applicable requirements |

Recorded by engineering from the approval given on 25 November 2026. If the schedule changes — a class
added, a period altered — this validation does not carry over, and activation must be re-approved.

## 7. Amendment of 26 November 2026 — three classes moved to five years, and audit moved back

Legal/Data Protection amended the schedule on 26 November 2026. This is the record of what changed and what
it does to the validation above. It records the second decision as well as the first, because the first one
was wrong in a way that is worth keeping visible.

| Class | 25 Nov | First amendment | **Final** |
|---|---|---|---|
| `clinical_and_procedure` | 2,557 days | 1,825 | **1,825 days (5 years)** |
| `uploaded_clinical_documents` | 2,557 days | 1,825 | **1,825 days** |
| `security_and_audit` | 2,557 days | 1,825 | **2,557 days (7 years) — reversed** |
| `dispatch_runs` | not in the schedule | 1,825 | **1,825 days** |
| `referral_and_intake` | 2,557 days | unchanged | unchanged |
| `appointment_and_administrative` | 2,557 days | unchanged | unchanged |
| `notification_and_outbox` | 365 days | unchanged | unchanged |

### 7a. The audit trail, and why it went back

The first amendment put `security_and_audit` at five years, which was **shorter than the records it
describes**: referrals and appointments keep seven. A referral deleted in year six would have left no audit
record of who authorised the deletion, or why.

Reversed the same day. The audit trail is now at least as long as every class it holds decisions about, and
`tests/Unit/RetentionScheduleTest.php` asserts that as an **invariant** — `audit >= max(classes)` — rather than
asserting the number seven. A future amendment that shortens audit or lengthens referrals fails that test and
names both figures.

The three genuinely shortened classes stay shortened: clinical and procedure records, uploaded clinical
documents. Neither of those is the evidence of anything else.

### 7b. What this does to the validation

**This validation does not carry over.** §6 states the rule: a class added or a period altered means the
validation is re-approved, not extended. Three classes and one new class have altered, so the schedule is
running on figures the 25 November signature does not cover.

`release:gates` reports retention activation as `PARTIAL` and will keep doing so, because the repository can
confirm the schedule runs and cannot confirm that a person validated it. That is the gate behaving correctly,
not a defect to work around.

`dispatch_runs` moving from unscheduled to five years closes one of the gaps in §5a and leaves the other
three: two classes with a period and no sweep, and uploads outside a referral with no path at all.