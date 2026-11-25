# Booking App

A minimal, working PHP 8.3 booking application with a server-rendered UI and an
installable PWA shell. Built from scratch: no code, schema or dependency was
carried over from any earlier project.

The baseline is deliberately small. It proves the whole vertical slice —
routing, templating, escaping, CSRF, validation, UTC persistence, slot
exclusivity and the PWA shell — so that features can be added without
re-litigating the foundation.

Verified on PHP 8.3.36 and MySQL 8.4.9 Community (InnoDB, `utf8mb4_0900_ai_ci`).

---

## 1. What the baseline does

| Capability | Where |
|---|---|
| Front controller, explicit route table | `public/index.php`, `routes/web.php` |
| Request/response objects, safe error handling | `src/Http/` |
| Content Security Policy and hardening headers | `src/Http/Kernel.php` |
| Escaped PHP templates with one layout | `templates/`, `src/Support/View.php` |
| Session cookies hardened, CSRF tokens | `src/Security/` |
| Allowlist validation | `src/Validation/Validator.php` |
| Booking request → stored as `requested` (never auto-confirmed) | `src/Domain/BookingService.php` |
| One booking per 30-minute slot, enforced by the database | `booking_slots` primary key, `PdoBookingRepository::create()` |
| Lifecycle transitions with an append-only event log | `src/Domain/BookingStatus.php`, `booking_events` |
| Staff accounts, roles, login throttling, password reset | `src/Security/Auth.php`, `users`, `login_attempts`, `password_resets` |
| Referring accounts by public request, then administrative approval, then clinical verification (ADR-026) | `/request-account`, `/admin/account-requests`, `account_requests`, `src/Domain/ApproveAccountRequest.php` |
| Read-only availability calendar built from claimed slots | `/availability`, `src/Domain/DaySchedule.php` |
| Transactional notification outbox with retries | `src/Notifications/`, `notification_outbox` |
| Configurable opening hours and one-off closures | `clinic_hours`, `clinic_closures`, `/admin/hours` |
| Retention window that redacts finished bookings | `src/Domain/RetentionPolicy.php`, `retention:purge` |
| Read-only audit trail over every status change | `/admin/audit`, `booking_events` |
| Operator diagnostics: schema, outbox, backups, errors | `/admin/diagnostics`, `src/Support/Diagnostics.php` |
| Logical backup and restore drill without `mysqldump` | `src/Database/DatabaseBackup.php`, `db:*` commands |
| Prepared statements only | `src/Repositories/PdoBookingRepository.php` |
| Forward-only migrations with checksum ledger | `src/Database/Migrator.php` |
| CLI: doctor, migrate, bookings, users, accounts, outbox, backup, retention | `bin/console` |
| PWA: manifest, service worker, offline page | `public/manifest.webmanifest`, `public/sw.js` |
| Unit + feature tests | `tests/` |

Deliberately **not** included: self-service sign-up, payments, file uploads,
multi-practitioner calendars, an email provider for password resets, and any
provider-specific SMS integration (the HTTP bridge is provider-neutral). Each is
a feature to add on top of a foundation that already has tests, escaping and
migrations in place.

---

## 2. Prerequisites

| Requirement | Version | Notes |
|---|---|---|
| PHP | 8.3.x | CLI + web SAPI, with `pdo_mysql`, `mbstring`, `json`, `openssl` |
| MySQL | 8.0.16+ or MariaDB 10.6+ | InnoDB; the app uses `utf8mb4` and `CHECK` constraints |
| Composer | 2.x | Only needed to install dev dependencies |
| Git | any recent | |

Verify before anything else:

```powershell
php -v
php -m | Select-String pdo_mysql,mbstring,openssl
composer --version
mysql --version
```

If `php -m` does not list `pdo_mysql`, stop and enable it (`extension=pdo_mysql`
in `php.ini`) — the application will not start against the database without it.

### 2.1 Windows without a system PHP

If PHP is not installed, a portable copy works without administrator rights:

```powershell
# 1. Download the PHP 8.3 NTS zip from https://windows.php.net/downloads/releases/
# 2. Extract it, e.g. to C:\tools\php83
# 3. Create php.ini next to php.exe with these lines:
#    extension_dir = "ext"
#    extension=pdo_mysql
#    extension=mbstring
#    extension=openssl
#    extension=fileinfo
# 4. Put C:\tools\php83 on PATH for the session:
$env:Path = "C:\tools\php83;$env:Path"
```

