# Production configuration and deferred surfaces

**Decisions:** HR-ENG-DEC-R1-003 **D-13** (confirm production domain, TLS and session settings) and
**D-11** (defer the alternative document route past the freeze).
**Confirmed by:** Dawit Muluneh, 2026-11-26. **Applied at:** deployment, not by commit.

---

## 1. D-13 — the values production must carry

These are the settings whose local defaults are wrong for a public deployment. Nothing here is secret;
secrets live in the credential register.

| Setting | Local default | Production value | Why it matters |
|---|---|---|---|
| `APP_ENV` | `local` | `production` | Selects production behaviour, including the notification readiness refusal (D-09) |
| `APP_URL` | `http://localhost:8000` | `https://booking.hakimerad.com` | Absolute links in notifications and the reset flow; a wrong host sends patients to a dead domain |
| `APP_DEBUG` | `true` | **`false`** | A stack trace on a clinical page is both a disclosure and an alarm |
| `SESSION_SECURE` | `false` | **`true`** | Without it the session cookie is sent over plain HTTP the moment anything downgrades |
| `SESSION_LIFETIME` | `7200` (2 h) | `7200`, reviewed | Long enough for a coordinator's working session, short enough that an unattended kiosk does not stay signed in |
| `SESSION_NAME` | `booking_session` | unchanged | The name reveals nothing about the stack |
| `LOG_LEVEL` | `debug` | `warning` | Debug logs carry patient references |
| `NOTIFY_DRIVER` | `log` | `brevo` | `notify:preflight` refuses `log` in production, by design |

**TLS:** terminated at the host, with HTTP redirected to HTTPS at the edge. The application does not
redirect; it assumes the host does, which is why `APP_URL` must be the `https://` form.

### How to confirm, rather than assume

```bash
php bin/console notify:preflight     # names the notification gaps
php bin/console doctor               # environment, extensions, database, migrations
```

Then, signed in over HTTPS, check the response headers once:

```
Set-Cookie: booking_session=…; Secure; HttpOnly; SameSite=Lax
```

A cookie without `Secure` in production is a finding, not a preference.

### What is not covered here

`APP_KEY`/`APP_SECRET` rotation, database credentials and the backup key are the credential register's
subject (`docs/operations/credential-rotation.md`), not this page. D-07 remains deferred by the owner, so
those are still unrotated.

## 2. D-11 — the alternative document route is deferred

The operating model accepts a fallback channel at `documents.hakimerad.com` for clinical documents that
cannot be uploaded. **It is deferred past the freeze and must not be described anywhere as available.**

Verified on 26 November 2026 by searching the templates, content sources and application code for
`documents.hakimerad`, "document portal" and "Documents portal": **no public copy names it.** That is the
whole of this decision — a deferral is only real if the copy agrees with it.

If it is ever built, it needs its own security review before it accepts a patient document, because a
second intake channel doubles the surface at the moment the first one is still unproven.

### Guard for whoever edits copy next

Adding a sentence about a document portal is a decision, not an edit. If a page needs to say where a
document can be sent, the answer today is the contact details in the appointment communication — nothing
else exists.
