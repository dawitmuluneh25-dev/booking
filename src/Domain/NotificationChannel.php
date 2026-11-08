<?php

declare(strict_types=1);

namespace App\Domain;

enum NotificationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Whatsapp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::Sms => 'SMS',
            self::Email => 'Email',
            self::Whatsapp => 'WhatsApp',
        };
    }
}
