<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One version of one patient-facing page, with §17.5's provenance beside it.
 *
 * The two questions this object exists to answer are "may this be shown?" and
 * "if not, what exactly is missing?". Both are answered here rather than in the
 * template, because a template that decides whether a page is publishable is a
 * template somebody can bypass.
 *
 * The body is structured sections rather than Markdown or HTML. That is a
 * deliberate trade: it costs the ability to write arbitrary prose, and it buys
 * the guarantee that every string printed on the page was escaped by the
 * template. Nothing that reaches a patient was parsed by a renderer that could
 * be talked into emitting markup.
 */
final class PublishedContent
{
    /**
     * The content-key prefix that marks a page as a policy notice rather than clinical copy.
     *
     * This is the classifier the publish gate uses, and it is the key rather than the audience
     * because `audience` has two values — `patient` and `referrer` — and a privacy notice is
     * written for patients. The key is stable, it is what the route and the decision records use,
     * and a page cannot be accidentally re-classified by editing a label.
     */
    public const POLICY_PREFIX = 'policy.';

    /**
     * §17.5's pre-publication list, restricted to the parts a row can be missing.
     * A content ID, an audience and a version are columns the row cannot exist
     * without, so they are not failure modes this list can report.
     *
     * @var list<string>
     */
    public const REQUIRED_FOR_PUBLICATION = [
        'clinical reviewer',
        'approver',
        'approval date',
        'effective date',
        'review date',
        'translation-review status',
    ];

    public function __construct(
        public readonly int $id,
        public readonly string $contentKey,
        public readonly string $audience,
        public readonly string $language,
        public readonly string $title,
        /** Raw JSON. Decoded by sections(), which validates the shape. */
        public readonly string $body,
        public readonly string $version,
        public readonly string $author,
        public readonly ?string $clinicalReviewer,
        public readonly ?string $approver,
        public readonly ?string $approvedOn,
        public readonly ?string $effectiveOn,
        public readonly ?string $reviewOn,
        public readonly ?string $supersededVersion,
        public readonly ?string $translationReviewStatus,
        public readonly ContentStatus $status,
        public readonly ?string $publishedAtUtc,
        public readonly ?int $publishedByUserId,
        public readonly ?string $withdrawnAtUtc,
        public readonly ?int $withdrawnByUserId,
        public readonly string $createdAtUtc,
        public readonly string $updatedAtUtc,
    ) {
    }

    /**
     * The page as the template renders it.
     *
     * A malformed body yields no sections rather than an exception, and the
     * caller is expected to treat "no sections" as "not fit to publish" — the
     * same way it treats a missing approver. Throwing here would turn a bad
     * import into a 500 on a public page, which is a worse outcome than an
     * empty page that the admin screen flags.
     *
     * @return list<array{
     *     heading: string,
     *     paragraphs: list<string>,
     *     bullets: list<string>,
     *     faq: list<array{question: string, answer: string}>
     * }>
     */
    public function sections(): array
    {
        $decoded = json_decode($this->body, true);

        if (!is_array($decoded)) {
            return [];
        }

        $sections = [];

        foreach ($decoded as $section) {
            if (!is_array($section)) {
                continue;
            }

            $heading = $section['heading'] ?? null;

            if (!is_string($heading) || trim($heading) === '') {
                continue;
            }

            $sections[] = [
                'heading' => $heading,
                'paragraphs' => self::stringList($section['paragraphs'] ?? null),
                'bullets' => self::stringList($section['bullets'] ?? null),
                'faq' => self::faqList($section['faq'] ?? null),
            ];
        }

        return $sections;
    }

    /**
     * The last segment of the content key, which is what the public URL carries.
     *
     * `service.fnac` is reachable at `/services/fnac`. The type prefix stays in the
     * key rather than in the slug so that a page's address and its record cannot
     * drift apart; the route decides the prefix and the controller applies it, and
     * there is only one place that does so.
     */
    public function slug(): string
    {
        $dot = strrpos($this->contentKey, '.');

        return $dot === false ? $this->contentKey : substr($this->contentKey, $dot + 1);
    }

    /** True when there is something to show. A page with no sections is not a page. */
    public function hasBody(): bool
    {
        return $this->sections() !== [];
    }

    /** True for a policy notice, false for clinical copy. See `POLICY_PREFIX`. */
    public function isPolicy(): bool
    {
        return str_starts_with($this->contentKey, self::POLICY_PREFIX);
    }

