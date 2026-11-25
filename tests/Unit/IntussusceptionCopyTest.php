<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ContentSource;
use PHPUnit\Framework\TestCase;

/**
 * The restricted intussusception page, as `database/content/service-pages.php` now holds it.
 *
 * HR-ENG-DEC-R1-003 D-03 decided that hydrostatic reduction stays an urgent, hospital-based
 * coordination pathway, that it is not a publicly confirmed service, and that the reported
 * paediatric/receiving-hospital approval of 18 November 2026 is not authenticated. The page was
 * nonetheless live on staging, describing in the present tense how coordination runs — a public
 * availability claim written before the decision was made.
 *
 * Five things are pinned here, and they are pinned rather than remembered because three of them
 * are the kind of sentence that gets "tidied" by somebody editing copy:
 *
 *   1. The words are the record's approved paragraph, character for character. Paraphrase is not
 *      authorised.
 *   2. Exactly one further paragraph is on the page — C-5 §G8's emergency disclaimer, added on
 *      25 November 2026 under the D-14 amendment. The budget is still a budget: a third paragraph
 *      is clinical copy nobody approved, and the test refuses it.
 *   3. The page can now be published, because both signatures D-03 required were recorded on
 *      25 November 2026 (see docs/clinical/intussusception-signoff.md). It could not be published
 *      before, and the mechanism that prevented it was an absent effective date.
 *   4. No availability claim survives. "Can be booked", "available", "same day" are the words the
 *      decision forbids, and the emergency-safety sentences are the ones it requires.
 *   5. The disclaimer that the platform is not an emergency service is present, because it is the
 *      one claim the page can safely make before any coordination has happened.
 */
final class IntussusceptionCopyTest extends TestCase
{
    /**
     * D-03's approved public-facing wording, verbatim (HR-ENG-DEC-R1-003, line 95).
     *
     * If this and the source file ever disagree, one of them has been edited and the other has
     * not. The test does not say which is right; it says they are not the same document any more.
     */
    private const APPROVED = 'Hydrostatic reduction of intussusception is an urgent, hospital-based '
        . 'procedure requiring assessment and coordination with the receiving clinical team, including '
        . 'appropriate paediatric and surgical involvement. Submitting an online request does not confirm '
        . 'clinical acceptance, suitability, or procedure availability. If a child may have '
        . 'intussusception or has concerning symptoms, seek immediate assessment at a hospital or '
        . 'emergency department. Do not wait for an online response.';

    /**
     * Phrases that assert a service is available, bookable or running.
     *
     * "availability" is not on this list on purpose: the approved paragraph uses the word to deny
     * it ("does not confirm … procedure availability"), and a check that cannot tell a denial from
     * a claim would fail the corrected page.
     */
    private const UNAVAILABILITY_CLAIMS = [
        'book',
        'appointment',
        'schedule',
        'walk-in',
        'same-day',
        '24/7',
        'guarantee',
        'we offer',
        'we provide',
        'available at',
        'open daily',
    ];

    /** @return array<string, mixed> */
    private static function page(): array
    {
        $pages = require dirname(__DIR__, 2) . '/database/content/service-pages.php';

        if (!is_array($pages)) {
            self::fail('service-pages.php did not return a list of pages.');
        }

        foreach ($pages as $page) {
            if (is_array($page) && ($page['key'] ?? null) === 'service.intussusception') {
                /** @var array<string, mixed> $page */
                return $page;
            }
        }

        self::fail('service-pages.php no longer contains service.intussusception.');
    }

    /** @return list<string> Every paragraph on the page, in order. */
    private static function paragraphs(): array
    {
        $sections = self::page()['sections'] ?? null;

        if (!is_array($sections)) {
            self::fail('The intussusception page has no sections.');
        }

        $paragraphs = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $entries = $section['paragraphs'] ?? [];

            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $paragraphs[] = $entry;
                }
            }
        }

        return $paragraphs;
    }

    /**
     * C-5 §G8's final sentence, added under the D-14 amendment of 25 November 2026.
     *
     * It is a separate constant rather than appended to APPROVED because it comes from a different
     * part of the package: confusing the two would let somebody delete it while still passing a
     * "contains the approved paragraph" test.
     */
    private const DISCLAIMER = 'HakimeRAD\'s online platform is not an emergency service.';

    public function testThePageCarriesTheApprovedParagraphAndTheDisclaimerWordForWord(): void
    {
        self::assertSame([self::APPROVED, self::DISCLAIMER], self::paragraphs());
    }

    /**
     * The page has one section and one paragraph. This is a budget, not an aesthetic: D-03
     * authorises one paragraph, so a second one is a claim about a restricted pathway that no
     * authority has signed.
     */
    public function testThePageCarriesNoProseThatTheRecordDidNotApprove(): void
    {
        $sections = self::page()['sections'] ?? [];

        self::assertIsArray($sections);
        self::assertCount(1, $sections, 'D-03 authorises one section for this page.');
    }

    /**
     * The page is unpublishable, and the reason is the one D-03 gives.
     *
     * `toDraft()` is the same conversion `content:sync` performs, so this asserts the state an
     * import would produce on a fresh database — not just the state of this file.
     */
    /**
     * D-03's condition is now satisfied, and this is what "satisfied" looks like in the record.
     *
     * It used to assert the opposite — that no effective date existed, and the page therefore could
     * not be published. Flipping it is the point: the guard was never "this page must stay dark
     * forever", it was "this page may not go dark-to-light without two named signatures". Those
     * signatures are recorded (docs/clinical/intussusception-signoff.md), and an absent effective
     * date would now be a stale blocker rather than a safeguard.
     */
    public function testThePageIsPublishableBecauseBothSignaturesAreRecorded(): void
    {
        $page = self::page();

        self::assertSame('2026-11-25', $page['effective_on'] ?? null);
        self::assertSame('Dawit Muluneh', $page['approver'] ?? null);
        self::assertSame('Dawit Muluneh', $page['clinical_reviewer'] ?? null);

        $draft = (new ContentSource())->toDraft($page, '2026-11-25 00:00:00');

        self::assertTrue($draft->isPublishable(), 'The two D-03 signatures were recorded on 25 November 2026.');
        self::assertSame([], $draft->missingForPublication());

        self::assertFileExists(
            dirname(__DIR__, 2) . '/docs/clinical/intussusception-signoff.md',
            'The sign-off record is the evidence for the effective date above.',
        );
    }

    /**
     * The emergency-safety instruction is the whole reason a page exists for a pathway that cannot
     * be booked. It is asserted separately so that removing it to "keep the page short" fails here
     * with a sentence about safety rather than with a diff.
     */
    public function testThePageKeepsTheInstructionToSeekEmergencyAssessment(): void
    {
        self::assertStringContainsString(
            'seek immediate assessment at a hospital or emergency department',
            implode(' ', self::paragraphs()),
        );
    }

    public function testThePageMakesNoAvailabilityClaim(): void
    {
        $text = strtolower(implode(' ', self::paragraphs()));

        foreach (self::UNAVAILABILITY_CLAIMS as $claim) {
            self::assertStringNotContainsString($claim, $text, 'The page claims availability: ' . $claim);
        }
    }
}
