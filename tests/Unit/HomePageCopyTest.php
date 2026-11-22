<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bootstrap\Application;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The landing page carries D-05's approved copy, and none of the claims it forbids.
 *
 * HR-ENG-DEC-R1-003 D-05 approved a headline, a supporting statement, three service descriptions, a
 * five-step process and two calls to action, then listed what the page must never say. Both halves
 * are asserted here, and the second half is the one that decays: a sentence promising a guaranteed
 * appointment reads like good marketing to whoever writes it next, and nothing about it looks wrong
 * in a diff.
 *
 * The page is rendered through the real composition root and needs no database, the same way
 * `PublicFunnelTest` does it: this page has to serve before MySQL is provisioned, and a copy test
 * that skipped in that state would be a copy test that never ran on a fresh host.
 */
final class HomePageCopyTest extends TestCase
{
    private const HEADLINE = 'Specialist Image-Guided Interventional Radiology Care';

    private const SUPPORTING = 'HakimeRAD Radiologic Consultancy coordinates referrals, specialist review, and '
        . 'procedure planning for minimally invasive image-guided interventions. Requests are reviewed by the '
        . 'appropriate clinical team before any procedure is approved or scheduled.';

    private const STEPS = [
        'Submit a referral or eligible procedure request.',
        'The team reviews the submitted clinical information.',
        'The appropriate clinician determines suitability and the next step.',
        'Approved procedures proceed to resource coordination and scheduling.',
        'The team coordinates attendance and follow-up as applicable.',
    ];

    /**
     * Phrases that would break one of D-05's five prohibitions.
     *
     * Chosen to be unmistakable claims rather than words that happen to appear in a denial: the
     * page legitimately contains "not a confirmed appointment" and "does not confirm clinical
     * acceptance", so "confirmed appointment" and "clinical acceptance" would fail a correct page.
     */
    private const FORBIDDEN = [
        'guaranteed appointment',
        'guaranteed procedure',
        'guaranteed availability',
        '24/7',
        'nationwide',
        'immediate appointment',
        'same-day appointment',
        'walk-in',
        'accepted automatically',
        'no waiting list',
    ];

    /** @var string|null */
    private static ?string $body = null;

    private static function body(): string
    {
        if (self::$body === null) {
            $response = Application::boot(dirname(__DIR__, 2))->handle(Request::create('GET', '/'));

            self::assertSame(200, $response->status, 'The landing page did not render.');

            self::$body = $response->body();
        }

        return self::$body;
    }

    /**
     * The rendered page with tags removed and every run of whitespace collapsed to one space.
     *
     * The template wraps its lines, so comparing raw HTML against an approved sentence would fail on
     * indentation rather than on wording. Collapsing the whitespace compares the words.
     */
    private static function text(): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', self::body()) ?? '';
        $collapsed = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($collapsed);
    }

    public function testTheHeadlineIsTheApprovedHeadline(): void
    {
        self::assertStringContainsString('<h1>' . self::HEADLINE . '</h1>', self::body());
    }

    public function testTheSupportingStatementIsTheApprovedStatement(): void
    {
        self::assertStringContainsString(self::SUPPORTING, self::text());
    }

    public function testTheThreeServiceDescriptionsAreTheApprovedDescriptions(): void
    {
        $text = self::text();

        $services = [
            'Image-Guided Biopsy and FNAC' => 'Specialist referral and assessment for image-guided fine '
                . 'needle aspiration cytology and core needle biopsy.',
            'Image-Guided Abscess Drainage' => 'Referral and clinical coordination for image-guided '
                . 'aspiration and percutaneous drainage of selected fluid collections.',
            'Hydrostatic Intussusception Reduction' => 'An urgent hospital-based coordination pathway '
                . 'requiring direct communication with the receiving clinical team.',
        ];

        foreach ($services as $heading => $description) {
            self::assertStringContainsString($heading, $text, 'A service heading is missing: ' . $heading);
            self::assertStringContainsString(
                $description,
                $text,
                'The description of "' . $heading . '" is not the approved sentence.',
            );
        }
    }

    /**
     * The five steps, in the order the decision lists them.
     *
     * Order is asserted by position rather than by containment, because a numbered process with two
     * steps swapped is a different process that still contains every sentence.
     */
    public function testTheProcessHasTheFiveApprovedStepsInOrder(): void
    {
        $text = self::text();
        $previous = -1;

        foreach (self::STEPS as $index => $step) {
            $position = strpos($text, $step);

            self::assertNotFalse($position, 'Step ' . ($index + 1) . ' is missing: ' . $step);
            self::assertGreaterThan($previous, $position, 'Step ' . ($index + 1) . ' is out of order.');
            $previous = $position;
        }
    }

    public function testBothCallsToActionCarryTheApprovedLabels(): void
    {
        $text = self::text();

        self::assertStringContainsString('Request a Procedure Assessment', $text);
        self::assertStringContainsString('For Healthcare Professionals — Referral Guidance', $text);
    }

    /**
     * Every call to action points somewhere that answers.
     *
     * The 18 November defect was a button that looked right and returned 403. So the assertion is
     * not "the label is present" but "the link is present, and it is not a route this release
     * cannot serve": `/bookings/new` needs a permission no visitor has, and `/referral-guidance`
     * answers 404 until Legal approves the page.
     */
    public function testTheCallsToActionPointAtRoutesThatWork(): void
    {
        $body = self::body();

        self::assertStringContainsString('href="/request-account"', $body);
        self::assertStringContainsString('href="/login"', $body);

        self::assertStringNotContainsString('href="/bookings/new"', $body);
        self::assertStringNotContainsString(
            'href="/referral-guidance"',
            $body,
            'The referral guidance page is unpublished and answers 404, so linking it is the 18 November defect again.',
        );
    }

    /**
     * D-05's five prohibitions, asserted as absent words.
     *
     * Each maps to one bullet of the decision: no promised appointments or guaranteed availability,
     * no 24/7 claim, no nationwide claim, no implication that a submitted request is accepted, and
     * no intussusception availability claim. The last one is why the intussusception card describes a
     * coordination pathway and says nothing about the service being available.
     */
    public function testThePageMakesNoneOfTheClaimsTheDecisionForbids(): void
    {
        $text = strtolower(self::text());

        foreach (self::FORBIDDEN as $phrase) {
            self::assertStringNotContainsString($phrase, $text, 'The page claims: ' . $phrase);
        }

        // Prohibition 4: a request must not read as an acceptance. The page states the opposite, and
        // the footer sentence is the one place that says so.
        self::assertStringContainsString('a submitted request is not a confirmed appointment', $text);
    }
}