    /**
     * The §17.5 inputs this row does not have yet, in the order §17.5 lists them.
     *
     * Named rather than counted: "3 required fields are missing" tells the person
     * filling the form nothing about which three, and §17.5 is a list somebody has
     * to work through.
     *
     * **One field is audience-dependent, and this is where that is decided.** §17.5 asks for a
     * clinical reviewer because it was written about clinical copy — a service page describing a
     * procedure. HR-ENG-DEC-R1-003 D-01 gives the authority for a policy notice as Legal/Data
     * Protection and the Product Owner, and neither is a clinician. Demanding a clinical reviewer of
     * a privacy notice does not make it safer; it makes the page unpublishable for a reason that
     * does not apply, so the natural next step is to type a clinician's name into a field they never
     * signed. The exemption is narrow: a policy page still needs an approver and an approval date,
     * which is exactly the approval Legal has not yet given.
     *
     * The same distinction is enforced in the schema by migration 0045, because a rule that only
     * holds when the application asks politely is not a rule.
     *
     * @return list<string>
     */
    public function missingForPublication(): array
    {
        $missing = [];

        if (!$this->isPolicy() && ($this->clinicalReviewer === null || trim($this->clinicalReviewer) === '')) {
            $missing[] = 'clinical reviewer';
        }

        if ($this->approver === null || trim($this->approver) === '') {
            $missing[] = 'approver';
        }

        if ($this->approvedOn === null) {
            $missing[] = 'approval date';
        }

        if ($this->effectiveOn === null) {
            $missing[] = 'effective date';
        }

        if ($this->reviewOn === null) {
            $missing[] = 'review date';
        }

        if ($this->translationReviewStatus === null) {
            $missing[] = 'translation-review status';
        }

        return $missing;
    }

    public function isPublishable(): bool
    {
        return $this->missingForPublication() === [] && $this->hasBody();
    }

    /**
     * Whether the review date has passed for the given day (YYYY-MM-DD).
     *
     * §17.5 says to hide expired content, and a page is expired once the day it
     * was due to be looked at again has gone by. Comparing ISO dates as strings
     * is exact here: they sort lexicographically by design.
     */
    public function isExpiredOn(string $today): bool
    {
        return $this->reviewOn !== null && $this->reviewOn < $today;
    }

    /**
     * Whether this row may be served to the public on the given day.
     *
     * Three conditions, and each one is a separate way for a page to be wrong:
     * it must be published, its effective date must have arrived, and its review
     * date must not have passed. A future effective date is content that has been
     * approved for later — showing it early would be showing something nobody
     * said was live yet.
     */
    public function isServableOn(string $today): bool
    {
        if ($this->status !== ContentStatus::Published) {
            return false;
        }

        if (!$this->hasBody()) {
            return false;
        }

        if ($this->effectiveOn !== null && $this->effectiveOn > $today) {
            return false;
        }

        return !$this->isExpiredOn($today);
    }

    /**
     * The document-control fields a reader is entitled to see, as label/value pairs.
     *
     * D-01 requires a visible effective date and review date on each of the three policy pages, and
     * "maintain the dates consistently across the pages and their underlying policy metadata" — so
     * both the label and the value are built from the record here, and the template prints what it
     * is given. A template that formatted the date itself would be a second place for the date to
     * be wrong.
     *
     * Only what the record holds is listed. An absent effective date prints nothing rather than an
     * empty field: a page that says "Effective from —" looks like a page somebody forgot to finish,
     * and this one would then be published with that gap visible.
     *
     * @return list<array{label: string, value: string}>
     */
    public function documentControl(): array
    {
        $entries = [];

        if ($this->effectiveOn !== null) {
            $entries[] = ['label' => 'Effective from', 'value' => self::humanDate($this->effectiveOn)];
        }

        if ($this->reviewOn !== null) {
            $entries[] = ['label' => 'Next review', 'value' => self::humanDate($this->reviewOn)];
        }

        return $entries;
    }

    /**
     * A stored ISO date as a reader expects to see it: `2027-01-12` becomes `12 January 2027`.
     *
     * Stored dates are ISO because that is what sorts and compares; printed dates are prose because
     * that is what a reader parses. An unparseable value is returned unchanged rather than replaced
     * with an empty string — a wrong date that is visible is a defect somebody can report, and a
     * blank one is not.
     */
    public static function humanDate(string $iso): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        if ($date === false) {
            return $iso;
        }

