<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The privacy notice carries the wording Legal approved, word for word.
 *
 * Three decisions of 20 November 2026 approved the paragraphs for storage and security, retention, and
 * cookies and third-party services. The transcription step is where a word gets lost — this project has
 * already had a reviewer's name dropped by a mapping (see `ContentSourceTest`) and a retention period
 * contradict a decision for three weeks. Both were silent. So the approved sentences are pinned here, and a
 * later edit that rewords one of them fails the build instead of publishing.
 *
 * The test compares whole paragraphs, not fragments: "contains" assertions pass when a sentence is
 * appended, duplicated or half-deleted around the matched substring, which is the failure mode that matters
 * on a page whose purpose is to state exactly what the controller does.
 */
final class PolicyWordingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function approvedSections(): array
    {
        return [
            'storage and security — DEC-2026-11-20-02' => [
                'Storage and security',
                [
                    'HakimeRAD uses technical and organizational measures intended to protect personal and health '
                        . 'information against unauthorized access, disclosure, alteration, loss and destruction.',
                    'Access to non-public information is restricted to authorized personnel according to their '
                        . 'assigned responsibilities. Documents and clinical information are intended to be handled '
                        . 'through protected application storage and access-controlled application workflows.',
                    'HakimeRAD maintains records of relevant system activity and applies security measures to '
                        . 'information transmitted between users and the service. Backup and recovery arrangements '
                        . 'are maintained to support service continuity.',
                    'No security measure eliminates every risk. HakimeRAD reviews its safeguards and works to '
                        . 'address identified security weaknesses.',
                ],
            ],
            // Amended 27 November 2026. DEC-2026-11-20-03's second sentence said "7 years"; D-02's schedule
            // keeps clinical and procedure records for five. The pin moved with the amendment, which is what
            // this test is for — an edit to the notice cannot happen without someone editing this array and
            // saying, in a diff, that the approved wording changed.
            'retention — DEC-2026-11-20-03 as amended 27 November 2026' => [
                'How long information is kept',
                [
                    'HakimeRAD retains personal and health information only for as long as is justified by the '
                        . 'purposes for which it is processed and by applicable legal, professional and '
                        . 'institutional requirements.',
                    'The applicable retention and disposal arrangements are governed by the approved '
                        . 'records-retention policy, which sets a period for each category of record: clinical '
                        . 'and procedure records, and clinical documents uploaded to a referral, are kept for '
                        . '5 years; referrals, appointments and audit records for 7 years; notification records '
                        . 'for 12 months; and encrypted backups for no more than 35 days. Where the Ethiopian '
                        . 'medical record keeping directive issued by the Ministry of Health, or another '
                        . 'applicable requirement, requires a longer period, that longer period applies.',
                ],
            ],
            'cookies and third parties — DEC-2026-11-20-04' => [
                'Cookies and third-party services',
                [
                    'HakimeRAD may use essential browser technologies required to operate and secure the service. '
                        . 'These technologies may support session handling, security and application '
                        . 'functionality.',
                    'The service may also rely on third-party infrastructure and communication providers where '
                        . 'these services are configured and used to operate the application or communicate with '
                        . 'users.',
                    'The identity and purpose of relevant third-party services, and any non-essential tracking '
                        . 'technologies, will be disclosed in accordance with the approved technology inventory '
                        . 'and privacy arrangements.',
                ],
            ],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('approvedSections')]
    public function testTheApprovedParagraphsArePresentVerbatim(string $heading, array $expected): void
    {
        $sections = $this->paragraphs('policy.privacy');

        self::assertArrayHasKey(
            $heading,
            $sections,
            sprintf(
                'The privacy notice has no "%s" section. An approved decision with no paragraph is not published '
                . 'copy, and a missing section is how that goes unnoticed.',
                $heading,
            ),
        );

        self::assertSame(
            array_map($this->normalise(...), $expected),
            array_map($this->normalise(...), $sections[$heading]),
            sprintf(
                'The "%s" paragraphs no longer match the approved wording. If Legal approved a change, record it '
                . 'as a new decision and update both this test and the content in the same commit; otherwise the '
                . 'page has been reworded without authority.',
                $heading,
            ),
        );
    }

    /**
     * The dates are D-01's, the approval is still nobody's, and those are two different things.
     *
     * HR-ENG-DEC-R1-003 D-01 fixed one date pair for all three pages — effective 12 January 2027,
     * review 12 January 2028 — and said in as many words not to publish a policy as approved
     * merely because the dates have been entered.
     *
     * **The approval arrived on 25 November 2026** (Gemechisa Dalu, Legal/Data Protection), and the
     * approving owner moved the effective date to the launch date. So this now asserts three things:
     * the approval is the one that was given, no clinical reviewer appears on a policy page, and the
     * review date is still D-01's — the only date nobody has re-decided.
     *
     * **The privacy page moved to 27 November 2026**, when its retention paragraph was amended to match the
     * schedule. `policy.booking` and `policy.referral` still carry the 25th: nothing in them changed, and
     * copying the newer date onto them would claim an approval that was never re-given.
     *
     * If it fails, either a provenance value was invented or somebody moved a document-control date
     * without a decision. Both are the failures this file exists to catch, in that order of likelihood.
     */
    public function testThePolicyPagesCarryTheApprovalTheyWereGiven(): void
    {
        // The date each page was actually approved over. The privacy notice was re-approved on 27 November
        // 2026 for its amended retention wording; the other two have not changed since the 25th.
        $approvedOn = [
            'policy.privacy' => '2026-11-27',
            'policy.booking' => '2026-11-25',
            'policy.referral' => '2026-11-25',
        ];

        foreach ($approvedOn as $key => $expected) {
            $page = $this->page($key);

            self::assertSame(
                '2026-12-18',
                $page['effective_on'] ?? null,
                $key . ' does not carry the approved effective date.',
            );
            self::assertSame('2028-01-12', $page['review_on'] ?? null, $key . ' does not carry D-01\'s review date.');
            self::assertSame('Gemechisa Dalu', $page['approver'] ?? null, $key . ' names the wrong approver.');
            self::assertSame($expected, $page['approved_on'] ?? null, $key . ' carries the wrong approval date.');

            self::assertArrayNotHasKey(
                'clinical_reviewer',
                $page,
                sprintf(
                    '`clinical_reviewer` is set on %s. D-01 makes a policy notice Legal/Data Protection\'s to '
                    . 'approve, not a clinician\'s; a value here would put clinical governance on a document '
                    . 'the clinical pack does not govern.',
                    $key,
                ),
            );
        }
    }

    public function testNoSectionIsEmptyAndNoPlaceholderSurvives(): void
    {
        foreach (['policy.privacy', 'policy.booking', 'policy.referral'] as $key) {
            foreach ($this->textBySection($key) as $heading => $text) {
                self::assertNotSame('', $text, sprintf('%s / %s is empty.', $key, $heading));
                self::assertStringNotContainsString(
                    '[',
                    $text,
                    sprintf(
                        '%s / %s carries a bracketed placeholder. The policy pack says to omit a section rather '
                        . 'than publish an unfilled marker, because a visitor reads "[insert number]" as a page '
                        . 'that is still being written.',
                        $key,
                        $heading,
                    ),
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $key): array
    {
        $path = dirname(__DIR__, 2) . '/database/content/policy-pages.php';
        self::assertFileExists($path);

        $pages = require $path;

        if (!is_array($pages)) {
            self::fail('The content source did not return an array.');
        }

        foreach ($pages as $page) {
            if (is_array($page) && ($page['key'] ?? null) === $key) {
                return $page;
            }
        }

        self::fail('No page with key ' . $key . ' in the content source.');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function sections(string $key): array
    {
        $sections = $this->page($key)['sections'] ?? [];

        if (!is_array($sections)) {
            self::fail($key . ' has no section list.');
        }

        return $sections;
    }

    /**
     * Heading → its paragraphs, in document order.
     *
     * @return array<string, list<string>>
     */
    private function paragraphs(string $key): array
    {
        $out = [];

        foreach ($this->sections($key) as $section) {
            if (!is_array($section)) {
                continue;
            }

            $heading = $section['heading'] ?? null;
            $paragraphs = $section['paragraphs'] ?? [];

            if (is_string($heading) && is_array($paragraphs)) {
                $texts = [];

                foreach ($paragraphs as $paragraph) {
                    if (is_string($paragraph)) {
                        $texts[] = $paragraph;
                    }
                }

                $out[$heading] = $texts;
            }
        }

        return $out;
    }

    /**
     * Heading → every word the visitor sees in it, paragraphs and bullets together.
     *
     * @return array<string, string>
     */
    private function textBySection(string $key): array
    {
        $out = [];

        foreach ($this->sections($key) as $section) {
            if (!is_array($section)) {
                continue;
            }

            $heading = $section['heading'] ?? null;

            if (!is_string($heading)) {
                continue;
            }

            $texts = [];

            foreach (['paragraphs', 'bullets'] as $field) {
                $values = $section[$field] ?? [];

                if (!is_array($values)) {
                    continue;
                }

                foreach ($values as $value) {
                    if (is_string($value)) {
                        $texts[] = $value;
                    }
                }
            }

            $out[$heading] = implode(' ', $texts);
        }

        return $out;
    }

    /** The file wraps sentences with a concatenation at the join, so collapse whitespace before comparing. */
    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
