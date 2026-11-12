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

## Second pass — styled, and the tables were innocent

With the stylesheet serving, the same pages were measured again. The result reversed the first run's
suspicion: every page except `/login` still overflowed at 320 px, by **70 px** (radiologist header) or
**146 px** (administrator header) — but the tables were not the cause. `.table-wrap` measures 242.8 px
wide with `overflow-x: auto`, so the wide tables were scrolling inside their own container the whole
time, exactly as intended.

The cause is the header. `nav.site-nav` was `display: flex` with no `flex-wrap`, so its links could
neither wrap nor shrink. Their measured totals were **290.2 px** (radiologist: Review, Urgent, New
booking, Upcoming, Availability) and **345.6 px** (administrator adds Admin) inside a **280 px** box.
The last link's right edge — 390.2 px and 465.6 px — matched `document.documentElement.scrollWidth`
exactly on both pages. That is the whole defect: a nav bar that would not wrap.

## The fix and its verification

`public/assets/css/app.css`: `.site-nav` gained `flex-wrap: wrap` and a two-axis gap. No template
change, no JavaScript, no layout rewrite.

Verified against the served file with cache bypass, `getComputedStyle(.site-nav).flexWrap` reading
`wrap` on every load:

| Page | 320 px | 1280 px | Nav rows at 320 |
|---|---|---|---|
| `/urgent-cases` | 320 / 320, overflow **0** | 1280 / 1280, **0** | 2 |
| `/referrals/RF-2026-0148` | 320 / 320, overflow **0** | 1280 / 1280, **0** | 2 |
| `/review/RF-2026-0143` | 320 / 320, overflow **0** | 1280 / 1280, **0** | 2 |

No nav link now leaves the viewport at either width. `/referrals` (queue) and the case detail were not
re-measured in the fixed build, but they carry one of the two header configurations measured above —
five links (radiologist) or six (administrator) — and the defect was a function of that link count, not
of the page body. That is reasoning from the measurement rather than a measurement of those two pages,
and is noted as such.

## Outstanding

1. **The 320 px header is now 150 px tall** — 19 % of an 800 px viewport — because the nav wraps to two
   rows under the brand and the account line. Functionally correct and visually cramped. Whether that
   becomes a compact menu, a shorter brand block, or an accepted cost is a design decision, not a defect
   to patch silently.
2. **`/referrals` and `/urgent-cases/{ref}` have no fixed-build measurement** (see above).
3. **The earlier unstyled table measurements should be disregarded entirely** as layout evidence. They
   stand only as a record of a harness that was serving no stylesheet.
4. Nothing here covers keyboard navigation, contrast, focus order, or a screen reader. BL-F4's
   accessibility half is untouched.

The honest summary: the first render pass invalidated itself, the second found one product bug
(missing contact form) and one real layout defect (a nav that would not wrap), and the defect is fixed
and re-measured. Two pages remain unmeasured in the fixed build, and accessibility is untouched.
