<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/** A profile review could not be applied as asked. */
final class PhysicianReviewException extends RuntimeException
{
}
