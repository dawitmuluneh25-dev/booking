# What UAT can and cannot prove on staging

**Decided:** 22 November 2026, Product Owner (Dawit Muluneh), on engineering's recommendation.
**Applies to:** UAT, 22–25 December 2026.

## Notification delivery is out of scope — log delivery is what is tested

Staging carries `NOTIFY_DRIVER=log` and an **empty** `NOTIFY_BREVO_API_KEY` (verified on the host,
21 November 2026). That is the sanctioned staging posture, and it means a notification is queued,
dispatched and written to `var/logs/notifications.log` — **no email reaches an inbox**.

**In scope for UAT:**

- the outbox fills when a booking, referral or account request is created;
- `outbox:dispatch` drains it and exits 0;
- the rendered record in the log carries the right recipient, reference and template;
- a failed send is retried on the documented schedule and, after the retry budget, marked `dead`;
- `/notifications` shows the in-app feed the same messages produce.

**Out of scope for UAT, and should not be recorded as a finding:**

- that a patient or clinician receives the email;
- that the sender passes DMARC at `hakimerad.com`;
- that the link inside an email opens from a real mail client.

**Why this boundary and not a Brevo test key.** `NOTIFY_PRODUCTION_APPROVED` is `false` and O-3 §8 is
unapproved. Putting a live transactional-email key on a clinical system and mailing test recipients is a
data-processing act that no one has signed, and the inbox itself is the provider's delivery, not this
application's logic. If a later UAT round needs inbox proof, that is a new decision with named
recipients — not a configuration change made the week of the test.

## Consequence for the UAT script

The reception walkthrough should be written as "the message appears in the outbox and in
`var/logs/notifications.log`", not "the receptionist receives an email". A finding phrased the second way
will be recorded against the software when it is a scope decision.

## Related

- Fixture data and what not to touch: `uat-fixture-data.md`
- Host accounts, databases and where their passwords live: `host-accounts.md`
