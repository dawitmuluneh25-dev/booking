<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** The credential or privilege a request names does not exist. */
final class PractitionerCredentialNotFoundException extends RuntimeException
{
}
