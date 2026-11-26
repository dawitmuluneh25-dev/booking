# The dispatch schedule, and the check that notices when it is missing

**Status:** implemented. One cron entry is still owed on the host.
**Audience:** whoever installs the host, and whoever is on call when messages stop.

## 1. The failure this closes

`outbox:dispatch` is the only thing that moves a queued notification toward a patient. Nothing in the
repository installs it, and nothing used to notice its absence.

`notify:queue-health` gets close: it reads the outbox and reports STALE when the oldest pending row is over
24 hours old, or when rows are queued and nothing has ever been dispatched. That inference has one blind
spot, and it is the one that matters on the day of a deployment:

> **An empty queue with no scheduler reports healthy.**

A fresh install with no cron entry has nothing queued, so nothing is old, so the queue looks fine. It keeps
looking fine until the first booking arrives — and then the message sits there while the report says the
queue is young.

The queue is the wrong witness. It measures work waiting, not whether anybody is looking.

## 2. What was built

`outbox:dispatch` now records each completed run in `dispatch_runs` (migration 0050): when it started and
finished, how many messages it claimed, sent, scheduled for retry and dead-lettered, and on which host.

The row is written **after** the dispatch, not before. A row means the process reached its own end, which is
what the check asks. A run that crashes halfway leaves the previous row standing, and its age is the truth
about when the schedule last completed.

`notify:dispatch-check` reads that row:

```
php bin/console notify:dispatch-check [--interval=5]
```

| Output | Meaning | Exit |
|---|---|---|
| `OK` | The last run finished inside two intervals | 0 |
| `LATE` | The last run is older than two intervals; the scheduler stopped or is failing | 1 |
| `NEVER` | No run has ever been recorded — the schedule was never installed, or never completed here | 1 |
| `UNKNOWN` | The ledger could not be read, or the recorded time is in the future (two clocks disagreeing) | 1 |

Two intervals, not one: a single missed tick is a slow query or a scheduler that started late. Two in a row
on a five-minute schedule is ten minutes of a clinic sending nothing.

`UNKNOWN` is deliberately not `OK`. A run timestamped in the future means the application host and the
dispatcher host disagree about the time, and reporting that as healthy would hide whichever one is wrong.

## 3. Installing the schedule

```
# crontab -e, on the application host
*/5 * * * * cd /path/to/booking-app && php bin/console outbox:dispatch --limit=50 >> var/logs/dispatch.log 2>&1
```

- **Every five minutes.** The retry ladder is 1, 5, 15 and 60 minutes, so a five-minute tick keeps a retry
  prompt without hammering a gateway that is already failing.
- **Pass `--interval=5`** to `notify:dispatch-check` on the same cadence so the check and the schedule cannot
  drift apart.
- **`--limit=50`** drains a backlog in one pass; the dispatcher claims at most that many rows per run.
- **Redirect the output.** The command prints `sent / retrying / dead` and exits non-zero when anything
  reached the dead state — that exit code is what a monitoring system should alert on.
- **Run it as the application user**, not root, so anything it writes is owned by the same account as the
  logs and cache.

The dispatcher must never be called from a web request: it holds the outbox row locks for the duration of
the provider call.

## 4. Monitoring

| Check | Cadence | Alert on |
|---|---|---|
| `notify:dispatch-check --interval=5` | every 5 min | any non-zero exit |
| `notify:queue-health` | every 15 min | any non-zero exit |
| `notify:preflight` | on deploy, and daily | `NOT READY` |

The three answer different questions and none of them substitutes for the others:

- **preflight** — can the configured driver deliver at all?
- **dispatch-check** — is anything running the dispatcher?
- **queue-health** — is anything actually moving, and are messages piling up dead?

A host can pass preflight and fail dispatch-check (configured bridge, no cron). It can pass dispatch-check and
fail queue-health (dispatcher runs, provider is refusing). It can pass all three and still be undelivered if
the bridge answers 2xx without sending — which is why `outbox:receipt` exists and why the bridge contract
requires the provider's own identifier.

## 5. What this does not do

- **It does not install the schedule.** It reports that the schedule is missing; installing it is a host
  action.
- **It does not verify the bridge.** That is the contract document's §9.
- **It does not prune its own ledger.** One row per five minutes is ~105,000 rows a year — small, but it
  should be pruned on the same retention review as the outbox. No purge path exists yet, deliberately: the
  ledger is evidence, and evidence should not be deleted before somebody decides how long to keep it.
