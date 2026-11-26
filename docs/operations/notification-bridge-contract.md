# Notification bridge contract — the `http` driver

**Status:** adopted for D-09. Brevo is deferred, not removed.
**Audience:** whoever operates the bridge, and whoever has to answer "was the patient told?" at 02:00.

## 1. Why this exists

The application must hand a notification to a gateway it does not control. Putting the provider inside the
application would mean every provider change touches clinical software, and holding an SMS provider's
credentials in the clinic's web root.

So the application posts one small JSON document over HTTPS to a **bridge** the clinic operates. The bridge
authenticates the caller, de-duplicates, and forwards to whichever SMS/WhatsApp/email provider is current.
Changing provider is then a change to the bridge, not to the application or its outbox.

The alternative path — sending transactional email directly through Brevo — remains implemented and
`NOTIFY_DRIVER=brevo` still works. It is deferred because it requires an account, DNS changes and a verified
sender. The bridge path needs a URL and a token, which is why it is being taken first.

## 2. What the application sends

```
POST <NOTIFY_HTTP_URL>
Content-Type: application/json
Accept: application/json
Authorization: Bearer <NOTIFY_HTTP_TOKEN>
Idempotency-Key: msg-<outbox row id>
User-Agent: booking-app/1.0
```

```json
{
  "channel": "sms",
  "template": "booking_confirmed",
  "to": "+251911000111",
  "body": "Booking BK-260110-ABC123 is confirmed for Tue 6 Jan 2027, 12:00 EAT.",
  "reference": "BK-260110-ABC123",
  "idempotency_key": "msg-9182"
}
```

| Field | Meaning |
|---|---|
| `channel` | `sms`, `whatsapp` or `email`. Anything else is a bug in the adapter, not something to guess at. |
| `template` | The message class, e.g. `booking_confirmed`, `password_reset`. Use it for provider template routing and for deciding what may be logged. |
| `to` | Recipient. E.164 (`+2519…`) for `sms` and `whatsapp`; an address for `email`. |
| `body` | The rendered text, **already localised to clinic time**. Send it as given: re-rendering the time is how a patient gets the wrong hour. |
| `reference` | Booking, referral or appointment reference. Safe to display; useful in provider-side reconciliation. |
| `idempotency_key` | Stable per outbox row. Also sent as the `Idempotency-Key` header. |

**The body contains no clinical detail.** It names a reference and a time. There is no patient name, no
procedure, no diagnosis and no reason for attendance — those must not be added by the bridge either, including
in provider-side template variables.

Exactly one template, `password_reset`, carries a single-use credential (a reset link). The application scrubs
it from the outbox immediately after a successful send. The bridge must not persist it (§6).

## 3. What the bridge must answer

The application treats **any 2xx** as "the bridge accepted this message" and marks the outbox row `sent`.
Everything else is a failure and is retried. That makes the status code a factual claim, so answer it as one.

| Status | When | What the application does |
|---|---|---|
| `202` | Accepted and handed to the provider | Marks the row `sent`, stores `messageId` |
| `200` | Accepted earlier — this is a repeat (§4) | Marks the row `sent`, stores the original `messageId` |
| `400` | Body is not valid JSON, or a required field is missing or malformed | Retries, then dead-letters after 5 attempts |
| `401` | Token missing or wrong | Retries, then dead-letters. **Investigate immediately** — either the token rotated on one side only, or something is posting to the bridge |
| `413` | Body over 8 KiB | Retries, then dead-letters. The application never sends this; it means something else is calling |
| `422` | Well-formed but unsendable: unknown channel, recipient not E.164, invalid email | Retries, then dead-letters. This is a data problem, not a transient one |
| `502` | The provider refused or was unreachable | Retries with backoff |
| `5xx` | Bridge fault | Retries with backoff |

Success bodies:

```json
{"status": "accepted",  "messageId": "sms-9f21c3"}
{"status": "duplicate", "messageId": "sms-9f21c3"}
```

