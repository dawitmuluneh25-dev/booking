# Notification delivery — readiness and refusal

**Decision:** HR-ENG-DEC-R1-003 **D-09** — Brevo transactional email, sender
`notifications@hakimerad.com`, `NOTIFY_PRODUCTION_APPROVED=true` **after** the sender domain verifies.
**Command:** `php bin/console notify:preflight` (exit 1 when the configuration cannot deliver).

---

## 1. The failure this closes

`NOTIFY_DRIVER` defaults to `log`. In development that is right: the transport writes what would have been
sent, and nobody believes a laptop sends SMS. In **production** the same driver marks every outbox row
`sent` and delivers nothing — and the outbox is the record staff use to answer *"was the patient told?"*

So a production deployment could have looked healthy from every angle: rows `sent`, no errors, no failed
jobs, and not one message leaving the host. That is the exact shape of failure this project keeps finding,
so the transport now refuses instead of pretending.

## 2. What changed in the code

| Piece | Behaviour |
|---|---|
| `DeliveryReadiness::assess()` | One rule, two consumers. Returns `ok`, a `reason`, and `warnings` |
| `Application::transport()` | In production, an `ok = false` readiness yields `MisconfiguredTransport` |
| `MisconfiguredTransport` | Throws on every send with the reason, so the outbox row becomes `failed` |
| `notify:preflight` | Prints environment, driver, approval, sender, key presence; exits 1 when not ready |

It throws a plain `RuntimeException`, **not** `PermanentNotificationFailure`: a misconfiguration is not a
per-message condition. Marking rows permanently failed would punish the patients whose messages were
queued during the mistake; they should be retried once the configuration is fixed.

## 3. What "ready" means per driver

| Driver | Ready when | Notes |
|---|---|---|
| `log` | **Never in production.** In development: ready, with a warning that nothing is delivered | The warning is printed rather than implied |
| `brevo` | API key + sender present; in production also `NOTIFY_PRODUCTION_APPROVED=true` | Outside production it warns that sends are real |
| `http` | URL **and** token present | An unauthenticated bridge is not ready |
| anything else | Never | A typo does not silently fall back to the log |

## 4. Turning it on — the order matters

1. Verify the sender domain in Brevo: **SPF and DKIM**. An unverified domain turns every confirmation
   into spam or a bounce, and the outbox will faithfully record the bounce.
2. Set `NOTIFY_BREVO_API_KEY` and `NOTIFY_BREVO_SENDER=notifications@hakimerad.com`.
3. Set `NOTIFY_DRIVER=brevo`.
4. Run `php bin/console notify:preflight` — it should say **NOT READY** and name
   `NOTIFY_PRODUCTION_APPROVED`. That is the expected state at this point.
5. Send one message to an address you control (`outbox:dispatch` on a disposable row, or the demo
   booking flow) and confirm it arrives, and that the row records a provider id rather than `null`.
6. Set `NOTIFY_PRODUCTION_APPROVED=true`, re-run `notify:preflight` — **READY**.

Step 5 is not optional: a configured transport and a delivered message are different facts, and only the
second one has been observed.

## 5. What is not covered

- **No scheduled dispatch.** The outbox is drained by `outbox:dispatch`, which must be on a schedule;
  nothing in the repository guarantees that. A stale queue is silent in the same way the log driver was.
- **No bounce handling beyond the status.** A hard bounce marks the row failed with the provider's
  message; there is no suppression list, so a wrong address will be retried until an operator fixes it.
- **No template approval for WhatsApp.** SMS and email are free text here; WhatsApp templates require
  provider approval before they can send at all, which is why `NotificationChannel::WhatsApp` is not in
  the launch path.
