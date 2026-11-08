<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What happened to an attempted document access.
 *
 * `denied` is recorded, not merely returned: an access refusal is the event an
 * investigator needs, and a refusal nobody wrote down looks identical to one
 * that never happened (AUD-04, DOC-06).
 */
enum AccessOutcome: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Error = 'error';
}