Composer can be run as a local PHAR without a global install:

```powershell
Invoke-WebRequest https://getcomposer.org/download/latest-stable/composer.phar -OutFile composer.phar
php composer.phar --version
```

---

## 3. Step-by-step setup

### Step 1 — Create the repository

```powershell
cd C:\Users\dev\Desktop\booking
mkdir booking-app
cd booking-app
git init -b main
```

### Step 2 — Add the scaffold

Copy or create the files exactly as they are laid out in section 5, then confirm
the tree:

```powershell
Get-ChildItem -Recurse -File | Where-Object { $_.FullName -notmatch '\\vendor\\|\\var\\cache\\' } |
    Select-Object -ExpandProperty FullName
```

### Step 3 — Install dependencies

```powershell
composer install
```

This installs **dev tooling only** (PHPUnit, PHPStan, PHP_CodeSniffer). There are
no runtime Composer packages: `composer install --no-dev` produces nothing but
the autoloader, which keeps deployment to shared hosting trivial.

### Step 4 — Create the environment file

```powershell
Copy-Item .env.example .env
```

Edit `.env`:

| Key | Purpose |
|---|---|
| `APP_ENV` | `local`, `staging`, `production` |
| `APP_DEBUG` | `true` locally; must be `false` in production |
| `APP_URL` | Base URL used for links and redirects |
| `APP_TIMEZONE` | IANA zone used for **display** (storage is always UTC) |
| `DB_*` | MySQL connection |
| `SESSION_SECURE` | `true` behind HTTPS |
| `LOG_LEVEL` | `debug` locally, `warning` or higher in production |

`.env` is git-ignored. Never commit it, and never place it inside `public/`.

### Step 5 — Create the database and a least-privilege user

```sql
CREATE DATABASE booking_app CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE USER 'booking_app'@'localhost' IDENTIFIED BY 'replace-with-a-long-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON booking_app.* TO 'booking_app'@'localhost';
FLUSH PRIVILEGES;
```

> For production, split credentials: a migration user with DDL rights and a
> runtime user without `CREATE`/`ALTER`/`DROP`. The runtime account should not be
> able to change the schema.

### Step 6 — Verify the environment

```powershell
php bin/console doctor
```

Expected output (abridged):

```
[ OK ] php                8.3.x
[ OK ] ext-pdo_mysql      loaded
[ OK ] .env               ...\booking-app\.env
[ OK ] db-connect         8.0.x
[ OK ] migrations         1 pending
```

`doctor` exits non-zero when any check fails, so it can gate a deployment.

### Step 7 — Run migrations

```powershell
php bin/console migrate --dry-run   # preview
php bin/console migrate             # apply
php bin/console migrate:status      # verify
```

The runner records each file's SHA-256 in `schema_migrations`. Editing an applied
migration is refused; add a new one instead.

Create the first administrator. Without this nobody can sign in: there is no default
account and no sign-up route.

```powershell
php bin/console user:create admin@example.com "Clinic Admin" admin
```

Omit `--password` and a 20-character password is generated and printed once.

### Step 8 — Start the development server

```powershell
composer serve
# equivalent: php -S 127.0.0.1:8000 -t public dev-router.php
```

`-t public` matters: it makes `public/` the document root so static assets are
served directly, while `dev-router.php` forwards unknown paths to the front
controller.

Open <http://127.0.0.1:8000>.

| URL | Expected |
|---|---|
| `/` | Landing page |
| `/bookings/new` | Form |
| `/bookings` | Upcoming list (empty initially) |
| `/healthz` | `{"status":"ok","checks":{...}}` |

### Step 9 — Exercise the slice

1. Submit a booking with a future date/time.
2. You are redirected to `/bookings/BK-…` with status `requested`.
3. `/bookings` lists it.
4. Submit again with a past date → inline validation errors, HTTP 422.
5. Submit with the CSRF field removed (dev tools) → HTTP 419, nothing persisted.
6. `/healthz` → 200; stop MySQL and it returns 503 with a generic body.
7. `/bookings` without a session → redirect to `/login`.
8. Sign in as the administrator → `/bookings` renders with Confirm and Cancel actions.
9. Cancel a booking → its slot is released and the time is bookable again.
10. Sign out → `/bookings` redirects to `/login` again.

