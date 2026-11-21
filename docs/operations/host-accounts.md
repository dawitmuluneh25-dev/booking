# Host accounts and where their credentials live

**Purpose:** name the things an operator needs to find, and say where the secrets are — without
putting any secret in a document. This exists so that the plaintext credential file in `outputs/` could
be deleted without losing operational knowledge.

**Host.** `uk-shared01.dapanel.net` — DirectAdmin, account `hakimevx`. The panel answers on two
hostnames, and they are separate cookie scopes: `https://hakimerad.com:2222` and
`https://uk-shared01.dapanel.net:2222`. Signing in to one does not sign you in to the other.

## Databases

| Database | Purpose |
|---|---|
| `hakimevx_booking` | production (not yet cut over) |
| `hakimevx_booking_staging` | staging |
| `hakimevx_booking_drill` | provisioned scratch schema from the build-out; unused by the current drill |
| `hakimevx_booking_staging_drill` | the drill's scratch schema, created 22 November 2026. `db:restore-drill` derives this name as `<live>_drill` and refuses anything else |

## Database users

`hakimevx_booking`, `hakimevx_booking_staging`, `hakimevx_booking_drill`, and
`hakimevx_booking_staging_drill` (the panel creates this one automatically with a database and will not
create the database without it).

## Where the passwords are

Not here, and not in any project document. Each application reads its own `.env`, which sits outside
the document root in the domain directory. To reset any of them: panel → Databases → the user →
change password, then update that domain's `.env`.

## Deploy key

`hakimerad_deploy`, ed25519, comment `hakimerad-deploy-2026-11`, fingerprint
`SHA256:lmxy/V+bre962dT7AB6zMGhEfbTV+uohM98vGr0w4MU`. Authorised in the panel under SSH Keys →
Authorized Keys. The private key is held by the operator who generated it; it is not in the repository
and must not be. It was created without a passphrase for unattended use — regenerate with one if the
deploys stay manual.

**Inbound SSH is not confirmed.** Port 22 on the host actively refuses connections, and the panel
publishes no SSH port. Ticket `000129285` asks the provider.

## Application accounts

| Account | Where | State |
|---|---|---|
| `admin@hakimerad.com` | staging | admin, active |
| `uat-walkthrough@example.test` | staging only | UAT fixture: physician account #3 with a **pending** physician profile. Must not exist in production |

## Precedent worth keeping

The plaintext file `outputs/.staging-credentials.txt` held the staging database and panel credentials
from 16 November. It was deleted on 22 November, after this document was written, because a copy of a
credential is a liability and its contents were recoverable from the panel and from each `.env`.
Deleting the file did not rotate anything: the panel and FTP password still needs rotating, and that is
the owner's act, not engineering's.
