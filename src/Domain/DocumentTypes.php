<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * The allowlist of file types this application will store.
 *
 * Two things matter here.
 *
 * First, the catalogue is code, not configuration: an operator may *narrow* the
 * list through `DOCUMENT_TYPES`, but a typo in the environment cannot widen it
 * into something nobody reviewed. The catalogue holds only formats whose
 * signature is unambiguous and which cannot execute in the application origin
 * (SEC 14.1 step 3, SEC 12.6.3: no HTML, no SVG, no office documents, no
 * archives).
 *
 * Second, each extension maps to the MIME types whose bytes may legitimately
 * appear under it. That mapping is what turns "the bytes say image/png but the
 * name says .pdf" into a rejection rather than a curiosity (DOC-03).
 */
final class DocumentTypes
{
    public const DEFAULT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    /**
     * Extension => MIME types those bytes may be.
     *
     * `jpg` and `jpeg` intentionally share one entry: they name the same format
     * and refusing one of them would be a usability bug, not a control.
     *
     * @var array<string, list<string>>
     */
    private const CATALOGUE = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'tif' => ['image/tiff'],
        'tiff' => ['image/tiff'],
    ];

    /** @var array<string, list<string>> */
    private readonly array $allowed;

    /** @param list<string>|null $extensions null uses the reviewed default set */
    public function __construct(?array $extensions = null)
    {
        $selected = $extensions ?? self::DEFAULT_EXTENSIONS;
        $allowed = [];

        foreach ($selected as $extension) {
            $key = strtolower(trim($extension));

            if (!isset(self::CATALOGUE[$key])) {
                throw new InvalidArgumentException(
                    'DOCUMENT_TYPES names an unsupported extension: ' . ($key === '' ? '(empty)' : $key)
                );
            }

            $allowed[$key] = self::CATALOGUE[$key];
        }

        if ($allowed === []) {
            throw new InvalidArgumentException('DOCUMENT_TYPES must name at least one accepted extension.');
        }

        $this->allowed = $allowed;
    }

    public function allows(string $extension): bool
    {
        return isset($this->allowed[strtolower($extension)]);
    }

    /** @return list<string> */
    public function mimeTypesFor(string $extension): array
    {
        return $this->allowed[strtolower($extension)] ?? [];
    }

    /** The MIME type recorded when the bytes are accepted, given the extension. */
    public function canonicalMime(string $extension): ?string
    {
        return $this->mimeTypesFor($extension)[0] ?? null;
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return array_keys($this->allowed);
    }

    /** "PDF, JPG or PNG" - for a message a person has to read. */
    public function describe(): string
    {
        $labels = array_map(static fn (string $extension): string => strtoupper($extension), $this->extensions());

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels) . ' or ' . $last;
    }
}