### Step 10 — Run the quality gates

```powershell
composer test     # PHPUnit
composer stan     # PHPStan level 9
composer cs       # PSR-12
composer check    # all three
```

### Step 11 — Commit

```powershell
git add .
git commit -m "chore: scaffold booking app baseline"
git tag v0.1.0
```

Verify nothing sensitive is staged:

```powershell
git status --short
git ls-files | Select-String -Pattern '\.env$|vendor/|\.log$'
```

The last command must return nothing.

### Step 12 — Deploy (outline)

The full deployment procedure is a separate runbook; the shape is:

1. Build on CI: `composer install --no-dev --optimize-autoloader`, `composer check`.
2. Upload only `public/` into the document root; keep `src/`, `templates/`,
   `database/`, `var/` and `vendor/` **one level above** it.
3. Point the web root at `public/`, enable the rewrite rule, force HTTPS.
4. Create `.env` on the server (never uploaded from a laptop), `APP_DEBUG=false`.
5. Run `php bin/console migrate` from the shell, not from a web route.
6. Hit `/healthz`, then smoke-test the booking flow.

---

## 4. Commands reference

| Command | Purpose |
|---|---|
| `composer serve` | Built-in server with pretty URLs |
| `composer test` | PHPUnit suite |
| `composer stan` | Static analysis (level 9) |
| `composer cs` / `composer cs:fix` | Code style check / fix |
| `composer check` | Style + static analysis + tests |
| `php bin/console doctor` | Runtime/database/migration health |
| `php bin/console migrate [--dry-run]` | Apply pending migrations |
| `php bin/console migrate:status` | Show applied/pending + checksum drift |
| `php bin/console bookings:list` | Print the next 20 bookings |
| `php bin/console booking:show <ref>` | Show one booking |
| `php bin/console booking:confirm <ref>` | `requested → confirmed` |
| `php bin/console booking:cancel <ref> [--reason="…"]` | `requested/confirmed → cancelled`, releases the slot |
| `php bin/console booking:complete <ref>` | `confirmed → completed`, keeps the slot |
| `php bin/console user:create <email> <name> <admin\|staff>` | Create an account; prints a generated password once |
| `php bin/console user:list` | List accounts |
| `php bin/console user:reset-token <email>` | Print a one-time reset path (60 min TTL) |
| `php bin/console user:deactivate <email> --actor=<email>` | Suspend an account: sign-in refused, live sessions ended, audit row written |
| `php bin/console user:reactivate <email> --actor=<email>` | Restore a suspended account |
| `php bin/console outbox:list [--limit=20]` | Inspect queued notifications |
| `php bin/console outbox:dispatch [--limit=20]` | Deliver due notifications (cron) |
| `php bin/console diag` | Print the operational checks (exits 1 on a failure) |
| `php bin/console db:backup [--prune=14] [--require-encryption]` | Write a logical backup to `var/backups/`; encrypted when `BACKUP_ENCRYPTION_KEY` is set, and `--require-encryption` exits 2 rather than write a plaintext one |
| `php bin/console db:backup --key` | Print a new `BACKUP_ENCRYPTION_KEY` and exit; storing nothing |
| `php bin/console db:backups` | List backups with size, age, and whether each one is encrypted |
| `php bin/console db:restore-drill [--file=…] [--database=…_drill] [--keep]` | Restore into a scratch schema and compare row counts |
| `php bin/console logs:review [--level=error] [--limit=30]` | Read the JSON-lines application log |
| `php bin/console retention:plan` | Show the cutoff and how many bookings are eligible |
| `php bin/console retention:purge [--days=2557] [--yes]` | Redact finished bookings; refuses to act without `--yes` |
| `php bin/console outbox:receipt <provider-message-id> [--at=…]` | Record that the provider reported delivery; refuses an id this system never handed over |
| `php bin/console accounts:requests [--expire]` | List the referring-account queue; `--expire` closes anything unanswered after 60 days |
| `php bin/console demo:seed [--yes] [--password=…]` | Fill an empty development database with fictional records; refuses to run in production |
| `php bin/console content:sync` | Import the service and policy pages as drafts; a published row is never rewritten, so a corrected page carries its own `version` and `superseded_version` |
| `php bin/console content:withdraw <content-key> --actor=<email> --yes` | Take a published page down, and record who decided it |
| `php bin/console backup:key-check` | Check the backup key's custody against D-08 (exits 1 on a failed control) |
| `php bin/console escalation:seed-register` | Create the five D-06 route categories, empty and inactive |
| `php bin/console security:credentials [--require-recorded]` | Print the D-10 credential register: office, custodian, last rotation, what is overdue |
| `php bin/console release:gates [--json]` | Print the seven §3.2 release gates, their owner and their evidence; exits 1 while any is unmet |

