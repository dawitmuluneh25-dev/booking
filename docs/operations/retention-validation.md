# Retention schedule — Legal validation and activation record

**Decision:** HR-ENG-DEC-R1-003 **D-02** (22 November 2026).
**Schedule:** `App\Domain\RetentionSchedule`, adopted 24 November 2026, amended 26 November 2026.
**Validation:** given **25 November 2026** by **Gemechisa Dalu** (Legal / Data Protection); **re-confirmed
26 November 2026** against the amended table.
**Record:** `database/operations/retention-validation.php` — `release:gates` reads this file, not this page.
**Activation:** `RETENTION_SCHEDULE_ACTIVE=true` in the production environment.
**Gate:** `[MET]` since the 26 November re-confirmation.

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
| `uploaded_clinical_documents` | 1825 days | **yes** | `PdoClinicalDocumentRetentionRepository` sweeps documents on their own 1,825-day clock, independently of the parent referral — see §5c |
| `clinical_and_procedure` | 1825 days | **no** | declared, nothing applies it |
| `security_and_audit` | 2557 days | **no** | declared, nothing applies it — and now the longest period in the schedule |
| `dispatch_runs` | 1825 days | **yes** | `PdoDispatchRunRetentionRepository` deletes the row — added 26 November 2026, the first class whose act is removal rather than redaction |

Each gap above is recorded rather than fixed, and the reason is the same for all of them: a period is a legal decision,
not an engineering one, and the mistake to avoid is an engineer choosing a number and calling it retention.

- **`security_and_audit`** is the more serious of those. An audit trail that is declared to expire and
  never does is a record kept beyond its stated basis. It also has an obvious tension to resolve first:
  deleting the evidence of who changed what is itself something a clinical system should think about
  deliberately, and legal hold has to exist before audit records can be removed.
- **`dispatch_runs`** — **closed on 26 November 2026.** The period arrived with the amendment and the sweep
  followed the same day. It was the smaller of the two gaps in consequence: a delivery schedule and counts,
  no patient detail, about 105,000 rows a year at one row per five minutes. It was also the only one that
  needed no new legal decision, which is why it went first.

**What is needed to close the rest:** a period approved by the same role that approved §6, and a purge path
that respects legal hold. Until then they appear in `retention:plan` under "Not covered", which is the point:
the report is the record, not the absence of a report.

### 5b. Why `dispatch_runs` deletes where the others redact

Every other class overwrites the identifying fields and keeps the row. `dispatch_runs` removes the row, and
that difference is deliberate rather than an oversight.

A run row holds a start and finish time, four counters and a hostname. It holds no recipient, no case
reference, no free text, and nothing unique. There is nothing in it to pseudonymise — a "redacted" run row
would be a row that still says exactly what it said before, wearing a stamp that implies something was
removed. Removal is the honest act, and `removed` is the honest verb in the run record.

What is lost is the aggregate count of one dispatch invocation. What is not lost is the per-message record,
which lives in `notification_outbox` with its provider message id and its status — that is what
`outbox:receipt` reconciles against, and it is what the ledger's accounting value actually rests on. The
ledger's own remaining purpose is liveness, and `notify:dispatch-check` reads the newest row, which a
1,825-day window never reaches.

The boundary is `started_at < cutoff`, not `<=`. A row written exactly on the cutoff is the oldest thing still
inside the period, and an off-by-one there would remove a day of ledger at the edge of every run.

### 5c. The `uploaded_clinical_documents` gap was described wrongly

The row above used to say the class was *"partly applied: referral documents are redacted with their referral,
uploads outside a referral are not"*. **There is no such thing as an upload outside a referral.**
`referral_documents.referral_id` is `NOT NULL` with a foreign key to `referrals`, and it has been since
migration 0009. Every document this system stores belongs to a referral. The phrase described a category that
cannot exist.

The class is nevertheless `partial`, and for a different reason:

| | Period | What actually happens |
|---|---|---|
| `referral_and_intake` | 2,557 days | swept on its own cutoff |
| `uploaded_clinical_documents` | 1,825 days | swept **only inside** `ReferralRetentionService::redactOne()`, when its parent referral is swept |

So a document attached to a referral that is still inside its seven-year window is retained past its own
five-year period — indefinitely, if the referral is retained to term. The document class's shorter period is
never applied on its own. That is the gap: **not a missing table, a missing trigger.**

Consequences worth stating before it is closed:

- The sweep has to remove the bytes as well as the row's identity, and it must do so in the same order the
  referral sweep uses — file first, then the row — or a failed delete leaves a redacted record whose file is
  still in private storage with nothing left to find it by.
- Closing it makes a document class redaction happen while its parent referral is still live. That is correct
  under the schedule, and it is the first time this system deletes a clinical attachment without deleting the
  record it was attached to. It should be an explicit act, not a side effect of the 1,825-day figure.
- `document_access_events` (migration 0016) references documents and is not in the schedule at all. A sweep
  that removes a document while its access log stays is a smaller version of the same problem.

**Closed on 26 November 2026.** `ClinicalDocumentRetentionService` now applies the class directly:
`redacted_at IS NULL AND created_at < cutoff`, bytes deleted first, then the row redacted and marked
deleted, in batches, recording against `retention_runs.uploaded_documents_redacted`.

The ordering is the part that can lose data, so it has a test rather than a comment: **bytes first, then
the row.** A file delete that fails leaves the row untouched and the sweep throws. The reverse order
produces a redacted row whose file is still in private storage with nothing left to enumerate it by — the
row no longer names the document, so nobody can discover what is still there.

