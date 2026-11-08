<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One entry from the browser's multipart upload, normalised.
 *
 * Nothing here is trusted: `declaredMime` is what the browser claimed (and is
 * only used to look for a disagreement worth rejecting), `originalName` is only
 * ever stored as metadata and never becomes a path, and `temporaryPath` is
 * PHP's own temporary file, which the scanner reads immediately.
 *
 * `fromArray` returns null when the field is absent rather than fabricating an
 * empty upload, so "no file chosen" and "a broken upload" stay distinguishable.
 */
final readonly class UploadedFile
{
    public function __construct(
        public string $originalName,
        public string $declaredMime,
        public string $temporaryPath,
        public int $byteSize,
        public int $errorCode,
    ) {
    }

    /**
     * @param array<string, mixed> $entry one element of `$_FILES`
     */
    public static function fromArray(array $entry): ?self
    {
        if ($entry === []) {
            return null;
        }

        $name = $entry['name'] ?? null;
        if (!is_string($name) && !is_int($name) && !is_float($name)) {
            return null;
        }

        $error = $entry['error'] ?? UPLOAD_ERR_NO_FILE;

        return new self(
            originalName: trim((string) $name),
            declaredMime: self::stringOf($entry['type'] ?? null),
            temporaryPath: self::stringOf($entry['tmp_name'] ?? null),
            byteSize: is_numeric($entry['size'] ?? null) ? (int) $entry['size'] : 0,
            errorCode: is_numeric($error) ? (int) $error : UPLOAD_ERR_NO_FILE,
        );
    }

    public function hasError(): bool
    {
        return $this->errorCode !== UPLOAD_ERR_OK;
    }

    /**
     * A sentence a patient or clerk can act on.
     *
     * The per-file limit is passed in rather than guessed, because the message
     * must agree with the limit that was actually enforced.
     */
    public function errorMessage(int $maxBytes): string
    {
        return match ($this->errorCode) {
            UPLOAD_ERR_OK => '',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
                'That file is larger than the %s limit.',
                self::humanSize($maxBytes),
            ),
            UPLOAD_ERR_PARTIAL => 'The upload did not finish. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not accept the file. Please try again.',
            UPLOAD_ERR_EXTENSION => 'The server refused the file.',
            default => 'The file could not be uploaded.',
        };
    }

    /**
     * The extension, lowercased, and only when it is a plausible one.
     *
     * Anything with a second dot, a slash or a character outside `[a-z0-9]` is
     * returned as an empty string so it fails the allowlist instead of being
     * normalised into something that passes (DOC-04).
     */
    public function extension(): string
    {
        $name = $this->originalName;
        $dot = strrpos($name, '.');

        if ($dot === false || $dot === strlen($name) - 1) {
            return '';
        }

        $extension = strtolower(substr($name, $dot + 1));

        return preg_match('/\A[a-z0-9]{1,8}\z/', $extension) === 1 ? $extension : '';
    }

    /**
     * A display name that cannot break a header or a template: control
     * characters removed, length capped, path components dropped.
     */
    public function safeName(): string
    {
        $name = str_replace(['\\', '/'], ' ', $this->originalName);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        if ($name === '') {
            return 'document';
        }

        return mb_substr($name, 0, 190);
    }

    private static function stringOf(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '',
        };
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.') . ' MiB';
        }

        return (int) ceil($bytes / 1024) . ' KiB';
    }
}