---

## 5. Directory structure

```text
booking-app/
├── bin/
│   ├── console                  # CLI entry point — a loader, see note below
│   └── console.php              # the console itself; this is the file phpcs reads
├── .github/workflows/ci.yml      # quality gate on push and pull request
├── database/
│   └── migrations/
│       ├── 0001_create_bookings.sql
│       ├── 0002_create_booking_slots.sql
│       ├── 0003_backfill_booking_slots.sql
│       ├── 0004_create_booking_events.sql
│       ├── 0005_create_auth_tables.sql
│       ├── 0006_create_notification_outbox.sql
│       ├── 0007_add_retention.sql
│       └── 0008_create_clinic_calendar.sql
├── public/                      # the only web-accessible directory
│   ├── .htaccess                # rewrite + dotfile denial
│   ├── index.php                # front controller
│   ├── manifest.webmanifest
│   ├── sw.js                    # static-assets-only service worker
│   ├── offline.html
│   └── assets/
│       ├── css/app.css
│       ├── js/app.js
│       └── icons/               # 192, 512, maskable 512
├── routes/
│   └── web.php                  # explicit route table
├── src/
│   ├── Bootstrap/               # Env, Config, Application (composition root)
│   ├── Controllers/
│   ├── Database/                # Connection, Migrator, SqlScript, DatabaseBackup
│   ├── Domain/                  # Booking, BookingService, ports, retention, calendar
│   ├── Http/                    # Request, Response, Router, Kernel, exceptions
│   ├── Notifications/           # outbox dispatcher, log + HTTP transports
│   ├── Repositories/            # PDO implementations of the ports
│   ├── Security/                # Session, Csrf, Auth, Gate, PasswordHasher
│   ├── Support/                 # View, Logger, LogReader, Diagnostics, helpers
│   └── Validation/
├── templates/
│   ├── layout.php
│   ├── home.php
│   ├── auth/{login,reset}.php
│   ├── admin/{index,users,hours,audit,diagnostics}.php
│   ├── availability/index.php
│   ├── bookings/{create,index,show}.php
│   └── errors/{403,404,410,419,500,503}.php
├── tests/
│   ├── Unit/
│   └── Feature/
├── var/                         # runtime only, git-ignored contents
│   ├── backups/                  # logical backups, git-ignored
│   ├── cache/
│   └── logs/
├── .editorconfig
├── .env.example
├── .gitignore
├── composer.json
├── dev-router.php               # router for `php -S`
├── phpcs.xml
├── phpstan.neon
├── phpunit.xml
└── README.md
```

**Rule that keeps the deployment safe:** nothing outside `public/` is ever
reachable by URL. `src/`, `templates/`, `database/`, `var/` and `vendor/` sit one
level above the document root.

**Why the console is two files.** `phpcs` takes a file's extension from its name. A file
called `console` has none, so it is skipped silently — the gate reports success while
reading nothing. That is how 1,219 lines of the most-executed code here went unlinted.
The body therefore lives in `bin/console.php`, which the gate reads, and `bin/console`
is a loader that requires it. The name `console` stays because `deploy.sh` and five cron
jobs on the host call it, and a rename that satisfies the linter would stop the outbox
and the backups. `tests/Feature/StyleGateCoverageTest.php` fails if a path without a
`.php` extension is added back to `phpcs.xml`, and `ConsoleEntryPointTest` fails if the
loader grows logic.

---

## 6. Architecture decisions

