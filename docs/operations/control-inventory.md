# Control inventory — what the application actually does with personal data

**Purpose.** The system administrator writes Privacy §5 and §10 (D1/D3) from this document. It exists
because those paragraphs must describe controls that are *in the code*, not controls somebody assumed
were in the code. Every claim below was read out of the source, not recalled, and each names where it
lives so a reviewer can check it without trusting this file.

**Scope.** The application at the commit this file was last verified against. **It is not** a privacy
notice, a legal opinion, or a description of the hosting provider. What the provider must supply is in
§10 and is explicitly marked as unverified here.

**Verified at:** commit `6df075d`, 19 November 2026, by reading `src/` and running the suite. Where a
fact comes from an earlier host inspection rather than from this commit, it says "carried".

---

## 1. What personal data the system holds, and where

| Data | Where it lives | Notes |
|---|---|---|
| Patient name, phone, notes on a booking | `bookings` table, MySQL/MariaDB on the host | Redacted after the retention period (§5) |
| Referral content — patient identity, referrer, clinical particulars | `referrals` and its history tables | The clinical record; redacted on the same schedule as documents |
| Uploaded documents (referral letters, results) | Filesystem, `var/private-uploads`, **outside the web root**; metadata in `referral_documents` | §3 |
| Staff accounts | `users` — email, name, role, password hash | Hash only; Argon2id where the runtime offers it, otherwise PHP's default (`src/Security/PasswordHasher.php`) |
| Account requests from prospective referrers | `account_requests` — practice and contact details | **No clinical content by design**; expires after 60 days |
| Sign-in attempts | `login_attempts` — email, IP, outcome, timestamp | Used for the lockout in §8 |
| Document access events | `document_access_events` | §4. No filename, no clinical text |
| Audit events | `audit_events` | Who did what, when, to which record |
| Outbound notifications | `notification_outbox` — recipient, template, payload | Payload carries reference, date/time and service only, never clinical detail |

The database and the document store sit on the same host (carried: `outputs/HANDOFF-R1.md` §3). There is
no third-party processor in the request path. The one outbound path is the notification transport (§9).

---

## 2. Who can reach it

Authorisation is declared on every route, and a route without a declaration cannot be registered
(`src/Http/Router.php`, `src/Http/RouteAccess.php`). The complete public set is pinned by
`tests/Feature/RouteAccessManifestTest.php` — 17 of 103 routes, each with a written reason. Everything
else needs a session and, in all but one case, a named permission.

Two separations matter for a privacy review and are enforced in code rather than by convention:

1. **Account versus clinical authority.** Approving an account request creates the account and a
   **`pending`** physician profile. Referral authority is granted only by `VerifyPhysician`
   (`src/Domain/VerifyPhysician.php`). An administrator cannot make a clinician by approving a form.
2. **Permission versus object scope.** Holding `document.download` is not enough; the actor must also be
   able to see the parent referral (`src/Domain/DocumentAccessService.php`). A staff account that may
   read referrals still cannot read another clinician's patient's file.

---

## 3. Private documents

- **Location.** `var/private-uploads` by default, configurable through `DOCUMENT_STORAGE_PATH`, and
  outside `public/` so no URL reaches it. `src/Storage/FilesystemDocumentStorage.php` also writes a
  deny-all `.htaccess` into every directory it creates — explicitly as the second line of defence
  against a misconfigured document root, not the first.
- **Name on disk.** 32 lowercase hex characters from `random_bytes(16)`, sharded two characters deep, and
  stored **with no extension** (`src/Domain/StorageKey.php`). `StorageKey::fromString` accepts exactly that
  pattern and nothing else, so a traversal sequence is unrepresentable rather than filtered.
- **Overwrite.** Files are opened with `'x'`, so a key collision fails rather than replacing an existing
  document.
