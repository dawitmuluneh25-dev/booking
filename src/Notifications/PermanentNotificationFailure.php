<?php

declare(strict_types=1);

namespace App\Notifications;

use RuntimeException;

/**
 * A failure that will not go away by trying again: a rejected payload, an
 * unconfigured credential, a channel the transport cannot deliver.
 *
 * The distinction matters operationally. A transient failure deserves the full
 * backoff ladder; a permanent one retried five times over an hour and a half
 * delays nothing, hides the real problem behind a growing attempt count, and
 * dead-letters anyway. The dispatcher gives up at once and says why.
 */
final class PermanentNotificationFailure extends RuntimeException
{
}