        return $date->format('j F Y');
    }

    /**
     * The provenance line §17.5 requires beside each page. It is built here and
     * never from a template constant, so the name on the page is the name in the
     * record or the page does not render.
     *
     * It no longer carries the review date. That date is a document-control field and it is printed
     * as one by `documentControl()`, on the same screen; stating it twice was how the two copies
     * could come to disagree.
     */
    public function provenance(): string
    {
        // Built by concatenation rather than by joining parts, because the separators
        // are not uniform: the reviewer and the approver are two records separated by
        // a comma, and everything after them by a space. Joining a list would have put
        // a space before the comma, which reads like a typo and is one.
        $reviewer = self::collapse($this->clinicalReviewer);
        $approver = self::collapse($this->approver);

        // One person may hold both roles, and the record still keeps two fields, because
        // "who reviewed it" and "who approved it" are two questions even when the answer
        // is the same. The sentence a patient reads does not repeat the name: printed
        // twice it reads as a copy-paste error and invites the reader to ask who checked
        // the checker. Said once as "reviewed and approved", the same fact is stated
        // without dressing a single signature up as two.
        $samePerson = $reviewer !== '' && $approver !== '' && self::sameName($reviewer, $approver);

        $line = '';

        if ($samePerson) {
            $line = 'Reviewed and approved by ' . $reviewer;
        } elseif ($reviewer !== '') {
            $line = 'Clinically reviewed by ' . $reviewer;
        }

        if ($approver !== '' && !$samePerson) {
            $line .= ($line === '' ? '' : ', ') . ($line === '' ? 'Approved' : 'approved') . ' by ' . $approver;
        }

        // Printed the same way as the document-control dates on the same screen, so a reader does
        // not have to work out that "2026-11-13" and "13 November 2026" are the same day.
        if ($this->approvedOn !== null) {
            $line .= ($line === '' ? '' : ' ') . 'on ' . self::humanDate($this->approvedOn);
        }

        return $line === '' ? '' : rtrim($line, '.') . '.';
    }

    /**
     * A name as a sentence needs it: no leading or trailing space, and no run of spaces
     * inside, so "Dawit  Muluneh" and "Dawit Muluneh" are recognised as one signature
     * rather than printed as two.
     */
    private static function collapse(?string $name): string
    {
        if ($name === null) {
            return '';
        }

        $collapsed = preg_replace('/\s+/u', ' ', trim($name));

        return is_string($collapsed) ? $collapsed : trim($name);
    }

    private static function sameName(string $one, string $other): bool
    {
        return strtolower($one) === strtolower($other);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: self::number($row, 'id'),
            contentKey: self::text($row, 'content_key'),
            audience: self::text($row, 'audience'),
            language: self::text($row, 'language'),
            title: self::text($row, 'title'),
            body: self::text($row, 'body'),
            version: self::text($row, 'version'),
            author: self::text($row, 'author'),
            clinicalReviewer: self::nullableText($row, 'clinical_reviewer'),
            approver: self::nullableText($row, 'approver'),
            approvedOn: self::nullableText($row, 'approved_on'),
            effectiveOn: self::nullableText($row, 'effective_on'),
            reviewOn: self::nullableText($row, 'review_on'),
            supersededVersion: self::nullableText($row, 'superseded_version'),
            translationReviewStatus: self::nullableText($row, 'translation_review_status'),
            status: ContentStatus::from(self::text($row, 'status')),
            publishedAtUtc: self::nullableText($row, 'published_at'),
            publishedByUserId: self::nullableNumber($row, 'published_by_user_id'),
            withdrawnAtUtc: self::nullableText($row, 'withdrawn_at'),
            withdrawnByUserId: self::nullableNumber($row, 'withdrawn_by_user_id'),
            createdAtUtc: self::text($row, 'created_at'),
            updatedAtUtc: self::text($row, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function number(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $row */
    private static function nullableNumber(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $row */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $row */
    private static function nullableText(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /** @return list<array{question: string, answer: string}> */
    private static function faqList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $entries = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = $item['question'] ?? null;
            $answer = $item['answer'] ?? null;

            if (is_string($question) && is_string($answer) && $question !== '' && $answer !== '') {
                $entries[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $entries;
    }
}