- **Acceptance.** Authority, then referral state (a closed referral accepts no new documents), then size
  (≤ 15 MiB and > 0), then extension (PDF, JPEG, PNG), then **content against extension** — the bytes are
  inspected before anything is written, and the stored MIME type comes from the application's own
  catalogue, never from the browser (`src/Domain/UploadReferralDocument.php`).
- **Quarantine.** Every upload is born `pending`. Metadata is readable while pending — a clinician needs
  to know an attachment exists — but the bytes are refused until an authorised reviewer releases them,
  and the verdict records who decided, when and why.
- **Orphans.** The storage write happens before the database transaction; if the row cannot be written the
  bytes are deleted, so a file with no record cannot survive.

---

## 4. Access logging

Every attempt on a private document writes one row — allowed **and** denied — before the bytes move
(`src/Domain/DocumentAccessService.php`). A denial then raises the same not-found as an unknown
reference: the audit trail knows the difference, the caller does not.

Recorded: document id, user id, action, outcome, timestamp, IP, user agent (truncated to 190 characters),
and a reason (also truncated). **Not** recorded: filename, storage key, or any clinical text. An audit
record that duplicated the content it audits would become a second place the PHI has to be protected.

Separately, `audit_events` names every consequential act as a case in `src/Domain/AuditAction.php` —
account created, role changed, physician verified/rejected/suspended, credential and privilege changes,
escalation routes, procedure milestones, sign-in succeeded/failed/throttled, document released/withheld,
account requested/approved/rejected/expired. A missing case is a compile-time omission, not a silent gap.

**What is not purged:** no command deletes rows from `audit_events` or `document_access_events`. The
retention job covers bookings, referrals and their documents (§5). Audit retention is therefore a policy
decision (D5), not an implemented control — say so in the notice rather than implying a sweep that does
not exist.

---

## 5. Retention and redaction

Retention is **2557 days** — seven calendar years, counting leap days — from `Config::DEFAULT_RETENTION_DAYS`
and overridable by `RETENTION_DAYS`. The period is the Ethiopian medical record-keeping directive's seven
years, approved in DEC-2026-11-20-02; until that decision the code held five (1827 days), which would have
disposed of clinical records two years early. `RetentionPolicy` refuses any value below 30 days, so a
mistyped environment variable cannot delete the current month.

What the sweep does, on a **finished** booking (`src/Repositories/PdoRetentionRepository.php`):

- overwrites `patient_name` and `patient_phone` with `[redacted]`;
- sets `notes` to `NULL`;
- stamps `redacted_at` and `updated_at`;
- overwrites `notification_outbox.recipient` for that booking with `[redacted]`, so the delivery table
  does not keep the phone number the booking record no longer has.

A request or a confirmed upcoming appointment is **not** eligible: the clinic needs its contact details
to do the work. Only a terminal state past the cutoff qualifies. Runs are batched (500 rows, 20 batches)
to avoid one long transaction on shared hosting, and each run writes a `retention_runs` row so the sweep
is evidenced rather than claimed.

Referrals follow the same cutoff and the same policy object, and the order inside one referral is
deliberate: delete the stored bytes, mark the document row, then redact the parent. If file removal fails
the sweep stops with that referral half-treated rather than producing a redacted record whose file is
still on disk with no way to find it again (`src/Domain/ReferralRetentionService.php`).

The referral's **status links are deleted** as part of the same redaction — once the identity is gone the
tokens have nothing left to guard (`src/Repositories/PdoReferralRetentionRepository.php`). A link that
outlived its referral would be the one URL still answering for a record the clinic has erased.

---

## 6. Backups

`src/Database/DatabaseBackup.php` writes a gzipped SQL dump using nothing but PDO — `exec()` and
`mysqldump` are disabled on much shared hosting, and a backup that only works on the machine that wrote
it is not a backup. It reads every table inside one `REPEATABLE READ` transaction so the dump is a
consistent view rather than a set of tables sampled at different moments.

