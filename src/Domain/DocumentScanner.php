<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Content inspection boundary.
 *
 * Two different jobs share this port and must not be confused:
 *
 * - at upload, an implementation answers "are these bytes the type they claim
 *   to be" (DOC-03). That check is mandatory and synchronous;
 * - malware scanning, if the deployment has a scanner, answers a different
 *   question and writes its verdict through `ReviewQuarantinedDocument`.
 *
 * The port only covers the first. A deployment without a malware scanner uses
 * the manual review command as its documented compensating control (HAK-0904)
 * rather than pretending a signature check is an antivirus.
 */
interface DocumentScanner
{
    public function inspect(string $path): ScanResult;
}