Recorded here so later changes are deliberate rather than accidental.

The decisions below are recorded in the standalone register at [docs/adr/README.md](docs/adr/README.md),
one entry per decision with its context, consequences, owner and status. That register, not this
section, is authoritative: it distinguishes a decision that has been **approved** from one that is
merely **implemented**, and it records ADR-013, the accepted boundary between the referral and booking intakes.

| ID | Decision | Status |
|---|---|---|
| ADR-001 … ADR-012 | Foundation, storage, workflow and operations decisions | Implemented, unapproved |
| ADR-013 | Public intake model: referrals only; `bookings` stays a staff record with no public route | **Accepted** — Product Owner, 2026-11-17. No anonymous referral submission in Release 1: `/referrals/new` and `POST /referrals` require `Permission::ReferralSubmit`. Referrer accounts are created through a request-and-approve flow. `/request` therefore redirects to `/login` until that flow ships. |

### Security posture in the baseline

- Output escaped through `e()` in every template; no raw interpolation.
- CSP with `default-src 'self'`, no inline scripts or styles.
- `HttpOnly`, `SameSite=Lax` session cookie; `Secure` when HTTPS is detected.
- CSRF token validated on every state-changing request; a POST without one returns 419 and stores nothing.
- Prepared statements everywhere; identifiers never come from input.
- Validation is an allowlist; unknown fields are ignored, not persisted.
- Errors never expose stack traces when `APP_DEBUG=false`.
- No secrets in the repository; `.env` is ignored and outside `public/`.

---

## 7. Baseline acceptance criteria

The scaffold is "done" when all of these are true:

- [ ] `composer install` completes with no runtime packages.
- [ ] `php bin/console doctor` exits 0.
- [ ] `php bin/console migrate` applies `0001` and `migrate:status` shows `yes`.
- [ ] `/`, `/bookings/new`, `/bookings`, `/healthz` respond as expected.
- [ ] A valid submission stores one row and shows a `BK-…` reference.
- [ ] An invalid submission returns 422 with field-level messages and stores nothing.
- [ ] A POST without a CSRF token returns 419 and stores nothing.
- [ ] A second claim on a taken slot returns 409 and stores nothing.
- [ ] Five concurrent claims on one slot produce exactly one booking.
- [ ] `booking:confirm` moves `requested → confirmed` and keeps the slot.
- [ ] `booking:cancel` releases the slot; the same time can be booked again.
- [ ] `booking:complete` on a cancelled booking fails with a non-zero exit code.
- [ ] Every transition leaves exactly one `booking_events` row.
- [ ] `/bookings` redirects to `/login` without a session.
- [ ] Five wrong passwords lock further attempts for 15 minutes.
- [ ] A `staff` account gets 403 on `/admin/users`; an `admin` gets 200.
- [ ] A reset link works once, then returns 410.
- [ ] Signing out invalidates the session for subsequent requests.
- [ ] `/availability` shows 18 half-hour slots for a default day, with claimed slots marked booked.
- [ ] A booked slot on the calendar links to the same booking the list shows.
- [ ] An invalid `?date=` falls back to today instead of erroring.
- [ ] `/availability` reflects the configured week: a closed Saturday shows no slots.
- [ ] A request outside opening hours returns 422 and stores nothing.
- [ ] A one-off closed day rejects a request even inside the weekly window.
- [ ] A `staff` account gets 403 on `/admin`, `/admin/hours`, `/admin/audit` and `/admin/diagnostics`.
- [ ] `php bin/console db:backup` writes a file; `db:restore-drill` reports identical row counts.
- [ ] `retention:purge` without `--yes` changes nothing and exits non-zero.
- [ ] A redacted booking keeps its reference, slot and history but has no name, phone or notes.
- [ ] `php bin/console diag` and `/admin/diagnostics` agree, and a dead outbox row fails the check.
- [ ] `composer check` passes (style, static analysis, tests).
- [ ] `git status` is clean; no `.env`, `vendor/`, or log file is tracked.
- [ ] Lighthouse installability check passes for the PWA manifest and service worker.

---

## 8. Booking rules

