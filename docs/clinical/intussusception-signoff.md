# Intussusception pathway — sign-off record

**Decision:** HR-ENG-DEC-R1-003 **D-03** (22 November 2026), amended 25 November 2026 by **D-14**.
**Page:** `service.intussusception` — *Hydrostatic Intussusception Reduction — Urgent Hospital Coordination*.
**Status:** signed, publishable, version `1.1` (supersedes `1.0`).

---

## 1. Why this file exists

D-03 made publication of this page conditional on a documented sign-off by **two** authorities, not
one:

1. the **Clinical Approver**, and
2. the **receiving paediatric/surgical authority**.

The database has a `clinical_reviewer` column and an `approver` column, and both hold the same person.
There is no column for a second institution's signature, and inventing one would have been schema
design by guesswork. So the pair is recorded here, and the page's effective date —
`2026-11-25` — is the date below.

Before this file existed, the page carried **no effective date**, and that absence was the mechanism
that made publication impossible: `PublishedContent::missingForPublication()` named it, and
`ck_published_content_complete` refused the row independently. The guard was never "keep this page
dark"; it was "do not light it without two names".

## 2. The signatures

| Authority | Name | Date | Scope of what was signed |
|---|---|---|---|
| Clinical Approver | **Dawit Muluneh** | 2026-11-25 | The page's clinical wording: the urgent-referral paragraph, the emergency instruction, and the disclaimer |
| Receiving clinical authority | **Dawit Ayele** | 2026-11-25 | Acceptance of the coordination pathway described, and of the receiving team being named in follow-up copy |

Both signatures were given on the same day, in the same approval exchange that produced decisions
D-01, D-02, D-04, D-05 and D-14. They are recorded by name and date, which is the whole of what this
file claims: it does not claim that either signatory read a diff, because nobody wrote down that they
did.

## 3. The D-14 amendment — the paragraph budget

D-03 authorised **one** paragraph for this page, and `IntussusceptionCopyTest` enforced exactly one.
That budget had an unintended consequence: C-5's intussusception urgent message (§G8) contains the
sentence

> HakimeRAD's online platform is not an emergency service.

which is *not* part of the record's paragraph, so the page could not carry it. The safest sentence in
the package about the highest-risk pathway was the one the paragraph budget forbade.

On 25 November 2026 the approving owner amended D-03 to permit **one additional approved paragraph**,
provided it is package wording transcribed verbatim and nothing else. The page now carries:

1. D-03's approved paragraph (unchanged, character for character);
2. C-5 §G8's disclaimer (verbatim).

The budget is still three paragraphs' worth of enforcement: the test pins **both** paragraphs by exact
string and fails if a third appears. Nothing was written to make the page read better, then or now.

## 4. What the page still may not say

D-03's prohibitions survive the amendment, and the test enforces them:

- No claim that the service can be booked, is available, or happens same-day.
- No description of coordination in the present tense, as a service in operation.
- No time, response, or outcome commitment.

If a future amendment needs to say any of those things, it needs a new decision — not an edit here.

## 5. Reverting

Publication is reversible without data loss: `content:withdraw service.intussusception --actor=<email>`
takes the page down and records who decided. The draft, its versions and this record stay.
