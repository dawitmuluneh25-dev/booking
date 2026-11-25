<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Whether the configured notification path can actually put a message in front of a person.
 *
 * One rule, two consumers: the transport factory uses it to refuse to pretend, and `notify:preflight`
 * uses it to tell an operator what is missing before they flip a flag. Written once because the failure
 * mode is a configuration that *looks* complete — a driver named, a key pasted, a flag set — while the
 * messages go to a file.
 *
 * The reason this exists at all is the log driver. In development it is exactly right: it writes what
 * would have been sent, and the outbox is not pretending anything because nobody believes a laptop sends
 * SMS. In production the same driver marks every row `sent` and delivers nothing, and the outbox — the
 * record staff use to answer "was the patient told?" — becomes a lie that looks like a receipt.
 */
final class DeliveryReadiness
{
    public const DRIVER_LOG = 'log';

    public const DRIVER_HTTP = 'http';

    public const DRIVER_BREVO = 'brevo';

    /**
     * @return array{ok: bool, reason: string|null, warnings: list<string>}
     */
    public static function assess(
        string $driver,
        bool $production,
        bool $approved,
        bool $hasApiKey,
        bool $hasSender,
        bool $hasHttpUrl,
        bool $hasHttpToken,
    ): array {
        $warnings = [];

        switch ($driver) {
            case self::DRIVER_LOG:
                if ($production) {
                    return [
                        'ok' => false,
                        'reason' => 'NOTIFY_DRIVER=log in production: the outbox would mark every message'
                            . ' sent while nothing leaves this host. Set the driver to brevo (or http) and'
                            . ' supply its credentials.',
                        'warnings' => [],
                    ];
                }

                return ['ok' => true, 'reason' => null, 'warnings' => ['log driver: messages are written to'
                    . ' var/logs/notifications.log and are not delivered to anybody.']];

            case self::DRIVER_BREVO:
                if (!$hasApiKey) {
                    return [
                        'ok' => false,
                        'reason' => 'NOTIFY_DRIVER=brevo without NOTIFY_BREVO_API_KEY. Messages would fail'
                            . ' one by one at dispatch.',
                        'warnings' => [],
                    ];
                }

                if (!$hasSender) {
                    return [
                        'ok' => false,
                        'reason' => 'NOTIFY_DRIVER=brevo without NOTIFY_BREVO_SENDER. Brevo rejects a send'
                            . ' with no verified sender.',
                        'warnings' => [],
                    ];
                }

                if ($production && !$approved) {
                    return [
                        'ok' => false,
                        'reason' => 'NOTIFY_PRODUCTION_APPROVED is not set. The transport is configured but'
                            . ' switched off, so every message fails loudly rather than reaching a patient.',
                        'warnings' => [],
                    ];
                }

                if (!$production) {
                    $warnings[] = 'brevo driver outside production: sends are real, so only use addresses you'
                        . ' control.';
                }

                return ['ok' => true, 'reason' => null, 'warnings' => $warnings];

            case self::DRIVER_HTTP:
                if (!$hasHttpUrl) {
                    return [
                        'ok' => false,
                        'reason' => 'NOTIFY_DRIVER=http without NOTIFY_HTTP_URL.',
                        'warnings' => [],
                    ];
                }

                if (!$hasHttpToken) {
                    return [
                        'ok' => false,
                        'reason' => 'NOTIFY_DRIVER=http without NOTIFY_HTTP_TOKEN. The bridge would be called'
                            . ' unauthenticated.',
                        'warnings' => [],
                    ];
                }

                return ['ok' => true, 'reason' => null, 'warnings' => $warnings];
        }

        return [
            'ok' => false,
            'reason' => 'NOTIFY_DRIVER="' . $driver . '" is not one of log, http or brevo.',
            'warnings' => [],
        ];
    }
}