- Appointments start on a 30-minute grid and last 30 minutes (`BookingService::SLOT_MINUTES`).
- A request must fit inside the configured opening hours for that weekday and must not fall on a
  one-off closure. The check lives in `BookingService`, so the public form, the CLI and any future
  client share it; the database is still the authority on the slot itself.
- The appointment is single-calendar: one occupied slot per start time. Multiple rooms or
  practitioners turn `booking_slots` into `(resource_id, slot_start)`.
- The UI never decides availability. `INSERT` into `booking_slots` is the authoritative
  claim; a duplicate-key rejection surfaces as HTTP 409 with the form preserved.
- Cancelling deletes the slot row in the same transaction as the status change, so the
  time becomes bookable again immediately.

## 9. Lifecycle

| From | Allowed to | Effect |
|---|---|---|
| `requested` | `confirmed` | slot stays claimed |
| `requested` | `cancelled` | slot released |
| `confirmed` | `completed` | slot stays claimed (the appointment happened) |
| `confirmed` | `cancelled` | slot released |
| `cancelled` / `completed` | — | terminal |

Rules are declared once in `BookingStatus`; every caller inherits them. Each change writes
an append-only `booking_events` row (from, to, actor, reason, timestamp). The guarded
`UPDATE … WHERE status = :expected` means a stale read cannot silently overwrite a newer
state: the loser gets `InvalidTransitionException`.

Confirm and cancel are available in the staff console (`/bookings`) and on the CLI. Both
paths call the same service method.

## 10. Accounts and roles

| Role | Bookings | Users | Opening hours | Diagnostics and audit |
|---|---|---|---|---|
| `admin` | confirm, cancel | list accounts | edit | read |
| `staff` | confirm, cancel | — | — | — |

Permissions are named capabilities (`bookings.manage`, `users.manage`, `clinic.configure`,
`system.maintain`); roles only map to them, and route handlers ask for the permission.

- Accounts are **created from the console only** — no self-service sign-up, no default admin.
- Passwords hash with Argon2id where available, else the platform default; minimum 12 characters.
- Five failed attempts for the same email + IP within 15 minutes lock sign-in for 15 minutes.
- The session stores only a user id and last-seen timestamp; identity and role are re-read
  from the database each request, so disabling an account takes effect immediately.
- Session id rotates on sign-in and sign-out (fixation defence); idle sessions expire after
  `SESSION_LIFETIME`.
- Reset tokens are 32 random bytes, stored only as SHA-256, single-use, valid for one hour.
  Issuing them is a console action because there is no mail provider yet.
- `/bookings` requires a session; the public booking form and a booking's confirmation page
  remain reachable by reference.

## 11. Notifications

Messages are written to `notification_outbox` **inside the transaction that changes the
booking**, then delivered by a CLI worker. No provider is called from a web request: a slow
gateway cannot delay or roll back a booking.

| Concern | Behaviour |
|---|---|
| Enqueue | `booking_requested`, `booking_confirmed`, `booking_cancelled`; unique per booking + template + channel, so retries cannot duplicate |
| Dispatch | `php bin/console outbox:dispatch`, claiming rows with `FOR UPDATE SKIP LOCKED` |
| Retries | 1, 5, 15, then 60 minutes; after 5 attempts the row becomes `dead` and the command exits non-zero |
| Crash recovery | Rows left in `sending` for over 10 minutes are requeued |
| Content | Reference, local date/time and service only — no clinical detail |
| Transport | `LogTransport` writes to `var/logs/notifications.log` with the recipient masked; `HttpTransport` posts the message to a gateway bridge |

Cron on the host (every minute):

```cron
* * * * * /usr/local/bin/php /home/ACCOUNT/booking/bin/console outbox:dispatch --limit=20
```

To deliver real messages, point the application at a bridge you operate:

```ini
NOTIFY_DRIVER=http
NOTIFY_HTTP_URL=https://bridge.example.africa/send
NOTIFY_HTTP_TOKEN=…          # sent as `Authorization: Bearer …`
NOTIFY_HTTP_TIMEOUT=5
```

The bridge receives one JSON object per message:

```json
{
  "channel": "sms",
  "template": "booking_requested",
  "to": "+251911000111",
  "body": "We received booking request BK-… for Wed 8 May 2030, 10:00 EAT. The team will confirm shortly.",
  "reference": "BK-…"
}
```

