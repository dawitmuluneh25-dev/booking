# UAT fixture data in staging

**Decision:** DEC-2026-11-20-05 (D2) — the rows created during the 18 November walkthrough stay in the
staging database and are documented here rather than deleted.

This file exists so that nobody has to guess, on 22 December, whether the account they are looking at is a
clinician or a test artefact. It is documentation of a decision, not a claim that the rows were re-counted
today.

## What is there, and why

| Record | Identifier | Origin |
|---|---|---|
| Account request | `AR-EAUQR66` | Submitted through `/request-account` on 18 November to prove the public form works end to end |
| User account | **#3**, `uat-walkthrough@example.test` | Created by approving that request |
| Physician profile | one row, status **pending** | Created by the same approval — deliberately left pending, because approval grants an account, not clinical authority |

None of these is a real person. The email domain `example.test` is reserved for exactly this purpose, and no
message was delivered to it.

## The point they prove

The two-act authority split: approving an account request creates a login **and** a physician profile that
is still `pending`. A pending profile cannot approve a referral, verify a physician, or publish clinical
content. The walkthrough account therefore has an account and no clinical authority, which is the behaviour
UAT should be able to see for itself.

## How to confirm they are still there

Run on the host, from the application root:

```
php bin/console accounts:requests
php bin/console user:list
```

The first lists `AR-EAUQR66` with its state; the second lists account #3 and whether it is active. If a
profile-status check is wanted, the physician list in the staff UI shows the pending row.

If any of the three is missing, this file is wrong and should be corrected by whoever finds it — not
worked around.

## What UAT should do with them

- **Use them.** Approving, rejecting and verifying are easier to test against a record that already exists
  than against one created in front of the participants.
- **Do not delete `AR-EAUQR66`.** It is the only recorded evidence that the public form was exercised on
  the host before launch.
- **Do not reactivate or verify the profile** to "tidy it up". A pending profile is the correct state and
  the demonstration of the split; verifying it would remove the example.
- **Do leave the account active.** Suspending it is available (`user:deactivate`) but on 22 December that
  reads as a broken environment rather than a documented fixture.

## Before cutover

These rows are staging-only and must not exist in the production database. The cutover checklist should
confirm that production is created from migrations and approved content, with no fixture accounts, no
walkthrough requests and no `example.test` identities.

## Provenance

Recorded from the 18 November 2026 walkthrough. **Verified against the host on 21 November 2026**, from
the DirectAdmin Terminal: `accounts:requests` reported no pending request (correct — approval is what
removes one from the queue), `user:list` showed account #3 `uat-walkthrough@example.test` active and
never signed in, and `physician_profiles` held one row for `user_id` 3 with `practice_number`
`pending-ar-eauqr66`, status **pending**, `verified_by_user_id` null.

The one thing that pass did **not** observe is the `account_requests` row's own status: the command
lists pending requests only, so an approved row is invisible to it. Nothing suggests loss; it is simply
not a fact this environment has produced.
