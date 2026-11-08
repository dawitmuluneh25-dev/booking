<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\RouteAccess;
use App\Http\Router;
use App\Support\Logger;
use App\Support\View;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelTest extends TestCase
{
    private string $logDirectory;

    protected function setUp(): void
    {
        $this->logDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'booking-log-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->logDirectory)) {
            rmdir($this->logDirectory);
        }
    }

    public function testItAddsSecurityHeaders(): void
    {
        $response = $this->kernel(new Router())->handle(Request::create('GET', '/'));

        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
        $policy = (string) $response->header('Content-Security-Policy');
        self::assertStringContainsString("frame-ancestors 'none'", $policy);
    }

    public function testItRendersNotFoundWithoutLeakingInternals(): void
    {
        $response = $this->kernel(new Router())->handle(Request::create('GET', '/missing'));

        self::assertSame(404, $response->status);
        self::assertStringContainsString('Page not found', $response->body());
    }

    public function testItHidesExceptionDetailsWhenDebugIsOff(): void
    {
        $router = (new Router())->get('/', static function (): Response {

            throw new RuntimeException('database password is hunter2');
        }, RouteAccess::public('test'));

        $response = $this->kernel($router)->handle(Request::create('GET', '/'));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('hunter2', $response->body());
        self::assertStringContainsString('Something went wrong', $response->body());
    }

    public function testItReportsMethodNotAllowed(): void
    {
        $router = (new Router())->post(
            '/bookings',
            static fn (): Response => Response::text('ok'),
            RouteAccess::public('test'),
        );

        $response = $this->kernel($router)->handle(Request::create('GET', '/bookings'));

        self::assertSame(405, $response->status);
        self::assertSame('POST', $response->header('Allow'));
    }

    private function kernel(Router $router): Kernel
    {
        return new Kernel(
            $router,
            new View(dirname(__DIR__, 2) . '/templates', ['appName' => 'Booking']),
            new Logger($this->logDirectory),
            false,
        );
    }
}