It answers `2xx` when the provider accepted the message. Anything else is a failure the outbox
retries, so a gateway outage never loses a notification. Provider credentials, sender IDs and
template registration stay in the bridge; changing provider never touches this application.

## 12. Routes

This table is curated: it documents the routes somebody needs to know about, not all 103. The
complete manifest is asserted by `tests/Feature/RouteAccessManifestTest.php`, and
`tests/Feature/DocumentedRoutesTest.php` fails if any row below names a route that does not exist or
describes its access differently from the code. Every path is written in full — a shorthand like
`/cancel` is not a URL and cannot be checked.

| Method | Path | Access |
|---|---|---|
| GET | `/` | public; the landing page links to the account request, not to the staff form |
| GET/POST | `/request-account` | public (CSRF required on POST); ADR-026 |
| GET | `/admin/account-requests` | `account_requests.triage` |
| POST | `/admin/account-requests/{id}/approve`, `/admin/account-requests/{id}/reject` | `users.manage` (CSRF required) |
| GET | `/bookings/new` | `bookings.manage` — staff only; not advertised publicly |
| POST | `/bookings` | `bookings.manage` (CSRF required) |
| GET | `/bookings` | `bookings.manage` |
| GET | `/bookings/{reference}` | `bookings.manage` — staff; the patient-facing path is the status link `/r/{token}` |
| POST | `/bookings/{reference}/confirm`, `/bookings/{reference}/cancel` | `bookings.manage` (CSRF required) |
| GET | `/availability` | public; the calendar answers before sign-in and carries no patient detail |
| GET | `/login` | public |
| POST | `/logout` | any signed-in user |
| GET/POST | `/password/reset/{token}` | public with a valid token |
| GET | `/admin` | `system.maintain` |
| GET | `/admin/users` | `users.manage` |
| GET/POST | `/admin/hours` | `clinic.configure` (CSRF required on POST) |
| GET | `/admin/audit` | `system.maintain` |
| GET | `/admin/diagnostics` | `system.maintain` |
| GET | `/referrals/{reference}/documents` | `document.read.metadata` + object scope |
| POST | `/referrals/{reference}/documents` | `document.upload` (CSRF required) |
| POST | `/referrals/{reference}/status-link` | `referral.triage` (CSRF required) |
| GET | `/documents/{ref}/download` | `document.download` + object scope |
| POST | `/documents/{ref}/review` | `document.quarantine.manage` (CSRF required) |
| GET | `/r/{token}` | public; the token is the credential |
| GET | `/schedule` | `schedule.manage` |
| GET | `/schedule/book` | `schedule.manage` |
| POST | `/schedule/book` | `schedule.manage` (CSRF required) |
| GET | `/schedule/{reference}/move` | `schedule.manage` |
| POST | `/schedule/{reference}/reschedule` | `schedule.manage` (CSRF required) |
| POST | `/schedule/{reference}/cancel` | `bookings.manage` (CSRF required) |
| GET | `/healthz` | public, generic output |

### 12.1 Private documents and status links

Files live in `var/private-uploads/`, outside `public/`, under a random 32-hex key with no extension.
The URL carries a different random reference, so a link to a document tells a passer-by nothing and
cannot be walked from one number to the next. Only PDF, PNG and JPEG are accepted, checked against
their magic bytes *and* libmagic — a browser's `Content-Type` is never evidence.

Every upload is born quarantined. `POST /documents/{ref}/review` is how an administrator releases a
file, and the verdict records who decided, when and why. That command is the documented compensating
control for a host with no malware scanner (HAK-0904); if a scanner is added later it writes the same
verdict through the same command.

`POST /referrals/{reference}/status-link` mints a link for the patient: 64 hex characters, stored only
as SHA-256, valid once, expiring after `TRACKING_LINK_TTL_MINUTES` (default 24 h). The page it opens
shows the reference, the status and the procedure — no name, no phone number, no clinical summary.

### 12.2 The reservation engine

A slot is 15 minutes. `resource_slots (resource_id, slot_start)` is the primary key and therefore the only
authority on whether a time is free: the second booking fails on the key, whatever the application believed a
moment earlier. Writers additionally take `SELECT ... FOR UPDATE` on the resource row first, in ascending id order,
so overlapping multi-quantum bookings are queued rather than deadlocked.

