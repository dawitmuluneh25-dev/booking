<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * A clinical decision was refused because the practitioner's verified authority
 * does not cover it (AC-009).
 *
 * Separate from `AuthorisationException` because the actor does hold
 * `clinical.approve` — telling them they lack the permission would be false, and
 * would send them to the wrong person. What they lack is a current verification for
 * this procedure, and the message says which part is missing so they can act.
 */
final class ClinicalPrivilegeException extends RuntimeException
{
}
