<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * The document does not exist, or the actor may not see it.
 *
 * One exception for both cases, exactly like `ReferralNotFoundException`: the
 * caller turns it into a 404 and must not be able to tell the two apart, because
 * "that document exists but is not yours" is a statement about a patient
 * (SEC 8.4, DOC-06).
 */
final class DocumentNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such document.');
    }
}
