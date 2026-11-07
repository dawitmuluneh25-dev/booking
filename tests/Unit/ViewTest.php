<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\View;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'booking-view-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0775, true);

        file_put_contents(
            $this->directory . '/layout.php',
            '<main><?= $content ?></main><footer><?= e($appName) ?></footer>'
        );
        file_put_contents(
            $this->directory . '/page.php',
            '<h1><?= e($title) ?></h1><p><?= e($unsafe) ?></p>'
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testItEscapesDynamicValues(): void
    {
        $view = new View($this->directory, ['appName' => 'Booking <script>']);

        $html = $view->render('page', [
            'title' => 'Hello',
            'unsafe' => '<script>alert(1)</script>',
        ]);

        self::assertStringContainsString('<h1>Hello</h1>', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('Booking &lt;script&gt;', $html);
    }

    public function testItFailsLoudlyForAMissingTemplate(): void
    {
        $this->expectException(RuntimeException::class);

        (new View($this->directory))->render('missing', []);
    }
}
