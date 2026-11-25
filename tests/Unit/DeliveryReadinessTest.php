<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\OutboundMessage;
use App\Notifications\DeliveryReadiness;
use App\Notifications\MisconfiguredTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The one rule that stops a production outbox claiming delivery it did not achieve (D-09).
 *
 * The cases are ordered by how likely each mistake is. The log driver in production is the dangerous
 * one: it is the default, it works perfectly in development, and it marks every row `sent`. The Brevo
 * cases are the ones an operator meets while setting the thing up.
 */
final class DeliveryReadinessTest extends TestCase
{
    public function testTheLogDriverIsRefusedInProduction(): void
    {
        $result = DeliveryReadiness::assess('log', true, false, false, false, false, false);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('NOTIFY_DRIVER=log', (string) $result['reason']);
        self::assertStringContainsString('nothing leaves this host', (string) $result['reason']);
    }

    public function testTheLogDriverIsAllowedInDevelopmentWithAWarning(): void
    {
        $result = DeliveryReadiness::assess('log', false, false, false, false, false, false);

        self::assertTrue($result['ok']);
        self::assertNull($result['reason']);
        self::assertNotEmpty($result['warnings'], 'A log driver delivers nothing; that has to be said.');
    }

    public function testAFullyConfiguredProductionBrevoPathIsReady(): void
    {
        $result = DeliveryReadiness::assess('brevo', true, true, true, true, false, false);

        self::assertTrue($result['ok']);
        self::assertSame([], $result['warnings']);
    }

    public function testBrevoWithoutTheProductionApprovalIsNotReady(): void
    {
        $result = DeliveryReadiness::assess('brevo', true, false, true, true, false, false);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('NOTIFY_PRODUCTION_APPROVED', (string) $result['reason']);
    }

    public function testBrevoWithoutAKeyIsNotReady(): void
    {
        $result = DeliveryReadiness::assess('brevo', true, true, false, true, false, false);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('NOTIFY_BREVO_API_KEY', (string) $result['reason']);
    }

    public function testBrevoWithoutASenderIsNotReady(): void
    {
        $result = DeliveryReadiness::assess('brevo', true, true, true, false, false, false);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('NOTIFY_BREVO_SENDER', (string) $result['reason']);
    }

    public function testTheHttpBridgeNeedsATokenAsWellAsAUrl(): void
    {
        $result = DeliveryReadiness::assess('http', true, true, false, false, true, false);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('NOTIFY_HTTP_TOKEN', (string) $result['reason']);
    }

    public function testAnUnknownDriverIsRefusedRatherThanFallingBack(): void
    {
        $result = DeliveryReadiness::assess('smtp', true, true, true, true, false, false);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('smtp', (string) $result['reason']);
    }
}
