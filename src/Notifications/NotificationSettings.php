<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Bootstrap\Config;

/**
 * Everything the transport factory needs to decide, in one value.
 *
 * `Application::transport()` used to read nine configuration values inline and
 * then branch on them. That made the most consequential decision in the
 * notification path — whether production delivers or refuses — reachable only by
 * editing a `.env` file, so the branch itself had no test: `Config` reads the
 * environment statically and the whole suite shares one environment.
 *
 * Passing the answers in makes the branch constructible. It is not a mock and not
 * a test double: it is the same nine values, read from the same place, gathered
 * once so a caller that already knows them does not have to re-read them.
 *
 * Secrets stay in their original form here rather than being reduced to
 * booleans, because the transports need the values, not just the fact that one
 * exists. The readiness check takes the booleans.
 */
final class NotificationSettings
{
    public function __construct(
        public readonly string $driver,
        public readonly bool $production,
        public readonly bool $approved,
        public readonly string $brevoApiKey,
        public readonly string $brevoSender,
        public readonly string $brevoSenderName,
        public readonly string $httpUrl,
        public readonly string $httpToken,
        public readonly int $httpTimeoutSeconds,
        public readonly string $timezone,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            driver: $config->notificationDriver(),
            production: $config->isProduction(),
            approved: $config->mailActivationApproved(),
            brevoApiKey: $config->brevoApiKey(),
            brevoSender: $config->brevoSender(),
            brevoSenderName: $config->brevoSenderName(),
            httpUrl: $config->notificationUrl(),
            httpToken: $config->notificationToken(),
            httpTimeoutSeconds: $config->notificationTimeout(),
            timezone: $config->timezone(),
        );
    }

    /**
     * The answer `DeliveryReadiness` gives for these settings.
     *
     * Kept here so the console command, the transport factory and a test all ask
     * the same object instead of re-listing nine arguments in three places and
     * eventually disagreeing about one of them.
     *
     * @return array{ok: bool, reason: string|null, warnings: list<string>}
     */
    public function readiness(): array
    {
        return DeliveryReadiness::assess(
            $this->driver,
            $this->production,
            $this->approved,
            $this->brevoApiKey !== '',
            $this->brevoSender !== '',
            $this->httpUrl !== '',
            $this->httpToken !== '',
        );
    }
}
