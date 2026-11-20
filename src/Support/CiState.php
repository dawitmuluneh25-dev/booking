<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a CI run says about a commit.
 *
 * Four states, not two, because the whole failure this type exists to prevent was a
 * missing third one: for seven commits the honest answer was "nobody looked", and it
 * got written down as though the build were fine. `Absent` and `Pending` are not
 * failure states and they are not success either — they are the tool declining to
 * guess.
 */
enum CiState: string
{
    case Green = 'green';

    case Red = 'red';

    case Pending = 'pending';

    case Absent = 'absent';

    /**
     * Process exit code. Deliberately spread across distinct values so a shell can
     * tell "red" from "couldn't tell" without parsing English.
     */
    public function exitCode(): int
    {
        return match ($this) {
            self::Green => 0,
            self::Red => 1,
            self::Absent => 2,
            self::Pending => 3,
        };
    }
}
