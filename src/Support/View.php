<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Plain PHP templates, escaped by convention through the `e()` helper.
 *
 * Templates are always rendered inside `layout.php`, except when `partial()`
 * is used for fragments.
 */
final class View
{
    /** @param array<string, mixed> $shared Values available to every template */
    public function __construct(
        private readonly string $directory,
        private readonly array $shared = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $data['content'] = $this->capture($template, $data);

        return $this->capture('layout', $data);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        return $this->capture($template, $data);
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $data = array_merge($this->shared, $data);
        $file = $this->directory . DIRECTORY_SEPARATOR . str_replace(['..', '\\'], '', $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                include $__file;
            })($file, $data);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
