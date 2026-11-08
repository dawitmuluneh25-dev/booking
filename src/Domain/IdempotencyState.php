<?php

declare(strict_types=1);

namespace App\Domain;

/** What the store knows about an incoming idempotency key. */
enum IdempotencyState: string
{
    /** Not seen before: the caller should do the work. */
    case New = 'new';

    /** Seen and finished with the same request: return the recorded result. */
    case Replay = 'replay';

    /** Seen with a different request, or still running: refuse. */
    case Conflict = 'conflict';
}
