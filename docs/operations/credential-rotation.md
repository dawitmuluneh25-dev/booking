# Credential inventory and rotation

**Decision:** HR-ENG-DEC-R1-003 **D-10** (decision date 22 November 2026)
**Register:** `database/operations/credential-inventory.php` — names and dates, never values.
**Command:** `php bin/console security:credentials [--require-recorded] [--today=YYYY-MM-DD]`
**State on 24 November 2026:** the register exists and is exhaustive; **no credential has a rotation on
record**, and **no custodian is named**. Both are printed on every run rather than inferred.

---

## 1. What D-10 actually decided

Routine rotation is **deferred** as a blocker, and three things are required anyway:

| Requirement | How it is held here |
|---|---|
| "A credential inventory and named owner for each secret" | The register, plus the `office` and `custodian` fields |
| Immediate rotation on any of five events | §3 — the five events, with the action each one demands |
| "A maximum 90-day rotation interval for hosting, database, SMTP, and API credentials" | `CredentialInventory::ROTATION_INTERVAL_DAYS`, enforced by the command |
| "Document rotation, validation, rollback, and revocation procedures" | §4, §5, §6, §7 |
| "Do not rotate a backup key in a way that makes retained backups unrecoverable" | §8 — the archive rule, computed from the files on disk |

---

## 2. The register

Eight secrets, five of them environment variables:

| Key | Source | Kind | Function | Office |
|---|---|---|---|---|
| `DB_PASSWORD` | `.env` | database | runtime database user | Engineering Lead |
| `APP_SECRET` | `.env` | application | HMAC key behind the account-request throttle | Engineering Lead |
| `BACKUP_ENCRYPTION_KEY` | `.env` | backup key | encrypts every archive; custody is D-08's subject | Engineering Lead |
| `NOTIFY_BREVO_API_KEY` | `.env` | API | Brevo transactional email | Engineering Lead |
| `NOTIFY_HTTP_TOKEN` | `.env` | API | bearer token for the notification bridge | Engineering Lead |
| `PANEL_PASSWORD` | external | hosting | DirectAdmin panel **and** FTPS — one secret, two surfaces | Operations Lead |
| `STAGING_BASIC_AUTH` | external | hosting | htpasswd in front of the staging site | Operations Lead |
| `ADMIN_LOGIN` | external | application | the `admin@hakimerad.com` account — a database row | Product Owner |

Every other environment key the code reads is in the same file under `non_secret`, with the reason it is
not a credential. Two tests hold that claim to the code: one fails when a key is read by `src/`, `bin/`,
`routes/` or `public/` and classified nowhere, the other when `.env.example` and the register disagree in
either direction. A new secret therefore arrives as a red build, not as a ninth undocumented variable.

**Custody is split by domain, decided 26 November 2026.** D-10 asks for a named owner per secret. The
owner's answer was that custody follows the domain rather than sitting with one person, which maps onto the
`office` values already in the register:

| Domain | Secrets | Accountable office | Named custodian |
|---|---|---|---|
| Application | `DB_PASSWORD`, `APP_SECRET`, `BACKUP_ENCRYPTION_KEY`, `NOTIFY_BREVO_API_KEY`, `NOTIFY_HTTP_TOKEN` | Engineering Lead | **Dawit Muluneh**, who also holds the Engineering Lead office |
| Hosting | `PANEL_PASSWORD`, `STAGING_BASIC_AUTH` | Operations Lead | **Bewnet**, holder of `operations_lead` since 26 November 2026 |
| Login | `ADMIN_LOGIN` | Product Owner | **Dawit Muluneh**, the account owner |

The names came from the operational appointments approved on 26 November 2026 and from the owner naming
themselves, not from engineering filling in a blank. That distinction is the whole reason the field is
trustworthy: a name that entered the project through an approval is an operational act, and a name typed by
engineering is the invented identity D-07 forbids.

**All eight are now named, and the gate still fails.** `--require-recorded` tests `rotated_on` as well as
`custodian`, and every `rotated_on` is still null because no credential in this system has a verifiable
rotation history. A custodian is a person to ask; it is not evidence that anything was rotated.

**What the distribution actually looks like.** Six secrets sit with one person, who holds both the Engineering
Lead and Product Owner offices. The other two sit with the Operations Lead, and those two are the panel and
FTPS credentials that share one password. So the arrangements that look like separation on paper collapse to
two people, and one of them is the only person who can restore a backup. Recorded rather than fixed: at this
clinic's size that may be the only workable arrangement, but it should be a decision somebody took rather than
a pattern that emerged.

**On the interval, 26 November 2026.** A 180-day interval was proposed and considered, and rejected. D-10
sets a *maximum* of 90 days and allows a deviation only towards something **stricter**; 180 is the opposite
direction, so adopting it would have meant amending the decision rather than applying it. The owner chose to
keep 90. Nothing in the register or `ROTATION_INTERVAL_DAYS` changed, and the recommendation is recorded here
because "we thought about loosening it and did not" is worth more than silence.

