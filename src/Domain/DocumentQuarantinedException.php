<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * The reader may see this document, but its bytes are not released yet.
 *
 * Raised only after the object-level check has passed, so the caller already
 * knows the document exists. Answering 409 here is therefore not a disclosure;
 * answering 404 would be a lie to an authorised clinician wondering why the
 * attachment will not open (DOC-05).
 */
final class DocumentQuarantinedException extends RuntimeException
{
    public function __construct(public readonly DocumentScanStatus $status)
    {
        parent::__construct('This document is not available yet: ' . strtolower($status->label()) . '.');
    }
}