`messageId` is the provider's identifier. The application stores it and reconciles it against delivery
receipts with `php bin/console outbox:receipt <provider-message-id>`. If the provider returns no identifier,
the bridge may return its own stable one — but then a receipt can never be matched to the provider's report,
so prefer the provider's.

Do not answer `202` before the provider has accepted the message. "Queued locally" is not acceptance, and the
outbox would say the patient was told when nothing has left the bridge.

## 4. Idempotency — the rule that makes retries safe

Delivery is **at-least-once**. The dispatcher claims rows and records `sent` only after a 2xx. If it dies in
between — or the response is lost — the row is re-claimed after 600 seconds and posted again with the
**same** `idempotency_key`.

So the bridge must deliver at most once per key:

1. Key seen before and accepted → answer `200` with the original `messageId`, send nothing.
2. Key seen before and the earlier attempt failed → treat as new; the failure left nothing delivered.
3. No key (older rows, manual calls) → deliver, no de-duplication.

Keep the record for longer than the retry window: at least 24 hours, comfortably 7 days. The application gives
up after 5 attempts, so a key that is still needed is at most a few hours old — but operator replays exist.

## 5. Security

- **HTTPS only in production.** The token is a bearer credential; over plain HTTP it is a password shouted in
  a corridor. `http://127.0.0.1` is acceptable only for the loopback verification in §8.
- **The token is the only authentication.** Generate it randomly (32+ bytes), one per deployment, stored in
  the application's `.env` as `NOTIFY_HTTP_TOKEN` and in the bridge's environment as `BRIDGE_TOKEN`.
- **Compare in constant time** and reject an unset token. A bridge with no token configured must refuse every
  request, not accept them unauthenticated — an unset environment variable is a misconfiguration, not a
  permission.
- **Do not put the token in the URL.** Query strings end up in access logs, proxies and browser history.
- **Rotate** by adding the new value to the bridge first, then to the application, then removing the old one.
  Record it in the credential register (`php bin/console security:credentials` already tracks
  `NOTIFY_HTTP_TOKEN`); the register holds names and dates, never values.
- **Network:** restrict inbound to the application host if the bridge is internet-facing, and do not expose it
  on a shared host. A bridge is a device that sends messages on the clinic's account.
- **Rate-limit** at the edge. The application sends in bursts of 20 per dispatch; a leaked token should not
  become an unbounded SMS bill.

## 6. Data handling

| Rule | Why |
|---|---|
| Do not log the `body` for `password_reset` | It contains a working single-use link. The application redacts it after sending; a bridge log would restore it with a longer life |
| Do not store bodies longer than needed for delivery diagnosis | The body is not clinical, but it is still personal correspondence |
| Store the `idempotency_key`, status, `messageId`, channel and timestamp | This is what makes a duplicate provable and a receipt matchable |
| Do not copy messages into a third-party analytics or support tool | The provider's dashboard is the only other place a message should exist |
| Keep bridge logs out of the web root | Same rule as the application's `var/logs` |

## 7. Configuration

Application side (`.env`, never committed):

```dotenv
NOTIFY_DRIVER=http
NOTIFY_HTTP_URL=https://bridge.example.internal/send
NOTIFY_HTTP_TOKEN=<random 32+ bytes>
NOTIFY_HTTP_TIMEOUT=5
NOTIFY_PRODUCTION_APPROVED=false
```

Bridge side:

```dotenv
BRIDGE_TOKEN=<the same value as NOTIFY_HTTP_TOKEN>
BRIDGE_STATE_DIR=/var/lib/notify-bridge/state
BRIDGE_RECORD_FILE=/var/lib/notify-bridge/accepted.jsonl
BRIDGE_GATEWAY=recording
```

`NOTIFY_PRODUCTION_APPROVED` gates **both** drivers. A configured URL and token are configuration, not a
decision to start messaging patients; without the flag the transport fails loudly per message instead of
delivering, exactly as the Brevo path does. Check with:

