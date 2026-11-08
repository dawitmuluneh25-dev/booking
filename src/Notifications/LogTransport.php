<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\OutboundMessage;
use RuntimeException;

/**
 * Development transport: writes what would have been sent to a log file.
 *
 * A real SMS/WhatsApp gateway replaces this by implementing
 * NotificationTransport; nothing else in the application changes.
 */
final class LogTransport implements NotificationTransport
{
    public function __construct(private readonly string $path)
    {
    }

    public function send(OutboundMessage $message): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the notification log directory.');
        }

        $record = [
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'channel' => $message->channel->value,
            'template' => $message->template->value,
            'to' => self::mask($message->recipient),
            'reference' => $message->payload['reference'] ?? '',
            'body' => $message->body(),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            throw new RuntimeException('Cannot encode the notification record.');
        }

        if (file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Cannot write to the notification log.');
        }
    }

    private static function mask(string $recipient): string
    {
        $visible = substr($recipient, -3);

        return '***' . $visible;
    }
}
