<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Application;
use App\Domain\NotificationChannel;
use App\Domain\NotificationTemplate;
use App\Domain\OutboundMessage;
use App\Notifications\BrevoTransport;
use App\Notifications\HttpTransport;
use App\Notifications\LogTransport;
use App\Notifications\MisconfiguredTransport;
use App\Notifications\NotificationSettings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The composition root's transport decision, finally under test.
 *
 * `DeliveryReadiness` has always had tests; the four lines in `transport()` that
 * act on its answer did not, because building a production application meant
 * rewriting the environment the whole suite shares. §51.2 recorded that as the
 * last untested branch in the notification path, and §52 made it matter more: the
 * branch now decides the activation rule for both real drivers.
 *
 * The seam is `NotificationSettings` — the same nine values `Config` reads,
 * gathered so they can be handed in. Nothing here mocks the rule; it exercises
 * the real factory with real transport classes.
 */
final class ApplicationTransportSelectionTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        // boot() sets the process timezone from configuration. Putting it back keeps
        // this class from changing what any later test sees.
        date_default_timezone_set($this->originalTimezone);
    }

    public function testProductionOnTheLogDriverRefusesRatherThanPretending(): void
    {
        $transport = $this->application(['driver' => 'log', 'production' => true])->transport();

        self::assertInstanceOf(MisconfiguredTransport::class, $transport);
    }

    public function testTheRefusalNamesTheDriverAndTheReason(): void
    {
        $transport = $this->application(['driver' => 'log', 'production' => true])->transport();

        try {
            $transport->send($this->message());
            self::fail('A production log driver must not accept a message.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not being delivered', $exception->getMessage());
            self::assertStringContainsString('NOTIFY_DRIVER=log', $exception->getMessage());
            self::assertStringContainsString('nothing leaves this host', $exception->getMessage());
        }
    }

    public function testDevelopmentOnTheLogDriverStillGetsALogTransport(): void
    {
        $transport = $this->application(['driver' => 'log', 'production' => false])->transport();

        self::assertInstanceOf(LogTransport::class, $transport);
    }

    public function testAProductionBridgeWithoutApprovalIsRefused(): void
    {
        $transport = $this->application([
            'driver' => 'http',
            'production' => true,
            'approved' => false,
            'httpUrl' => 'https://bridge.test/send',
            'httpToken' => 'secret',
        ])->transport();

        self::assertInstanceOf(MisconfiguredTransport::class, $transport);
    }

    public function testAnApprovedProductionBridgeIsSelected(): void
    {
        $transport = $this->application([
            'driver' => 'http',
            'production' => true,
            'approved' => true,
            'httpUrl' => 'https://bridge.test/send',
            'httpToken' => 'secret',
        ])->transport();

        self::assertInstanceOf(HttpTransport::class, $transport);
    }

    public function testTheBridgeWorksOutsideProductionWithoutApproval(): void
    {
        // Staging and development must be exercisable without the production flag;
        // otherwise nobody can test the path before approving it.
        $transport = $this->application([
            'driver' => 'http',
            'production' => false,
            'approved' => false,
            'httpUrl' => 'https://bridge.test/send',
            'httpToken' => 'secret',
        ])->transport();

        self::assertInstanceOf(HttpTransport::class, $transport);
    }

    public function testAnApprovedBrevoPathIsSelected(): void
    {
        $transport = $this->application([
            'driver' => 'brevo',
            'production' => true,
            'approved' => true,
            'brevoApiKey' => 'xkeysib-test',
            'brevoSender' => 'notifications@hakimerad.com',
        ])->transport();

        self::assertInstanceOf(BrevoTransport::class, $transport);
    }

    public function testAnIncompleteBrevoPathIsRefused(): void
    {
        $transport = $this->application([
            'driver' => 'brevo',
            'production' => true,
            'approved' => true,
            'brevoApiKey' => '',
            'brevoSender' => 'notifications@hakimerad.com',
        ])->transport();

        self::assertInstanceOf(MisconfiguredTransport::class, $transport);
    }

    /**
     * @param array<string, string|bool> $overrides
     */
    private function application(array $overrides): Application
    {
        $settings = new NotificationSettings(
            driver: (string) ($overrides['driver'] ?? 'log'),
            production: (bool) ($overrides['production'] ?? false),
            approved: (bool) ($overrides['approved'] ?? false),
            brevoApiKey: (string) ($overrides['brevoApiKey'] ?? ''),
            brevoSender: (string) ($overrides['brevoSender'] ?? ''),
            brevoSenderName: 'HakimeRAD',
            httpUrl: (string) ($overrides['httpUrl'] ?? ''),
            httpToken: (string) ($overrides['httpToken'] ?? ''),
            httpTimeoutSeconds: 5,
            timezone: 'Africa/Addis_Ababa',
        );

        return Application::boot(dirname(__DIR__, 2), $settings);
    }

    private function message(): OutboundMessage
    {
        return new OutboundMessage(
            channel: NotificationChannel::Sms,
            template: NotificationTemplate::BookingConfirmed,
            recipient: '+251911000111',
            payload: ['reference' => 'BK-1', 'scheduled_at' => '2030-01-01 08:00:00'],
        );
    }
}