`db:restore-drill` restores the dump into a scratch schema and compares row counts table by table. It
**refuses** to target the live database, and it only drops a database whose name ends in `_drill`, so a
mistyped name cannot delete production. `prune()` keeps the newest 14 dumps.

**Encryption at rest — provided since 22 November 2026, when a key is configured.** `src/Support/BackupCipher.php`
encrypts the dump with `sodium_crypto_secretstream_xchacha20poly1305`: a 32-byte key from
`BACKUP_ENCRYPTION_KEY`, a per-file header, and 256 KiB records each carrying its own authentication tag.
A flipped byte fails the restore rather than producing a dump that is subtly wrong, and a truncated file is
refused rather than half-restored. `db:restore-drill` reads either form, because dispatch is by content
rather than by file name.

Three limits belong beside that sentence, because "encrypted" on its own overstates what this does:

- **A key held on the same host as the database password protects a copied file, not a compromised host.**
  It defeats the real exposure — a dump downloaded over FTP, mailed, synced or left on a decommissioned
  disk — and it does not defeat somebody who already has the application's environment. Key custody is an
  operational control this document cannot supply.
- **A lost key means the backups are unreadable, not recoverable.** That is the property encryption is
  supposed to have, and it makes the key the single most important secret in the backup path.
- **Unconfigured is the current state on staging.** `BACKUP_ENCRYPTION_KEY` is empty there, so dumps are
  still plain gzipped SQL and every command says so on its output; `db:backup --require-encryption` refuses
  to run when the key is missing, and that flag is how the cron entry fails closed once the key exists.

The dump is also written to a `.part` name and moved into place only when complete, so a killed run no
longer leaves a truncated file that `latest()` would offer to the restore drill. Whether the backup
directory itself sits on encrypted storage, and where it is stored, remains a hosting question — one of the
twelve unanswered ones (S4).

---

## 7. Transmission and session security

- **TLS** terminates at the host. The application sends `Strict-Transport-Security: max-age=31536000;
  includeSubDomains` **only when the request arrives over HTTPS** (`src/Http/Kernel.php`), so it cannot
  lock a browser into HTTPS on a host that has not got a certificate yet.
