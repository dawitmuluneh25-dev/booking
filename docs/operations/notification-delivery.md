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
| `http` | URL **and** token present; in production also `NOTIFY_PRODUCTION_APPROVED=true` | An unauthenticated bridge is not ready, and configuring one is not a decision to start messaging patients |
| anything else | Never | A typo does not silently fall back to the log |

## 4. Turning it on — the order matters

**The adopted path is the bridge (`http`).** It needs a URL and a token rather than an account and DNS, and
its contract — payload, status codes, idempotency, what may be logged — is
[notification-bridge-contract.md](notification-bridge-contract.md). Follow that document; the steps below are
the Brevo path, kept because the driver is still implemented and may be chosen later.

### Bridge path, in short

1. Deploy `tools/notify-bridge/` to its own host with a random `BRIDGE_TOKEN`, on HTTPS.
2. Set `NOTIFY_DRIVER=http`, `NOTIFY_HTTP_URL`, `NOTIFY_HTTP_TOKEN`.
3. `php bin/console notify:preflight` → **NOT READY**, naming `NOTIFY_PRODUCTION_APPROVED`. Expected.
4. One controlled message with `BRIDGE_GATEWAY=recording` → proves the wiring, not delivery.
5. Implement a real `BridgeGateway`, send one message to a number the clinic controls, match it with
   `outbox:receipt`.
6. Set `NOTIFY_PRODUCTION_APPROVED=true`, re-run preflight → **READY**.

### Brevo path

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

---

## 6. Is the queue moving? — `notify:queue-health`

A second way for delivery to fail silently, found on 26 November 2026 while finishing D-09.

`NOTIFY_DRIVER=log` was one way for the outbox to lie. This is the other: the outbox only moves when
`outbox:dispatch` runs, and nothing in the repository guaranteed that it did. On the demo database the
check found **14 messages queued and no dispatch in the table's history** — rows sitting `pending` since
the previous day, which staff would reasonably read as "in hand".

```
pending     : 14
oldest      : 9.7 hours
failed      : 0
last sent   : (never)
state       : STALE
  - 14 message(s) are queued and nothing has ever been dispatched.
    The `outbox:dispatch` schedule has not run at all.
```

### The rule

| Condition | State | Exit |
|---|---|---|
| Rows queued **and** no dispatch ever recorded | `STALE` | 1 |
| Oldest queued row older than 24 hours | `STALE` | 1 |
| Any row `failed` (and the queue is not stale) | `ATTENTION` | 1 |
| Nothing queued, nothing failed | `OK` | 0 |
| Outbox unreadable | `UNKNOWN` | 1 |

The first row is the one that matters. A threshold-only rule would have reported `OK` for that queue for
another fourteen hours, because the oldest row had not yet passed 24 hours — which is exactly the window
in which somebody asks whether messages are going out. **Never dispatched is decisive on its own.**

`ATTENTION` exits non-zero too: a row in `failed` will not retry itself once its attempts are exhausted,
and a queue with failures in it needs a person.

### What it reads, and what it does not

- Reads `status`, `created_at` and `sent_at` only. Read-only by construction — a monitoring path that
  could write would be able to change what it measures.
- Uses `sent_at` rather than `updated_at` for "last activity": retention redaction also touches
  `updated_at`, so a queue that had not moved would look like it moved on the day retention ran.
- Does **not** check whether the dispatch schedule exists. It reports the symptom; wiring
  `outbox:dispatch` into cron remains a deployment task, and this check is what tells you it was missed.
