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
| `clinical_and_procedure` | 7 years (2,557 days) | Procedure records, clinical notes, milestones |
| `referral_and_intake` | 7 years | Referrals, assessments, decisions, consent |
| `uploaded_clinical_documents` | 7 years | Documents attached to a referral |
| `appointment_and_administrative` | 7 years | Appointments, resources, scheduling history |
| `notification_and_outbox` | 12 months (365 days) | Notification records and the outbox |
| `security_and_audit` | 7 years | Audit trail, sign-in records, security events |

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

## 2a. Why the flag is still off — a defect found on 25 November 2026

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

Activating in that state would have produced a green-looking flag, a gate reading `declared`, and no
change to what the system retains. That is the exact failure mode this project has spent a month
removing, so the flag was removed from `.env` and the work is recorded as a **blocking defect**:

> **DEFECT (D-02 activation):** the per-class schedule is data and documentation, not behaviour.
> Activation requires a purge path that honours `RetentionSchedule` per class, starting with
> `notification_and_outbox` (365 days), a dry-run that reports per class, and audit rows naming the
> class. Until that exists, `RETENTION_SCHEDULE_ACTIVE` must stay unset.

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
| The gate reading it | `php bin/console release:gates` — retention row moves from `open` to `declared` while the flag is on |
| The purge behaves per class | `tests/Unit/RetentionScheduleTest.php`, `tests/Feature/RetentionPurgeTest.php` |

Note the gate's honest word for this state: **`declared`**, not `met`. A flag being on is an assertion
that Legal validated the schedule; no local check can verify that assertion, and the gate does not
pretend otherwise.

## 5. What this does not cover

- **Statistical or aggregated retention** beyond the six classes above.
- **Legal hold**: a record under hold is not purged regardless of class. That mechanism is separate and
  must be exercised before the first production purge.
- **The 35-day backup ceiling** cannot be met while the hourly cadence and the 14-copy floor are both
  enforced — see the schedule's own note. Encryption and restore testing are D-08/D-09's subject; the
  arithmetic is recorded there.

## 6. Signature

| Role | Name | Date | Scope |
|---|---|---|---|
| Legal / Data Protection | **Gemechisa Dalu** | 2026-11-25 | Periods, lawful basis, preservation exceptions, applicable requirements |

Recorded by engineering from the approval given on 25 November 2026. If the schedule changes — a class
added, a period altered — this validation does not carry over, and activation must be re-approved.