The parent referral is never touched by this sweep, and `ClinicalDocumentRetentionTest` asserts that
against a real foreign key. That constraint is what makes the class distinct: a document reaches this
sweep only because it is older than 1,825 days while its referral is younger than 2,557 — so the referral
is still protected, and redacting it here would destroy a record the schedule says to keep.

**Left open, and stated rather than fixed:** `document_access_events` still has no class in the schedule.
A document removed on its own period now leaves an access log behind, which is the same problem one table
down. It needs a period decision before it needs code.

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

`release:gates` reported retention activation as `PARTIAL` from the amendment until the re-confirmation later
the same day, because the repository can confirm the schedule runs and cannot confirm that a person validated
it. The re-confirmation in §8 is what closed it — not a flag, and not this paragraph. The gate was behaving
correctly, not failing.

Two classes left §5a's gap list on 26 November 2026. `dispatch_runs` moved from unscheduled to five years and
gained a sweep the same day; `uploaded_clinical_documents` gained the sweep that applies its own period
rather than inheriting its referral's.

That leaves **two**: `clinical_and_procedure` and `security_and_audit`, both with a period and no sweep. They
are the two that cannot be finished by writing code alone. Deleting audit rows is a decision a clinical
system should take deliberately, and legal hold has to exist before it does; deleting procedure records is
the same question pointed at the clinical record itself.
---

## 8. The validation record, and why the gate can now move

Until 26 November 2026 the gate had two answers: `open` while `RETENTION_SCHEDULE_ACTIVE` was off, and
`partial` while it was on. There was no `met` branch, so the row could not stop asking — and a gate that
can never close stops being read, which is how §7b's gap would have survived indefinitely.

The validation is now a record at **`database/operations/retention-validation.php`**: who validated, the
office they act for, the date, what they validated, and a fingerprint of the schedule as it stood. The gate
compares that fingerprint with `RetentionSchedule::fingerprint()` and reports one of four states.

| State | Meaning | Gate |
|---|---|---|
| `unrecorded` | No file, or nobody named | `partial` |
| `incomplete` | Named, but no date, no scope, or an office other than Legal / Data Protection | `partial` |
| `stale` | Complete, but the schedule changed after the date recorded | `partial` |
| `current` | Complete, and the fingerprint matches the table this build executes | `met` |

**The record read `stale` for a few hours, and that is the mechanism working.** Gemechisa Dalu validated
the schedule on 25 November 2026 (§1). The next day four classes moved and one was added (§7), and the
re-confirmation came the same afternoon — so the window between "the table moved" and "someone looked at it
again" was hours, not weeks. A validation given on the 25th cannot cover a table adopted on the 26th, so
between those points the record named the real
approver and the real date and left the fingerprint null — inventing a hash for the 25th would have been a
fabricated provenance for a genuine approval.

On **26 November 2026** the same approver re-confirmed the table as it then stood, and the record now carries
that date and the fingerprint `cad40459f901…`. The gate reads `met`. What matters for the future is what
happens next time a period moves: the literal fingerprint stops matching, the row returns to `partial` on
that commit, and a person has to look at the table again. The closure is a signature, not a switch.

### 8a. Why the fingerprint is a literal

`schedule_fingerprint` is written into the file, not computed from `RetentionSchedule` at load time. A file
that recomputed itself would always agree with the table, and a check that always agrees is decoration. The
literal is what makes an amendment un-validate itself: change a period, add a class, or move the 35-day
backup maximum, and the recorded hash no longer matches — on that commit, without anyone remembering to
revoke the approval.

### 8b. What the record cannot do

It cannot verify that the named person exists, holds the office, or read what they were given. It records an
assertion and checks its **scope**, not its sincerity. The signature stays a human act, and no command fills
the file in — §3.3 forbids a mechanism that turns its own rows green, and a program that could mark its own
attestation current would be exactly that.

To re-validate: run `php bin/console.php retention:plan`, which prints the table and its fingerprint; have
Legal / Data Protection confirm the periods, the lawful basis, the preservation exceptions and the applicable
Ethiopian requirements against that table; then update `approved_on` and copy the printed fingerprint into
`schedule_fingerprint`. Commit. The commit is the provenance.

One thing a validator is entitled to be told before they sign: `clinical_and_procedure` and
`security_and_audit` still have periods with no sweep behind them (§5c). The period is right and nothing
applies it. That is a coverage gap, reported by `retention:plan` and distinct from this validation — but a
confirmation given without knowing it would not be an informed one.

### 8c. Re-confirmation, 26 November 2026

| Field | Value |
|---|---|
| Approver | Gemechisa Dalu |
| Office | Legal / Data Protection |
| Date | 2026-11-26 |
| Scope | The periods, the lawful basis, the preservation exceptions and the applicable Ethiopian requirements, as the schedule stood on 26 November 2026 |
| Fingerprint | `cad40459f901073f26e3a6901a2a4150cf6b4fc22a186623eb2525d9780fedce` |

Confirmed: `clinical_and_procedure` 1,825 days · `referral_and_intake` 2,557 · `uploaded_clinical_documents`
1,825 · `appointment_and_administrative` 2,557 · `notification_and_outbox` 365 · `security_and_audit` 2,557 ·
`dispatch_runs` 1,825 · backups 35 days maximum, 14 copies.

The confirmation was given with the coverage gap stated — two classes have periods and nothing applies them
(§5c). It does not qualify the approval, and it is recorded here so no later reader assumes the signatory was
kept in the dark about it.