```
php bin/console notify:preflight      # exits 1 while the path cannot deliver
php bin/console notify:queue-health   # exits 1 while the queue is not being drained
```

## 8. The reference implementation

`tools/notify-bridge/` is a working bridge that ships with the application and is deployed **separately**.

```bash
# local verification only
BRIDGE_TOKEN=local-test BRIDGE_STATE_DIR=./var/bridge/state \
BRIDGE_RECORD_FILE=./var/bridge/accepted.jsonl BRIDGE_GATEWAY=recording \
php -S 127.0.0.1:8099 tools/notify-bridge/index.php
```

```
NOTIFY_DRIVER=http NOTIFY_HTTP_URL=http://127.0.0.1:8099/send NOTIFY_HTTP_TOKEN=local-test \
php bin/console outbox:dispatch --limit=1
```

`BRIDGE_GATEWAY=recording` is the only gateway included. It writes each accepted message to
`BRIDGE_RECORD_FILE` and returns a `rec-…` identifier. It **sends nothing**. Its name says so deliberately:
a deployment that points at it gets outbox rows marked `sent` and no message in front of a patient, which is
precisely the failure the application refuses to commit itself.

**It is not the clinic web root.** `deploy/deploy.ps1` ships `bin`, `src`, `templates`, `routes`, `database`
and `public` — `tools/` is deliberately not among them. The bridge lives on its own host, with its own token,
and must never be served from `public/`.

**What the bridge host needs:** the application's `vendor/` and `src/` (the validation and grammar are the
tested ones, not a second copy), which `BRIDGE_APP_ROOT` points at. Running `deploy.sh` on the bridge host
with that subset, or a checkout with `composer install --no-dev`, both work.

### What this reference implementation does not do

It is a correct implementation of this contract, not a production messaging service:

- de-duplication is a file per key on local disk — single host only, no expiry;
- no rate limiting, no provider failover, no delivery receipts;
- no real provider gateway (add one by implementing `BridgeGateway` against the provider's API).

A production bridge keeps the same contract and replaces those three things.

## 9. Proving the path (D-09 evidence)

| Step | Command | Expected |
|---|---|---|
| 1. Bridge reachable and authenticating | `curl -sS -o /dev/null -w '%{http_code}' -X POST <url> -d '{}'` | `401` — it is listening and refusing anonymous calls |
| 2. Application configuration complete | `php bin/console notify:preflight` | `READY`, or a named missing value |
| 3. One controlled message | `php bin/console outbox:dispatch --limit=1` with `NOTIFY_HTTP_URL` pointed at the recording bridge | `sent: 1`, and one line in `BRIDGE_RECORD_FILE` |
| 4. Identifier recorded | `php bin/console outbox:list --limit=5` | the row is `sent` with a non-empty provider id |
| 5. Duplicate is harmless | re-dispatch the same row | `200`/`duplicate` from the bridge, one line in the record file |

Step 3 with the **recording** gateway proves the wiring and the contract. It does **not** prove delivery, and
D-09 stays open until a real gateway sends one message to a number the clinic controls and
`outbox:receipt` matches the provider's identifier.

## 10. When it goes wrong

| Symptom | Likely cause | First check |
|---|---|---|
| `queue-health` reports STALE, rows pile up | `outbox:dispatch` not scheduled | crontab on the application host |
| Every row fails with HTTP 401 | token rotated on one side only | `notify:preflight`, then the bridge's log |
| Rows fail with HTTP 502 | provider outage or bad sender id | bridge log, provider dashboard |
| Rows fail with HTTP 422 | recipient is not E.164, or channel not enabled | the row's `last_error` |
| Messages sent twice | bridge not honouring `idempotency_key` | two records with the same key in the bridge's store |

The outbox is the record of what happened. A row that is `sent` with a provider id can be reconciled; a row
that is `sent` without one cannot, and the bridge answered 2xx without naming the message.