- Headers on every response: `Content-Security-Policy`, `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, a restrictive
  `Permissions-Policy`, and `Cross-Origin-Opener-Policy: same-origin`.
- **Session cookie**: `HttpOnly`, `SameSite=Lax`, `Secure` when configured or when the request is HTTPS,
  session cookie (no `Expires`), `session.use_strict_mode` and `use_only_cookies` on
  (`src/Security/Session.php`).
- **Idle expiry is real, not just garbage collection**: `Auth` records last activity and refuses a session
  idle for more than 7200 seconds (`src/Security/Auth.php`). The identifier is regenerated on sign-in.
- **CSRF** is required on every state-changing form; a missing or stale token answers 419.
- **Password reset and status links** are stored as hashes and consumed by a single conditional `UPDATE`
  with `used_at IS NULL AND expires_at >= UTC_TIMESTAMP()` — single use is a property of the statement,
  not a check that a second request could race past. Reset tokens live one hour; status links 24 hours.

---

## 8. Abuse controls

- **Sign-in**: five failures for the same address-and-IP pair inside 15 minutes answers **429** and tells
  the person how many minutes to wait, in the page body. There is **no `Retry-After` header** — checked,
  because "retry after fifteen minutes" is easy to assume and would have been wrong. An unknown address
  is verified against a dummy hash so the response time does not reveal whether the account exists, and a
  role that may not sign in is refused as bad credentials rather than as a distinct state.
- **Account requests**: 5 per network per hour, 20 per day, plus a honeypot field and a self-hosted
  arithmetic challenge. Networks are counted by a **keyed** hash of the address — a plain SHA-256 of an
  IPv4 address is reversible by enumeration, which would make the "hash" a slower way of storing it. A
  refused attempt is not recorded, so one blocked caller cannot extend their own lockout and a shared
  clinic network does not become permanently blocked after a busy morning.
- **Password reset is deliberately not throttled** (`src/Domain/RequestPasswordReset.php` documents why):
  it answers identically whether or not the address is registered, so the only thing a throttle would add
  is a way to deny a legitimate reset.

---

## 9. What leaves the system

Notifications are written to `notification_outbox` **inside the transaction that changes the booking**, then
delivered by a CLI worker; no provider is called from a web request. Payload: reference, local date/time
and service only.

The default transport writes to a log file and masks the recipient to its last three characters. The HTTP
transport posts to a bridge the operator runs. Delivery retries at 1, 5, 15 and 60 minutes and a message
becomes `dead` after five attempts, so a gateway outage delays a notification but cannot lose it silently.

**Production sending is off unless explicitly approved** (`NOTIFY_PRODUCTION_APPROVED=false` in
`.env.example`); staging and development send regardless so staging can be tested.

---

## 10. What the code does not provide — for the notice, and for the provider

These are stated here so the notice does not promise them. Each is a host or policy decision, not an
application feature, and none has been verified in this pass unless marked.

| Not provided | Consequence for the notice |
|---|---|
| A web application firewall | Nothing filters hostile requests before the application sees them. The host said none is in place (carried, `HANDOFF-R1.md` §7.2) |
| Malware scanning of uploads | Quarantine plus human release is the compensating control; the notice must not claim scanning |
| ~~Encryption of backups at rest~~ | **Provided since 22 November 2026 when `BACKUP_ENCRYPTION_KEY` is set; not yet set on staging (§6).** The notice may not claim it until it is configured and a drill has passed against an encrypted file |
| A second, least-privilege database user | The application connects as the account's database user (carried; one of the twelve S4 questions) |
| Multi-factor authentication | Sign-in is password only |
| Purging of audit and access-log rows | Retention covers clinical records; audit retention is still D5 |
| A signed data-processing agreement | Legal, not engineering. Nothing in the code depends on it, and nothing in the code substitutes for it |

Hosting location, DPA status, subprocessor list and the provider's own backup encryption are **not**
answerable from this repository. They are among the twelve questions to Yegara (S4) and must be answered
before the notice describes them.

---

## 11. Machine-checked facts

The numbers below are the ones the notice and the security documentation will quote. Each is read from
the constant named beside it by `tests/Feature/ControlInventoryTest.php`, so a change in the code fails
the build rather than silently making this page wrong. Add a row only with a constant that exists.

| Control | Value | Constant |
|---|---|---|
| Retention period for a finished booking, in days | 2557 | `App\Bootstrap\Config::DEFAULT_RETENTION_DAYS` |
| Smallest retention a misconfiguration may set, in days | 30 | `App\Domain\RetentionPolicy::MIN_DAYS` |
| Failed sign-ins tolerated before a lockout | 5 | `App\Security\Auth::MAX_FAILURES` |
| Lockout window, in minutes | 15 | `App\Security\Auth::LOCK_MINUTES` |
| Password-reset token lifetime, in seconds | 3600 | `App\Security\Auth::RESET_TTL_SECONDS` |
| Largest accepted document upload, in bytes | 15728640 | `App\Domain\UploadReferralDocument::DEFAULT_MAX_BYTES` |
| Account requests allowed per network per hour | 5 | `App\Domain\AccountRequestThrottle::PER_HOUR` |
| Account requests allowed per network per day | 20 | `App\Domain\AccountRequestThrottle::PER_DAY` |
| Delivery attempts before a notification is dead | 5 | `App\Notifications\OutboxDispatcher::MAX_ATTEMPTS` |

---

## 12. What this document cannot tell you

- Whether any of it is true **on the host**. It describes the code at a commit. The staging probe is
  separate evidence, and production has not been deployed.
- Whether it is **sufficient**. That is the privacy review's judgement (L2), not engineering's.
- What the provider does with data at rest, in transit beyond TLS, or in its own backups. That is Yegara's
  twelve answers.
