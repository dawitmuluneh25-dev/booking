<?php

declare(strict_types=1);

namespace App\Storage;

use App\Domain\DocumentScanner;
use App\Domain\ScanResult;

/**
 * Content inspection by signature, without shelling out.
 *
 * The browser's `Content-Type` is a guess and the filename is attacker-chosen, so
 * neither can decide what a file is. This reads the bytes and requires two
 * independent answers to agree:
 *
 * - the fixed magic number at the start of the file, checked here;
 * - libmagic's opinion via `finfo`, which understands more formats than the
 *   handful this application accepts.
 *
 * If either says something the accepted set does not contain, the result is
 * "unrecognised" and the upload is refused. Disagreement is treated as a
 * mismatch rather than resolved in the file's favour, which is what makes a
 * polyglot that starts with one signature and claims another type fail (DOC-03).
 *
 * This is not malware scanning, and the class does not pretend otherwise; see
 * `DocumentScanner` and `ReviewQuarantinedDocument` for where that decision
 * lives.
 */
final class FileSignatureScanner implements DocumentScanner
{
    /** offset => magic bytes, mapped to the MIME type those bytes mean. */
    private const SIGNATURES = [
        ['magic' => "%PDF-", 'mime' => 'application/pdf'],
        ['magic' => "\x89PNG\r\n\x1a\n", 'mime' => 'image/png'],
        ['magic' => "\xFF\xD8\xFF", 'mime' => 'image/jpeg'],
        ['magic' => "II*\x00", 'mime' => 'image/tiff'],
        ['magic' => "MM\x00*", 'mime' => 'image/tiff'],
    ];

    /** libmagic returns older aliases for two of the accepted formats. */
    private const ALIASES = [
        'image/pjpeg' => 'image/jpeg',
        'image/jpg' => 'image/jpeg',
        'application/x-pdf' => 'application/pdf',
        'image/x-png' => 'image/png',
    ];

    public function inspect(string $path): ScanResult
    {
        if (!is_file($path)) {
            return new ScanResult(null, 'file is missing');
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return new ScanResult(null, 'file is unreadable');
        }

        $head = (string) fread($handle, 16);
        fclose($handle);

        $byMagic = $this->detectByMagic($head);

        if ($byMagic === null) {
            return new ScanResult(null, 'unrecognised file signature');
        }

        $byLibmagic = $this->detectByLibmagic($path);

        if ($byLibmagic === null) {
            // Without libmagic there is no second opinion, and one opinion is not
            // enough for a control that decides what enters clinical storage.
            return new ScanResult(null, 'content engine unavailable');
        }

        if ($byLibmagic !== $byMagic) {
            return new ScanResult(null, 'signature and content engine disagree');
        }

        return new ScanResult($byMagic, 'signature and content engine agree');
    }

    private function detectByMagic(string $head): ?string
    {
        foreach (self::SIGNATURES as $signature) {
            if (str_starts_with($head, $signature['magic'])) {
                return $signature['mime'];
            }
        }

        return null;
    }

    private function detectByLibmagic(string $path): ?string
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = @$finfo->file($path);

        if (!is_string($detected) || $detected === '') {
            return null;
        }

        $detected = strtolower(trim(explode(';', $detected)[0]));

        return self::ALIASES[$detected] ?? $detected;
    }
}