The wizard offers only times the server computed from the room's opening windows and its occupancy, and it submits
one of those times as a single value. A hand-edited POST that names an occupied time is refused; the desk sees the
reason and a freshly computed list. Rescheduling inserts every new quantum before deleting any old one, so a failed
move leaves the patient where they were. Cancelling deletes the occupancy rows inside the same transaction that sets
`cancelled`, so a room is never blocked by a patient who is not coming.

Every move is recorded in `appointment_reschedules` with the old time, the new time, the person who moved it and
their reason. Tested by `ReservationConcurrencyTest` (20 operating-system processes, one winner) and
`SchedulingFlowTest` (book, move, fail, cancel against MySQL).


## 13. Operations

| Task | Command | Cadence |
|---|---|---|
| Health check | `php bin/console doctor` / `diag` | after deploy, daily |
| Backup | `php bin/console db:backup --prune=14` | hourly, off the host afterwards |
| Restore proof | `php bin/console db:restore-drill` | weekly, and after any schema change |
| Notification delivery | `php bin/console outbox:dispatch --limit=20` | every minute |
| Retention sweep (bookings, referrals and their files) | `php bin/console retention:purge --yes` | monthly |
| Log review | `php bin/console logs:review --level=error` | after an incident |
| CI result for a commit | `php bin/ci-status.php` | after every push |

`db:backup` never calls `mysqldump`: the dump is produced with PDO, so it works where `exec()` is
disabled. A drill restores into `booking_app_drill` (or the database given with `--database=`) and
compares row counts; it refuses to target the live database and only drops a scratch schema whose
name ends in `_drill`.

Backups under `var/backups/` sit inside the same failure domain as the database. Copy them off the
host — that step is yours, because it needs a destination only you can choose.

`retention:purge` runs both sweeps against one cutoff: bookings, then referrals whose last transition
is older than `RETENTION_DAYS`. For a referral it deletes the stored file first, marks the row, then
overwrites the identity columns and destroys that referral's status links — so a redacted record never
leaves an orphaned file behind. `retention:plan` prints both counts without changing anything.

Back up `var/private-uploads/` with the database. A database-only backup holds document rows whose
bytes are gone, which is worse than either half on its own.

`bin/ci-status.php` answers "did CI pass for this commit?" with an exit code, and it is the only answer the
release may rely on: `0` green, `1` red, `2` no run for that commit or the API could not be read, `3`
still running. After a push, run `php bin/ci-status.php --wait=300` — the local suite passing is not the same
claim, and a green badge is not evidence unless it belongs to the commit being released. The tool
matches the exact `head_sha`, so it reports no run rather than the previous commit's success. A token in
`GITHUB_TOKEN` lifts the 60-per-hour unauthenticated limit to 5,000 and is never printed.

What the application actually does with personal data — where it is held, who can reach it, what the
retention sweep redacts, what the backups do not protect — is written down in
[docs/operations/control-inventory.md](docs/operations/control-inventory.md). It is the input a privacy
notice is written from, it names the file behind every claim, and the nine numbers it quotes are pinned
to their constants by `tests/Feature/ControlInventoryTest.php` so the page cannot quietly become wrong.

## 14. Remaining backlog

The application implements the foundation, the clinical command layer, scheduling and documents;
it does **not** yet implement the whole plan. The current gap analysis is
`../audit/implimentation_review_and_audit.md`, and the approved work is
`../audit/implimentation_backlog_and_plan.md` — read those before treating this section as
complete. These are deliberate next steps, in order of value:

1. **Bridge deployment** — run the HTTP bridge against the chosen provider and set `NOTIFY_*`.
2. **Off-host backups** — push `db:backup` output somewhere else, and alarm when the newest file is
   older than 48 h (the diagnostics check already reports it).
3. **Password reset delivery** — issue reset links by SMS/email through the same outbox instead of
   the console.
4. **Multiple practitioners or rooms** — `booking_slots` becomes `(resource_id, slot_start)`; the
   uniqueness that protects a single calendar extends unchanged.
5. **Alerting** — call `diag` from the host's monitoring, or send a message when an outbox row dies.

Each increment adds a migration, tests, and an ADR entry — no feature ships
without all three.
