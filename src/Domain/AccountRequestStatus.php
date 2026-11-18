<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The four states a request can hold, and the only moves between them.
 *
 * `expired` is a decision the system makes on a queue nobody attended, not
 * something a person chooses. It exists so an unattended queue stops growing
 * rather than so anybody can point at a request and expire it.
 */
enum AccountRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting a decision',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    /**
     * Only a pending request can be decided. Approving twice must not create a
     * second account, and a rejection must not overwrite an approval that already
     * produced one.
     */
    public function isDecidable(): bool
    {
        return $this === self::Pending;
    }
}
