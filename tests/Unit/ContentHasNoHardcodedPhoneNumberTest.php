<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No telephone number may be hard-coded in the content source.
 *
 * Contact routes are production configuration: the operating model (HR-OPS-REF-R1-001 §5.4 and
 * CFG-07…CFG-10) holds them in the `escalation_routes` table, where `verified_at` records that
 * somebody actually dialled the number. A number typed into a content file has no such record, so
 * a page can publish "emergency contact" beside a number nobody has tested — which is exactly what
 * happened: three instances of `+251912480486` and one `0906373154` sat in the policy source, one of
 * them labelled "Verified emergency coordination contact" and one "Emergency contact".
 *
 * The rule is deliberately about shape, not about particular numbers. A list of banned numbers would
 * pass the day somebody adds the next one.
 */
final class ContentHasNoHardcodedPhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function contentSources(): array
    {
        return [
            'service pages' => ['service-pages.php'],
            'policy pages' => ['policy-pages.php'],
        ];
    }

    #[DataProvider('contentSources')]
    public function testTheSourceCarriesNoTelephoneNumber(string $file): void
    {
        $path = dirname(__DIR__, 2) . '/database/content/' . $file;
        self::assertFileExists($path);

        $pages = require $path;
        self::assertIsArray($pages);

        $found = [];

        foreach ($this->strings($pages) as $where => $text) {
            foreach ($this->phoneShaped($text) as $candidate) {
                $found[] = $where . ': "' . $candidate . '"';
            }
        }

        self::assertSame(
            [],
            $found,
            "A telephone number is hard-coded in {$file}. Contact routes belong in `escalation_routes`, "
            . "where a route carries the date somebody verified it answers; a number in a content file "
            . "can be published without that check ever happening.\n  " . implode("\n  ", $found),
        );
    }

    /**
     * Every string anywhere in the structure, keyed by a readable path.
     *
     * @param array<array-key, mixed> $pages
     *
     * @return array<string, string>
     */
    private function strings(array $pages, string $prefix = ''): array
    {
        $out = [];

        foreach ($pages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $out += $this->strings($value, $path);
                continue;
            }

            if (is_string($value)) {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /**
     * Candidates that look like a dialable number: nine or more digits, optionally carrying a
     * country-code plus, spaces, dashes, brackets, dots or slashes between them.
     *
     * Nine is the threshold because Ethiopia's national format is ten digits (09xxxxxxxx) and its
     * international form is twelve; a shorter run is a PO box, a year or a version. Separators are
     * tolerated because "0906 373 154" is the same number with the same problem.
     *
     * @return list<string>
     */
    private function phoneShaped(string $text): array
    {
        if (preg_match_all('/\+?\(?\d[\d\s()\/.\-]{6,}\d/', $text, $matches) === 0) {
            return [];
        }

        $out = [];

        foreach ($matches[0] as $match) {
            $digits = preg_replace('/\D+/', '', $match) ?? '';

            if (strlen($digits) >= 9) {
                $out[] = trim($match);
            }
        }

        return $out;
    }
}
