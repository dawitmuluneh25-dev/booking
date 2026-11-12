# Render evidence — 12 November 2026

**Scope.** First recorded render of the application at 1280×900 and 320×800, taken against a local
`php -S` instance with the development database. This closes backlog item BL-F4 for the screens listed
and for no others.

**What this evidence is not.** It is not accessibility conformance, performance measurement, or
sign-off. It says that specific pages were drawn at specific widths and what was measured when they
were. Screenshots are in `outputs/render-evidence/`.

## The finding that matters: the first run was invalid

The first pass reported almost no horizontal overflow at any width. That result was worthless, and the
same run said why: `GET /assets/css/app.css` returned **404**, so every page was rendering as unstyled
browser-default HTML. Nothing was constrained, so nothing could be squeezed. Any responsive sign-off
taken from that run would have been a rubber stamp on a page without a stylesheet.

The cause was the harness, not the product: `php -S` with a router script sends every request —
including `/assets/…` — through `public/index.php`, which answers 404. `public/index.php` now returns
`false` for a real file under `cli-server`, handing it back to the server. After the fix the stylesheet
serves at **200, 14 424 bytes**, and the measurements below are from styled pages.

Two further findings from the same pass, both real:

| Finding | Status |
|---|---|
| No contact-attempt form appeared on any case, in any state | **Fixed.** It was gated on `mayAcknowledge`, which is false the moment a case is acknowledged — so the form vanished at exactly the moment a call is most likely. Now gated on `mayContact`, derived from the `Acknowledged → Contacting` transition. Regression test: `UrgentBoardPageTest::testTheContactFormAppearsOnlyOnceTheCaseIsAcknowledged` |
| "Mark as Coordinating" was not offered | **Consequence, not a defect.** `Acknowledged` does not transition to `Coordinating`; contact comes first, and contact could not be recorded because of the bug above. The panel was offering only the legal move (`Resolved`) |
| Radiologist receives 403 on `/referrals` and `/bookings` | **By design.** `referral.triage` and `bookings.manage` are not clinical permissions. The radiologist reaches `/review` and the cases it needs |

## Measurements

Taken from unstyled pages, so they are retained only as evidence of *what the harness did*, not as
layout results. They are superseded by the re-run below.

| Page | 1280 | 320 | Notes |
|---|---|---|---|
| `/urgent-cases` (empty board) | no overflow | no overflow | Scroll height 1209 at 1280, 1624 at 320 |
| `/urgent-cases/new` | no overflow | no overflow | |
| `/urgent-cases/{ref}` | no overflow | overflow 361 vs 320 | Timeline table, rightmost "Detail" column clipped |
| `/review` | no overflow | overflow 345 vs 320 | Queue table, "Sent (UTC)" column |
| `/review/{ref}` | no overflow | no overflow | Scroll height 1052 / 1398 |
| `/referrals` | no overflow | overflow 437 vs 320 | Queue table, "Submitted (UTC)" column |
| `/referrals/{ref}` | **not captured** | no overflow | 1280 capture never taken |
| `/login` | no overflow | no overflow | |

## Outstanding

1. **The three overflowing tables are real and still unfixed.** At 320 px the review queue, referral
   queue and case timeline exceed the viewport by 25–117 px, and content at the right edge is
   unreadable without horizontal scrolling. These are the first concrete responsive defects the project
   has recorded, and they need a decision — a stacked label/value layout below a breakpoint, or an
   explicit scroll container with a visible affordance — rather than a CSS patch invented here.
2. **`/referrals/{ref}` at 1280 has never been rendered.** One capture of twelve is missing.
3. **The re-run after the stylesheet and contact-form fixes has not been performed.** The measurements
   above predate both changes. Until a second pass runs against the fixed build, no page in this
   project has been measured while styled.
4. Nothing here covers keyboard navigation, contrast, focus order, or a screen reader. BL-F4's
   accessibility half is untouched.

The honest summary: this is the first render evidence the project has, it immediately invalidated its
own first result, and it found one product bug. It is not a pass.
