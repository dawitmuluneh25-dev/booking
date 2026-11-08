<?php

declare(strict_types=1);

namespace App\Domain;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Dead = 'dead';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Failed => 'Retrying',
            self::Dead => 'Dead',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Sent || $this === self::Dead;
    }
}
