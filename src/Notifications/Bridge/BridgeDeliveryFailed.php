<?php

declare(strict_types=1);

namespace App\Notifications\Bridge;

use RuntimeException;

/**
 * A provider that refused, or that could not be reached.
 *
 * It is its own type because the bridge's answer differs by cause: a refusal is
 * the application's problem to fix and should not be retried forever, while an
 * unreachable provider is somebody else's outage and should be. Both are
 * currently reported as 502, and the distinction is carried in the message the
 * outbox records — inventing a retry policy at the bridge would move a decision
 * that already exists in the dispatcher.
 */
final class BridgeDeliveryFailed extends RuntimeException
{
}
