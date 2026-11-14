<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** The escalation route a request names does not exist. */
final class EscalationRouteNotFoundException extends RuntimeException
{
}
