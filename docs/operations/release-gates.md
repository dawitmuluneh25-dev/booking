# Release gates

**Source:** HR-ENG-DEC-R1-003 §3.2 — seven conditions that block publication or production activation.
**Command:** `php bin/console release:gates [--json]`
**State on 25 November 2026:** **seven gates, none met.** Three are partly evidenced; four have nothing
behind them at all.

---

## 1. What this command is for

The gate table has lived in three places since 22 November: the decision record, §4 and §29 of the
status-and-plan document, and the memory of whoever was in the room. Prose checklists rot quietly —
a row gets struck through once and then nobody re-reads it.

`release:gates` prints all seven, each with the authority that owns it and what it requires, assessed
against what this host can actually see. It exists so that the number of open gates is visible on any
day, and so the next action is "ask Legal", not "hunt through a document".

## 2. What it deliberately does not do

It decides nothing. It cannot approve a policy, validate retention periods, sign a clinical pathway,
appoint a person, or attest that a restore drill ran. §3.3 says no item in the record authorises
production release by itself; a command that could turn its own rows green would be the mechanism that
sentence forbids.

There is no `--force`, no override flag and no environment variable that closes a gate.

## 3. The three states

| State | Meaning |
|---|---|
| `met` | Every element of the required evidence was **observed**. Not believed, not configured — observed |
| `partial` | Some elements observed; the rest is either an attestation the repository cannot check, or a record that does not exist to check |
| `open` | The required evidence is absent |

`partial` is the important one. It exists so that a set flag, an encrypted file, or a defined-but-vacant
appointment cannot be reported as a pass. The detail text names the missing element, so nobody has to
infer it from a status word.

## 4. Reading the output, gate by gate

| Gate | Owner | What would make it `met` | State today |
|---|---|---|---|
| Policy publication | Legal / Data Protection | All three policy pages published with approver and dates | `open` — 0 of 3; D-01's approver name and approval date are absent |
| Retention activation | Legal / Data Protection | `RETENTION_SCHEDULE_ACTIVE` on **and** Legal's validation recorded | `open` — flag off, so the single seven-year window still runs |
| Intussusception service activation | Clinical Approver / Receiving institution | The page published, which requires the D-03 sign-off | `open` — held as a draft with no effective date, by design |
| Escalation-route activation | Operations / Clinical | Every route active, with a contact, verified by a named person | `open` — 0 of 5; D-06 seeded them inactive and empty |
| Operational coverage | Operations | Named individuals against the five D-07 appointments | `partial` — appointments defined, no name exists in the repository and cannot |
| Backup production readiness | Engineering / Operations | Encrypted archive **and** a recorded successful restore | `partial` — encrypted archive and key present; no drill result is stored anywhere |
| Credential production readiness | Engineering / Owner | Register, rotation history, named custodians, recovery copy | `partial` — register and procedures exist; 8 rotations missing, 8 custodians unnamed, no recovery copy |

## 5. Why three gates cannot go green from here

Two facts in the assessment are deliberately `null` — "unobservable" rather than "false":

- **`appointmentsNamed`** — D-07 defines five appointments with titles and responsibilities and
  deliberately names nobody. A name typed into a commit would be an identity engineering invented,
  which that decision prohibits.
- **`restoreDrillRecorded`** — `db:restore-drill` restores into a scratch schema, compares row counts,
  prints its verdict, and stores none of it. That is a reasonable design for a one-off operation and a
  poor one for a release gate; the gate therefore reports `partial` until a human records the result
  in the release file.

And one is an attestation by construction: **`RETENTION_SCHEDULE_ACTIVE`**. The variable says the
schedule may run; it cannot say that Legal validated anything. A gate that read the flag as proof would
be asserting an approval on Legal's behalf.

## 6. Using it

```bash
php bin/console release:gates          # table, exit 1 while any gate is not met
php bin/console release:gates --json   # same rows as JSON, for a release script or a dashboard
```

Exit codes: `0` when all seven are `met`, `1` otherwise. Today it is `1`, and that is the honest
answer rather than a defect to be fixed by loosening the check.

For a release decision, run it alongside the two commands that hold the detail behind two of the rows:

```bash
php bin/console security:credentials --require-recorded   # credential row, and what is missing
php bin/console backup:key-check                          # D-08 custody behind the backup row
```

## 7. Keeping it honest

Two things would quietly break this command, so they are asserted in `ReleaseGatesTest`:

- **Renaming or merging a gate.** The seven names, owners and evidence strings are asserted against the
  record's own words, in the record's order. A gate that disappears while the report still looks
  complete is worse than no report.
- **Letting a process artefact stand in for a decision.** The tests pin the flag as `partial`, the
  vacant appointment as `partial`, and the encrypted-but-unrestored backup as `partial`. Each of those
  was, at some point in this project, mistaken for progress.

If the record is amended, this table changes with it — and the test that copies the record's wording is
the place where the change has to be argued rather than applied.