---

## 3. The five events that require immediate rotation

Each one is a trigger, not a suggestion. D-10 names them; the column on the right is what this project
has to do about it.

| Event | Action |
|---|---|
| Suspected or confirmed compromise | Rotate every credential with access to the affected system, then read the audit trail for use since the suspected date |
| Accidental exposure in source code, logs, email or another unapproved location | Rotate that credential **and** the ones it can reach; record the exposure as an incident |
| Departure or loss of authorisation of a person with access | Rotate the credentials that person held — the panel, the FTPS account and any application login, not only the last one they used |
| Hosting- or service-provider account change that invalidates existing trust | Rotate after the change is confirmed by the provider, not before |
| Any security incident requiring credential invalidation | Rotate, then record which archives and sessions predate it |

This register was written because of the second row: the panel credential has already been transmitted in
a chat transcript and stored in a plaintext document (`outputs/.staging-credentials.txt`). Under D-10 that
is an exposure event, and the plan's S1 is the same finding stated as a task.

---

## 4. Rotation procedure

Rotation is a change to a running system, so it is done in this order and not another.

1. **Record the intent.** Add a line to the change record: what is being rotated, why, by whom, and the
   window. For a production credential the window is a low-traffic period with the operator reachable.
2. **Create the new value** through the provider or the application, not by inventing one.
   `php bin/console db:backup --key` prints a backup key and stores nothing.
3. **Deploy it to the consumer** — `.env` on the host, then `php bin/console doctor` to confirm the
   application still boots and reaches the database.
4. **Verify by use, not by presence.** A credential that is set but wrong looks identical to one that is
   right until something depends on it. The verification per kind is in §5.
5. **Revoke the old value** — but read §8 first if it is the backup key.
6. **Record the rotation** by setting `rotated_on` on the register entry and committing it. The commit is
   the register's audit trail; a rotation that is not in the file did not happen as far as this system is
   concerned, and `security:credentials` will keep saying `unrecorded`.

---

## 5. Validation — what "rotated successfully" means

| Credential | Verified by |
|---|---|
| `DB_PASSWORD` | `php bin/console doctor` reports `db-connect`; `migrate:status` lists without error |
| `APP_SECRET` | an account request twice from one address still throttles (the HMAC is derived, so a wrong value shows up as a lost counter, not an error) |
| `BACKUP_ENCRYPTION_KEY` | `php bin/console db:backup --require-encryption` writes `*.enc`, then `db:restore-drill` on that file prints identical row counts |
| `NOTIFY_BREVO_API_KEY` | `php bin/console outbox:dispatch` on a queued message returns a provider id rather than a 401, and `outbox:receipt` accepts it |
| `NOTIFY_HTTP_TOKEN` | a dispatch to the configured bridge returns 2xx; a wrong token returns 401 rather than a retry storm |
| `PANEL_PASSWORD` | sign in to the panel **and** connect over FTPS — they are the same secret, so verifying one verifies neither |
| `STAGING_BASIC_AUTH` | `curl -I https://<staging>/` returns 401 without credentials and 200 with them |
| `ADMIN_LOGIN` | sign in as the account and confirm the audit trail records the session |

A rotation whose validation was not performed is a rotation that will be discovered by a user. The
register records the date either way; the difference belongs in the change record.

---

## 6. Rollback

Every rotation needs a way back, and it has to exist **before** the old value is destroyed.

- **Keep the previous value until validation passes.** For a panel or API credential that means a
  password manager entry that is replaced, not overwritten, and a provider console where two keys are
  live at once. Where the provider allows only one value at a time, the rollback is the *old* value kept
  outside the provider until step 4 of §4 has succeeded.
- **Revert the consumer, not the provider.** If deployment of the new value broke the application, put
  the old value back into `.env` and restart; do not rotate the provider again to "fix" it.
- **For the backup key there is no rollback, only retention.** A rotation that loses the old key cannot
  be undone by restoring configuration, because the archives are already encrypted. §8 governs it.

---

## 7. Revocation

Revocation is rotation without a replacement, and it is the right act when access is being removed rather
than renewed.

- **Panel / FTPS:** change the password, then remove any additional FTP accounts and confirm the old
  session tokens no longer work. Note that the panel's "Password Protected Directories" feature is broken
  on this host (S5); `staging` basic auth is edited over FTPS and must be revoked there.
- **Database:** revoke the account, or change its password and confirm the application reconnects with the
  new one. This host has a single database user whose name is the database name (S4), so revocation and
  rotation are the same act.
- **Application login:** deactivate the account (`php bin/console user:deactivate <email> --actor=<email>`)
  rather than deleting the row — the audit trail refers to it, and a deleted row leaves the trail pointing
  at nothing.
- **API keys:** revoke in the provider first, then remove from `.env`. The reverse order leaves a live key
  in a file nobody is watching.

