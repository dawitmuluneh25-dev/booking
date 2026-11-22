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
 * contradict a decision for three weeks. Both were silent. So the approved sentences are pinned here,
 * and a later edit that rewords one of them fails the build instead of publishing.
 *
 * The test compares whole paragraphs, not fragments: "contains" assertions pass when a sentence is
 * appended, duplicated or half-deleted around the matched substring, which is the failure mode that
 * matters on a page whose purpose is to state exactly what the controller does.
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
            'retention — DEC-2026-11-20-03' => [
                'How long information is kept',
                [
                    'HakimeRAD retains personal and health information only for as long as is justified by the '
                        . 'purposes for which it is processed and by applicable legal, professional and '
                        . 'institutional requirements.',
                    'The applicable retention and disposal arrangements are governed by the approved '
                        . 'records-retention policy. In this case 7 years by Ethiopian medical record keeping '
                        . 'directive by MOH.',
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
        $sections = $this->sections('policy.privacy');

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
                "The \"%s\" paragraphs no longer match the approved wording. If Legal approved a change, record it "
                . 'as a new decision and update both this test and the content in the same commit; otherwise the '
                . 'page has been reworded without authority.',
                $heading,
            ),
        );
    }

    /**
     * The three sections arrived as wording, not as permission to publish.
     *
     * The publish path refuses a row without a clinical reviewer, an approver, an approval date, an
     * effective date and a review date. None of the three decisions supplied those, so the page must
     * still carry none of them — if this ever fails, somebody has published health-policy text whose
     * dates were invented.
     */
    public function testThePrivacyPageStillCarriesNoProvenance(): void
    {
        $page = $this->page('policy.privacy');

        foreach (['approver', 'approved_on', 'effective_on', 'review_on', 'clinical_reviewer'] as $field) {
            self::assertArrayNotHasKey(
                $field,
                $page,
                sprintf(
                    '`%s` is set on policy.privacy. The approved decisions gave wording and an approver for the '
                    . 'decision, not an effective date or a review date for the page, so a value here would have '
                    . 'been invented by whoever typed it.',
                    $field,
                ),
            );
        }
    }

    public function testNoSectionIsEmptyAndNoPlaceholderSurvives(): void
    {
        foreach (['policy.privacy', 'policy.booking', 'policy.referral'] as $key) {
            $page = $this->page($key);

            foreach ($page['sections'] as $index => $section) {
                $text = implode(' ', array_merge(
                    $section['paragraphs'] ?? [],
                    $section['bullets'] ?? [],
                ));

                self::assertNotSame('', trim($text), sprintf('%s section %d is empty.', $key, $index));
                self::assertStringNotContainsString(
                    '[',
                    $text,
                    sprintf(
                        '%s section %d carries a bracketed placeholder. The policy pack says to omit a section '
                        . 'rather than publish an unfilled marker, because a visitor reads "[insert number]" as a '
                        . 'page that is still being written.',
                        $key,
                        $index,
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
        self::assertIsArray($pages);

        foreach ($pages as $page) {
            if (is_array($page) && ($page['key'] ?? null) === $key) {
                return $page;
            }
        }

        self::fail('No page with key ' . $key . ' in the content source.');
    }

    /**
     * Heading → its paragraphs, in document order.
     *
     * @return array<string, list<string>>
     */
    private function sections(string $key): array
    {
        $out = [];

        foreach ($this->page($key)['sections'] as $section) {
            if (!is_array($section)) {
                continue;
            }

            $heading = $section['heading'] ?? null;
            $paragraphs = $section['paragraphs'] ?? [];

            if (is_string($heading) && is_array($paragraphs)) {
                $out[$heading] = array_values(array_filter($paragraphs, is_string(...)));
            }
        }

        return $out;
    }

    /** The file wraps sentences with ` . ` at the join, so collapse whitespace before comparing. */
    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