---

## 8. The backup key: the archive rule outranks the interval

D-10: *"Old keys must not be destroyed until the corresponding backup archives have expired or have been
securely re-encrypted and verified."* That is why the register gives this key a null interval: an
inherited 90-day rule applied to it would strand archives.

`security:credentials` answers the question from the files themselves, because archive names carry a UTC
timestamp (`booking-YYYYMMDD-HHMMSS.sql.gz[.enc]`) and nothing else is needed:

```
Backup key and the archives it unlocks
  2 archive(s) on disk under …/var/backups.
  No backup-key rotation is on record. Any rotation must keep the previous key until
  every archive above has expired or has been re-encrypted and verified (D-10).
```

The procedure, in order:

1. Read the report. Archives that **predate** a recorded rotation were written under the previous key.
2. Keep the previous key until every one of them has passed retention (D-02: 35 days, 14 copies) **or**
   has been re-encrypted under the new key and verified by `db:restore-drill`.
3. Only then destroy the old key — and read D-08 first: the recovery copy is a separate holder of the same
   secret, and destroying one copy is not destroying the key.

**What this does not do.** It does not rotate anything, and it does not detect that a key was reused from
the database password or an SMTP credential. Reuse is only visible by comparing values a person should not
paste into a report; the register says so rather than claiming a control that only an attestation can give.

---

## 9. State, honestly

| Item | State on 24 November 2026 |
|---|---|
| Register, with an accountable office per secret | Done — eight entries, eight offices |
| Every environment key classified, both directions | Done, and guarded by tests |
| Rotation on record for any credential | **None.** Every credential reports `unrecorded` |
| Named custodian for any credential | **Two of eight.** The hosting pair, from the 26 November appointments. `--require-recorded` still fails |
| Credentials kept out of source control | `.env` and `.env.*` are ignored; `.env.example` documents names only. The panel credential in `outputs/.staging-credentials.txt` is **outside** this repository and still on disk (S2) |
| Panel and FTPS password rotated | **Not done** (S1). Under D-10 this is an exposure event that has already occurred. **Requested 26 November 2026**; the register carries `rotation_requested_on` and the row still reads `unobservable` |
| Staging basic-auth rotated | **Not done.** Requested 26 November 2026, same custodian, same row state |
| Backup key rotated | Not rotated; no rotation needed, and §8 governs it when one is |
| Independent recovery copy of the backup key | **Placed 26 November 2026** at `C:\Users\dev\booking-recovery\backup-encryption.key`, named by `BACKUP_RECOVERY_KEY_FILE`. See §10 for what that does and does not achieve |
| Rotate-before-release gate | `security:credentials --require-recorded` exists and currently exits 1, which is the true state |

The gap that matters is not the register. It is that **no credential in this system has a verifiable
rotation history**, and the panel password is the one that has demonstrably left the building. The
register makes both facts visible on every run; only the owner and Operations can close them.

---

## 10. The recovery copy, and the independence it does not have

D-08 requires an independent copy of `BACKUP_ENCRYPTION_KEY` in a separate protected place. Until 27
November 2026 there was none on the record: `BACKUP_RECOVERY_KEY_FILE` was unset and `backup:key-check`
warned on every run.

**What was done, on the owner's instruction.** A copy was written to
`C:\Users\dev\booking-recovery\backup-encryption.key`, outside the application tree and outside the backup
directory, and `BACKUP_RECOVERY_KEY_FILE` was set to that path. `backup:key-check` now reports `[OK] Recovery
copy`.

**What that does and does not achieve, stated plainly because the check cannot say it.**

- **It does:** make the key recoverable if the `.env` file alone is lost or corrupted, and it keeps the copy
  out of the document root and away from the archives it unlocks. Those are real.
- **It does not:** survive the loss this control exists for. The copy is on the same host as the key it
  protects, so a disk failure, a ransomware event or a full account compromise takes both. `KeyCustody`
  verifies a path, not a location, which is why the check passing is not the same as D-08 being satisfied.

A genuinely independent copy — on another machine, or in a password manager outside this host — remains the
requirement. When one exists, point `BACKUP_RECOVERY_KEY_FILE` at it and this section stops applying. The
honest reading of the current state is: **recoverable from a file-level mistake, not from a host-level one.**

### 10a. Why a request is a date and not a sentence

`rotation_requested_on` exists because the two credentials that matter most here cannot be rotated from this
host: `PANEL_PASSWORD` and `STAGING_BASIC_AUTH` live in a hosting panel operated by the Operations Lead. An
engineering request to rotate them previously existed only in conversation, and a control gap that lives only
in conversation survives until someone happens to remember it.

The field records when the request was made and leaves `rotated_on` null until the rotation actually happened.
`security:credentials` prints it (`rotation requested 2026-11-26, not observable from here`), and
`--require-recorded` still fails the row. A request recorded as a rotation would be the same lie as no record
at all, only better hidden.
